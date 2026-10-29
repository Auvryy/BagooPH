<?php

namespace Tests\Feature\BuyerMobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['buyer_mobile.google.enabled' => false]);
    }

    public function test_guest_can_discover_the_exact_version_without_authentication_or_csrf(): void
    {
        $response = $this->getJson('/buyer/mobile-capabilities')->assertOk()->assertExactJson($this->disabled());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertFalse($response->headers->has('Location'));
        $this->assertGuest();
        $this->getJson('/auth/session')->assertOk()->assertExactJson(['authenticated' => false]);
    }

    public static function accounts(): array
    {
        return ['approved buyer' => ['buyer', 'active', 'approved'], 'pending buyer' => ['buyer', 'pending_approval', 'none'],
            'suspended buyer' => ['buyer', 'suspended', 'approved'], 'rejected buyer' => ['buyer', 'active', 'rejected'],
            'seller session' => ['seller', 'active', 'approved']];
    }

    #[DataProvider('accounts')]
    public function test_discovery_exposes_no_account_or_provider_information(string $role, string $status, string $kyc): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => $kyc]);
        config(['services.google.client_secret' => 'not-a-real-provider-secret']);
        $this->actingAs($user)->getJson('/buyer/mobile-capabilities')->assertOk()->assertExactJson($this->disabled());
        $this->assertAuthenticatedAs($user);
        $this->assertSame($status, $user->fresh()->status);
        $this->assertSame($kyc, $user->fresh()->kyc_status);
    }

    public static function invalidConfiguration(): array
    {
        return ['absent clients' => [[], []], 'empty audience' => [[''], ['android.apps.googleusercontent.com']],
            'partial invalid configuration' => [['web.apps.googleusercontent.com', 'not-a-client'], ['android.apps.googleusercontent.com']],
            'non-list clients' => [['client' => 'web.apps.googleusercontent.com'], ['android.apps.googleusercontent.com']],
            'wrong type' => ['web.apps.googleusercontent.com', ['android.apps.googleusercontent.com']],
            'no approved native party' => [['web.apps.googleusercontent.com'], []]];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_a_switch_cannot_advertise_unconfigured_google(mixed $audiences, mixed $parties): void
    {
        config(['buyer_mobile.google.enabled' => true, 'buyer_mobile.google.audiences' => $audiences,
            'buyer_mobile.google.authorized_parties' => $parties]);
        $this->getJson('/buyer/mobile-capabilities')->assertOk()->assertExactJson($this->disabled());
    }

    public function test_client_parameters_cannot_enable_optional_features(): void
    {
        $this->getJson('/buyer/mobile-capabilities?native_google_sign_in=true&device_push=true&role=admin')
            ->assertOk()->assertExactJson($this->disabled());
    }

    public function test_capability_throttling_returns_json_and_does_not_block_session_recognition(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/buyer/mobile-capabilities')->assertOk();
        }
        $response = $this->getJson('/buyer/mobile-capabilities')->assertStatus(429);
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->getJson('/auth/session')->assertOk()->assertExactJson(['authenticated' => false]);
    }

    public function test_published_disabled_fixture_matches_the_executed_response(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/api/buyer-mobile-contract-handoff/capabilities/disabled.json')), true, flags: JSON_THROW_ON_ERROR);
        $response = $this->getJson($fixture['request']['path'])->assertStatus($fixture['response']['status']);
        $this->assertSame($fixture['response']['body'], $response->json());
        $this->assertSame($fixture['response']['headers']['Cache-Control'], $response->headers->get('Cache-Control'));
    }

    private function disabled(): array
    {
        return ['contract_version' => 1, 'features' => ['native_google_sign_in' => false, 'device_push' => false]];
    }
}
