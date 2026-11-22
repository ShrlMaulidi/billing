<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\PaymentGateway;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\TransaksiMidtrans;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PembayaranXenditTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'xnd_development_kunciRekaan123456';

    private const TOKEN = 'token-callback-rekaan-abc';

    private const INVOICE = 'https://api.xendit.co/v2/invoices';

    private Lokasi $lokasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
    }

    public function test_super_admin_adds_xendit_gateway_after_the_key_is_accepted(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['api.xendit.co/balance' => Http::response(['balance' => 0])]);

        $this->postJson('/payment-gateway', $this->isian())->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.xendit.co/balance' && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::KUNCI.':')));
        $gateway = PaymentGateway::sole();
        $this->assertSame(['xendit', self::KUNCI, self::TOKEN, true], [$gateway->provider, $gateway->kredensial['secret_key'], $gateway->kredensial['callback_token'], $gateway->utama]);
    }

    public function test_xendit_key_of_the_wrong_mode_or_refused_key_is_not_saved(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['api.xendit.co/balance' => Http::response(['error_code' => 'INVALID_API_KEY'], 401)]);

        $this->postJson('/payment-gateway', $this->isian(['produksi' => true]))->assertStatus(422)
            ->assertJsonValidationErrors(['secret_key' => 'Secret Key mode Test diawali "xnd_development_", mode Live diawali "xnd_production_". Sesuaikan kunci dengan pilihan mode.']);
        $this->postJson('/payment-gateway', $this->isian())->assertStatus(422)
            ->assertJsonValidationErrors(['secret_key' => 'Secret Key ditolak Xendit. Periksa kembali kunci dan mode (Sandbox/Production).']);
        $this->postJson('/payment-gateway', $this->isian(['callback_token' => '']))->assertStatus(422)->assertJsonValidationErrors('callback_token');
        $this->assertDatabaseCount('payment_gateway', 0);
    }

    public function test_customer_pays_through_xendit_invoice_and_only_a_valid_callback_marks_it_paid(): void
    {
        $this->gateway();
        $this->pelanggan();
        Http::fake([self::INVOICE => Http::response(['id' => 'inv-rekaan-1', 'invoice_url' => 'https://checkout-staging.xendit.co/web/inv-rekaan-1', 'status' => 'PENDING'])]);

        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])
            ->assertOk()->assertJsonPath('redirect_url', 'https://checkout-staging.xendit.co/web/inv-rekaan-1');

        $transaksi = TransaksiMidtrans::sole();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::INVOICE && $r['external_id'] === $transaksi->order_id && $r['amount'] === 200000
            && str_contains($r['success_redirect_url'], 'order_id='.$transaksi->order_id));
        $this->assertSame('inv-rekaan-1', $transaksi->transaction_id);

        // Token salah atau order milik provider lain ditolak, tanpa mengecek status
        Http::fake(['api.xendit.co/v2/invoices/inv-rekaan-1' => Http::response($this->invoice($transaksi->order_id, 'PAID'))]);
        $this->postJson('/pembayaran/notifikasi/xendit', ['external_id' => $transaksi->order_id, 'status' => 'PAID'], ['x-callback-token' => 'tebakan'])->assertForbidden();
        $this->postJson('/pembayaran/notifikasi/tripay', ['merchant_ref' => $transaksi->order_id], ['X-Callback-Event' => 'payment_status', 'X-Callback-Signature' => 'x'])->assertForbidden();
        $this->assertSame('pending', $transaksi->fresh()->status);

        // Token benar: status diambil dari API Xendit, lalu tercatat lunas
        $this->postJson('/pembayaran/notifikasi/xendit', ['external_id' => $transaksi->order_id, 'status' => 'PAID'], ['x-callback-token' => self::TOKEN])->assertOk();

        $transaksi->refresh();
        $this->assertSame(['lunas', 'QRIS'], [$transaksi->status, $transaksi->payment_type]);
        $pembayaran = Pembayaran::sole();
        $this->assertSame(['xendit', 200000], [$pembayaran->metode, $pembayaran->jml_bayar]);
        $this->assertSame('Xendit · QRIS', $pembayaran->load('transaksiMidtrans')->keteranganMetode());
    }

    public function test_paid_amount_different_from_the_bill_is_not_recorded_automatically(): void
    {
        $this->gateway();
        $this->pelanggan();
        Http::fake([self::INVOICE => Http::response(['id' => 'inv-rekaan-2', 'invoice_url' => 'https://checkout-staging.xendit.co/web/inv-rekaan-2', 'status' => 'PENDING'])]);
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])->assertOk();
        $transaksi = TransaksiMidtrans::sole();
        Http::fake(['api.xendit.co/v2/invoices/inv-rekaan-2' => Http::response([...$this->invoice($transaksi->order_id, 'PAID'), 'id' => 'inv-rekaan-2', 'paid_amount' => 1000])]);

        $this->postJson('/pembayaran/notifikasi/xendit', ['external_id' => $transaksi->order_id], ['x-callback-token' => self::TOKEN])->assertOk();

        $this->assertSame('selisih', $transaksi->fresh()->status);
        $this->assertSame(0, Pembayaran::count());
    }

    public function test_changing_method_expires_the_old_xendit_invoice_first(): void
    {
        $this->gateway();
        $this->pelanggan();
        Http::fake([self::INVOICE => Http::sequence()
            ->push(['id' => 'inv-lama', 'invoice_url' => 'https://checkout-staging.xendit.co/web/inv-lama', 'status' => 'PENDING'])
            ->push(['id' => 'inv-baru', 'invoice_url' => 'https://checkout-staging.xendit.co/web/inv-baru', 'status' => 'PENDING']),
            'api.xendit.co/invoices/inv-lama/expire!' => Http::response(['id' => 'inv-lama', 'status' => 'EXPIRED']),
        ]);
        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01'])->assertOk();

        $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01', 'ganti' => true])
            ->assertOk()->assertJsonPath('redirect_url', 'https://checkout-staging.xendit.co/web/inv-baru');

        $this->assertSame(['expire', 'pending'], TransaksiMidtrans::orderBy('id')->pluck('status')->all());
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/invoices/inv-lama/expire!'));
    }

    /** @param  array<string, mixed>  $isi */
    private function isian(array $isi = []): array
    {
        return ['provider' => 'xendit', 'nama' => 'Xendit Test', 'id_lokasi' => 'perusahaan', 'produksi' => false, 'secret_key' => self::KUNCI,
            'callback_token' => self::TOKEN, 'status' => 'aktif', 'password' => 'password', ...$isi];
    }

    private function gateway(): PaymentGateway
    {
        return PaymentGateway::create(['provider' => 'xendit', 'nama' => 'Xendit', 'id_lokasi' => null, 'produksi' => false, 'status' => 'aktif', 'utama' => true,
            'kredensial' => ['secret_key' => self::KUNCI, 'callback_token' => self::TOKEN]]);
    }

    private function pelanggan(): Pelanggan
    {
        $paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);

        return Pelanggan::create([
            'id_pelanggan' => 'MSS001', 'nama' => 'Budi Santoso', 'username_pppoe' => 'mss001', 'id_lokasi' => $this->lokasi->id, 'id_paket' => $paket->id,
            'jns_tagihan' => 'prabayar', 'tgl_registrasi' => '2026-11-01', 'status' => 'aktif', 'tgl_jatuh_tempo' => 10,
        ]);
    }

    /** @return array<string, mixed> */
    private function invoice(string $orderId, string $status): array
    {
        return ['id' => 'inv-rekaan-1', 'external_id' => $orderId, 'status' => $status, 'amount' => 200000, 'paid_amount' => 200000,
            'payment_method' => 'QR_CODE', 'payment_channel' => 'QRIS', 'paid_at' => '2026-11-15T03:30:00.000Z'];
    }
}
