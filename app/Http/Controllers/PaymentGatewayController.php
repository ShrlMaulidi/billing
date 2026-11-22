<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Midtrans\MidtransClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Menu Payment Gateway: akun Midtrans diatur sendiri dari aplikasi, tanpa mengubah .env. Tiap pemilik boleh punya beberapa
 * gateway, dan yang ditandai Utama dipakai untuk transaksi baru (gateway pertama otomatis Utama).
 * - Pemilik "Perusahaan": Utama-nya dipakai semua cabang non Admin Full. Hanya Super Admin yang boleh mengelolanya karena
 *   kunci ini menentukan ke akun mana uang pelanggan masuk.
 * - Cabang Admin Full mengelola gateway sendiri dari dashboard-nya (uang masuk ke mitra); selain pemilik cabang hanya Super
 *   Admin yang boleh mengubahnya. Admin pusat lain hanya melihat.
 *   Cabang non Admin Full hanya melihat keterangan gateway Utama perusahaan (tanpa kunci).
 *
 * Keamanan: kunci terenkripsi di database dan tidak pernah dikirim ke browser (hanya 4 karakter terakhir); simpan wajib
 * konfirmasi password; kunci diuji ke Midtrans sebelum disimpan; hanya gateway aktif yang bisa dijadikan Utama, dan Utama
 * tidak bisa dinonaktifkan/dihapus selama pemilik masih punya gateway lain; semua perubahan tercatat di Logs dengan kunci disamarkan. Gateway yang sudah punya
 * transaksi tidak bisa dihapus agar transaksi lama tetap bisa diverifikasi.
 */
