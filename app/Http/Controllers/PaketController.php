<?php

namespace App\Http\Controllers;

use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Services\Mikrotik\IsolirService;
use App\Services\Mikrotik\RouterOsException;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Paket langganan (database) per jenis koneksi. Profile Mikrotik paket PPPoE/Hotspot dipakai sebagai tujuan saat pelanggan
 * di-On-kan, dan bisa dibuat/diperbarui otomatis di router (rate-limit, local address, IP pool). Paket Static & IP Binding tanpa profile.
 */
class PaketController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);
        // Filter Jenis Koneksi di daftar paket
        $f['jns_koneksi'] = in_array($request->input('jns_koneksi'), array_keys(Pelanggan::KONEKSI), true) ? $request->input('jns_koneksi') : null;
        $rows = Paket::with(['router:id,nama_router', 'lokasi:id,nama_lokasi'])
            ->withCount(['pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif')])
            ->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']))
            ->when($f['jns_koneksi'], fn ($q, $jenis) => $q->where('jns_koneksi', $jenis))
            ->orderBy('nama_paket')->get()
            ->map(fn (Paket $p) => [...$p->toArray(), 'cabang' => $p->lokasi?->nama_lokasi ?? '-', 'semua_cabang' => ! $p->filter_lokasi]);
        $cabangUser = $request->user()->id_lokasi;

        return Inertia::render('KelolaData/Paket', [
            'filters' => $f,
            'rows' => $rows,
            // Form menyaring router sesuai cabang terpilih (id_lokasi); router tanpa cabang dipakai bersama semua cabang
            'routerOptions' => Router::when($cabangUser, fn ($q) => $q->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $cabangUser)))
                ->orderBy('nama_router')->get()
                ->map(fn ($r) => ['value' => $r->id, 'label' => $r->id_lokasi ? $r->nama_router : "{$r->nama_router} (semua cabang)", 'id_lokasi' => $r->id_lokasi]),
        ]);
    }

    public function store(Request $request, IsolirService $isolir): JsonResponse
    {
        $paket = Paket::create($this->validated($request));

        return $this->simpanDanSinkron($paket, "Paket {$paket->nama_paket} ditambahkan", $isolir);
    }

    public function update(Request $request, Paket $paket, IsolirService $isolir): JsonResponse
    {
        $this->pastikanCabangPaket($request, $paket);
        $paket->update($this->validated($request, $paket));

        return $this->simpanDanSinkron($paket, "Paket {$paket->nama_paket} diperbarui", $isolir);
    }

    public function destroy(Request $request, Paket $paket): JsonResponse
    {
        $this->pastikanCabangPaket($request, $paket);
        if ($paket->pelanggan()->exists()) {
            return response()->json(['message' => "Paket {$paket->nama_paket} masih dipakai pelanggan, pindahkan pelanggan ke paket lain dulu"], 422);
        }
        $paket->delete();

        return response()->json(['message' => "Paket {$paket->nama_paket} dihapus"]);
    }

    /** Tombol "Sinkron ke MikroTik": profile paket PPPoE/Hotspot, atau queue semua pelanggan paket Static/IP Binding. */
    public function sinkron(Request $request, Paket $paket, IsolirService $isolir): JsonResponse
    {
        $this->pastikanCabangPaket($request, $paket);
        if (! $paket->pakaiProfile() && ! $paket->pakaiQueue()) {
            return response()->json(['message' => "Paket {$paket->nama_paket} ({$paket->labelKoneksi()}) belum diisi rate limit, tidak ada yang disinkronkan"], 422);
        }
        [$berhasil, $pesan] = $paket->pakaiQueue() ? $isolir->sinkronQueuePaket($paket) : $this->sinkronSemua($paket, $isolir);
        Audit::catat('Paket', 'sinkron', "Sinkron paket {$paket->nama_paket} ke Mikrotik: ".($berhasil ? 'berhasil' : 'gagal'), $paket, ['hasil' => ['ke' => $pesan]]);

        return response()->json(['message' => $pesan], $berhasil ? 200 : 422);
    }

    /** Dropdown "Sinkronisasi": profile yang sudah ada di router terpilih, dimuat langsung dari MikroTik. */
    public function profileRouter(Request $request, Router $router, IsolirService $isolir): JsonResponse
    {
        // Router cabang lain tidak boleh dibaca (404 agar keberadaannya tidak terbaca); router tanpa cabang berlaku umum
        abort_unless($router->id_lokasi === null || $request->user()->bolehAksesCabang($router->id_lokasi), 404);
        $jenis = $request->validate(['jenis' => ['required', 'in:pppoe,hotspot']])['jenis'];

        try {
            return response()->json(['data' => $isolir->profilePaket($router, $jenis)]);
        } catch (RouterOsException $e) {
            return response()->json(['message' => "{$router->nama_router}: {$e->getMessage()}"], 422);
        }
    }

    /**
     * Data tetap tersimpan walau sinkron gagal; kegagalan dikembalikan sebagai peringatan. Paket Static & IP Binding:
     * rate limit diubah → queue semua pelanggannya ikut diperbarui (dikosongkan → queue di router dibiarkan).
     */
    private function simpanDanSinkron(Paket $paket, string $pesan, IsolirService $isolir): JsonResponse
    {
        if (! $paket->pakaiProfile()) {
            if (! $paket->pakaiQueue() || ! $paket->wasChanged('rate_limit') || ! $paket->pelanggan()->where('status', 'aktif')->exists()) {
                return response()->json(['message' => $pesan, 'type' => 'success']);
            }
            [$berhasil, $hasil] = $isolir->sinkronQueuePaket($paket);

            return response()->json(['message' => "$pesan. $hasil", 'type' => $berhasil ? 'success' : 'warning']);
        }
        [$berhasil, $hasil] = $this->sinkronSemua($paket, $isolir);

        return response()->json(['message' => "$pesan. $hasil", 'type' => $berhasil ? 'success' : 'warning']);
    }

    /**
     * Buat baru: profile dibuat/diperbarui di router paket. Bila router dikosongkan: semua router cabang paket (plus router
     * bersama tanpa cabang), atau semua router bila paket ditampilkan di semua cabang.
     * Sinkronisasi: profile yang sudah ada di router hanya dicocokkan (rate limit disalin), router tidak diubah.
     *
     * @return array{0: bool, 1: string}
     */
    private function sinkronSemua(Paket $paket, IsolirService $isolir): array
    {
        if (! $paket->buat_profile && $paket->router) {
            return $this->cocokkanProfile($paket, $paket->router, $isolir);
        }
        $routers = Router::query()
            ->when($paket->router_id, fn ($q) => $q->whereKey($paket->router_id))
            ->when(! $paket->router_id && $paket->filter_lokasi, fn ($q) => $q->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $paket->id_lokasi)))
            ->orderBy('nama_router')->get();
        if ($routers->isEmpty()) {
            return [false, 'Belum ada router untuk menyinkronkan profile'];
        }

        $berhasil = [];
        $gagal = [];
        foreach ($routers as $router) {
            try {
                $berhasil[] = $isolir->sinkronPaket($paket, $router);
            } catch (RouterOsException $e) {
                $gagal[] = "{$router->nama_router}: {$e->getMessage()}";
            }
        }
        $paket->update(['synced_at' => $gagal ? $paket->synced_at : now(), 'sync_error' => $gagal ? implode('; ', $gagal) : null]);

        return [! $gagal, implode('; ', [...$berhasil, ...$gagal])];
    }

    /** @return array{0: bool, 1: string} */
    private function cocokkanProfile(Paket $paket, Router $router, IsolirService $isolir): array
    {
        try {
            $profile = collect($isolir->profilePaket($router, $paket->jns_koneksi))->firstWhere('name', $paket->profile_mikrotik);
            if (! $profile) {
                throw new RouterOsException("profile {$paket->profile_mikrotik} tidak ada di router");
            }
        } catch (RouterOsException $e) {
            $paket->update(['sync_error' => "{$router->nama_router}: {$e->getMessage()}"]);

            return [false, $paket->sync_error];
        }
        $paket->update(['rate_limit' => $profile['rate_limit'], 'synced_at' => now(), 'sync_error' => null]);

        return [true, "{$router->nama_router}: profile {$paket->profile_mikrotik} terhubung"];
    }

    /** Paket untuk semua cabang hanya bisa diubah user semua cabang. */
    private function pastikanCabangPaket(Request $request, Paket $paket): void
    {
        $this->pastikanCabang($request, $paket->filter_lokasi ? $paket->id_lokasi : null);
    }

    /**
     * Urutan form: cabang → router milik cabang itu (atau router bersama tanpa cabang) → jenis koneksi.
     * semua_cabang = paket juga bisa dipilih pelanggan cabang lain (disimpan sebagai filter_lokasi = false).
     * Profile MikroTik hanya untuk PPPoE & Hotspot; Static & IP Binding tanpa profile.
     */
    private function validated(Request $request, ?Paket $paket = null): array
    {
        $lokasi = $request->integer('id_lokasi');
        $data = $request->validate([
            'nama_paket' => ['required', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'harga' => ['required', 'integer', 'min:0'],
            'jenis' => ['required', 'in:bulanan,non_bulanan'],
            'jns_koneksi' => ['required', Rule::in(array_keys(Pelanggan::KONEKSI))],
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id', ...$this->aturanCabangSendiri($request)],
            // User cabang hanya boleh membuat paket khusus cabangnya
            'semua_cabang' => ['boolean', ...($request->user()->id_lokasi ? ['declined'] : [])],
            // Sinkronisasi memilih profile dari router tertentu; router harus milik cabang terpilih atau router bersama
            'router_id' => [Rule::requiredIf(in_array($request->input('jns_koneksi'), Paket::KONEKSI_PROFILE, true) && ! $request->boolean('buat_profile')), 'nullable', 'integer',
                Rule::exists('routers', 'id')->where(fn ($q) => $q->where('id_lokasi', $lokasi)->orWhereNull('id_lokasi'))],
            'profile_mikrotik' => ['required_unless:jns_koneksi,static,ip_binding', 'nullable', 'string', 'max:100'],
            // Format rate-limit Mikrotik: rx/tx, boleh dengan burst, mis. 10M/10M atau 10M/10M 20M/20M 8M/8M 16/16
            'rate_limit' => ['nullable', 'string', 'max:100', 'regex:/^[0-9kKmMgG\/ ]+$/'],
            'local_address' => ['nullable', 'ip'],
            'buat_profile' => ['boolean'],
            'buat_pool' => ['boolean'],
            'nama_pool' => ['required_if:buat_pool,true', 'nullable', 'string', 'max:100'],
            'range_pool' => ['required_if:buat_pool,true', 'nullable', 'string', 'max:255', 'regex:/^[0-9.\-, ]+$/'],
            'tampil_registrasi' => ['boolean'],
        ], [...$this->pesanValidasi(), 'router_id.exists' => 'Router tidak ada di cabang terpilih'], [
            'nama_paket' => 'Nama paket', 'keterangan' => 'Keterangan', 'harga' => 'Harga', 'jenis' => 'Jenis', 'jns_koneksi' => 'Jenis koneksi',
            'id_lokasi' => 'Cabang', 'semua_cabang' => 'Tampilkan di semua cabang', 'router_id' => 'Router', 'profile_mikrotik' => 'Profile MikroTik', 'rate_limit' => 'Rate limit',
            'local_address' => 'Local address', 'nama_pool' => 'Nama pool', 'range_pool' => 'Range IP pool',
        ]);

        $data['filter_lokasi'] = ! ($data['semua_cabang'] ?? false);
        unset($data['semua_cabang']);
        if (! in_array($data['jns_koneksi'], Paket::KONEKSI_PROFILE, true)) {
            $data = [...$data, 'profile_mikrotik' => null, 'buat_profile' => false];
        }

        // Sinkronisasi memakai profile router apa adanya: pengaturan profile diambil dari router, bukan dari form.
        // Static & IP Binding tetap menyimpan rate limit: dipakai sebagai limit Simple Queue pelanggannya
        if (empty($data['buat_profile'])) {
            $tanpaProfile = in_array($data['jns_koneksi'], Paket::KONEKSI_QUEUE, true);
            $data = [...$data, 'rate_limit' => $tanpaProfile ? ($data['rate_limit'] ?? null) : null, 'local_address' => null, 'buat_pool' => false];
        }
        if (empty($data['buat_pool'])) {
            $data['nama_pool'] = null;
            $data['range_pool'] = null;
        }

        return $data;
    }
}
