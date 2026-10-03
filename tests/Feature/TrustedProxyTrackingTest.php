<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotating_untrusted_headers_cannot_reset_the_shared_tracking_budget(): void
    {
        config(['trustedproxy.proxies' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => long2ip(0xC0000201)]);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $prefix = $attempt % 2 ? '/track/' : '/api/track/';
            $this->withHeader('X-Forwarded-For', long2ip(0xC6336401 + $attempt))
                ->getJson($prefix.'BGO-PROXY-MISSING')->assertNotFound();
        }

        foreach (['/track/', '/api/track/'] as $prefix) {
            $this->withHeader('X-Forwarded-For', long2ip(0xCB007101))
                ->getJson($prefix.'BGO-PROXY-MISSING')->assertStatus(429)->assertHeader('Retry-After');
        }
    }

    public function test_trusted_proxy_chain_preserves_client_budgets_and_https(): void
    {
        $proxy = long2ip(0xC0000201);
        $upstream = long2ip(0xC0000202);
        config(['trustedproxy.proxies' => [$proxy, $upstream]]);
        $this->withServerVariables(['REMOTE_ADDR' => $proxy]);
        $client = long2ip(0xC6336401);
        $this->withHeaders(['X-Forwarded-For' => "$client, $upstream", 'X-Forwarded-Proto' => 'https']);

        Route::get('/proxy-test', fn (Request $request) => ['ip' => $request->ip(), 'secure' => $request->isSecure()]);
        $this->getJson('/proxy-test')->assertJson(['ip' => $client, 'secure' => true]);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->getJson('/api/track/BGO-PROXY-MISSING')->assertNotFound();
        }
        $this->getJson('/track/BGO-PROXY-MISSING')->assertStatus(429);
        $this->withHeader('X-Forwarded-For', long2ip(0xC6336402).", $upstream")
            ->getJson('/api/track/BGO-PROXY-MISSING')->assertNotFound();
    }

    public function test_untrusted_peer_cannot_spoof_a_trusted_proxy_or_https(): void
    {
        $peer = long2ip(0xCB007101);
        $proxy = long2ip(0xC0000201);
        config(['trustedproxy.proxies' => [$proxy]]);
        $this->withServerVariables(['REMOTE_ADDR' => $peer]);
        $this->withHeaders(['X-Forwarded-For' => long2ip(0xC6336401).", $proxy", 'X-Forwarded-Proto' => 'https']);
        Route::get('/proxy-test', fn (Request $request) => ['ip' => $request->ip(), 'secure' => $request->isSecure(), 'url' => url('/track')]);

        $this->getJson('/proxy-test')->assertJson(['ip' => $peer, 'secure' => false])
            ->assertJsonPath('url', fn ($url) => str_starts_with($url, 'http://'));
    }
}
