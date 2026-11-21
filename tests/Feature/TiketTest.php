<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\Tiket;
use App\Models\User;
use App\Services\AkunPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TiketTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $garut;

    private Lokasi $tasik;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->tasik = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Tasik']);
        $this->router = Router::create([
            'nama_router' => 'CCR Pusat', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ]);
    }

    public function test_customer_creates_ticket_for_own_subscription_in_its_branch(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890', $this->garut);

        $res = $this->actingAs($budi->user)->postJson('/akun-saya/tiket', [
            'pelanggan_id' => $budi->id, 'kategori' => 'gangguan', 'judul' => 'Internet mati', 'pesan' => "Sejak pagi lampu LOS merah.\nMohon dicek.",
        ])->assertOk();

        $tiket = Tiket::firstOrFail();
        $res->assertJsonPath('id', $tiket->id)->assertJsonPath('message', "Tiket {$tiket->no_tiket} terkirim. Petugas kami akan segera menanggapi.");
        $this->assertMatchesRegularExpression('/^TKT\d{4}-\d{5}$/', $tiket->no_tiket);
        $this->assertSame([$budi->user_id, $this->garut->id, 'baru'], [$tiket->user_id, $tiket->id_lokasi, $tiket->status]);
        $this->assertSame([true, "Sejak pagi lampu LOS merah.\nMohon dicek."], [$tiket->pesan->first()->dari_pelanggan, $tiket->pesan->first()->pesan]);
    }

    public function test_customer_cannot_open_ticket_for_someone_elses_subscription(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890', $this->garut);
        $lain = $this->pelanggan('Orang Lain', '089900001111', $this->garut);

        $this->actingAs($budi->user)->postJson('/akun-saya/tiket', ['pelanggan_id' => $lain->id, 'kategori' => 'gangguan', 'judul' => 'X', 'pesan' => 'Y'])
            ->assertStatus(422)->assertJsonValidationErrors(['pelanggan_id' => 'Langganan tidak ditemukan']);
        $this->postJson('/akun-saya/tiket', [])
            ->assertStatus(422)->assertJsonValidationErrors(['pelanggan_id', 'kategori', 'judul' => 'Judul wajib diisi', 'pesan' => 'Isi keluhan wajib diisi']);
        $this->assertSame(0, Tiket::count());
    }

    public function test_customer_cannot_see_or_reply_to_another_customers_ticket(): void
    {
        $tiket = $this->tiket($this->pelanggan('Orang Lain', '089900001111', $this->garut));
        $budi = $this->pelanggan('Budi', '081234567890', $this->garut);

        $this->actingAs($budi->user)->get("/akun-saya/tiket/{$tiket->id}")->assertNotFound();
        $this->postJson("/akun-saya/tiket/{$tiket->id}/pesan", ['pesan' => 'Halo'])->assertNotFound();
        $this->get('/akun-saya/tiket')->assertInertia(fn ($page) => $page->component('Portal/Tiket')->has('rows', 0));
        $this->assertSame(1, $tiket->pesan()->count());
    }

    public function test_staff_reply_moves_new_ticket_to_in_progress_and_customer_sees_it(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890', $this->garut);
        $tiket = $this->tiket($budi);
        $teknisi = User::factory()->peran('Teknisi')->create(['name' => 'Asep Teknisi', 'id_lokasi' => $this->garut->id]);

        $this->actingAs($teknisi)->postJson("/tiket/{$tiket->id}/pesan", ['pesan' => 'Teknisi menuju lokasi'])->assertOk();

        $this->assertSame('diproses', $tiket->fresh()->status);
        $this->actingAs($budi->user)->get("/akun-saya/tiket/{$tiket->id}")->assertInertia(fn ($page) => $page->component('Portal/TiketDetail')
            ->where('tiket.status', 'diproses')
            ->has('pesan', 2)
            ->where('pesan.1.dari_pelanggan', false)
            ->where('pesan.1.pengirim', 'Asep Teknisi · MSS')
            ->where('pesan.1.pesan', 'Teknisi menuju lokasi'));
    }

    public function test_finished_ticket_cannot_be_replied_by_customer(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890', $this->garut);
        $tiket = $this->tiket($budi);
        $this->actingAs(User::factory()->create())->putJson("/tiket/{$tiket->id}/status", ['status' => 'selesai'])
            ->assertOk()->assertJsonPath('message', "Tiket {$tiket->no_tiket} selesai");
        $this->assertNotNull($tiket->fresh()->selesai_at);

        $this->actingAs($budi->user)->postJson("/akun-saya/tiket/{$tiket->id}/pesan", ['pesan' => 'Masih mati'])
            ->assertStatus(422)->assertJsonPath('message', 'Tiket sudah selesai. Buat tiket baru bila masih ada kendala.');
    }

    public function test_branch_staff_only_sees_and_handles_tickets_of_own_branch(): void
    {
        $this->tiket($this->pelanggan('Budi', '081234567890', $this->garut));
        $tiketTasik = $this->tiket($this->pelanggan('Siti', '085711110000', $this->tasik), 'Lambat');
        $admin = User::factory()->peran('Admin')->create(['id_lokasi' => $this->garut->id]);

        $this->actingAs($admin)->get('/tiket')->assertOk()->assertInertia(fn ($page) => $page->component('Tiket/Index')
            ->has('rows', 1)
            ->where('rows.0.pelanggan.nama', 'Budi'));
        $this->get("/tiket/{$tiketTasik->id}")->assertNotFound();
        $this->putJson("/tiket/{$tiketTasik->id}/status", ['status' => 'selesai'])->assertNotFound();
        $this->assertSame('baru', $tiketTasik->fresh()->status);
    }

    public function test_branch_owner_receives_tickets_of_own_branch_with_new_ticket_count(): void
    {
        $this->tiket($this->pelanggan('Budi', '081234567890', $this->garut));
        $this->tiket($this->pelanggan('Siti', '085711110000', $this->tasik), 'Lambat');
        $mitra = User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]);

        $this->actingAs($mitra)->get('/tiket')->assertOk()->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.pelanggan.nama', 'Budi')
            ->where('tiketBaru', 1));
    }

    public function test_migration_grants_ticket_permission_to_existing_branch_roles_only(): void
    {
        // Server lama: role sudah ada sebelum menu Tiket, tanpa izin tiket
        foreach (['Admin', 'Mitra', 'Teknisi', 'Sales'] as $nama) {
            Role::findByName($nama)->revokePermissionTo(Role::findByName($nama)->permissions->filter(fn ($p) => str_starts_with($p->name, 'tiket.')));
        }

        (require database_path('migrations/2026_11_21_184827_beri_izin_tiket_ke_role_cabang.php'))->up();

        foreach (['Admin', 'Mitra', 'Teknisi'] as $nama) {
            $this->assertTrue(Role::findByName($nama)->hasPermissionTo('tiket.update'), "$nama harus bisa membalas tiket");
        }
        $this->assertFalse(Role::findByName('Sales')->hasPermissionTo('tiket.view'));
    }

    public function test_role_without_ticket_permission_cannot_open_tickets(): void
    {
        $this->actingAs(User::factory()->peran('Sales')->create())->get('/tiket')->assertForbidden();
    }

    private function pelanggan(string $nama, string $tlp, Lokasi $lokasi): Pelanggan
    {
        $pelanggan = Pelanggan::create(['nama' => $nama, 'tlp' => $tlp, 'id_lokasi' => $lokasi->id, 'router_id' => $this->router->id, 'status' => 'aktif']);
        app(AkunPelanggan::class)->sinkronkan($pelanggan)->update(['wajib_ganti_password' => false]);

        return $pelanggan->fresh();
    }

    /** Tiket yang dibuat pelanggan lewat dashboard-nya. */
    private function tiket(Pelanggan $pelanggan, string $judul = 'Internet mati'): Tiket
    {
        $this->actingAs($pelanggan->user)->postJson('/akun-saya/tiket', ['pelanggan_id' => $pelanggan->id, 'kategori' => 'gangguan', 'judul' => $judul, 'pesan' => 'Tolong dicek'])->assertOk();

        return Tiket::where('judul', $judul)->where('pelanggan_id', $pelanggan->id)->firstOrFail();
    }
}
