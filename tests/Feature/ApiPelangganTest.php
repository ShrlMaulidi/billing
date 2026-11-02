<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Pelanggan $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '20M']);
        $this->budi = Pelanggan::create([
            'id_pelanggan' => 'P00001', 'nama' => 'Budi Santoso', 'tlp' => '0812-3456-7890', 'nik' => '3205010101010001', 'alamat' => 'Jl. Rahasia 1',
            'username_pppoe' => 'budi', 'id_lokasi' => $lokasi->id, 'id_paket' => $paket->id, 'tgl_registrasi' => '2026-09-01',
        ]);
    }

    public function test_customer_logs_in_with_id_and_phone_in_any_format_and_sees_only_minimum_profile(): void
    {
        $res = $this->postJson('/api/v1/pelanggan/login', ['id_pelanggan' => 'P00001', 'tlp' => '+62 812 3456 7890', 'device_name' => 'HP Budi'])
            ->assertOk()
            ->assertJsonPath('expires_at', '2027-01-13T10:00:00+00:00')
            ->assertJsonPath('pelanggan.nama', 'Budi Santoso')
            ->assertJsonPath('pelanggan.paket.harga', 200000)
            ->assertJsonMissingPath('pelanggan.alamat')
            ->assertJsonMissingPath('pelanggan.nik');

        $this->assertSame(['pelanggan'], PersonalAccessToken::sole()->abilities);
        $this->withToken($res->json('token'))->getJson('/api/v1/pelanggan-saya')->assertOk()->assertJsonPath('data.id_pelanggan', 'P00001');
    }

    public function test_wrong_phone_and_stopped_customer_cannot_log_in(): void
    {
        $this->postJson('/api/v1/pelanggan/login', ['id_pelanggan' => 'P00001', 'tlp' => '081299999999', 'device_name' => 'HP'])
            ->assertStatus(422)->assertJsonValidationErrors(['id_pelanggan' => 'ID pelanggan atau nomor HP tidak cocok.']);

        $this->budi->update(['status' => 'berhenti']);
        $this->postJson('/api/v1/pelanggan/login', ['id_pelanggan' => 'P00001', 'tlp' => '081234567890', 'device_name' => 'HP'])
            ->assertStatus(422)->assertJsonValidationErrors('id_pelanggan');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_customer_sees_own_unpaid_bills_and_payment_history(): void
    {
        Pembayaran::create(['pelanggan_id' => $this->budi->id, 'id_lokasi' => $this->budi->id_lokasi, 'no_invoice' => 'INV202609-00001', 'periode' => '2026-09-01', 'nama_pelanggan' => 'Budi', 'nama_paket' => 'Home', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => '2026-09-05 10:00:00']);
        $token = $this->budi->createToken('HP', ['pelanggan'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/pelanggan-saya/tagihan')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.periode', '2026-10-01')->assertJsonPath('data.0.nominal', 200000);
        $this->withToken($token)->getJson('/api/v1/pelanggan-saya/pembayaran')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.no_invoice', 'INV202609-00001');
    }

    public function test_staff_token_cannot_open_customer_portal(): void
    {
        $token = User::factory()->create()->createToken('HP', ['staf'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/pelanggan-saya')->assertForbidden();
    }

    public function test_token_is_revoked_when_customer_stops(): void
    {
        $token = $this->budi->createToken('HP', ['pelanggan'])->plainTextToken;
        $this->budi->update(['status' => 'berhenti']);

        $this->withToken($token)->getJson('/api/v1/pelanggan-saya')->assertUnauthorized()->assertJsonPath('message', 'Layanan Anda sudah tidak aktif');
        $this->assertSame(0, $this->budi->tokens()->count());
    }
}
