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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PembayaranTripayTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'DEV-apiKeyRekaan123';

    private const PRIVATE_KEY = 'privateKeyRekaan-456';

    private const MERCHANT = 'T00001';

    private const KANAL = 'https://tripay.co.id/api-sandbox/merchant/payment-channel';

    private const BUAT = 'https://tripay.co.id/api-sandbox/transaction/create';

    private Lokasi $lokasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
    }

    public function test_super_admin_adds_tripay_gateway_after_the_key_is_accepted(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([self::KANAL => Http::response(['success' => true, 'data' => []])]);

        $this->postJson('/payment-gateway', ['provider' => 'tripay', 'nama' => 'Tripay Sandbox', 'id_lokasi' => 'perusahaan', 'produksi' => false,
            'merchant_code' => self::MERCHANT, 'api_key' => self::API_KEY, 'private_key' => self::PRIVATE_KEY, 'status' => 'aktif', 'password' => 'password'])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::KANAL && $r->hasHeader('Authorization', 'Bearer '.self::API_KEY));
        $this->assertSame(['tripay', self::MERCHANT], [PaymentGateway::sole()->provider, PaymentGateway::sole()->kredensial['merchant_code']]);
    }

    public function test_customer_chooses_a_method_then_is_sent_to_tripay_checkout_with_a_valid_signature(): void
    {
        $this->gateway();
        $this->pelanggan();
        Http::fake([self::KANAL => Http::response($this->kanal()), self::BUAT => Http::response($this->dibuat('T0001REF1'))]);

        // Tanpa metode: billing menjawab daftar metode aktif yang sesuai nominal (VA mahal di atas batas disaring)
        $this->bayar()->assertOk()->assertJsonPath('pilih_kanal', [['kode' => 'QRIS', 'nama' => 'QRIS', 'grup' => 'E-Wallet', 'ikon' => 'https://tripay.co.id/qris.png']]);
        $this->bayar(['kanal' => 'BRIVA'])->assertStatus(422)->assertJsonValidationErrors(['bayar' => 'Metode pembayaran tidak tersedia. Silakan pilih metode lain.']);
        $this->assertSame(0, TransaksiMidtrans::count());

        $this->bayar(['kanal' => 'QRIS'])->assertOk()->assertJsonPath('redirect_url', 'https://tripay.co.id/checkout/T0001REF1');

        $transaksi = TransaksiMidtrans::sole();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BUAT && $r['method'] === 'QRIS' && $r['merchant_ref'] === $transaksi->order_id && (int) $r['amount'] === 200000
            && $r['signature'] === hash_hmac('sha256', self::MERCHANT.$transaksi->order_id.'200000', self::PRIVATE_KEY)
            && $r['callback_url'] === route('pembayaran.notifikasi', 'tripay'));
        $this->assertSame(['T0001REF1', 'QRIS'], [$transaksi->transaction_id, $transaksi->payment_type]);
    }

    public function test_only_a_correctly_signed_tripay_callback_marks_the_bill_paid(): void
    {
        $this->gateway();
        $this->pelanggan();
        Http::fake([self::KANAL => Http::response($this->kanal()), self::BUAT => Http::response($this->dibuat('T0001REF1'))]);
        $this->bayar(['kanal' => 'QRIS'])->assertOk();
        $transaksi = TransaksiMidtrans::sole();
        Http::fake(['tripay.co.id/api-sandbox/transaction/detail*' => Http::response(['success' => true, 'data' => $this->detail($transaksi->order_id, 'PAID')])]);
        $isi = json_encode(['reference' => 'T0001REF1', 'merchant_ref' => $transaksi->order_id, 'status' => 'PAID', 'total_amount' => 200000]);

        $this->notifikasi($isi, hash_hmac('sha256', $isi, 'kunci-salah'))->assertForbidden();
        $this->notifikasi($isi, hash_hmac('sha256', $isi, self::PRIVATE_KEY), 'payment_other')->assertForbidden();
        $this->assertSame('pending', $transaksi->fresh()->status);

        $this->notifikasi($isi, hash_hmac('sha256', $isi, self::PRIVATE_KEY))->assertOk()->assertJsonPath('success', true);

        $this->assertSame('lunas', $transaksi->fresh()->status);
        $pembayaran = Pembayaran::sole();
        $this->assertSame(['tripay', 'Tripay · QRIS'], [$pembayaran->metode, $pembayaran->load('transaksiMidtrans')->keteranganMetode()]);
    }

    public function test_choosing_another_method_makes_a_new_tripay_transaction(): void
    {
        $this->gateway();
        $this->pelanggan();
        Http::fake([self::KANAL => Http::response($this->kanal(1000000)), self::BUAT => Http::sequence()->push($this->dibuat('REF-QRIS'))->push($this->dibuat('REF-VA', 'BRIVA'))]);
        $this->bayar(['kanal' => 'QRIS'])->assertOk();

        // Metode sama dipakai ulang; metode lain membuat transaksi baru (kode lama tidak bisa dibatalkan di Tripay)
        $this->bayar(['kanal' => 'QRIS'])->assertOk()->assertJsonPath('redirect_url', 'https://tripay.co.id/checkout/REF-QRIS');
        $this->bayar(['kanal' => 'BRIVA'])->assertOk()->assertJsonPath('redirect_url', 'https://tripay.co.id/checkout/REF-VA');

        $this->assertSame(['expire', 'pending'], TransaksiMidtrans::orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('tidak bisa dibatalkan', TransaksiMidtrans::orderBy('id')->first()->catatan);
    }

    /** @param  array<string, mixed>  $isi */
    private function bayar(array $isi = []): TestResponse
    {
        return $this->postJson('/cek-tagihan/bayar', ['id_pelanggan' => 'MSS001', 'periode' => '2026-11-01', ...$isi]);
    }

    private function notifikasi(string $isi, string $tandaTangan, string $event = 'payment_status'): TestResponse
    {
        return $this->call('POST', '/pembayaran/notifikasi/tripay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CALLBACK_SIGNATURE' => $tandaTangan, 'HTTP_X_CALLBACK_EVENT' => $event,
        ], $isi);
    }

    private function gateway(): PaymentGateway
    {
        return PaymentGateway::create(['provider' => 'tripay', 'nama' => 'Tripay', 'id_lokasi' => null, 'produksi' => false, 'status' => 'aktif', 'utama' => true,
            'kredensial' => ['merchant_code' => self::MERCHANT, 'api_key' => self::API_KEY, 'private_key' => self::PRIVATE_KEY]]);
    }

    private function pelanggan(): Pelanggan
    {
        $paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);

        return Pelanggan::create([
            'id_pelanggan' => 'MSS001', 'nama' => 'Budi Santoso', 'tlp' => '081234567890', 'username_pppoe' => 'mss001', 'id_lokasi' => $this->lokasi->id,
            'id_paket' => $paket->id, 'jns_tagihan' => 'prabayar', 'tgl_registrasi' => '2026-11-01', 'status' => 'aktif', 'tgl_jatuh_tempo' => 10,
        ]);
    }

    /** @return array<string, mixed> QRIS aktif; BRIVA aktif tapi batas maksimalnya di bawah tagihan (kecuali $maksVa dinaikkan); OVO nonaktif */
    private function kanal(int $maksVa = 100000): array
    {
        return ['success' => true, 'data' => [
            ['group' => 'E-Wallet', 'code' => 'QRIS', 'name' => 'QRIS', 'icon_url' => 'https://tripay.co.id/qris.png', 'active' => true, 'minimum_amount' => 1000, 'maximum_amount' => 5000000],
            ['group' => 'Virtual Account', 'code' => 'BRIVA', 'name' => 'BRI Virtual Account', 'icon_url' => null, 'active' => true, 'minimum_amount' => 10000, 'maximum_amount' => $maksVa],
            ['group' => 'E-Wallet', 'code' => 'OVO', 'name' => 'OVO', 'icon_url' => null, 'active' => false, 'minimum_amount' => 1000, 'maximum_amount' => 5000000],
        ]];
    }

    /** @return array<string, mixed> */
    private function dibuat(string $referensi, string $metode = 'QRIS'): array
    {
        return ['success' => true, 'data' => ['reference' => $referensi, 'payment_method' => $metode, 'status' => 'UNPAID', 'checkout_url' => "https://tripay.co.id/checkout/{$referensi}"]];
    }

    /** @return array<string, mixed> */
    private function detail(string $orderId, string $status): array
    {
        return ['reference' => 'T0001REF1', 'merchant_ref' => $orderId, 'payment_method' => 'QRIS', 'status' => $status, 'amount' => 200000, 'fee_customer' => 0, 'paid_at' => 1763177400];
    }
}
