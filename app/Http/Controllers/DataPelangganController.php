<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PelangganInstansi;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Menu Data Klien: daftar semua klien perusahaan (berbeda dengan menu Pelanggan yang berisi pelanggan end user).
 * - Mitra Reseller & Retail / Shafa.net disimpan sebagai cabang (Lokasi) dan disimpan lewat CabangController.
 * - Korporat & Instansi Pemerintah disimpan di pelanggan_instansi (beserta dokumen KTP/NPWP) lewat controller ini.
 * Seluruh route-nya di grup semua_cabang: data ini memuat NIK & dokumen identitas, bukan data cabang.
 */
class DataPelangganController extends Controller
{
    public function index(Request $request): Response
    {
        $semuaTipe = [...array_keys(Lokasi::TIPE), ...array_keys(PelangganInstansi::TIPE)];
        $tipe = in_array($request->input('tipe'), $semuaTipe, true) ? $request->input('tipe') : null;

        $cabang = $tipe === null || isset(Lokasi::TIPE[$tipe])
            ? Lokasi::withCount(['area as jumlah_area', 'pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif')])
                ->when($tipe, fn ($q) => $q->where('tipe', $tipe))
                ->orderBy('kode_lokasi')->get()
                ->map(fn (Lokasi $l) => [
                    ...$l->toArray(), 'key' => "lokasi-{$l->id}", 'sumber' => 'lokasi', 'nama' => $l->nama_lokasi, 'alamat' => $l->alamat_lokasi,
                ])
            : collect();

        $instansi = $tipe === null || isset(PelangganInstansi::TIPE[$tipe])
            ? PelangganInstansi::with('sales:id,name')->when($tipe, fn ($q) => $q->where('tipe', $tipe))->orderBy('nama')->get()
                ->map(fn (PelangganInstansi $p) => [
                    ...$p->toArray(), 'key' => "instansi-{$p->id}", 'sumber' => 'instansi',
                    'ada_ktp' => (bool) $p->file_ktp, 'ada_npwp' => (bool) $p->file_npwp, 'nama_sales' => $p->sales?->name,
                ])
            : collect();

