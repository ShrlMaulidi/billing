<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsolirTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_isolir_page_shows_saved_settings_without_login(): void
    {
        $this->actingAs(User::factory()->create())->putJson('/isolir/pengaturan', [
            'isolir_nama_isp' => 'MSS Net', 'isolir_pesan' => 'Tagihan belum dibayar.', 'isolir_whatsapp' => '0812-3456-789',
            'isolir_pembayaran' => "BCA 123 a.n. <MSS>\nDANA 0812",
        ])->assertOk();
        auth()->logout();

        $this->get('/isolir')->assertOk()
            ->assertSee('MSS Net')->assertSee('Tagihan belum dibayar.')
            ->assertSee('https://wa.me/628123456789', false)
            ->assertSee('BCA 123 a.n. &lt;MSS&gt;', false);
    }

    public function test_settings_require_login_and_valid_input(): void
    {
        $this->putJson('/isolir/pengaturan', [])->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->putJson('/isolir/pengaturan', ['isolir_nama_isp' => '', 'isolir_pesan' => 'x', 'isolir_whatsapp' => 'wa saya'])
            ->assertStatus(422)->assertJsonValidationErrors(['isolir_nama_isp', 'isolir_whatsapp']);
    }

    public function test_index_uses_router_isolir_profile_and_builds_three_scripts(): void
    {
        Router::create([
            'nama_router' => 'RIFKY-GARUT', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'x', 'profile_isolir' => 'ISOLIREBILLING',
        ]);
        config(['app.url' => 'http://38.44.252.4']);

        $this->actingAs(User::factory()->create())->get('/kelola-data/template-isolir')->assertInertia(fn ($page) => $page
            ->component('KelolaData/TemplateIsolir')
            ->where('opsi.profile', 'ISOLIREBILLING')
            ->where('opsi.url_isolir', 'http://38.44.252.4/isolir')
            ->has('script.static')->has('script.pppoe')->has('script.hotspot'));
    }

    public function test_pppoe_script_creates_pool_profile_redirect_and_block_rules(): void
    {
        $script = $this->script(['alamat_izin' => "checkout.xendit.co\ntripay.co.id"])['pppoe'];

        $this->assertStringContainsString('/ip firewall address-list add list=ISOLIR-ALLOW address="38.44.252.4" comment="isolir-mss"', $script);
        $this->assertStringContainsString('address="checkout.xendit.co"', $script);
        $this->assertStringContainsString('/ip proxy set enabled=yes port=8099', $script);
        $this->assertStringContainsString('/ip proxy access add action=deny redirect-to="38.44.252.4/isolir" comment="isolir-mss"', $script);
        $this->assertStringContainsString('chain=dstnat src-address-list=ISOLIR dst-address-list=!ISOLIR-ALLOW protocol=tcp dst-port=80 action=redirect to-ports=8099', $script);
        $this->assertStringContainsString('/ip pool add name="ISOLIREBILLING" ranges=192.168.200.2-192.168.200.254', $script);
        $this->assertStringContainsString('/ppp profile add name="ISOLIREBILLING" local-address=192.168.200.1 remote-address="ISOLIREBILLING" address-list=ISOLIR', $script);
        $this->assertStringContainsString('chain=srcnat src-address=192.168.200.0/24 action=masquerade', $script);
        $this->assertStringNotContainsString('ebilling.id', $script);

        // Tiap aturan disisipkan paling atas, jadi di script urutannya terbalik dari urutan akhir di firewall
        $this->assertLessThan(
            strpos($script, 'dst-address-list=ISOLIR-ALLOW action=accept'),
            strpos($script, 'src-address-list=ISOLIR action=drop'),
        );
    }

    public function test_hotspot_and_static_scripts_use_their_own_isolation_step(): void
    {
        $script = $this->script(['jaringan' => '10.50.0.0/22']);

        $this->assertStringContainsString('/ip hotspot user profile add name="ISOLIREBILLING" address-pool="ISOLIREBILLING" address-list=ISOLIR', $script['hotspot']);
        $this->assertStringContainsString('ranges=10.50.0.2-10.50.3.254', $script['hotspot']);
        $this->assertStringContainsString('/ip firewall address-list add list=ISOLIR address=IP_PELANGGAN', $script['static']);
        $this->assertStringNotContainsString('/ppp profile', $script['static']);
    }

    public function test_script_options_are_validated(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/isolir/script', [
            'profile' => 'ISOLIR BARU', 'jaringan' => '192.168.200.0/30', 'port_proxy' => 80, 'url_isolir' => 'bukan-url',
        ])->assertStatus(422)->assertJsonValidationErrors(['profile', 'jaringan', 'port_proxy', 'url_isolir']);

        $this->postJson('/isolir/script', [...$this->opsi(), 'alamat_izin' => "tripay.co.id\nbad;host"])
            ->assertStatus(422)->assertJsonValidationErrors(['alamat_izin' => 'Alamat tidak valid: bad;host']);
    }

    /** @return array{static: string, pppoe: string, hotspot: string} */
    private function script(array $attrs = []): array
    {
        return $this->actingAs(User::factory()->create())
            ->postJson('/isolir/script', [...$this->opsi(), ...$attrs])->assertOk()->json('script');
    }

    private function opsi(): array
    {
        return ['profile' => 'ISOLIREBILLING', 'jaringan' => '192.168.200.0/24', 'port_proxy' => 8099, 'url_isolir' => 'http://38.44.252.4/isolir'];
    }
}
