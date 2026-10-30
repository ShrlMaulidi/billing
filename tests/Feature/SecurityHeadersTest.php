<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Hasil test tidak bergantung pada `npm run dev` yang sedang berjalan (CSP dilewati saat Vite hot)
        Vite::useHotFile(storage_path('framework/testing/vite.hot'));
    }

    public function test_pages_send_security_headers_and_csp_nonce_matches_inline_script(): void
    {
        $response = $this->get('/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', $response->getContent());
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_csp_can_be_disabled_by_config(): void
    {
        config(['app.csp' => false]);

        $this->get('/login')->assertOk()->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
