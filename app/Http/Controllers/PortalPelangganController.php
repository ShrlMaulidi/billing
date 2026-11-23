<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Rekening;
use App\Models\Tiket;
use App\Models\TransaksiMidtrans;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Dashboard pelanggan (role Pelanggan, prefix /akun-saya, tampilan template Cuba): Home berisi tagihan + Bayar Sekarang,
 * Riwayat Tagihan, Tiket (PortalTiketController), dan Akun. Data hanya milik pelanggan yang memakai akun ini
 * (pelanggan.user_id); tagihan memakai data yang sama dengan Cek Tagihan, dengan nama tampil lengkap.
 */
class PortalPelangganController extends Controller
{
    /** Batas baris riwayat pembayaran (±5 tahun langganan bulanan untuk beberapa langganan). */
    private const MAKS_RIWAYAT = 120;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $pelanggan = CekTagihanController::query()->where('user_id', $user->id)->orderBy('nama')->get();

        return Inertia::render('Portal/Home', [
            'hasil' => CekTagihanController::hasil($pelanggan, samarkanNama: false),
            'tiketAktif' => Tiket::where('user_id', $user->id)->where('status', '!=', 'selesai')->count(),
            // Tagihan bulan berjalan tampil paling atas; tanggal dari server agar hitungan jatuh tempo tidak bergantung jam HP
            'bulanIni' => today()->startOfMonth()->toDateString(),
            'hariIni' => today()->toDateString(),
        ]);
    }

    /** Semua pembayaran lunas milik pelanggan akun ini (termasuk langganan yang sudah berhenti), terbaru dulu. */
    public function riwayat(Request $request): Response
    {
        $pelanggan = Pelanggan::where('user_id', $request->user()->id)->orderBy('nama')->get(['id', 'id_pelanggan', 'nama']);
        $rows = Pembayaran::with(['rekening:id,nama_rekening', 'transaksiMidtrans:id,pembayaran_id,payment_type'])
            ->whereIn('pelanggan_id', $pelanggan->pluck('id'))
            ->orderByDesc('periode')->orderByDesc('id')->limit(self::MAKS_RIWAYAT)->get()
            ->map(fn (Pembayaran $b) => [
                ...CekTagihanController::barisLunas($b),
                'nama_paket' => $b->nama_paket,
                'pelanggan' => $pelanggan->firstWhere('id', $b->pelanggan_id)?->only(['id_pelanggan', 'nama']),
            ]);

        return Inertia::render('Portal/Riwayat', ['rows' => $rows, 'jumlahLangganan' => $pelanggan->count()]);
    }

    /**
     * Invoice periode yang sudah lunas (cap LUNAS). Nominal & nama paket diambil dari catatan pembayaran (bukan paket saat ini),
     * jatuh tempo & rincian prorate dari data pelanggan.
     */
    public function invoice(Request $request, Pembayaran $pembayaran): Response
    {
        $pelanggan = $this->pembayaranMilik($request, $pembayaran);
        $pelanggan->load('paket:id,nama_paket,harga');
        $pembayaran->load('lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi');

        return Inertia::render('Portal/Dokumen', [
            'jenis' => 'invoice',
            'pembayaranId' => $pembayaran->id,
            'tagihan' => [
                ...$pelanggan->invoice($pembayaran->periodePembayaran()),
                'periode' => $pembayaran->periode,
                'nama_paket' => $pembayaran->nama_paket,
                'nominal' => $pembayaran->harga,
                'is_prorate' => $pembayaran->is_prorate,
                ...$pelanggan->only(['id_pelanggan', 'nama', 'alamat']),
                'jns_tagihan' => $pembayaran->jns_tagihan,
                'cabang' => $pembayaran->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
                'rekening' => Rekening::where('tampil_invoice', true)->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $pembayaran->id_lokasi))
                    ->orderBy('nama_rekening')->get()->map->only(['nama_rekening', 'no_rekening', 'atas_nama']),
                'pembayaran_id' => $pembayaran->id,
            ],
        ]);
    }

    /** Kuitansi (bukti pembayaran) satu periode lunas, sama dengan kwitansi di dashboard staf tanpa nama petugas. */
    public function kuitansi(Request $request, Pembayaran $pembayaran): Response
    {
        $pelanggan = $this->pembayaranMilik($request, $pembayaran);
        $pembayaran->load(['lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi', 'rekening', 'transaksiMidtrans:id,pembayaran_id,payment_type']);

        return Inertia::render('Portal/Dokumen', [
            'jenis' => 'kuitansi',
            'pembayaranId' => $pembayaran->id,
            'kwitansi' => [
                ...$pembayaran->only(['no_invoice', 'periode', 'jns_tagihan', 'nama_pelanggan', 'nama_paket', 'harga', 'is_prorate', 'diskon_tipe', 'diskon_nilai', 'diskon', 'jml_bayar', 'metode', 'paid_at']),
                'periode_pembayaran' => $pembayaran->periodePembayaran(),
                'id_pelanggan' => $pelanggan->id_pelanggan,
                'alamat' => $pelanggan->alamat,
                'cabang' => $pembayaran->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
                'rekening' => $pembayaran->rekening?->label(),
                'metode_label' => $pembayaran->keteranganMetode(),
            ],
        ]);
    }

    /**
     * Hasil pembayaran online setelah kembali dari Midtrans (dialihkan dari /cek-tagihan/selesai bila pelanggan login).
     * Status sudah disinkronkan dengan Midtrans saat pengalihan; transaksi milik pelanggan lain dijawab 404.
     */
    public function pembayaran(Request $request): Response
    {
        $transaksi = TransaksiMidtrans::with(['pelanggan:id,nama,id_pelanggan,user_id', 'pembayaran:id', 'gateway:id,provider'])->firstWhere('order_id', (string) $request->query('order_id'));
        abort_unless($transaksi && $transaksi->pelanggan?->user_id === $request->user()->id, 404);

        return Inertia::render('Portal/PembayaranSelesai', [
            'transaksi' => [
                'status' => in_array($transaksi->status, TransaksiMidtrans::LUNAS, true) ? 'lunas' : $transaksi->status,
                'nominal' => $transaksi->gross_amount,
                'periode' => $transaksi->periode,
                'nama' => $transaksi->pelanggan->nama,
                'id_pelanggan' => $transaksi->pelanggan->id_pelanggan,
                'metode' => TransaksiMidtrans::namaKanal($transaksi->provider(), $transaksi->payment_type),
                'pembayaran_id' => $transaksi->pembayaran?->id,
                // Masih menunggu & belum kedaluwarsa: bisa dilanjutkan di halaman pembayaran Midtrans
                'lanjut_url' => $transaksi->masihBerlaku() ? $transaksi->redirect_url : null,
            ],
        ]);
    }

    /** Data akun & langganan, ganti password, dan pilihan navigasi di HP. */
    public function akun(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Portal/Akun', [
            'akun' => ['nama' => $user->name, 'username' => $user->username, 'last_login_at' => $user->last_login_at?->toDateTimeString()],
            'langganan' => Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi'])->where('user_id', $user->id)->orderBy('nama')->get()
                ->map(fn (Pelanggan $p) => [
                    'id' => $p->id, 'id_pelanggan' => $p->id_pelanggan, 'nama' => $p->nama, 'alamat' => $p->alamat, 'tlp' => $p->tlp, 'status' => $p->status,
                    'paket' => $p->paket?->nama_paket, 'harga' => $p->paket?->harga, 'cabang' => $p->lokasi?->nama_lokasi,
                    'jns_tagihan' => $p->jns_tagihan, 'tgl_jatuh_tempo' => $p->tgl_jatuh_tempo, 'tgl_registrasi' => $p->tgl_registrasi?->toDateString(),
                    // Kartu member hanya untuk langganan aktif (sama dengan menu Kartu Member staf); QR = link Cek Tagihan bertanda tangan
                    'link_kartu' => $p->status === 'aktif' ? $p->linkCekTagihan() : null,
                ]),
        ]);
    }

    /** Foto profil akun ini (disk privat); tidak ada route untuk melihat foto akun lain. */
    public function foto(Request $request): StreamedResponse
    {
        $path = $request->user()->foto;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=31536000']);
    }

    /**
     * Ganti foto profil. Browser sudah memotongnya menjadi persegi kecil (JPEG, tanpa metadata); server tetap hanya menerima
     * gambar JPG/PNG/WebP (bukan SVG) sampai 2 MB. Nama file acak, foto lama dihapus setelah foto baru tersimpan.
     */
    public function unggahFoto(Request $request): JsonResponse
    {
        $request->validate([
            'foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
        ], [
            ...$this->pesanValidasi(),
            'foto.image' => 'File harus berupa gambar',
            'foto.mimes' => 'Foto harus berformat JPG, PNG, atau WebP',
            'foto.max' => 'Ukuran foto maksimal 2 MB',
            'foto.dimensions' => 'Ukuran gambar minimal 64 × 64 piksel',
        ], ['foto' => 'Foto']);

        $user = $request->user();
        $lama = $user->foto;
        $path = $request->file('foto')->store(User::FOLDER_FOTO, 'local');
        try {
            $user->forceFill(['foto' => $path])->save();
        } catch (Throwable $e) {
            // Simpan ke database gagal: jangan tinggalkan file yatim
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($lama) {
            Storage::disk('local')->delete($lama);
        }

        return response()->json(['message' => 'Foto profil diperbarui', 'foto' => $user->urlFoto()]);
    }

    public function hapusFoto(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->foto) {
            Storage::disk('local')->delete($user->foto);
            $user->forceFill(['foto' => null])->save();
        }

        return response()->json(['message' => 'Foto profil dihapus', 'foto' => null]);
    }

    /** Halaman wajib buat password baru setelah login pertama / password di-reset (sebelum bisa membuka Akun Saya). */
    public function passwordBaru(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->wajib_ganti_password) {
            return redirect('/akun-saya');
        }

        return Inertia::render('Portal/PasswordBaru', ['akun' => ['nama' => $request->user()->name, 'username' => $request->user()->username]]);
    }

    /**
     * Ganti password; sesi lain akun ini dikeluarkan. Password lama tidak ditanya saat wajib membuat password baru
     * (baru saja login dengan password awal), selain itu wajib dikonfirmasi.
     */
    public function gantiPassword(Request $request): JsonResponse
    {
        $wajib = $request->user()->wajib_ganti_password;
        $data = $request->validate([
            'password_lama' => [$wajib ? 'nullable' : 'required', 'string', ...($wajib ? [] : ['current_password'])],
            'password' => ['required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed', 'different:password_lama'],
        ], [
            ...$this->pesanValidasi(),
            'current_password' => 'Password lama salah',
            'password.min' => 'Password minimal 8 karakter',
            'password.regex' => 'Password harus berisi huruf dan angka',
            'password.confirmed' => 'Konfirmasi password tidak sama',
            'password.different' => 'Password baru harus berbeda dari password lama',
        ], ['password_lama' => 'Password lama', 'password' => 'Password baru']);

        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'wajib_ganti_password' => false, 'remember_token' => Str::random(60)])->save();
        // Sesi ini tetap masuk, sesi di perangkat lain harus login ulang
        $request->session()->regenerate();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }

        return response()->json(['message' => 'Password berhasil diganti']);
    }

    /** Pelanggan pemilik pembayaran bila memakai akun ini; pembayaran milik orang lain dijawab 404. */
    private function pembayaranMilik(Request $request, Pembayaran $pembayaran): Pelanggan
    {
        $pelanggan = Pelanggan::whereKey($pembayaran->pelanggan_id)->where('user_id', $request->user()->id)->first();
        abort_unless($pelanggan, 404);

        return $pelanggan;
    }
}
