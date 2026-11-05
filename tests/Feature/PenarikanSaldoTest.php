<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Pembayaran;
use App\Models\PenarikanSaldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PenarikanSaldoTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $garut;

    private Lokasi $cimahi;

    private User $mitra;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-15 10:00:00');
        $this->garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'nama_mitra' => 'Mitra Garut']);
        $this->cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $this->mitra = User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]);
    }

    public function test_mitra_can_request_up_to_available_balance_minus_pending_requests(): void
    {
        // Tanpa tarif: pembayaran lewat payment gateway perusahaan Rp1.000.000 diterima MSS, seluruhnya jadi saldo mitra
        $this->bayarLewatGatewayPerusahaan($this->garut, 1000000);
        $this->actingAs($this->mitra);

        $this->postJson('/transaksi/penarikan-saldo', $this->pengajuan(['nominal' => 600000]))->assertOk();
        $this->assertDatabaseHas('penarikan_saldo', [
            'id_lokasi' => $this->garut->id, 'user_id' => $this->mitra->id, 'nominal' => 600000, 'status' => 'pending', 'bank_penerima' => 'BCA',
        ]);

        // Rp600.000 masih pending, jadi yang tersedia tinggal Rp400.000
        $this->postJson('/transaksi/penarikan-saldo', $this->pengajuan(['nominal' => 400001]))->assertJsonValidationErrors(['nominal' => 'Rp400.000']);
        $this->get('/transaksi/penarikan-saldo')->assertOk()->assertInertia(fn ($page) => $page
            ->has('rows', 1)->where('rows.0.cabang', 'MSS Garut')->missing('saldo')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where("saldo.{$this->garut->id}.2026-10-01", 400000)->missing("saldo.{$this->cimahi->id}")));
    }

    public function test_mitra_cannot_request_when_balance_is_zero_or_negative_or_for_another_branch(): void
    {
        $this->actingAs($this->mitra);
        $this->postJson('/transaksi/penarikan-saldo', $this->pengajuan(['nominal' => 1000]))->assertJsonValidationErrors(['nominal' => 'tidak bisa melakukan penarikan']);

        // Cash diterima mitra tanpa tarif: pendapatan = cash, saldo 0
        Pembayaran::create($this->pembayaran($this->garut, 500000) + ['metode' => 'cash']);
        $this->postJson('/transaksi/penarikan-saldo', $this->pengajuan(['nominal' => 1000]))->assertJsonValidationErrors(['nominal' => 'Rp0']);

        $this->bayarLewatGatewayPerusahaan($this->cimahi, 1000000);
        $this->postJson('/transaksi/penarikan-saldo', $this->pengajuan(['id_lokasi' => $this->cimahi->id, 'nominal' => 1000]))->assertJsonValidationErrors('id_lokasi');
        $this->postJson('/transaksi/penarikan-saldo', $this->pengajuan(['periode_sumber' => '2026-11-01']))->assertJsonValidationErrors('periode_sumber');
        $this->assertDatabaseCount('penarikan_saldo', 0);
    }

    public function test_super_admin_approval_requires_transfer_photo_and_reduces_mitra_balance_and_rejection_does_not(): void
    {
        $this->bayarLewatGatewayPerusahaan($this->garut, 1000000);
        $disetujui = $this->buatPengajuan(300000);
        $ditolak = $this->buatPengajuan(200000);
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->postJson("/transaksi/penarikan-saldo/{$disetujui->id}/setujui")->assertJsonValidationErrors('bukti_transfer');
        $this->postJson("/transaksi/penarikan-saldo/{$disetujui->id}/setujui", ['bukti_transfer' => UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')])
            ->assertJsonValidationErrors(['bukti_transfer' => 'foto']);
        $this->assertSame('pending', $disetujui->fresh()->status);

        $this->postJson("/transaksi/penarikan-saldo/{$disetujui->id}/setujui", ['bukti_transfer' => $this->foto(), 'catatan' => 'REF123'])->assertOk();
        $this->postJson("/transaksi/penarikan-saldo/{$ditolak->id}/tolak", ['catatan' => 'Rekening tidak valid'])->assertOk();
        $this->postJson("/transaksi/penarikan-saldo/{$disetujui->id}/setujui", ['bukti_transfer' => $this->foto()])->assertStatus(422);

        $disetujui->refresh();
        $this->assertSame(['disetujui', $admin->id, 'REF123'], [$disetujui->status, $disetujui->diproses_oleh, $disetujui->catatan]);
        Storage::disk('local')->assertExists($disetujui->bukti_transfer);
        $this->assertStringStartsWith('bukti-penarikan/', $disetujui->bukti_transfer);
        $this->assertDatabaseHas('penarikan_saldo', ['id' => $ditolak->id, 'status' => 'ditolak', 'catatan' => 'Rekening tidak valid', 'bukti_transfer' => null]);
        $this->assertCount(1, Storage::disk('local')->allFiles('bukti-penarikan'));

        $this->actingAs($this->mitra)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('saldoMitra.total_wd', 300000)->where('saldoMitra.saldo', 700000));
    }

    public function test_transfer_photo_is_only_visible_to_users_of_that_branch(): void
    {
        $this->bayarLewatGatewayPerusahaan($this->garut, 1000000);
        $pengajuan = $this->buatPengajuan(100000);
        $this->actingAs(User::factory()->create())->postJson("/transaksi/penarikan-saldo/{$pengajuan->id}/setujui", ['bukti_transfer' => $this->foto()])->assertOk();

        $this->get("/transaksi/penarikan-saldo/{$pengajuan->id}/bukti")->assertOk();
        $this->actingAs($this->mitra)->get("/transaksi/penarikan-saldo/{$pengajuan->id}/bukti")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $mitraCimahi = User::factory()->peran('Mitra')->create(['id_lokasi' => $this->cimahi->id]);
        $this->actingAs($mitraCimahi)->get("/transaksi/penarikan-saldo/{$pengajuan->id}/bukti")->assertNotFound();
        auth()->logout();
        $this->get("/transaksi/penarikan-saldo/{$pengajuan->id}/bukti")->assertRedirect('/login');
    }

    public function test_approval_is_rejected_when_balance_is_no_longer_enough(): void
    {
        $this->bayarLewatGatewayPerusahaan($this->garut, 500000);
        $pertama = $this->buatPengajuan(400000);
        $kedua = $this->buatPengajuan(400000);

        $this->actingAs(User::factory()->create());
        $this->postJson("/transaksi/penarikan-saldo/{$pertama->id}/setujui", ['bukti_transfer' => $this->foto()])->assertOk();
        $this->postJson("/transaksi/penarikan-saldo/{$kedua->id}/setujui", ['bukti_transfer' => $this->foto()])->assertStatus(422)->assertJson(['message' => 'Saldo cabang saat ini Rp100.000, tidak cukup untuk penarikan ini']);
        $this->assertSame('pending', $kedua->fresh()->status);
        $this->assertCount(1, Storage::disk('local')->allFiles('bukti-penarikan'));
    }

    public function test_branch_users_cannot_approve_even_with_permission(): void
    {
        $this->bayarLewatGatewayPerusahaan($this->garut, 1000000);
        $pengajuan = $this->buatPengajuan(100000);
        $this->mitra->givePermissionTo('transaksi.penarikan.approve');

        $this->actingAs($this->mitra)->postJson("/transaksi/penarikan-saldo/{$pengajuan->id}/setujui", ['bukti_transfer' => $this->foto()])->assertForbidden();
        $this->actingAs(User::factory()->peran('Teknisi')->create())->postJson("/transaksi/penarikan-saldo/{$pengajuan->id}/setujui", ['bukti_transfer' => $this->foto()])->assertForbidden();
        $this->assertSame('pending', $pengajuan->fresh()->status);
    }

    /** @return array<string, mixed> */
    private function pengajuan(array $attrs = []): array
    {
        return [
            'id_lokasi' => $this->garut->id, 'nama_request' => 'Penarikan Oktober', 'periode_sumber' => '2026-10-01', 'nominal' => 100000,
            'keterangan' => 'Bagi hasil', 'bank_penerima' => 'BCA', 'no_rekening_penerima' => '1234567890', 'atas_nama_penerima' => 'Mitra Garut', ...$attrs,
        ];
    }

    private function buatPengajuan(int $nominal): PenarikanSaldo
    {
        return PenarikanSaldo::create([...$this->pengajuan(['nominal' => $nominal]), 'user_id' => $this->mitra->id, 'status' => 'pending']);
    }

    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('bukti.jpg', 400, 600);
    }

    private function bayarLewatGatewayPerusahaan(Lokasi $lokasi, int $nominal): void
    {
        Pembayaran::create($this->pembayaran($lokasi, $nominal) + ['metode' => 'xendit']);
    }

    /** @return array<string, mixed> */
    private function pembayaran(Lokasi $lokasi, int $nominal): array
    {
        return ['id_lokasi' => $lokasi->id, 'periode' => '2026-10-01', 'nama_pelanggan' => 'Pelanggan', 'nama_paket' => 'Paket', 'harga' => $nominal, 'jml_bayar' => $nominal, 'paid_at' => '2026-10-10 09:00:00'];
    }
}
