<?php

namespace Tests\Feature\BuyerMobile;

use App\Models\User;
use App\Services\GoogleRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\WithNativeGoogleTokens;
use Tests\TestCase;

class ContractFixturesTest extends TestCase
{
    use RefreshDatabase, WithNativeGoogleTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configureNativeGoogle();
    }

    public function test_enabled_capability_and_guest_session_match_the_mobile_fixtures(): void
    {
        $this->fixtureResponse('capabilities/enabled.json', $this->getJson('/buyer/mobile-capabilities'));
        $this->fixtureResponse('transport/session-guest.json', $this->getJson('/auth/session'));
    }

    public function test_existing_account_states_preserve_session_recognition_without_inventing_a_role_field(): void
    {
        foreach ($this->fixture('transport/session-states.json')['cases'] as $case) {
            $attributes = $case['setup'];
            unset($attributes['id']);
            $buyer = User::factory()->create($attributes);
            $response = $this->actingAs($buyer)->getJson('/auth/session')->assertStatus($case['response']['status']);
            $response->assertExactJson($case['response']['body']);
            $this->assertSame($case['response']['headers']['Cache-Control'], $response->headers->get('Cache-Control'));
        }
    }

    public function test_linked_google_authentication_matches_the_native_fixture(): void
    {
        User::factory()->create(['role' => 'buyer', 'google_id' => 'synthetic-google-subject']);
        $this->fixtureResponse('google/authenticated.json', $this->postJson('/auth/google/native', ['id_token' => $this->googleToken()]));
    }

    public function test_new_identity_challenge_and_registration_fields_match_sanitized_fixtures(): void
    {
        $this->fixtureResponse('google/registration-required.json', $this->postJson('/auth/google/native', ['id_token' => $this->googleToken()]));
        $response = $this->getJson('/auth/google/native/registration')->assertOk();
        $body = $response->json();
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{40}\z/', $body['registrationNonce']);
        $this->assertIsInt($body['expiresAt']);
        $this->assertGreaterThan(time(), $body['expiresAt']);
        $this->assertLessThanOrEqual(time() + 600, $body['expiresAt']);
        foreach ($body['birthDateLimits'] as $date) {
            $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}\z/', $date);
        }
        $body['registrationNonce'] = '<SESSION_BOUND_NONCE>';
        $body['expiresAt'] = '<UTC_UNIX_SECONDS>';
        $body['birthDateLimits'] = ['today' => '<TODAY_YYYY-MM-DD>', 'past_maximum' => '<YESTERDAY_YYYY-MM-DD>', 'adult_maximum' => '<18_YEARS_AGO_YYYY-MM-DD>'];
        $this->assertSame($this->fixture('google/registration.json')['response']['body'], $body);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_link_proof_and_disabled_error_match_the_mobile_fixtures(): void
    {
        User::factory()->create(['role' => 'buyer', 'email' => 'native-buyer@bagoo.test', 'google_id' => null]);
        $this->fixtureResponse('google/link-required.json', $this->postJson('/auth/google/native', ['id_token' => $this->googleToken()]));
        config(['buyer_mobile.google.enabled' => false]);
        $this->fixtureResponse('google/unavailable.json', $this->postJson('/auth/google/native', ['id_token' => 'synthetic-placeholder']));
    }

    public function test_absent_challenge_and_closed_session_match_the_mobile_fixtures(): void
    {
        $this->fixtureResponse('google/registration-expired.json', $this->getJson('/auth/google/native/registration'));
        $this->assertNull(session(GoogleRegistrationService::NATIVE_PENDING));
        $buyer = User::factory()->create(['closed_at' => now(), 'status' => 'inactive']);
        $this->actingAs($buyer);
        $this->fixtureResponse('transport/closed.json', $this->getJson('/auth/session'));
        $this->assertGuest();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('docs/api/buyer-mobile-contract-handoff/'.$name)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixtureResponse(string $name, TestResponse $response): void
    {
        $fixture = $this->fixture($name);
        $response->assertStatus($fixture['response']['status'])->assertExactJson($fixture['response']['body']);
        $this->assertSame($fixture['response']['headers']['Cache-Control'], $response->headers->get('Cache-Control'));
    }
}
