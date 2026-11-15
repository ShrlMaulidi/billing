<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lokasi;
use App\Models\PenarikanSaldo;
use App\Models\Rekening;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-14 09:00:00');
        $this->admin = User::factory()->create(['name' => 'Sahrul']);
        $this->actingAs($this->admin);
    }

    public function test_create_update_and_delete_are_logged_with_actor_changes_and_ip(): void
    {
        $this->postJson('/rekening', ['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '123'])->assertOk();
        $rekening = Rekening::sole();
        $this->putJson("/rekening/{$rekening->id}", ['nama_rekening' => 'BCA', 'atas_nama' => 'PT MSS', 'no_rekening' => '123'])->assertOk();
        $this->deleteJson("/rekening/{$rekening->id}")->assertOk();

        $log = AuditLog::where('modul', 'Rekening')->orderBy('id')->get();
        $this->assertSame(['create', 'update', 'delete'], $log->pluck('aksi')->all());
        $this->assertSame(['Menambah rekening BCA', 'Mengubah rekening BCA', 'Menghapus rekening BCA'], $log->pluck('deskripsi')->all());
        $this->assertSame(['Sahrul', 'Super Admin', '127.0.0.1', $rekening->id], [$log[1]->user_nama, $log[1]->user_role, $log[1]->ip_address, $log[1]->subjek_id]);
        $this->assertSame(['atas_nama' => ['dari' => 'MSS', 'ke' => 'PT MSS']], $log[1]->perubahan);
        $this->assertSame(['ke' => 'MSS'], $log[0]->perubahan['atas_nama']);
        $this->assertSame(['dari' => 'PT MSS'], $log[2]->perubahan['atas_nama']);
    }

    public function test_passwords_and_hidden_columns_are_never_stored(): void
    {
        $this->postJson('/user', ['nama' => 'Rifky', 'username' => 'rifky', 'email' => null, 'role' => 'Admin', 'id_lokasi' => null, 'password' => 'rahasia123', 'status' => 'aktif'])->assertOk();
        $rifky = User::firstWhere('username', 'rifky');
        $this->putJson("/user/{$rifky->id}", ['nama' => 'Rifky', 'username' => 'rifky', 'email' => null, 'role' => 'Mitra', 'id_lokasi' => Lokasi::create(['kode_lokasi' => 'C1', 'nama_lokasi' => 'Garut'])->id, 'password' => 'baru12345', 'status' => 'aktif'])->assertOk();

        $isi = AuditLog::where('subjek_type', 'User')->where('subjek_id', $rifky->id)->get();
        $this->assertStringNotContainsString('rahasia123', $isi->toJson());
        $this->assertStringNotContainsString($rifky->fresh()->password, $isi->toJson());
        $ubah = $isi->firstWhere('deskripsi', 'Mengubah user Rifky');
        $this->assertSame(['dari' => Audit::DISAMARKAN, 'ke' => Audit::DISAMARKAN], $ubah->perubahan['password']);
        $this->assertNotNull($isi->firstWhere('deskripsi', 'Mengganti role user Rifky: Admin → Mitra'));
    }

    public function test_login_failed_login_and_logout_are_logged(): void
    {
        auth()->logout();
        User::factory()->create(['username' => 'budi', 'password' => 'rahasia123', 'name' => 'Budi']);

        $this->post('/login', ['username' => 'budi', 'password' => 'SandiKeliru99'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => 'budi', 'password' => 'rahasia123']);
        $this->post('/logout');

        $akses = AuditLog::where('modul', 'Akses')->orderBy('id')->get();
        $this->assertSame(['login_gagal', 'login', 'logout'], $akses->pluck('aksi')->all());
        $this->assertSame(['budi', 'Budi', 'Budi'], $akses->pluck('user_nama')->all());
        $this->assertStringNotContainsString('SandiKeliru99', $akses->toJson());
        // Waktu login terakhir yang ikut diperbarui saat login tidak tercatat sebagai "ubah user"
        $this->assertSame(0, AuditLog::where('modul', 'User')->where('aksi', 'update')->count());
    }

    public function test_rejected_withdrawal_is_logged_once_as_reject(): void
    {
        $lokasi = Lokasi::create(['kode_lokasi' => 'C1', 'nama_lokasi' => 'Garut']);
        $penarikan = PenarikanSaldo::create([
            'id_lokasi' => $lokasi->id, 'nama_request' => 'WD Oktober', 'periode_sumber' => '2026-10-01', 'nominal' => 100000, 'status' => 'pending',
            'keterangan' => 'Bagi hasil', 'bank_penerima' => 'BCA', 'no_rekening_penerima' => '1', 'atas_nama_penerima' => 'Mitra',
        ]);

        $this->postJson("/transaksi/penarikan-saldo/{$penarikan->id}/tolak", ['catatan' => 'Rekening salah'])->assertOk();

        $log = AuditLog::where('subjek_type', 'PenarikanSaldo')->where('aksi', '!=', 'create')->get();
        $this->assertCount(1, $log);
        $this->assertSame(['reject', 'Menolak penarikan WD Oktober Rp100.000'], [$log[0]->aksi, $log[0]->deskripsi]);
        $this->assertSame(['dari' => 'pending', 'ke' => 'ditolak'], $log[0]->perubahan['status']);
    }

    public function test_role_permission_changes_list_added_and_removed_permissions(): void
    {
        $role = Role::findByName('Sales');

        $this->putJson("/user/role/{$role->id}", ['permissions' => ['dashboard.view', 'pelanggan.data.view', 'laporan.view']])->assertOk();

        $log = AuditLog::where('modul', 'Hak Akses')->sole();
        $this->assertSame('Mengubah hak akses role Sales (+1 / −1)', $log->deskripsi);
        $this->assertSame(['izin ditambah' => ['ke' => ['laporan.view']], 'izin dicabut' => ['dari' => ['pelanggan.data.create']]], $log->perubahan);
    }

    public function test_logs_page_filters_by_date_and_action_and_branch_users_see_only_their_branch(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C1', 'nama_lokasi' => 'Garut']);
        AuditLog::query()->delete();
        $tulis = fn (array $isi) => AuditLog::create(['created_at' => now(), 'modul' => 'Pelanggan', 'aksi' => 'update', 'deskripsi' => 'x', ...$isi]);
        $tulis(['user_nama' => 'Pusat', 'aksi' => 'create', 'deskripsi' => 'pusat hari ini']);
        $tulis(['user_nama' => 'Mitra Garut', 'id_lokasi' => $garut->id, 'deskripsi' => 'garut hari ini']);
        $tulis(['user_nama' => 'Pusat', 'created_at' => now()->subDays(10), 'deskripsi' => 'lama']);

        $this->get('/logs')->assertOk()->assertInertia(fn ($page) => $page->component('Logs/Index')
            ->where('filters.dari', '2026-11-08')->where('filters.sampai', '2026-11-14')->where('total', 2)->has('rows', 2)
            ->where('rows.0.deskripsi', 'garut hari ini')->where('options.user', ['Mitra Garut', 'Pusat']));
        $this->get('/logs?aksi=create')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.deskripsi', 'pusat hari ini'));
        $this->get('/logs?aksi=hapus-semua&dari=2026-11-01')->assertInertia(fn ($page) => $page->where('filters.aksi', null)->has('rows', 3));

        $mitra = User::factory()->peran('Mitra')->create(['id_lokasi' => $garut->id]);
        $mitra->givePermissionTo('logs.view');
        $this->actingAs($mitra)->get('/logs?dari=2026-11-01')->assertInertia(fn ($page) => $page
            ->has('rows', 1)->where('rows.0.deskripsi', 'garut hari ini')->where('options.user', ['Mitra Garut']));
    }

    public function test_audit_records_cannot_be_changed_and_are_pruned_after_a_year(): void
    {
        $baru = AuditLog::create(['created_at' => now(), 'modul' => 'Uji', 'aksi' => 'update', 'deskripsi' => 'baru']);
        AuditLog::create(['created_at' => now()->subDays(AuditLog::MASA_SIMPAN_HARI + 1), 'modul' => 'Uji', 'aksi' => 'update', 'deskripsi' => 'lama']);

        try {
            $baru->update(['deskripsi' => 'diubah']);
            $this->fail('Catatan audit seharusnya tidak bisa diubah');
        } catch (LogicException) {
        }
        try {
            $baru->delete();
            $this->fail('Catatan audit seharusnya tidak bisa dihapus');
        } catch (LogicException) {
        }
        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertSame(['baru'], AuditLog::where('modul', 'Uji')->pluck('deskripsi')->all());
    }
}
