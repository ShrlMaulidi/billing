<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien layanan wa-gateway (Node.js, 127.0.0.1) yang memegang satu sesi WhatsApp per cabang. Sesi bernama "cabang-{id}",
 * jadi setiap cabang hanya memakai WhatsApp yang di-scan di cabangnya sendiri.
 *
 * Status sesi: disconnected (belum terhubung) | connecting | qr (menunggu scan) | connected, ditambah "offline" bila
 * layanan gateway tidak bisa dihubungi / belum dikonfigurasi.
 */
class WaGateway
{
    /** Kunci rahasia sudah diisi di .env (WA_GATEWAY_KEY). */
    public function dikonfigurasi(): bool
    {
        return strlen((string) config('services.wa_gateway.key')) >= 32;
    }

    /**
     * Status semua sesi, dikunci per id_lokasi.
     *
     * @return array<int, array{status: string, nomor: ?string, nama: ?string, sejak: ?string, galat: ?string}>|null null = gateway offline
     */
    public function semuaStatus(): ?array
    {
        if (! $this->dikonfigurasi()) {
            return null;
        }
        try {
            $respons = $this->http()->get('/sessions');
        } catch (ConnectionException) {
            return null;
        }
        if (! $respons->ok()) {
            return null;
        }

        return collect($respons->json('sessions', []))
            ->filter(fn ($s) => preg_match('/^cabang-(\d+)$/', (string) ($s['id'] ?? '')))
            ->mapWithKeys(fn ($s) => [(int) substr($s['id'], 7) => $this->rapikan($s)])
            ->all();
    }

    /** @return array{status: string, qr: ?string, nomor: ?string, nama: ?string, sejak: ?string, galat: ?string} */
    public function status(int $idLokasi): array
    {
        return $this->panggil(fn () => $this->http()->get('/sessions/'.$this->sesi($idLokasi)), true);
    }

    /** Mulai sesi; bila belum login, QR muncul di status beberapa detik kemudian. */
    public function hubungkan(int $idLokasi): array
    {
        return $this->panggil(fn () => $this->http()->post('/sessions/'.$this->sesi($idLokasi).'/start'), true);
    }

    public function putuskan(int $idLokasi): void
    {
        $this->panggil(fn () => $this->http()->post('/sessions/'.$this->sesi($idLokasi).'/logout'));
    }

    /**
     * Kirim pesan teks ke nomor 62… lewat WhatsApp cabang.
     *
     * @return string|null ID pesan WhatsApp
     */
    public function kirim(int $idLokasi, string $nomor62, string $teks): ?string
    {
        return $this->panggil(fn () => $this->http()->post('/sessions/'.$this->sesi($idLokasi).'/send', ['to' => $nomor62, 'text' => $teks]))['id'] ?? null;
    }

    private function sesi(int $idLokasi): string
    {
        return 'cabang-'.$idLokasi;
    }

    /** Jalankan permintaan; gagal koneksi / ditolak gateway → GagalWhatsApp dengan pesan yang bisa ditampilkan. */
    private function panggil(callable $minta, bool $status = false): array
    {
        if (! $this->dikonfigurasi()) {
            throw new GagalWhatsApp('Layanan WhatsApp belum dikonfigurasi di server (WA_GATEWAY_KEY).');
        }
        try {
            /** @var Response $respons */
            $respons = $minta();
        } catch (ConnectionException $e) {
            Log::warning('WA gateway tidak bisa dihubungi', ['error' => $e->getMessage()]);
            throw new GagalWhatsApp('Layanan WhatsApp di server sedang tidak berjalan. Hubungi admin pusat.');
        }
        if ($respons->status() === 401) {
            Log::error('WA gateway menolak kunci (WA_GATEWAY_KEY berbeda dengan gateway)');
            throw new GagalWhatsApp('Layanan WhatsApp menolak koneksi dari billing (kunci tidak cocok). Hubungi admin pusat.');
        }
        if (! $respons->successful()) {
            throw new GagalWhatsApp((string) ($respons->json('message') ?: 'WhatsApp gagal memproses permintaan ('.$respons->status().')'));
        }

        return $status ? $this->rapikan($respons->json()) : (array) $respons->json();
    }

    /** @return array{status: string, qr: ?string, nomor: ?string, nama: ?string, sejak: ?string, galat: ?string} */
    private function rapikan(array $s): array
    {
        return [
            'status' => in_array($s['status'] ?? null, ['disconnected', 'connecting', 'qr', 'connected'], true) ? $s['status'] : 'disconnected',
            'qr' => isset($s['qr']) ? (string) $s['qr'] : null,
            'nomor' => isset($s['nomor']) ? (string) $s['nomor'] : null,
            'nama' => isset($s['nama']) ? (string) $s['nama'] : null,
            'sejak' => $s['sejak'] ?? null,
            'galat' => isset($s['galat']) ? (string) $s['galat'] : null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.wa_gateway.url'), '/'))
            ->withHeaders(['X-Api-Key' => (string) config('services.wa_gateway.key')])
            ->acceptJson()->asJson()->timeout(config('services.wa_gateway.timeout', 20))->connectTimeout(3);
    }
}
