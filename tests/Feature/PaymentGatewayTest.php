<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\PaymentGateway;
use App\Models\Pelanggan;
use App\Models\TransaksiMidtrans;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI_PT = 'SB-Mid-server-perusahaanABC1234';

    private const KUNCI_MITRA = 'SB-Mid-server-mitraCimahi9876';

    private const UJI = 'api.sandbox.midtrans.com/v2/UJI-*';

    private const SNAP = 'https://app.sandbox.midtrans.com/snap/v1/transactions';

    private Lokasi $biasa;

    private Lokasi $full;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->biasa = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->full = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi', 'admin_full' => true]);
    }

    public function test_super_admin_adds_company_gateway_with_encrypted_key_that_never_reaches_the_browser(): void
    {
        $this->actingAs(User::factory()->create());
        $this->kunciDiterima();

        $this->tambah()->assertOk();

        // Kunci diuji ke Midtrans dengan akun itu sendiri sebelum disimpan
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.sandbox.midtrans.com/v2/UJI-')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::KUNCI_PT.':')));
        $gateway = PaymentGateway::sole();
        $this->assertSame([self::KUNCI_PT, 'perusahaan', null], [$gateway->kredensial['server_key'], $gateway->kunci_pemilik, $gateway->id_lokasi]);
        $this->assertNotNull($gateway->diuji_at);
        $this->assertStringNotContainsString(self::KUNCI_PT, DB::table('payment_gateway')->value('kredensial'));
        $this->assertArrayNotHasKey('kredensial', $gateway->toArray());

        $this->get('/payment-gateway')->assertOk()->assertDontSee('perusahaanABC')
            ->assertInertia(fn ($page) => $page->where('rows.0.server_key_tersamar', '••••1234')->where('rows.0.pemilik', 'Perusahaan')->where('rows.0.bisa_kelola', true));
        $log = AuditLog::where('modul', 'Payment Gateway')->sole();
        $this->assertSame('[disembunyikan]', $log->perubahan['kredensial']['ke']);
        $this->assertStringNotContainsString('perusahaanABC', json_encode($log->toArray()));
    }

    public function test_key_is_rejected_when_midtrans_refuses_it_the_mode_is_wrong_the_password_is_wrong_or_midtrans_is_unreachable(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([self::UJI => Http::sequence()->push(['status_code' => '401', 'status_message' => 'Unauthorized'], 401)->pushFailedConnection()]);

        $this->tambah()->assertStatus(422)->assertJsonPath('errors.server_key.0', 'Server Key ditolak Midtrans. Periksa kembali kunci dan mode (Sandbox/Production).');
        $this->tambah()->assertStatus(422)->assertJsonPath('errors.server_key.0', 'Tidak bisa menghubungi Midtrans untuk menguji kunci. Periksa koneksi server lalu coba lagi.');
        $this->tambah(['produksi' => true])->assertStatus(422)->assertJsonPath('errors.server_key.0', 'Server Key Sandbox diawali "SB-", Server Key Production tidak. Sesuaikan kunci dengan pilihan mode.');
        $this->tambah(['password' => 'SandiKeliru99'])->assertStatus(422)->assertJsonValidationErrors(['password' => 'Password salah']);
        $this->tambah(['server_key' => self::KUNCI_PT.' "'])->assertStatus(422)->assertJsonValidationErrors('server_key');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_gateway', 0);
    }

    public function test_only_super_admin_manages_the_company_gateway_and_branches_only_their_own_admin_full_gateway(): void
    {
        $this->kunciDiterima();
        $perusahaan = $this->gateway(null, self::KUNCI_PT);

        // Admin pusat bukan Super Admin: tidak boleh menyentuh gateway perusahaan
        $admin = User::factory()->peran('Admin')->create();
        $this->actingAs($admin)->tambah()->assertForbidden();
        $this->actingAs($admin)->putJson("/payment-gateway/{$perusahaan->id}", $this->isian())->assertForbidden();
        $this->actingAs($admin)->deleteJson("/payment-gateway/{$perusahaan->id}")->assertForbidden();
        $this->actingAs($admin)->get('/payment-gateway')->assertInertia(fn ($page) => $page->where('rows.0.bisa_kelola', false)
            ->where('pemilikOptions', [['value' => $this->full->id, 'label' => 'MSS Cimahi (Admin Full)']]));
        $this->actingAs($admin)->tambah(['id_lokasi' => $this->biasa->id])->assertStatus(422)->assertJsonValidationErrors('id_lokasi');

        // Mitra cabang biasa ikut gateway perusahaan; mitra Admin Full hanya untuk cabangnya sendiri
        $this->actingAs($this->mitra($this->biasa))->tambah()->assertForbidden();
        $mitraFull = $this->mitra($this->full);
        $this->actingAs($mitraFull)->tambah(['id_lokasi' => 'perusahaan', 'server_key' => self::KUNCI_MITRA])->assertOk();
        $this->assertSame("lokasi:{$this->full->id}", PaymentGateway::where('id_lokasi', $this->full->id)->sole()->kunci_pemilik);
        $this->actingAs($mitraFull)->putJson("/payment-gateway/{$perusahaan->id}", $this->isian())->assertForbidden();
        $this->actingAs($mitraFull)->get('/payment-gateway')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.pemilik', 'MSS Cimahi'));

        // Satu gateway per pemilik
        $this->actingAs($admin)->tambah(['id_lokasi' => $this->full->id])->assertStatus(422)->assertJsonValidationErrors(['id_lokasi' => 'Pemilik ini sudah punya payment gateway. Ubah yang sudah ada.']);
        $this->assertSame(self::KUNCI_PT, $perusahaan->fresh()->kredensial['server_key']);
    }

    public function test_edit_keeps_the_stored_key_when_left_blank_and_deactivating_skips_the_midtrans_check(): void
    {
        $this->actingAs(User::factory()->create());
        $gateway = $this->gateway(null, self::KUNCI_PT);
        $this->kunciDiterima();

        $this->putJson("/payment-gateway/{$gateway->id}", $this->isian(['server_key' => '', 'status' => 'tidak_aktif']))->assertOk();

        Http::assertNothingSent();
        $this->assertSame(['tidak_aktif', self::KUNCI_PT], [$gateway->fresh()->status, $gateway->fresh()->kredensial['server_key']]);

        $this->putJson("/payment-gateway/{$gateway->id}", $this->isian(['server_key' => 'SB-Mid-server-kunciBaru5555', 'signature_key' => 'rahasia-tanda-tangan']))->assertOk();
        $this->assertSame(['aktif', 'SB-Mid-server-kunciBaru5555', 'rahasia-tanda-tangan'], [$gateway->fresh()->status, $gateway->fresh()->kredensial['server_key'], $gateway->fresh()->kredensial['signature_key']]);
        $this->postJson("/payment-gateway/{$gateway->id}/uji")->assertOk();
        Http::assertSentCount(2);
    }

    public function test_gateway_with_transactions_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $dipakai = $this->gateway(null, self::KUNCI_PT);
        $kosong = $this->gateway($this->full->id, self::KUNCI_MITRA);
        TransaksiMidtrans::create(['order_id' => 'MSSB-202611-1-aaaaaaaaaaaa', 'payment_gateway_id' => $dipakai->id, 'periode' => '2026-11-01', 'gross_amount' => 200000]);

        $this->deleteJson("/payment-gateway/{$dipakai->id}")->assertStatus(422);
        $this->deleteJson("/payment-gateway/{$kosong->id}")->assertOk();

        $this->assertSame([$dipakai->id], PaymentGateway::pluck('id')->all());
    }

    public function test_each_payment_uses_the_gateway_of_the_customer_branch_and_is_verified_with_that_account_only(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-dariEnv000000']);
        $perusahaan = $this->gateway(null, self::KUNCI_PT);
        $mitra = $this->gateway($this->full->id, self::KUNCI_MITRA);
        $paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);
        $this->pelanggan('MSS001', $this->biasa, $paket);
        $budiFull = $this->pelanggan('MSS002', $this->full, $paket);
        Http::fake([self::SNAP => Http::response(['token' => 't', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t'], 201)]);

        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])->assertOk();
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS002', 'periode' => '2026-11-01'])->assertOk();

        // Gateway di menu lebih diutamakan daripada .env; cabang Admin Full memakai akunnya sendiri
        $kunciDipakai = Http::recorded()->map(fn ($r) => $r[0]->header('Authorization')[0])->all();
        $this->assertSame(['Basic '.base64_encode(self::KUNCI_PT.':'), 'Basic '.base64_encode(self::KUNCI_MITRA.':')], $kunciDipakai);
        $transaksiFull = TransaksiMidtrans::where('pelanggan_id', $budiFull->id)->sole();
        $this->assertSame($mitra->id, $transaksiFull->payment_gateway_id);
        $this->assertSame($perusahaan->id, TransaksiMidtrans::where('pelanggan_id', '!=', $budiFull->id)->sole()->payment_gateway_id);
        $this->post('/cek-tagihan', ['cari' => 'MSS002'])->assertInertia(fn ($page) => $page->where('hasil.0.bayar_online', true));

        // Notifikasi bertanda tangan akun perusahaan tidak bisa melunasi transaksi di akun mitra
        Http::fake(['api.sandbox.midtrans.com/v2/'.$transaksiFull->order_id.'/status' => Http::response($this->statusApi($transaksiFull->order_id))]);
        $this->postJson('/midtrans/notifikasi', $this->notifikasi($transaksiFull->order_id, self::KUNCI_PT))->assertForbidden();
        $this->postJson('/midtrans/notifikasi', $this->notifikasi('MSSB-TIDAK-ADA', self::KUNCI_MITRA))->assertForbidden();
        $this->postJson('/midtrans/notifikasi', $this->notifikasi($transaksiFull->order_id, self::KUNCI_MITRA))->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/status') && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::KUNCI_MITRA.':')));
        $this->assertSame('lunas', $transaksiFull->fresh()->status);
    }

    public function test_inactive_gateway_stops_new_payments_but_still_processes_running_transactions(): void
    {
        $gateway = $this->gateway($this->full->id, self::KUNCI_MITRA);
        $paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);
        $budi = $this->pelanggan('MSS002', $this->full, $paket);
        $berjalan = TransaksiMidtrans::create([
            'order_id' => 'MSSB-202610-2-bbbbbbbbbbbb', 'pelanggan_id' => $budi->id, 'payment_gateway_id' => $gateway->id, 'periode' => '2026-10-01', 'gross_amount' => 200000,
        ]);
        $gateway->update(['status' => 'tidak_aktif']);
        Http::fake(['api.sandbox.midtrans.com/v2/'.$berjalan->order_id.'/status' => Http::response($this->statusApi($berjalan->order_id))]);

        // Cabang Admin Full tanpa gateway aktif tidak jatuh ke gateway perusahaan
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS002', 'periode' => '2026-11-01'])->assertStatus(422);
        $this->post('/cek-tagihan', ['cari' => 'MSS002'])->assertInertia(fn ($page) => $page->where('hasil.0.bayar_online', false));
        $this->postJson('/midtrans/notifikasi', $this->notifikasi($berjalan->order_id, self::KUNCI_MITRA))->assertOk();

        $this->assertSame('lunas', $berjalan->fresh()->status);
    }

    private function kunciDiterima(): void
    {
        Http::fake([self::UJI => Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404)]);
    }

    /** @param  array<string, mixed>  $isi */
    private function isian(array $isi = []): array
    {
        return ['provider' => 'midtrans', 'id_lokasi' => 'perusahaan', 'produksi' => false, 'server_key' => self::KUNCI_PT, 'status' => 'aktif', 'password' => 'password', ...$isi];
    }

    /** @param  array<string, mixed>  $isi */
    private function tambah(array $isi = []): TestResponse
    {
        return $this->postJson('/payment-gateway', $this->isian($isi));
    }

    private function gateway(?int $idLokasi, string $kunci): PaymentGateway
    {
        return PaymentGateway::create(['provider' => 'midtrans', 'id_lokasi' => $idLokasi, 'produksi' => false, 'status' => 'aktif', 'kredensial' => ['server_key' => $kunci]]);
    }

    private function mitra(Lokasi $lokasi): User
    {
        $user = User::factory()->peran('Mitra')->create(['id_lokasi' => $lokasi->id]);
        $user->givePermissionTo(['payment_gateway.view', 'payment_gateway.create', 'payment_gateway.update', 'payment_gateway.delete']);

        return $user;
    }

    private function pelanggan(string $id, Lokasi $lokasi, Paket $paket): Pelanggan
    {
        return Pelanggan::create([
            'id_pelanggan' => $id, 'nama' => "Pelanggan {$id}", 'username_pppoe' => strtolower($id), 'id_lokasi' => $lokasi->id, 'id_paket' => $paket->id,
            'jns_tagihan' => 'prabayar', 'tgl_registrasi' => '2026-10-01', 'status' => 'aktif', 'tgl_jatuh_tempo' => 10,
        ]);
    }

    /** @return array<string, string> */
    private function statusApi(string $orderId): array
    {
        return [
            'order_id' => $orderId, 'transaction_status' => 'settlement', 'gross_amount' => '200000.00', 'status_code' => '200',
            'payment_type' => 'qris', 'transaction_id' => 'trx-1', 'settlement_time' => '2026-11-15 09:30:00', 'fraud_status' => 'accept',
        ];
    }

    /** @return array<string, string> */
    private function notifikasi(string $orderId, string $kunci): array
    {
        return [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => '200000.00', 'transaction_status' => 'settlement',
            'signature_key' => hash('sha512', $orderId.'200'.'200000.00'.$kunci),
        ];
    }
}
