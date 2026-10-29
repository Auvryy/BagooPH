<?php

namespace Tests\Feature\BuyerMobile;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppAssociationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['buyer_mobile.links' => [
            'enabled' => true, 'origin' => 'https://buyer.example.invalid',
            'android_application_id' => 'shop.bagooph.bagoo_buyer_mobile',
            'android_sha256_fingerprints' => [implode(':', array_fill(0, 32, 'AB'))],
            'apple_team_id' => 'A1B2C3D4E5', 'ios_bundle_id' => 'shop.bagooph.bagooBuyerMobile',
        ]]);
    }

    public function test_android_and_apple_associations_are_guest_stateless_json_without_redirects_or_database_access(): void
    {
        // No migrations or session tables exist: public associations must not need them.
        DB::enableQueryLog();
        $android = $this->getJson('https://buyer.example.invalid/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.namespace', 'android_app')
            ->assertJsonPath('0.target.package_name', 'shop.bagooph.bagoo_buyer_mobile')
            ->assertHeader('Cache-Control', 'max-age=300, public');
        $apple = $this->getJson('https://buyer.example.invalid/.well-known/apple-app-site-association')->assertOk()
            ->assertJsonPath('applinks.details.0.appIDs.0', 'A1B2C3D4E5.shop.bagooph.bagooBuyerMobile');
        foreach ([$android, $apple] as $response) {
            $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
            $this->assertFalse($response->headers->has('Location'));
            $this->assertFalse($response->headers->has('Set-Cookie'));
            $this->assertSame([], $response->headers->getCookies());
        }
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame($android->json()[0]['relation_extensions']['delegate_permission/common.handle_all_urls']['dynamic_app_link_components'], $apple->json('applinks.details.0.components'));
    }

    public function test_disabling_associations_removes_both_public_documents_without_advertising_placeholders(): void
    {
        config(['buyer_mobile.links.enabled' => false]);
        foreach (['assetlinks.json', 'apple-app-site-association'] as $file) {
            $this->getJson('https://buyer.example.invalid/.well-known/'.$file)->assertNotFound()
                ->assertExactJson(['message' => 'Buyer app links are not configured.'])->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_synthetic_handoff_documents_match_the_executed_public_contracts(): void
    {
        foreach (['android.json', 'apple.json', 'disabled.json'] as $name) {
            if ($name === 'disabled.json') {
                config(['buyer_mobile.links.enabled' => false]);
            }
            $fixture = json_decode(file_get_contents(base_path('docs/api/buyer-mobile-contract-handoff/associations/'.$name)), true, flags: JSON_THROW_ON_ERROR);
            $this->getJson($fixture['request']['origin'].$fixture['request']['path'])
                ->assertStatus($fixture['response']['status'])->assertExactJson($fixture['response']['body'])
                ->assertHeader('Cache-Control', $fixture['response']['headers']['Cache-Control']);
        }
    }

    public static function invalidOrigins(): array
    {
        return [['http://buyer.example.invalid'], ['https://user@buyer.example.invalid'], ['https://buyer.example.invalid/path'],
            ['https://buyer.example.invalid/'], ['https://buyer.example.invalid?setting=yes'], ['https://buyer.example.invalid#app'],
            ['https://buyer.example.invalid:444'], ['https://localhost'], ['https://127.0.0.1'], ['https://seller.example.invalid']];
    }

    #[DataProvider('invalidOrigins')]
    public function test_bad_or_worker_origin_never_advertises_an_app(string $origin): void
    {
        config(['buyer_mobile.links.origin' => $origin]);
        $this->getJson('https://buyer.example.invalid/.well-known/assetlinks.json')->assertNotFound();
        $this->getJson('https://buyer.example.invalid/.well-known/apple-app-site-association')->assertNotFound();
    }

    public function test_http_other_hosts_and_worker_hosts_get_no_redirect_or_association(): void
    {
        foreach (['http://buyer.example.invalid', 'https://other.example.invalid', 'https://seller.example.invalid'] as $origin) {
            $response = $this->getJson($origin.'/.well-known/assetlinks.json')->assertNotFound();
            $this->assertFalse($response->headers->has('Location'));
        }
    }

    public function test_incomplete_platform_configuration_is_independently_disabled(): void
    {
        config(['buyer_mobile.links.apple_team_id' => '', 'buyer_mobile.links.android_sha256_fingerprints' => ['not-a-fingerprint']]);
        $this->getJson('https://buyer.example.invalid/.well-known/assetlinks.json')->assertNotFound();
        $this->getJson('https://buyer.example.invalid/.well-known/apple-app-site-association')->assertNotFound();
        config(['buyer_mobile.links.android_sha256_fingerprints' => [implode(':', array_fill(0, 32, 'ab'))]]);
        $this->getJson('https://buyer.example.invalid/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.sha256_cert_fingerprints.0', implode(':', array_fill(0, 32, 'AB')));
        $this->getJson('https://buyer.example.invalid/.well-known/apple-app-site-association')->assertNotFound();
    }

    public function test_buyer_paths_and_reset_role_filters_are_explicit_with_a_final_exclusion(): void
    {
        $rules = $this->getJson('https://buyer.example.invalid/.well-known/apple-app-site-association')->assertOk()->json('applinks.details.0.components');
        $paths = array_column($rules, '/');
        foreach (['/', '/buyer/orders/*', '/buyer/messages', '/account/security', '/buyer-returns/*', '/wallet/payments/*', '/verify-email/*'] as $path) {
            $this->assertContains($path, $paths);
        }
        foreach (['/buyer/*', '/account/*', '/admin/*', '/seller/*', '/courier/*', '/hub/*', '/*'] as $path) {
            $this->assertNotContains($path, $paths);
        }
        $reset = array_values(array_filter($rules, fn ($rule) => $rule['/'] === '/reset-password/*'));
        $this->assertSame([
            ['/' => '/reset-password/*', '?' => ['role' => 'buyer']],
            ['/' => '/reset-password/*', '?' => ['role' => '*'], 'exclude' => true],
            ['/' => '/reset-password/*'],
        ], $reset);
        $this->assertSame(['/' => '*', 'exclude' => true], end($rules));
    }
}
