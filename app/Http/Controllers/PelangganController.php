<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\IsolirService;
use App\Services\Mikrotik\RouterOsException;
use App\Support\IpPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Data pelanggan + akun koneksinya di Mikrotik (Sinkronisasi akun yang sudah ada, atau Buat baru),
 * dan On/Off internet lewat isolir PPPoE. status null = semua pelanggan, 'on' = internet aktif, 'off' = terisolir.
 */
class PelangganController extends Controller
{
    public function index(Request $request, ?string $status = null)
    {
        $f = $this->filters($request);
        $cabangUser = $request->user()->id_lokasi;
        // Pilihan milik cabang user, plus yang berlaku untuk semua cabang (tanpa cabang)
        $cabangDanUmum = fn ($q) => $q->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $cabangUser));
        $base = Pelanggan::query()->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']));

        $rows = (clone $base)->with(['router:id,nama_router,profile_isolir', 'paket:id,nama_paket,harga,profile_mikrotik', 'lokasi:id,nama_lokasi'])
            ->when($status, fn ($q) => $q->where('status', 'aktif')->where('status_langganan', $status))
            ->orderBy('nama')->get()
            ->map(fn (Pelanggan $p) => [...$p->toBaris(), 'profile_paket' => $p->paket?->profile_mikrotik]);

        return Inertia::render('Pelanggan/Index', [
            'filters' => $f,
            'status' => $status,
            'rows' => $rows,
            'jumlah' => [
                'semua' => (clone $base)->count(),
                'on' => (clone $base)->where('status', 'aktif')->where('status_langganan', 'on')->count(),
                'off' => (clone $base)->where('status', 'aktif')->where('status_langganan', 'off')->count(),
            ],
            // id_lokasi dkk. dipakai form untuk menyaring pilihan sesuai cabang & jenis koneksi
            'areaOptions' => Area::when($cabangUser, fn ($q) => $q->where('id_lokasi', $cabangUser))->orderBy('nama_area')->get()->map(fn ($a) => ['value' => $a->id, 'label' => $a->nama_area, 'id_lokasi' => $a->id_lokasi]),
            'routerOptions' => Router::when($cabangUser, $cabangDanUmum)->orderBy('nama_router')->get()->map(fn ($r) => ['value' => $r->id, 'label' => $r->nama_router, 'id_lokasi' => $r->id_lokasi]),
            'paketOptions' => Paket::when($cabangUser, fn ($q) => $q->where(fn ($q) => $q->where('filter_lokasi', false)->orWhere('id_lokasi', $cabangUser)))
                ->orderBy('nama_paket')->get()->map(fn ($p) => [
                    'value' => $p->id, 'label' => "{$p->nama_paket} ({$p->profile_mikrotik})",
                    'id_lokasi' => $p->filter_lokasi ? $p->id_lokasi : null, 'jns_koneksi' => $p->jns_koneksi,
                ]),
            'salesOptions' => User::role('Sales')->where('status', 'aktif')->when($cabangUser, $cabangDanUmum)->orderBy('name')->get()
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name, 'id_lokasi' => $u->id_lokasi]),
        ]);
    }

    public function store(Request $request, IsolirService $mikrotik): JsonResponse
    {
        [$data, $akun] = $this->validated($request);
        $this->pastikanIpKosongDiRouter($data, null, $mikrotik);
        $pelanggan = Pelanggan::create($data);
        // Kode pelanggan otomatis bila tidak diisi
        if (! $pelanggan->id_pelanggan) {
            $pelanggan->update(['id_pelanggan' => sprintf('P%05d', $pelanggan->id)]);
        }

        return $this->simpanAkun($pelanggan, $akun, "Pelanggan {$pelanggan->nama} ditambahkan", $mikrotik);
    }

    public function update(Request $request, Pelanggan $pelanggan, IsolirService $mikrotik): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);
        [$data, $akun] = $this->validated($request, $pelanggan);
        $this->pastikanIpKosongDiRouter($data, $pelanggan, $mikrotik);
        $pelanggan->update($data);
        $pesan = "Data {$pelanggan->nama} diperbarui";

        // PPP Secret yang sudah ada ikut diperbarui saat paket / IP diganti ("Buat baru" sudah memakai data terbaru)
        $gantiPaket = $pelanggan->wasChanged('id_paket');
        $gantiIp = $pelanggan->wasChanged('ip_address');
        if (($gantiPaket || $gantiIp) && $akun['mode'] === 'sinkron' && $pelanggan->jns_koneksi === 'pppoe') {
            try {
                return response()->json(['message' => "$pesan, {$mikrotik->perbaruiSecret($pelanggan->load('paket'), $gantiPaket, $gantiIp)}"]);
            } catch (RouterOsException $e) {
                return response()->json(['message' => "$pesan, tetapi akun di router gagal diperbarui: {$e->getMessage()}", 'type' => 'warning']);
            }
        }

        return $this->simpanAkun($pelanggan, $akun, $pesan, $mikrotik);
    }

    public function destroy(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);
        $pelanggan->delete();

        return response()->json(['message' => "Pelanggan {$pelanggan->nama} dihapus"]);
    }

    /** Daftar akun di router untuk dropdown "Sinkronisasi" (PPP Secret / Hotspot User / IP Binding). */
    public function akunRouter(Request $request, Router $router, IsolirService $mikrotik): JsonResponse
    {
        // Router tanpa cabang dipakai bersama semua cabang
        abort_unless($router->id_lokasi === null || $request->user()->bolehAksesCabang($router->id_lokasi), 404);
        $jenis = $request->validate(['jenis' => ['required', 'in:pppoe,hotspot,ip_binding']])['jenis'];

        try {
            return response()->json(['data' => $mikrotik->akun($router, $jenis)]);
        } catch (RouterOsException $e) {
            return response()->json(['message' => "{$router->nama_router}: {$e->getMessage()}"], 422);
        }
    }

    /**
     * Remote Address untuk form pelanggan PPPoE: IP Pool router beserta IP kosongnya, dan IP yang sudah terpakai
     * (dibaca langsung dari router, termasuk akun yang belum tercatat di billing).
     */
    public function ipRouter(Request $request, Router $router, IsolirService $mikrotik): JsonResponse
    {
        abort_unless($router->id_lokasi === null || $request->user()->bolehAksesCabang($router->id_lokasi), 404);

        try {
            $ip = $mikrotik->ipPppoe($router);
        } catch (RouterOsException $e) {
            return response()->json(['message' => "{$router->nama_router}: {$e->getMessage()}"], 422);
        }
        // IP milik pelanggan billing di router ini juga dianggap terpakai
        $terpakai = [...array_keys($ip['terpakai']), ...Pelanggan::where('router_id', $router->id)->whereNotNull('ip_address')->pluck('ip_address')->all()];

        return response()->json([
            'pools' => array_map(fn ($pool) => [...$pool, ...IpPool::kosong($pool['ranges'], $terpakai)], $ip['pools']),
            'terpakai' => $ip['terpakai'],
        ]);
    }

    /** Off: isolir PPPoE pelanggan. */
    public function isolir(Request $request, Pelanggan $pelanggan, IsolirService $isolir): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);

        return $this->jalankan(fn () => $isolir->isolir($pelanggan));
    }

    /** On: kembalikan ke profile asal (atau profile pilihan bila asal tidak diketahui). */
    public function aktifkan(Request $request, Pelanggan $pelanggan, IsolirService $isolir): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);
        $profile = $request->validate(['profile' => ['nullable', 'string', 'max:100']])['profile'] ?? null;

        return $this->jalankan(fn () => $isolir->aktifkan($pelanggan, $profile));
    }

    /** Kesalahan dari router dikembalikan sebagai 422 berisi pesan yang bisa ditampilkan. */
    private function jalankan(callable $fn): JsonResponse
    {
        try {
            return response()->json(['message' => $fn()]);
        } catch (RouterOsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Pilihan "Buat baru" membuat akun di router setelah data tersimpan. Data tetap tersimpan walau gagal;
     * kegagalan dikembalikan sebagai peringatan agar akun bisa dibuat ulang lewat Edit.
     *
     * @param  array{mode: ?string, password: ?string}  $akun
     */
    private function simpanAkun(Pelanggan $pelanggan, array $akun, string $pesan, IsolirService $mikrotik): JsonResponse
    {
        if ($akun['mode'] !== 'baru') {
            return response()->json(['message' => $pesan]);
        }

        try {
            return response()->json(['message' => "$pesan. {$mikrotik->buatAkun($pelanggan, $akun['password'])}"]);
        } catch (RouterOsException $e) {
            return response()->json(['message' => "$pesan, tetapi akun gagal dibuat di router: {$e->getMessage()}", 'type' => 'warning']);
        }
    }

    /**
     * IP PPPoE baru/diubah dicek ke router: tolak bila sudah dipakai PPP Secret atau sesi aktif lain,
     * termasuk akun yang belum tercatat di billing. Router tidak terjangkau → pengecekan dilewati.
     *
     * @param  array<string, mixed>  $data
     */
    private function pastikanIpKosongDiRouter(array $data, ?Pelanggan $pelanggan, IsolirService $mikrotik): void
    {
        $berubah = ! $pelanggan || $pelanggan->ip_address !== $data['ip_address'] || $pelanggan->router_id !== (int) $data['router_id']
            || $pelanggan->username_pppoe !== $data['username_pppoe'];
        if ($data['jns_koneksi'] !== 'pppoe' || ! $data['ip_address'] || ! $berubah) {
            return;
        }
        try {
            $pemakai = $mikrotik->ipPppoe(Router::findOrFail($data['router_id']))['terpakai'][$data['ip_address']] ?? null;
        } catch (RouterOsException) {
            return;
        }
        if ($pemakai !== null && $pemakai !== $data['username_pppoe']) {
            throw ValidationException::withMessages(['ip_address' => "IP sudah dipakai akun PPPoE '$pemakai' di router"]);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{mode: ?string, password: ?string}} data pelanggan & pilihan akun router
     */
    private function validated(Request $request, ?Pelanggan $pelanggan = null): array
    {
        $lokasi = $request->integer('id_lokasi');
        $router = $request->input('router_id');
        $data = $request->validate([
            'id_pelanggan' => ['nullable', 'string', 'max:50', Rule::unique('pelanggan')->ignore($pelanggan)],
            'nama' => ['required', 'string', 'max:100'],
            'tlp' => ['required', 'string', 'max:30'],
            'nik' => ['nullable', 'digits:16'],
            'alamat' => ['required', 'string', 'max:500'],
            'jns_tagihan' => ['required', 'in:prabayar,pascabayar'],
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id', ...$this->aturanCabangSendiri($request)],
            'id_area' => ['nullable', 'integer', Rule::exists('area', 'id')->where('id_lokasi', $lokasi)],
            // Router milik cabang ini, atau router yang belum diberi cabang
            'router_id' => ['required', 'integer', Rule::exists('routers', 'id')->where(fn ($q) => $q->where('id_lokasi', $lokasi)->orWhereNull('id_lokasi'))],
            'jns_koneksi' => ['required', Rule::in(array_keys(Pelanggan::KONEKSI))],
            'mode_akun' => ['nullable', 'required_unless:jns_koneksi,static', 'in:sinkron,baru'],
            // Dikirim ke router: tolak karakter kontrol
            'username_pppoe' => ['nullable', 'required_if:jns_koneksi,pppoe,hotspot', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/',
                Rule::unique('pelanggan')->where('router_id', $router)->ignore($pelanggan)],
            'password_akun' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/',
                Rule::requiredIf($request->input('mode_akun') === 'baru' && in_array($request->input('jns_koneksi'), ['pppoe', 'hotspot'], true))],
            'mac_address' => ['nullable', 'required_if:jns_koneksi,ip_binding', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/',
                Rule::unique('pelanggan')->where('router_id', $router)->ignore($pelanggan)],
            // PPPoE: remote address PPP Secret (IP pelanggan), tidak boleh dipakai pelanggan lain di router yang sama
            'ip_address' => ['nullable', 'required_if:jns_koneksi,static,pppoe', 'ip',
                Rule::unique('pelanggan')->where('router_id', $router)->ignore($pelanggan)],
            // Paket untuk semua cabang, atau khusus cabang ini
            'id_paket' => ['required', 'integer', Rule::exists('paket', 'id')->where(fn ($q) => $q->where('filter_lokasi', false)->orWhere('id_lokasi', $lokasi))],
            'tgl_registrasi' => ['required', 'date'],
            'tgl_jatuh_tempo' => ['required', 'integer', 'between:1,31'],
            'ppn' => ['required', 'boolean'],
            'sales_id' => ['required', 'integer', Rule::in(User::role('Sales')->where('status', 'aktif')->pluck('id'))],
            'status' => [$pelanggan ? 'required' : 'nullable', 'in:aktif,berhenti'],
        ], $this->pesanValidasi(), [
            'id_pelanggan' => 'ID pelanggan', 'nama' => 'Nama pelanggan', 'tlp' => 'No. telepon', 'nik' => 'NIK', 'alamat' => 'Alamat',
            'jns_tagihan' => 'Jenis tagihan', 'id_lokasi' => 'Cabang', 'id_area' => 'Area', 'router_id' => 'Router', 'jns_koneksi' => 'Jenis koneksi',
            'mode_akun' => 'Akun MikroTik', 'username_pppoe' => 'Username', 'password_akun' => 'Password', 'mac_address' => 'MAC address',
            'ip_address' => 'IP address', 'id_paket' => 'Paket', 'tgl_registrasi' => 'Tanggal registrasi', 'tgl_jatuh_tempo' => 'Tanggal jatuh tempo',
            'ppn' => 'PPN', 'sales_id' => 'Sales', 'status' => 'Status',
        ]);

        $akun = ['mode' => $data['jns_koneksi'] === 'static' ? null : $data['mode_akun'], 'password' => $data['password_akun'] ?? null];
        unset($data['mode_akun'], $data['password_akun']);

        // Simpan hanya identitas akun yang dipakai jenis koneksinya
        $data['username_pppoe'] = in_array($data['jns_koneksi'], ['pppoe', 'hotspot'], true) ? $data['username_pppoe'] : null;
        $data['mac_address'] = $data['jns_koneksi'] === 'ip_binding' ? strtoupper($data['mac_address']) : null;
        $data['ip_address'] = in_array($data['jns_koneksi'], ['static', 'ip_binding', 'pppoe'], true) ? ($data['ip_address'] ?? null) : null;
        if (empty($data['status'])) {
            unset($data['status']);
        }

        return [$data, $akun];
    }
}
