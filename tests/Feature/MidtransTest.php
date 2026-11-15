<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\TransaksiMidtrans;
use App\Support\RekapCabang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'SB-Mid-server-UJI';

    private const SNAP = 'https://app.sandbox.midtrans.com/snap/v1/transactions';

    private Lokasi $garut;

    private Pelanggan $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        config(['services.midtrans.server_key' => self::KEY, 'services.midtrans.production' => false, 'services.midtrans.prefix' => 'MSSB']);
        $this->garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'admin_full' => false]);
        $paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);
        $this->budi = Pelanggan::create([
            'id_pelanggan' => 'MSS001', 'nama' => 'Budi Santoso', 'username_pppoe' => 'budi', 'id_lokasi' => $this->garut->id, 'id_paket' => $paket->id,
            'jns_tagihan' => 'prabayar', 'tgl_registrasi' => '2026-10-01', 'status' => 'aktif', 'tgl_jatuh_tempo' => 10,
        ]);
    }

    public function test_pay_button_creates_snap_transaction_with_server_amount_and_reuses_it(): void
    {
        Http::fake([self::SNAP => Http::response(['token' => 'tok', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok'], 201)]);

        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01', 'gross_amount' => 1])
            ->assertOk()->assertJsonPath('redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::SNAP
            && $r->header('X-Override-Notification')[0] === route('midtrans.notifikasi')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::KEY.':'))
            && $r['transaction_details']['gross_amount'] === 200000
            && str_starts_with($r['transaction_details']['order_id'], 'MSSB-202611-'.$this->budi->id.'-')
            && $r['callbacks']['finish'] === route('midtrans.selesai'));
        $transaksi = TransaksiMidtrans::sole();
        $this->assertSame(['pending', 200000, '2026-11-01'], [$transaksi->status, $transaksi->gross_amount, $transaksi->periode]);

        // Klik lagi: link yang masih berlaku dipakai ulang, tidak membuat transaksi baru
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_pay_button_rejects_paid_period_admin_full_branch_and_unconfigured_gateway(): void
    {
        Http::fake();
        $this->bayarManual('2026-10-01');

        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('bayar');
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'TIDAK-ADA', 'periode' => '2026-11-01'])->assertStatus(422);
        $this->garut->update(['admin_full' => true]);
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])->assertStatus(422);
        config(['services.midtrans.server_key' => null]);
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('transaksi_midtrans', 0);
    }

    public function test_notification_with_invalid_signature_is_rejected(): void
    {
        Http::fake();
        $transaksi = $this->transaksi();

        $this->postJson('/midtrans/notifikasi', [...$this->notifikasi($transaksi), 'signature_key' => str_repeat('a', 128)])->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame('pending', $transaksi->fresh()->status);
        $this->assertDatabaseCount('pembayaran', 0);
    }

    public function test_notification_signed_with_signature_secret_key_is_accepted(): void
    {
        config(['services.midtrans.signature_key' => 'rahasia-tanda-tangan']);
        $transaksi = $this->transaksi();
        $this->fakeStatus($transaksi, 'settlement');
        $data = $transaksi->order_id.'200200000.00';

        $this->postJson('/midtrans/notifikasi', [...$this->notifikasi($transaksi), 'signature_key' => hash('sha512', $data.'kunci-lain')])->assertForbidden();
        $this->postJson('/midtrans/notifikasi', [...$this->notifikasi($transaksi), 'signature_key' => hash('sha512', $data.'rahasia-tanda-tangan')])->assertOk();

        $this->assertSame('lunas', $transaksi->fresh()->status);
    }

    public function test_settled_payment_is_recorded_once_after_checking_the_status_api(): void
    {
        $transaksi = $this->transaksi();
        $this->fakeStatus($transaksi, 'settlement');

        $this->postJson('/midtrans/notifikasi', $this->notifikasi($transaksi))->assertOk();
        $this->postJson('/midtrans/notifikasi', $this->notifikasi($transaksi))->assertOk();

        $pembayaran = Pembayaran::sole();
        $this->assertSame(['midtrans', 200000, 200000, '2026-11-01', null, '2026-11-15 09:30:00', sprintf('INV202611-%05d', $pembayaran->id)], [
            $pembayaran->metode, $pembayaran->harga, $pembayaran->jml_bayar, $pembayaran->periode, $pembayaran->user_id, $pembayaran->paid_at->format('Y-m-d H:i:s'), $pembayaran->no_invoice,
        ]);
        $this->assertSame(['lunas', $pembayaran->id, 'bank_transfer'], [$transaksi->fresh()->status, $transaksi->fresh()->pembayaran_id, $transaksi->fresh()->payment_type]);
        // Satu catatan audit dengan pelaku Midtrans, sudah lengkap dengan no. invoice
        $log = AuditLog::where('subjek_type', 'Pembayaran')->sole();
        $this->assertSame(['Midtrans', 'create'], [$log->user_nama, $log->aksi]);
        $this->assertStringContainsString($pembayaran->no_invoice, $log->deskripsi);
        // Dihitung sebagai payment gateway perusahaan di rekap cabang
        $this->assertSame(200000, RekapCabang::hitung(Lokasi::whereKey($this->garut->id)->get(), '2026-11-01')->first()['total_pg']);
    }

    public function test_status_is_taken_from_the_midtrans_api_not_from_the_notification(): void
    {
        $transaksi = $this->transaksi();
        $this->fakeStatus($transaksi, 'pending');

        // Isi notifikasi mengaku lunas, tetapi API Midtrans menyatakan masih pending
        $this->postJson('/midtrans/notifikasi', $this->notifikasi($transaksi, 'settlement'))->assertOk();

        $this->assertSame('pending', $transaksi->fresh()->status);
        $this->assertDatabaseCount('pembayaran', 0);
    }

    public function test_amount_mismatch_and_already_paid_period_are_flagged_instead_of_recorded(): void
    {
        $selisih = $this->transaksi();
        $ganda = $this->transaksi(['order_id' => 'MSSB-202610-1-bbbbbb', 'periode' => '2026-10-01']);
        $this->bayarManual('2026-10-01');
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$selisih->order_id.'/status' => Http::response($this->statusApi($selisih, 'settlement', '150000.00')),
            'api.sandbox.midtrans.com/v2/'.$ganda->order_id.'/status' => Http::response($this->statusApi($ganda, 'settlement')),
        ]);

        $this->postJson('/midtrans/notifikasi', $this->notifikasi($selisih))->assertOk();
        $this->postJson('/midtrans/notifikasi', $this->notifikasi($ganda))->assertOk();

        $this->assertSame('selisih', $selisih->fresh()->status);
        $this->assertSame('lunas_ganda', $ganda->fresh()->status);
        $this->assertSame(1, Pembayaran::count());
        $this->assertSame(['cash'], Pembayaran::pluck('metode')->all());
    }

    public function test_finish_page_syncs_pending_transaction_and_hides_full_name(): void
    {
        $transaksi = $this->transaksi();
        $this->fakeStatus($transaksi, 'settlement');

        $this->get('/cek-tagihan/selesai?order_id='.$transaksi->order_id)->assertOk()->assertInertia(fn ($page) => $page->component('CekTagihan/Selesai')
            ->where('transaksi.status', 'lunas')->where('transaksi.nominal', 200000)->where('transaksi.nama', 'Bu** Sa*****')
            ->where('transaksi.link_kwitansi', fn (string $link) => str_starts_with($link, '/cek-tagihan/kwitansi/'.Pembayaran::sole()->id.'?')));
        $this->assertSame(1, Pembayaran::count());
        $this->get('/cek-tagihan/selesai?order_id=tidak-ada')->assertInertia(fn ($page) => $page->where('transaksi', null));
    }

    public function test_bill_lookup_offers_online_payment_only_for_non_admin_full_branches_when_configured(): void
    {
        $this->post('/cek-tagihan', ['cari' => 'MSS001'])->assertInertia(fn ($page) => $page->where('hasil.0.bayar_online', true)->missing('hasil.0.cabang.admin_full'));
        $this->garut->update(['admin_full' => true]);
        $this->post('/cek-tagihan', ['cari' => 'MSS001'])->assertInertia(fn ($page) => $page->where('hasil.0.bayar_online', false));
        $this->garut->update(['admin_full' => false]);
        config(['services.midtrans.server_key' => null]);
        $this->post('/cek-tagihan', ['cari' => 'MSS001'])->assertInertia(fn ($page) => $page->where('hasil.0.bayar_online', false));
    }

    public function test_production_mode_uses_production_endpoints_and_rejects_a_sandbox_key(): void
    {
        config(['services.midtrans.production' => true, 'services.midtrans.server_key' => 'Mid-server-PRODUKSI']);
        Http::fake(['app.midtrans.com/snap/v1/transactions' => Http::response(['token' => 't', 'redirect_url' => 'https://app.midtrans.com/snap/v4/redirection/t'], 201)]);

        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])
            ->assertOk()->assertJsonPath('redirect_url', 'https://app.midtrans.com/snap/v4/redirection/t');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://app.midtrans.com/snap/v1/transactions');

        // Kunci Sandbox terpasang di mode Production: tombol bayar disembunyikan, tidak ada transaksi yang pasti gagal
        config(['services.midtrans.server_key' => self::KEY]);
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])->assertNotFound();
        $this->post('/cek-tagihan', ['cari' => 'MSS001'])->assertInertia(fn ($page) => $page->where('hasil.0.bayar_online', false));
        Http::assertSentCount(1);
    }

    private function transaksi(array $isi = []): TransaksiMidtrans
    {
        return TransaksiMidtrans::create([
            'order_id' => 'MSSB-202611-1-aaaaaa', 'pelanggan_id' => $this->budi->id, 'periode' => '2026-11-01', 'gross_amount' => 200000,
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/x', 'kedaluwarsa_at' => now()->addDay(), ...$isi,
        ]);
    }

    private function statusApi(TransaksiMidtrans $t, string $status, string $nominal = '200000.00'): array
    {
        return [
            'order_id' => $t->order_id, 'transaction_status' => $status, 'gross_amount' => $nominal, 'status_code' => $status === 'settlement' ? '200' : '201',
            'payment_type' => 'bank_transfer', 'transaction_id' => 'trx-1', 'settlement_time' => '2026-11-15 09:30:00', 'fraud_status' => 'accept',
        ];
    }

    private function fakeStatus(TransaksiMidtrans $t, string $status): void
    {
        Http::fake(['api.sandbox.midtrans.com/v2/'.$t->order_id.'/status' => Http::response($this->statusApi($t, $status))]);
    }

    private function notifikasi(TransaksiMidtrans $t, string $status = 'settlement'): array
    {
        $kode = $status === 'settlement' ? '200' : '201';

        return [
            'order_id' => $t->order_id, 'status_code' => $kode, 'gross_amount' => '200000.00', 'transaction_status' => $status,
            'signature_key' => hash('sha512', $t->order_id.$kode.'200000.00'.self::KEY),
        ];
    }

    private function bayarManual(string $periode): void
    {
        Pembayaran::create([
            'pelanggan_id' => $this->budi->id, 'id_lokasi' => $this->garut->id, 'periode' => $periode, 'nama_pelanggan' => 'Budi', 'nama_paket' => 'Home 20',
            'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => now(),
        ]);
    }
}
