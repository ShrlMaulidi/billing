<?php

namespace Tests\Unit;

use App\Support\RouterOsScript;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class RouterOsScriptTest extends TestCase
{
    #[TestWith(['budi', '"budi"'])]
    #[TestWith(['a"b\\c$d?e', '"a\"b\\\\c\$d\?e"'])]
    #[TestWith(["x\n/system reset-configuration", '"x\0A/system reset-configuration"'])]
    #[TestWith(["a\r\tb\x00\x7F", '"a\0D\09b\00\7F"'])]
    public function test_quote_escapes_special_and_control_characters_into_one_line(string $nilai, string $hasil): void
    {
        $this->assertSame($hasil, RouterOsScript::quote($nilai));
    }
}