class PaymentGatewayController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $lokasiUser = $user->lokasi;

        return Inertia::render('PaymentGateway/Index', [
            'rows' => PaymentGateway::with('lokasi:id,nama_lokasi')->withCount('transaksi')
                ->when($user->id_lokasi, fn ($q) => $q->where('id_lokasi', $user->id_lokasi))
                // Perusahaan dulu, lalu per cabang; Utama paling atas di tiap pemilik
                ->orderByRaw('id_lokasi is not null')->orderBy('id_lokasi')->orderByDesc('utama')->orderBy('id')->get()
                ->map(fn (PaymentGateway $g) => [
                    'id' => $g->id,
                    'nama' => $g->nama,
                    'provider' => $g->provider,
                    'utama' => $g->utama,
                    'pemilik' => $g->namaPemilik(),
                    'produksi' => $g->produksi,
                    'status' => $g->status,
                    'diuji_at' => $g->diuji_at?->toDateTimeString(),
                    // Nama kolom sengaja beda dari isian form agar nilai tersamar tidak ikut terkirim saat edit
                    'server_key_tersamar' => PaymentGateway::samarkan($g->kredensial['server_key'] ?? null),
                    'signature_key_tersamar' => PaymentGateway::samarkan($g->kredensial['signature_key'] ?? null),
                    'jumlah_transaksi' => $g->transaksi_count,
                    'bisa_kelola' => $this->bolehKelola($user, $g->id_lokasi),
                ]),
            'cabangUser' => $lokasiUser?->only(['id', 'nama_lokasi', 'admin_full']),
            // Cabang non Admin Full: keterangan gateway Utama perusahaan yang dipakai (tanpa kunci)
            'utamaPerusahaan' => $lokasiUser && ! $lokasiUser->admin_full
                ? PaymentGateway::utamaUntuk(null)?->only(['nama', 'provider', 'produksi'])
                : null,
            'superAdmin' => $user->isSuperAdmin(),
            // Pilihan pemilik hanya untuk Super Admin (perusahaan & cabang Admin Full); user cabang selalu cabangnya sendiri,
            // admin pusat lain hanya melihat
            'pemilikOptions' => $lokasiUser || ! $user->isSuperAdmin() ? [] : Lokasi::where('admin_full', true)->orderBy('nama_lokasi')->get(['id', 'nama_lokasi'])
                ->map(fn (Lokasi $l) => ['value' => $l->id, 'label' => "{$l->nama_lokasi} (Admin Full)"])
                ->prepend(['value' => 'perusahaan', 'label' => 'Perusahaan (Utama-nya dipakai cabang non Admin Full)'])
                ->values(),
            'perusahaanDariEnv' => $lokasiUser === null && ! PaymentGateway::whereNull('id_lokasi')->exists() && (new MidtransClient)->aktif(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request, null);
        // User cabang selalu untuk cabangnya sendiri, apa pun yang dikirim form
        $idLokasi = $user->id_lokasi ?? ($data['id_lokasi'] === 'perusahaan' ? null : (int) $data['id_lokasi']);
        $this->pastikanBolehKelola($user, $idLokasi);

        $gateway = new PaymentGateway([
            'provider' => $data['provider'],
            'nama' => $data['nama'],
            // Gateway pertama milik pemilik ini langsung menjadi Utama
            'utama' => ! PaymentGateway::where('kunci_pemilik', PaymentGateway::kunciPemilik($idLokasi))->exists(),
            'id_lokasi' => $idLokasi,
            'produksi' => $data['produksi'],
            'status' => $data['status'],
            'kredensial' => ['server_key' => $data['server_key'], 'signature_key' => $data['signature_key'] ?? null],
        ]);
        $this->ujiKunci($gateway);
        try {
            $gateway->save();
        } catch (UniqueConstraintViolationException) {
            // Dua gateway pertama disimpan bersamaan: yang kalah cukup disimpan sebagai cadangan
            $gateway->utama = false;
            $gateway->save();
        }

        return response()->json(['message' => "Payment gateway {$gateway->labelAudit()} ditambahkan".($gateway->utama ? ' sebagai Utama' : '').' dan kuncinya diterima Midtrans']);
    }

    public function update(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $this->pastikanBolehKelola($request->user(), $gateway->id_lokasi);
        $data = $this->validated($request, $gateway);

        // Kunci yang dikosongkan tetap memakai kunci lama; pemilik & provider tidak bisa diganti
        $kredensial = $gateway->kredensial;
        if (filled($data['server_key'] ?? null)) {
            $kredensial['server_key'] = $data['server_key'];
        }
        if (filled($data['signature_key'] ?? null)) {
            $kredensial['signature_key'] = $data['signature_key'];
        }
        // Satu-satunya gateway boleh dinonaktifkan (menghentikan pembayaran online sementara); bila ada gateway lain,
        // Utama dipindah dulu agar pelanggan tidak kehilangan pembayaran online tanpa sengaja
        if ($gateway->utama && $data['status'] !== 'aktif' && $this->adaGatewayLain($gateway)) {
            throw ValidationException::withMessages(['status' => 'Gateway Utama tidak bisa dinonaktifkan. Jadikan gateway lain sebagai Utama terlebih dahulu.']);
        }
        $gateway->fill(['nama' => $data['nama'], 'produksi' => $data['produksi'], 'status' => $data['status'], 'kredensial' => $kredensial]);
        $this->ujiKunci($gateway);
        $gateway->save();

        return response()->json(['message' => "Payment gateway {$gateway->labelAudit()} diperbarui"]);
    }

    /** Tombol "Jadikan Utama": gateway ini dipakai untuk transaksi baru pemiliknya, Utama sebelumnya menjadi cadangan. */
    public function utama(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $this->pastikanBolehKelola($request->user(), $gateway->id_lokasi);
        abort_if($gateway->status !== 'aktif', 422, 'Hanya gateway Aktif yang bisa dijadikan Utama.');
        if (! $gateway->utama) {
            DB::transaction(function () use ($gateway) {
                PaymentGateway::where('kunci_pemilik', $gateway->kunci_pemilik)->whereKeyNot($gateway->id)->where('utama', true)->get()
                    ->each(fn (PaymentGateway $lama) => $lama->update(['utama' => false]));
                $gateway->update(['utama' => true]);
            });
        }

        return response()->json(['message' => "{$gateway->labelAudit()} sekarang menjadi payment gateway Utama"]);
    }

    public function destroy(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $this->pastikanBolehKelola($request->user(), $gateway->id_lokasi);
        if ($gateway->utama && $this->adaGatewayLain($gateway)) {
            return response()->json(['message' => "{$gateway->labelAudit()} adalah gateway Utama. Jadikan gateway lain sebagai Utama sebelum menghapusnya."], 422);
        }
        if ($gateway->transaksi()->exists()) {
            return response()->json(['message' => "Payment gateway {$gateway->labelAudit()} sudah punya transaksi sehingga tidak bisa dihapus. Ubah statusnya menjadi Tidak Aktif."], 422);
        }
        $gateway->delete();

        return response()->json(['message' => "Payment gateway {$gateway->labelAudit()} dihapus"]);
    }

    /** Tombol "Uji koneksi": cek kunci yang tersimpan ke Midtrans. */
    public function uji(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $this->pastikanBolehKelola($request->user(), $gateway->id_lokasi);
        $this->ujiKunci($gateway, wajib: true);
        $gateway->save();

        return response()->json(['message' => "Koneksi {$gateway->labelAudit()} berhasil: kunci diterima Midtrans (".($gateway->produksi ? 'Production' : 'Sandbox').')']);
    }

    private function adaGatewayLain(PaymentGateway $gateway): bool
    {
        return PaymentGateway::where('kunci_pemilik', $gateway->kunci_pemilik)->whereKeyNot($gateway->id)->exists();
    }

    /**
     * Gateway menentukan ke rekening mana uang pelanggan masuk, jadi yang boleh mengubahnya dibatasi:
     * - perusahaan: hanya Super Admin pusat;
     * - cabang Admin Full: pemilik cabang itu sendiri (user cabangnya) atau Super Admin. Admin pusat lain hanya melihat,
     *   agar tidak bisa mengalihkan pembayaran milik mitra.
     */
    private function bolehKelola(User $user, ?int $idLokasi): bool
    {
        if ($idLokasi === null) {
            return $user->id_lokasi === null && $user->isSuperAdmin();
        }
        if (! Lokasi::whereKey($idLokasi)->value('admin_full')) {
            return false;
        }

        return $user->id_lokasi === $idLokasi || ($user->id_lokasi === null && $user->isSuperAdmin());
    }

    private function pastikanBolehKelola(User $user, ?int $idLokasi): void
    {
        abort_unless($this->bolehKelola($user, $idLokasi), 403, $idLokasi === null
            ? 'Payment gateway perusahaan hanya bisa diatur Super Admin.'
            : 'Payment gateway cabang Admin Full hanya bisa diatur pemilik cabangnya atau Super Admin.');
    }

    /**
     * Kunci harus cocok dengan mode lalu diuji ke Midtrans. Uji dilewati hanya untuk gateway nonaktif yang kunci & modenya
     * tidak berubah (mis. sekadar menonaktifkan). Gagal: tidak ada yang disimpan.
     */
    private function ujiKunci(PaymentGateway $gateway, bool $wajib = false): void
    {
        $kunci = (string) ($gateway->kredensial['server_key'] ?? '');
        if (! MidtransClient::kunciSesuaiMode($kunci, $gateway->produksi)) {
            throw ValidationException::withMessages(['server_key' => 'Server Key Sandbox diawali "SB-", Server Key Production tidak. Sesuaikan kunci dengan pilihan mode.']);
        }
        if (! $wajib && $gateway->status !== 'aktif' && ! $gateway->isDirty(['kredensial', 'produksi'])) {
            return;
        }
        try {
            $diterima = $gateway->client()->ujiKoneksi();
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['server_key' => 'Tidak bisa menghubungi Midtrans untuk menguji kunci. Periksa koneksi server lalu coba lagi.']);
        }
        if (! $diterima) {
            throw ValidationException::withMessages(['server_key' => 'Server Key ditolak Midtrans. Periksa kembali kunci dan mode (Sandbox/Production).']);
        }
        $gateway->diuji_at = now();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PaymentGateway $gateway): array
    {
        $baru = $gateway === null;
        // Kunci Midtrans hanya huruf, angka, dan - _ : . (tanpa spasi atau tanda kutip yang biasanya salah tempel)
        $kunci = ['string', 'max:200', 'regex:/^[A-Za-z0-9_\-:.]+$/'];

        return $request->validate([
            'provider' => [Rule::requiredIf($baru), Rule::in(array_keys(PaymentGateway::PROVIDER))],
            // Pembeda antar gateway milik pemilik yang sama, mis. "Midtrans Utama", "Midtrans Cadangan"
            'nama' => ['required', 'string', 'max:60'],
            // User pusat: perusahaan atau cabang Admin Full (user cabang diabaikan, selalu cabangnya sendiri)
            'id_lokasi' => $baru && $request->user()->id_lokasi === null
                ? ['required', Rule::in(Lokasi::where('admin_full', true)->pluck('id')->map(fn (int $id) => (string) $id)->push('perusahaan')->all())]
                : ['nullable'],
            'produksi' => ['required', 'boolean'],
            'server_key' => [$baru ? 'required' : 'nullable', ...$kunci],
            'signature_key' => ['nullable', ...$kunci],
            'status' => ['required', Rule::in(['aktif', 'tidak_aktif'])],
            'password' => ['required', 'current_password'],
        ], [
            ...$this->pesanValidasi(),
            'server_key.regex' => 'Server Key hanya berisi huruf, angka, dan tanda - _ : . (periksa spasi atau tanda kutip)',
            'signature_key.regex' => 'Signature Key hanya berisi huruf, angka, dan tanda - _ : . (periksa spasi atau tanda kutip)',
            'password.current_password' => 'Password salah',
        ], [
            'provider' => 'Provider', 'nama' => 'Nama gateway', 'id_lokasi' => 'Pemilik gateway', 'produksi' => 'Mode', 'server_key' => 'Server Key',
            'signature_key' => 'Signature Key', 'status' => 'Status', 'password' => 'Password Anda',
        ]);
    }
}
