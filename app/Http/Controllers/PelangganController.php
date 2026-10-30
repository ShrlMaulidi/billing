<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\IsolirService;
use App\Services\Mikrotik\RouterOsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'areaOptions' => Area::orderBy('nama_area')->get()->map(fn ($a) => ['value' => $a->id, 'label' => $a->nama_area, 'id_lokasi' => $a->id_lokasi]),
            'routerOptions' => Router::orderBy('nama_router')->get()->map(fn ($r) => ['value' => $r->id, 'label' => $r->nama_router, 'id_lokasi' => $r->id_lokasi]),
            'paketOptions' => Paket::orderBy('nama_paket')->get()->map(fn ($p) => [
                'value' => $p->id, 'label' => "{$p->nama_paket} ({$p->profile_mikrotik})",
                'id_lokasi' => $p->filter_lokasi ? $p->id_lokasi : null, 'jns_koneksi' => $p->jns_koneksi,
            ]),
            'salesOptions' => User::where('role', 'Sales')->orderBy('name')->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
        ]);
    }

    public function store(Request $request, IsolirService $mikrotik): JsonResponse
    {
        [$data, $akun] = $this->validated($request);
        $pelanggan = Pelanggan::create($data);
        // Kode pelanggan otomatis bila tidak diisi
        if (! $pelanggan->id_pelanggan) {
            $pelanggan->update(['id_pelanggan' => sprintf('P%05d', $pelanggan->id)]);
        }

        return $this->simpanAkun($pelanggan, $akun, "Pelanggan {$pelanggan->nama} ditambahkan", $mikrotik);
    }

    public function update(Request $request, Pelanggan $pelanggan, IsolirService $mikrotik): JsonResponse
    {
        [$data, $akun] = $this->validated($request, $pelanggan);
        $pelanggan->update($data);

        return $this->simpanAkun($pelanggan, $akun, "Data {$pelanggan->nama} diperbarui", $mikrotik);
    }

    public function destroy(Pelanggan $pelanggan): JsonResponse
    {
        $pelanggan->delete();

        return response()->json(['message' => "Pelanggan {$pelanggan->nama} dihapus"]);
    }

    /** Daftar akun di router untuk dropdown "Sinkronisasi" (PPP Secret / Hotspot User / IP Binding). */
    public function akunRouter(Request $request, Router $router, IsolirService $mikrotik): JsonResponse
    {
        $jenis = $request->validate(['jenis' => ['required', 'in:pppoe,hotspot,ip_binding']])['jenis'];

        try {
            return response()->json(['data' => $mikrotik->akun($router, $jenis)]);
        } catch (RouterOsException $e) {
            return response()->json(['message' => "{$router->nama_router}: {$e->getMessage()}"], 422);
        }
    }

    /** Off: isolir PPPoE pelanggan. */
    public function isolir(Pelanggan $pelanggan, IsolirService $isolir): JsonResponse
    {
        return $this->jalankan(fn () => $isolir->isolir($pelanggan));
    }

    /** On: kembalikan ke profile asal (atau profile pilihan bila asal tidak diketahui). */
    public function aktifkan(Request $request, Pelanggan $pelanggan, IsolirService $isolir): JsonResponse
    {
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
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id'],
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
            'ip_address' => ['nullable', 'required_if:jns_koneksi,static', 'ip'],
            // Paket untuk semua cabang, atau khusus cabang ini
            'id_paket' => ['required', 'integer', Rule::exists('paket', 'id')->where(fn ($q) => $q->where('filter_lokasi', false)->orWhere('id_lokasi', $lokasi))],
            'tgl_registrasi' => ['required', 'date'],
            'tgl_jatuh_tempo' => ['required', 'integer', 'between:1,31'],
            'ppn' => ['required', 'boolean'],
            'sales_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'Sales')],
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
        $data['ip_address'] = in_array($data['jns_koneksi'], ['static', 'ip_binding'], true) ? ($data['ip_address'] ?? null) : null;
        if (empty($data['status'])) {
            unset($data['status']);
        }

        return [$data, $akun];
    }
}