        return Inertia::render('DataPelanggan/Index', [
            'filters' => ['tipe' => $tipe],
            'rows' => $cabang->concat($instansi)->values(),
            'tipeOptions' => collect([...Lokasi::TIPE, ...PelangganInstansi::TIPE])->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'periodeSekarang' => today()->format('Y-m-01'),
            'hariIni' => today()->toDateString(),
            // Korporat & instansi tidak terikat cabang: semua sales aktif bisa dipilih
            'salesOptions' => User::role('Sales')->where('status', 'aktif')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $pelanggan = $this->simpan(new PelangganInstansi, $data, $request);

        return response()->json(['message' => "Pelanggan {$pelanggan->nama} ditambahkan"]);
    }

    public function update(Request $request, PelangganInstansi $instansi): JsonResponse
    {
        $data = $this->validated($request, $instansi);
        $this->simpan($instansi, $data, $request);

        return response()->json(['message' => "Pelanggan {$instansi->nama} diperbarui"]);
    }

    public function destroy(PelangganInstansi $instansi): JsonResponse
    {
        if ($instansi->pembayaran()->exists()) {
            return response()->json(['message' => "Pelanggan {$instansi->nama} sudah punya pembayaran masuk, ubah statusnya menjadi Berhenti"], 422);
        }
        $dokumen = array_filter($instansi->only(PelangganInstansi::DOKUMEN));
        $instansi->delete();
        Storage::disk('local')->delete(array_values($dokumen));

        return response()->json(['message' => "Pelanggan {$instansi->nama} dihapus"]);
    }

    /** File KTP / NPWP dari disk privat; jenis dibatasi pola route (ktp|npwp). */
    public function dokumen(PelangganInstansi $instansi, string $jenis): StreamedResponse
    {
        $path = $instansi->{PelangganInstansi::DOKUMEN[$jenis]};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Simpan data + dokumen baru. Dokumen lama baru dihapus setelah database tersimpan;
     * bila penyimpanan gagal, file yang baru diunggah ikut dihapus agar tidak ada file yatim.
     */
    private function simpan(PelangganInstansi $pelanggan, array $data, Request $request): PelangganInstansi
    {
        $baru = [];
        $lama = [];
        // KTP hanya untuk korporat; instansi pemerintah cukup NPWP
        $jenisDokumen = $data['tipe'] === 'korporat' ? PelangganInstansi::DOKUMEN : Arr::only(PelangganInstansi::DOKUMEN, ['npwp']);
        foreach ($jenisDokumen as $jenis => $kolom) {
            $file = $request->file("file_{$jenis}");
            if ($file instanceof UploadedFile) {
                $baru[$kolom] = $file->store(PelangganInstansi::FOLDER_DOKUMEN, 'local');
                $lama[] = $pelanggan->{$kolom};
            }
        }

        try {
            DB::transaction(fn () => $pelanggan->fill([...Arr::except($data, ['file_ktp', 'file_npwp']), ...$baru])->save());
        } catch (Throwable $e) {
            Storage::disk('local')->delete(array_values($baru));
            throw $e;
        }
        Storage::disk('local')->delete(array_values(array_filter($lama)));

        return $pelanggan;
    }

    private function validated(Request $request, ?PelangganInstansi $instansi = null): array
    {
        // NPWP/NIK boleh diketik dengan titik/strip; disimpan sebagai angka saja
        foreach (['nik', 'npwp'] as $kolom) {
            if (is_string($request->input($kolom))) {
                $request->merge([$kolom => preg_replace('/\D/', '', $request->input($kolom))]);
            }
        }
        $dokumen = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:2048'];

        $data = $request->validate([
            'tipe' => [$instansi ? 'sometimes' : 'required', Rule::in(array_keys(PelangganInstansi::TIPE))],
            'nama' => ['required', 'string', 'max:255'],
            'alamat' => ['required', 'string', 'max:500'],
            'kapasitas' => ['required', 'string', 'max:50'],
            'harga' => ['required', 'integer', 'min:0', 'max:4000000000'],
            'metro' => ['nullable', 'string', 'max:100'],
            'nik' => ['nullable', 'digits:16'],
            'npwp' => ['nullable', 'regex:/^(\d{15}|\d{16})$/'],
            'file_ktp' => $dokumen,
            'file_npwp' => $dokumen,
            'tanggal_aktif' => ['required', 'date'],
            'tanggal_berhenti' => ['nullable', 'required_if:status,berhenti', 'date', 'after_or_equal:tanggal_aktif'],
            'jns_tagihan' => ['required', 'in:prabayar,pascabayar'],
            'status' => ['required', 'in:berlangganan,berhenti'],
            // Opsional. Sales lama yang sudah nonaktif tetap boleh dipertahankan saat edit
            'sales_id' => ['nullable', 'integer', Rule::in(User::role('Sales')->where('status', 'aktif')->pluck('id')->push($instansi?->sales_id)->filter())],
        ], [
            ...$this->pesanValidasi(),
            'npwp.regex' => 'NPWP harus 15 atau 16 digit angka',
            'mimes' => ':attribute harus berformat JPG, PNG, WEBP, atau PDF',
            'file_ktp.max' => 'Ukuran file KTP maksimal 2 MB',
            'file_npwp.max' => 'Ukuran file NPWP maksimal 2 MB',
            'harga.max' => 'Harga terlalu besar',
            'file' => ':attribute harus berupa file',
            'tanggal_berhenti.after_or_equal' => 'Tanggal berhenti tidak boleh sebelum tanggal aktif',
        ], [
            'tipe' => 'Tipe klien', 'nama' => 'Nama', 'alamat' => 'Alamat', 'kapasitas' => 'Kapasitas', 'harga' => 'Harga', 'metro' => 'Metro',
            'nik' => 'NIK', 'npwp' => 'NPWP', 'file_ktp' => 'File KTP', 'file_npwp' => 'File NPWP', 'tanggal_aktif' => 'Tanggal aktif', 'tanggal_berhenti' => 'Tanggal berhenti',
            'jns_tagihan' => 'Jenis tagihan', 'status' => 'Status', 'sales_id' => 'Sales',
        ]);

        // Tanggal berhenti hanya berlaku untuk pelanggan yang berhenti
        if ($data['status'] === 'berlangganan') {
            $data['tanggal_berhenti'] = null;
        }
        // Tipe klien tidak bisa diganti lewat edit; Metro, NIK & KTP hanya untuk korporat
        $tipe = $instansi?->tipe ?? $data['tipe'];
        unset($data['tipe']);
        if ($tipe === 'pemerintah') {
            $data = [...$data, 'metro' => null, 'nik' => null];
        }

        return [...$data, 'tipe' => $tipe];
    }
}
