<?php

namespace Tests\Unit;

use App\Services\Mikrotik\RouterOsApi;
use App\Services\Mikrotik\RouterOsException;
use PHPUnit\Framework\TestCase;

/** Protokol RouterOS API diuji lewat pasangan socket: sisi "server" meniru balasan Mikrotik. */
class RouterOsApiTest extends TestCase
{
    /** @var resource */
    private $server;

    private RouterOsApi $api;

    protected function setUp(): void
    {
        [$client, $this->server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->api = new RouterOsApi($client);
    }

    public function test_command_sends_attributes_and_query_and_parses_rows(): void
    {
        $this->reply(['!re', '=.id=*1', '=name=budi', '=comment=a=b'], ['!re', '=.id=*2', '=name=sari'], ['!done']);

        $rows = $this->api->command('/ppp/secret/print', ['.proplist' => '.id,name'], ['name' => 'budi']);

        $this->assertSame([['.id' => '*1', 'name' => 'budi', 'comment' => 'a=b'], ['.id' => '*2', 'name' => 'sari']], $rows);
        $this->assertSame(['/ppp/secret/print', '=.proplist=.id,name', '?name=budi'], $this->sentSentence());
    }

    public function test_long_words_use_multi_byte_length_encoding(): void
    {
        $long = str_repeat('x', 300);
        $this->reply(['!re', "=comment=$long"], ['!done']);

        $rows = $this->api->command('/ppp/secret/set', ['comment' => $long]);

        $this->assertSame($long, $rows[0]['comment']);
        $this->assertSame(['/ppp/secret/set', "=comment=$long"], $this->sentSentence());
    }

    public function test_trap_is_thrown_as_command_error(): void
    {
        $this->reply(['!trap', '=message=input does not match any value of profile'], ['!done']);

        try {
            $this->api->command('/ppp/secret/set', ['.id' => '*1', 'profile' => 'TIDAKADA']);
            $this->fail('Seharusnya melempar exception');
        } catch (RouterOsException $e) {
            $this->assertSame('perintah', $e->jenis);
            $this->assertSame('input does not match any value of profile', $e->getMessage());
        }
    }

    public function test_login_modern_sends_plain_credentials(): void
    {
        $this->reply(['!done']);

        $this->api->login('billing', 'rahasia');

        $this->assertSame(['/login', '=name=billing', '=password=rahasia'], $this->sentSentence());
    }

    public function test_login_legacy_answers_md5_challenge(): void
    {
        $challenge = '0123456789abcdef0123456789abcdef';
        $this->reply(['!done', "=ret=$challenge"], ['!done']);

        $this->api->login('billing', 'rahasia');

        $this->sentSentence();
        $this->assertSame(['/login', '=name=billing', '=response=00'.md5(chr(0).'rahasia'.hex2bin($challenge))], $this->sentSentence());
    }

    public function test_login_failure_is_reported_as_login_error(): void
    {
        $this->reply(['!trap', '=message=invalid user name or password (6)'], ['!done']);

        $this->expectExceptionObject(RouterOsException::login('Login API gagal: invalid user name or password (6)'));
        $this->api->login('billing', 'salah');
    }

    public function test_closed_connection_is_reported_as_connection_error(): void
    {
        fclose($this->server);

        try {
            $this->api->command('/system/identity/print');
            $this->fail('Seharusnya melempar exception');
        } catch (RouterOsException $e) {
            $this->assertSame('koneksi', $e->jenis);
        }
    }

    public function test_unreachable_host_is_reported_as_connection_error(): void
    {
        try {
            RouterOsApi::connect('127.0.0.1', 1, false, 2);
            $this->fail('Seharusnya melempar exception');
        } catch (RouterOsException $e) {
            $this->assertSame('koneksi', $e->jenis);
            $this->assertStringContainsString('127.0.0.1:1', $e->getMessage());
        }
    }

    private function reply(array ...$sentences): void
    {
        foreach ($sentences as $words) {
            $data = '';
            foreach ($words as $word) {
                $data .= $this->encodeLength(strlen($word)).$word;
            }
            fwrite($this->server, $data."\0");
        }
    }

    private function sentSentence(): array
    {
        $words = [];
        while (($length = $this->readLength()) > 0) {
            $words[] = fread($this->server, $length);
        }

        return $words;
    }

    private function encodeLength(int $length): string
    {
        return $length < 0x80 ? chr($length) : pack('n', $length | 0x8000);
    }

    private function readLength(): int
    {
        $byte = ord(fread($this->server, 1));

        return ($byte & 0x80) === 0 ? $byte : (($byte & 0x3F) << 8) | ord(fread($this->server, 1));
    }
}
