<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TransaksiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['/transaksi/penarikan-saldo', 'Transaksi/Penarikan'])]
    #[TestWith(['/transaksi/setor-kewajiban', 'Transaksi/Setor'])]
    #[TestWith(['/transaksi/pengeluaran', 'Transaksi/Pengeluaran'])]
    public function test_transaction_menu_renders_no_rows(string $url, string $component): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component)->has('rows', 0));
    }
}
