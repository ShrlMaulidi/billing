<?php

namespace App\Services\Mikrotik;

/**
 * Klien RouterOS API (port 8728, atau 8729 dengan SSL) tanpa ekstensi tambahan.
 * Protokol: perintah dikirim sebagai "sentence" berisi "word" berawalan panjang,
 * balasan berupa !re (data), !done (selesai), !trap (ditolak) atau !fatal.
 */
class RouterOsApi
{
    /** @param resource $stream */
    public function __construct(private $stream) {}

    public static function connect(string $host, int $port, bool $ssl = false, int $timeout = 5): self
    {
        // Sertifikat API-SSL Mikrotik umumnya self-signed
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $stream = @stream_socket_client(($ssl ? 'tls' : 'tcp')."://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $stream) {
            throw RouterOsException::koneksi("Tidak dapat terhubung ke $host:$port".($errstr ? " ($errstr)" : ''));
        }
        stream_set_timeout($stream, $timeout);

        return new self($stream);
    }

    public function login(string $username, string $password): void
    {
        try {
            $reply = $this->talk(['/login', "=name=$username", "=password=$password"]);
            // RouterOS < 6.43 membalas dengan challenge (=ret=) yang harus dijawab MD5
            if (isset($reply['done']['ret'])) {
                $this->talk(['/login', "=name=$username", '=response=00'.md5(chr(0).$password.hex2bin($reply['done']['ret']))]);
            }
        } catch (RouterOsException $e) {
            throw $e->jenis === 'koneksi' ? $e : RouterOsException::login('Login API gagal: '.$e->getMessage());
        }
    }

    /**
     * Jalankan perintah, mis. command('/ppp/secret/set', ['.id' => '*1', 'profile' => 'ISOLIR']).
     *
     * @return array<int, array<string, string>> baris !re
     */
    public function command(string $path, array $attributes = [], array $query = []): array
    {
        $words = [$path];
        foreach ($attributes as $key => $value) {
            $words[] = "=$key=$value";
        }
        foreach ($query as $key => $value) {
            $words[] = "?$key=$value";
        }

        return $this->talk($words)['rows'];
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return array{rows: array<int, array<string, string>>, done: array<string, string>} */
    private function talk(array $words): array
    {
        $this->writeSentence($words);
        $rows = [];
        $trap = null;
        while (true) {
            [$type, $attrs] = $this->readSentence();
            if ($type === '!re') {
                $rows[] = $attrs;
            } elseif ($type === '!trap') {
                $trap ??= $attrs['message'] ?? 'Perintah ditolak router';
            } elseif ($type === '!fatal') {
                throw RouterOsException::koneksi('Router menutup koneksi: '.($attrs['message'] ?? 'fatal'));
            } elseif ($type === '!done') {
                if ($trap !== null) {
                    throw new RouterOsException($trap);
                }

                return ['rows' => $rows, 'done' => $attrs];
            }
        }
    }

    private function writeSentence(array $words): void
    {
        $data = '';
        foreach ($words as $word) {
            $data .= $this->encodeLength(strlen($word)).$word;
        }
        $data .= "\0";
        if (@fwrite($this->stream, $data) !== strlen($data)) {
            throw RouterOsException::koneksi('Gagal mengirim perintah ke router');
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function readSentence(): array
    {
        $type = null;
        $attrs = [];
        while (($length = $this->readLength()) > 0) {
            $word = $this->read($length);
            if ($type === null) {
                $type = $word;
            } elseif ($word[0] === '=') {
                // "=key=value": nilai boleh mengandung "="
                [$key, $value] = array_pad(explode('=', substr($word, 1), 2), 2, '');
                $attrs[$key] = $value;
            }
        }

        return [$type ?? '', $attrs];
    }

    private function encodeLength(int $length): string
    {
        return match (true) {
            $length < 0x80 => chr($length),
            $length < 0x4000 => pack('n', $length | 0x8000),
            $length < 0x200000 => substr(pack('N', $length | 0xC00000), 1),
            $length < 0x10000000 => pack('N', $length | 0xE0000000),
            default => chr(0xF0).pack('N', $length),
        };
    }

    private function readLength(): int
    {
        $byte = ord($this->read(1));

        return match (true) {
            ($byte & 0x80) === 0x00 => $byte,
            ($byte & 0xC0) === 0x80 => (($byte & 0x3F) << 8) | ord($this->read(1)),
            ($byte & 0xE0) === 0xC0 => (($byte & 0x1F) << 16) | unpack('n', $this->read(2))[1],
            ($byte & 0xF0) === 0xE0 => (($byte & 0x0F) << 24) | (unpack('N', "\0".$this->read(3))[1]),
            default => unpack('N', $this->read(4))[1],
        };
    }

    private function read(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->stream, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $timedOut = stream_get_meta_data($this->stream)['timed_out'] ?? false;
                throw RouterOsException::koneksi($timedOut ? 'Router tidak merespons (timeout)' : 'Koneksi ke router terputus');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
