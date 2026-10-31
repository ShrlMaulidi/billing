<?php

namespace App\Http\Controllers;

use App\Models\Pengaturan;
use App\Models\Router;
use App\Support\IsolirScript;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;

/**
 * Halaman isolir publik (tujuan redirect pelanggan terisolir) + template script isolir untuk NOC.
 */
class IsolirController extends Controller
{
    /** Isi halaman isolir yang bisa diubah dari Kelola Data › Template Isolir. */
    private const PENGATURAN = [
        'isolir_nama_isp' => 'MSS e-Billing ISP',
        'isolir_pesan' => 'Layanan internet Anda sedang diisolir karena tagihan belum dibayar. Silakan lakukan pembayaran, lalu hubungi kami agar internet aktif kembali.',
        'isolir_whatsapp' => null,
        'isolir_pembayaran' => null,
    ];

    public function index()
    {
        $router = Router::orderBy('nama_router')->get();
        $opsi = [
            'profile' => $router->first()->profile_isolir ?? 'ISOLIR',
            'jaringan' => '192.168.200.0/24',
            'port_proxy' => 8099,
            'url_isolir' => $this->urlIsolir(),
            'alamat_izin' => [],
        ];

        return Inertia::render('KelolaData/TemplateIsolir', [
            'pengaturan' => Pengaturan::ambil(self::PENGATURAN),
            'opsi' => $opsi,
            'script' => (new IsolirScript($opsi))->semua(),
            'routerOptions' => $router->map(fn ($r) => ['value' => $r->id, 'label' => $r->nama_router, 'profile_isolir' => $r->profile_isolir]),
        ]);
    }

    public function simpanPengaturan(Request $request): JsonResponse
    {
        Pengaturan::simpanSemua($request->validate([
            'isolir_nama_isp' => ['required', 'string', 'max:100'],
            'isolir_pesan' => ['required', 'string', 'max:1000'],
            'isolir_whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ -]+$/'],
            'isolir_pembayaran' => ['nullable', 'string', 'max:2000'],
        ], $this->pesanValidasi(), [
            'isolir_nama_isp' => 'Nama ISP', 'isolir_pesan' => 'Pesan', 'isolir_whatsapp' => 'Nomor WhatsApp', 'isolir_pembayaran' => 'Info pembayaran',
        ]));

        return response()->json(['message' => 'Halaman isolir diperbarui']);
    }

    public function script(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'jaringan' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $value, $m) || ip2long($m[1]) === false || $m[2] < 16 || $m[2] > 29) {
                    $fail('Jaringan isolir harus berupa IPv4/prefix 16–29, mis. 192.168.200.0/24');
                }
            }],
            'port_proxy' => ['required', 'integer', 'between:1024,65535'],
            'url_isolir' => ['required', 'url:http,https', 'max:255'],
            'alamat_izin' => ['nullable', 'string', 'max:2000'],
        ], [...$this->pesanValidasi(), 'url' => ':attribute harus berupa URL yang valid'], [
            'profile' => 'Nama profile isolir', 'port_proxy' => 'Port proxy', 'url_isolir' => 'URL halaman isolir', 'alamat_izin' => 'Alamat yang diizinkan',
        ]);

        // Satu alamat per baris (atau dipisah koma): domain, IP, atau IP/prefix
        $izin = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $data['alamat_izin'] ?? ''))));
        $salah = array_filter($izin, fn ($a) => ! preg_match('#^[A-Za-z0-9.-]+(/\d{1,2})?$#', $a));
        if ($salah) {
            return response()->json([
                'message' => 'Alamat tidak valid: '.implode(', ', $salah),
                'errors' => ['alamat_izin' => ['Alamat tidak valid: '.implode(', ', $salah)]],
            ], 422);
        }

        return response()->json(['script' => (new IsolirScript([...$data, 'port_proxy' => (int) $data['port_proxy'], 'alamat_izin' => $izin]))->semua()]);
    }

    /** Halaman publik tujuan redirect web proxy router; ringan, tanpa login dan tanpa aset eksternal. */
    public function halaman(): View
    {
        $p = Pengaturan::ambil(self::PENGATURAN);
        // Nomor 08xx → 628xx untuk tautan wa.me
        $wa = preg_replace('/\D/', '', (string) $p['isolir_whatsapp']);
        $wa = $wa !== '' && str_starts_with($wa, '0') ? '62'.substr($wa, 1) : $wa;

        return view('isolir', ['p' => $p, 'wa' => $wa]);
    }

    private function urlIsolir(): string
    {
        return config('services.isolir.url') ?: rtrim((string) config('app.url'), '/').'/isolir';
    }
}
