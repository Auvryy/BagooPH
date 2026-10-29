<?php

namespace Tests\Feature\BuyerMobile;

use App\Models\User;
use App\Services\BuyerMobile\GoogleIdTokenVerifier;
use App\Services\GoogleRegistrationService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithNativeGoogleTokens;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class NativeGoogleTest extends TestCase
{
    use RefreshDatabase, WithNativeGoogleTokens, WithRegistrationPolicies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configureNativeGoogle();
    }

    public function test_capability_advertises_only_the_explicitly_configured_implemented_google_contract(): void
    {
        $this->getJson('/buyer/mobile-capabilities')->assertOk()->assertExactJson([
            'contract_version' => 1, 'features' => ['native_google_sign_in' => true, 'device_push' => false],
        ]);
        Http::assertNothingSent();
    }

    public function test_disabled_exchange_is_private_json_and_does_not_call_google(): void
    {
        config(['buyer_mobile.google.enabled' => false]);
        $response = $this->post('/auth/google/native', ['id_token' => 'not-a-real-token'], ['X-Inertia' => 'true'])->assertStatus(503);
        $response->assertJsonPath('code', 'SERVICE_UNAVAILABLE')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_linked_buyer_signs_in_rotates_session_and_preserves_immutable_email_and_approval(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'google_id' => 'synthetic-google-subject', 'email' => 'Original@bagoo.test', 'status' => 'active', 'kyc_status' => 'approved']);
        $this->getJson('/auth/session');
        $oldId = session()->getId();
        $oldCsrf = session()->token();
        $this->postJson('/auth/google/native', ['id_token' => $this->googleToken(), 'role' => 'admin', 'status' => 'active'])
            ->assertOk()->assertExactJson(['authenticated' => true])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertAuthenticatedAs($buyer);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        $this->assertSame('Original@bagoo.test', $buyer->fresh()->email);
        $this->assertSame('approved', $buyer->fresh()->kyc_status);
        $this->getJson('/auth/session')->assertJsonPath('authenticated', true)->assertJsonPath('code', 'ALREADY_AUTHENTICATED');
    }

    public function test_first_link_requires_password_proof_and_never_changes_email_name_or_approval(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'email' => 'Native-Buyer@bagoo.test', 'google_id' => null, 'password' => '  Password With Spaces  ', 'kyc_status' => 'none']);
        $token = $this->googleToken();
        $this->postJson('/auth/google/native', ['id_token' => $token])->assertStatus(409)->assertJsonPath('code', 'GOOGLE_ACCOUNT_LINK_REQUIRED');
        $this->assertNull($buyer->fresh()->google_id);
        $this->postJson('/auth/google/native', ['id_token' => $token, 'account_password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('account_password');
        $this->assertGuest();
        $this->postJson('/auth/google/native', ['id_token' => $token, 'account_password' => '  Password With Spaces  '])->assertOk()->assertExactJson(['authenticated' => true]);
        $this->assertAuthenticatedAs($buyer);
        $this->assertSame('synthetic-google-subject', $buyer->fresh()->google_id);
        $this->assertSame('Native-Buyer@bagoo.test', $buyer->fresh()->email);
        $this->assertSame($buyer->name, $buyer->fresh()->name);
        $this->assertSame('none', $buyer->fresh()->kyc_status);
    }

    public function test_wrong_role_subject_and_session_cannot_be_converted_to_a_buyer(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'google_id' => 'synthetic-google-subject']);
        $this->postJson('/auth/google/native', ['id_token' => $this->googleToken(), 'role' => 'buyer'])->assertForbidden();
        $this->assertGuest();
        $this->actingAs($seller)->postJson('/auth/google/native', ['id_token' => $this->googleToken()])->assertForbidden();
        $this->assertAuthenticatedAs($seller);
        $this->assertSame('seller', $seller->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_email_in_another_role_does_not_replace_that_account_or_its_role(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'email' => 'native-buyer@bagoo.test', 'google_id' => null]);
        $this->startRegistration();
        $this->completeRegistration()->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('seller', $seller->fresh()->role);
        $this->assertNull($seller->fresh()->google_id);
        $this->assertSame('buyer', Auth::user()->role);
    }

    public static function restrictedBuyers(): array
    {
        return [['suspended', 'approved'], ['inactive', 'approved'], ['pending_approval', 'pending_approval'], ['active', 'rejected']];
    }

    #[DataProvider('restrictedBuyers')]
    public function test_restricted_sign_in_retains_account_status_and_denies_checkout(string $status, string $kyc): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'google_id' => 'synthetic-google-subject', 'status' => $status, 'kyc_status' => $kyc]);
        $this->postJson('/auth/google/native', ['id_token' => $this->googleToken()])->assertOk();
        $this->assertAuthenticatedAs($buyer);
        $this->assertSame($status, $buyer->fresh()->status);
        $this->assertSame($kyc, $buyer->fresh()->kyc_status);
        $this->assertFalse($buyer->fresh()->canAccessPortal());
    }

    public function test_closed_account_cannot_sign_in(): void
    {
        User::factory()->create(['role' => 'buyer', 'google_id' => 'synthetic-google-subject', 'closed_at' => now()]);
        $this->postJson('/auth/google/native', ['id_token' => $this->googleToken()])->assertForbidden();
        $this->assertGuest();
    }

    public function test_authenticated_buyer_cannot_switch_to_another_subject_or_register_twice(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'google_id' => 'existing-subject']);
        $this->actingAs($buyer)->postJson('/auth/google/native', ['id_token' => $this->googleToken()])
            ->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_SWITCH_REQUIRED');
        $this->postJson('/auth/google/native', ['id_token' => $this->googleToken(['sub' => 'existing-subject'])])->assertOk()->assertExactJson(['authenticated' => true]);
        $this->postJson('/auth/google/native/register', $this->form('synthetic-nonce'))->assertStatus(409)->assertJsonPath('code', 'ALREADY_AUTHENTICATED');
        $this->assertAuthenticatedAs($buyer);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_new_identity_returns_only_a_bound_expiring_registration_contract_then_creates_an_unapproved_buyer(): void
    {
        $browser = ['nonce' => 'separate-browser-nonce', 'expires_at' => time() + 600];
        $this->withSession(['registration.google' => $browser]);
        $expires = time() + 180;
        $this->startRegistration(['exp' => $expires], remember: true);
        $response = $this->getJson('/auth/google/native/registration')->assertOk()->assertJsonStructure([
            'identity' => ['name', 'email'], 'registrationNonce', 'expiresAt', 'birthDateLimits',
            'policies' => ['required', 'terms_version', 'privacy_version', 'terms_url', 'privacy_url'],
        ])->assertJsonPath('expiresAt', $expires);
        $this->assertSame('native-buyer@bagoo.test', $response->json('identity.email'));
        $this->assertStringNotContainsString('synthetic-google-subject', $response->getContent());
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->completeRegistration()->assertOk()->assertExactJson(['authenticated' => true]);
        $buyer = User::where('role', 'buyer')->firstOrFail();
        $this->assertAuthenticatedAs($buyer);
        $this->assertSame('none', $buyer->kyc_status);
        $this->assertSame('active', $buyer->status);
        $this->assertFalse($buyer->canAccessPortal());
        $this->assertSame('native-buyer@bagoo.test', $buyer->email);
        $this->assertDatabaseCount('account_policy_acceptances', 1);
        $this->assertNull(session(GoogleRegistrationService::NATIVE_PENDING));
        $this->assertSame($browser, session('registration.google'));
        $this->getJson('/auth/session')->assertJsonPath('authenticated', true);
        $this->postJson('/auth/google/native/register', $this->form('old-nonce'))->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_default_remember_is_false_and_expiry_is_bounded_to_ten_minutes(): void
    {
        $this->startRegistration();
        $pending = session(GoogleRegistrationService::NATIVE_PENDING);
        $this->assertFalse($pending['remember']);
        $this->assertLessThanOrEqual(time() + 600, $pending['expires_at']);
    }

    public static function rememberModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('rememberModes')]
    public function test_remember_controls_the_existing_recaller_cookie(bool $remember): void
    {
        User::factory()->create(['role' => 'buyer', 'google_id' => 'synthetic-google-subject']);
        $response = $this->postJson('/auth/google/native', ['id_token' => $this->googleToken(), 'remember' => $remember])->assertOk();
        $rememberCookies = array_filter($response->headers->getCookies(), fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_') && $cookie->getValue() !== '');
        $this->assertSame($remember ? 1 : 0, count($rememberCookies));
    }

    public function test_native_challenge_is_not_a_browser_registration_challenge_and_client_identity_is_ignored(): void
    {
        $this->startRegistration();
        $nonce = session(GoogleRegistrationService::NATIVE_PENDING.'.nonce');
        $this->postJson('/auth/google/register', $this->form($nonce))->assertUnprocessable();
        $this->completeRegistration(['email' => 'other@bagoo.test', 'google_id' => 'client-supplied', 'role' => 'admin', 'kyc_status' => 'approved'])->assertOk();
        $buyer = User::sole();
        $this->assertSame('buyer', $buyer->role);
        $this->assertSame('native-buyer@bagoo.test', $buyer->email);
        $this->assertSame('synthetic-google-subject', $buyer->google_id);
        $this->assertSame('none', $buyer->kyc_status);
    }

    public function test_wrong_session_purpose_nonce_and_expiry_cannot_complete_registration(): void
    {
        $this->withSession(['registration.google' => ['nonce' => 'browser-only', 'expires_at' => time() + 600]])
            ->getJson('/auth/google/native/registration')->assertUnprocessable();
        $this->postJson('/auth/google/native/register', $this->form('browser-only'))->assertUnprocessable();
        $this->startRegistration();
        $this->postJson('/auth/google/native/register', $this->form('wrong-native-nonce'))->assertUnprocessable()->assertJsonValidationErrors('registration_nonce');
        $this->assertDatabaseCount('users', 0);
        $this->travel(11)->minutes();
        $this->completeRegistration()->assertUnprocessable();
        $this->assertNull(session(GoogleRegistrationService::NATIVE_PENDING));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_underage_missing_names_and_old_policy_without_consuming_valid_challenge(): void
    {
        $this->startRegistration();
        $this->completeRegistration(['birthday' => now()->subYears(17)->format('Y-m-d')])->assertUnprocessable()->assertJsonValidationErrors('birthday');
        $this->completeRegistration(['first_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->completeRegistration(['terms_version' => 'old'])->assertUnprocessable()->assertJsonValidationErrors('terms_version');
        $this->completeRegistration(['policy_accepted' => false])->assertUnprocessable()->assertJsonValidationErrors('policy_accepted');
        $this->assertDatabaseCount('users', 0);
        $this->completeRegistration(['birthday' => now()->subYears(18)->format('Y-m-d')])->assertOk();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_changed_identity_and_email_between_exchange_and_completion_cannot_create_duplicates(): void
    {
        $this->startRegistration();
        User::factory()->create(['role' => 'buyer', 'email' => 'native-buyer@bagoo.test']);
        $this->completeRegistration()->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_real_csrf_rejection_is_private_json_and_never_performs_the_exchange(): void
    {
        $this->app->bind(PreventRequestForgery::class, NativeEnforcedCsrf::class);
        $token = $this->googleToken();
        $this->post('/auth/google/native', ['id_token' => $token, '_token' => 'expired'])->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();
        $this->assertGuest();
        $this->postJson('/auth/google/native', ['id_token' => $token, '_token' => session()->token()])->assertOk()->assertExactJson(['registration_required' => true]);
    }

    public function test_provider_and_unexpected_errors_are_redacted_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $this->googleResponseBody = ['private-provider-detail' => 'do-not-show'];
        $this->googleResponseStatus = 503;
        $this->postJson('/auth/google/native', ['id_token' => $this->googleToken()])->assertStatus(503)->assertExactJson([
            'code' => 'SERVICE_UNAVAILABLE', 'message' => 'Buyer sign-in is temporarily unavailable. Use email sign-in or try again later.',
        ]);
        Log::spy();
        $this->mock(GoogleIdTokenVerifier::class)->shouldReceive('verify')->andThrow(new \RuntimeException('private-token-or-sql-detail'));
        $response = $this->postJson('/auth/google/native', ['id_token' => 'synthetic-value']);
        $response->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $this->assertStringNotContainsString('private-token-or-sql-detail', $response->getContent());
        Log::shouldHaveReceived('error')->with('Native buyer operation failed.', ['exception_type' => \RuntimeException::class]);
    }

    public function test_native_auth_is_throttled_without_affecting_email_session_recognition(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/auth/google/native', [])->assertUnprocessable();
        }
        $this->postJson('/auth/google/native', [])->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/auth/session')->assertOk();
        Http::assertNothingSent();
    }

    public function test_native_contracts_reject_worker_hosts_without_redirects(): void
    {
        $this->postJson('http://seller.localhost/auth/google/native', ['id_token' => 'synthetic-value'])->assertForbidden()->assertJsonPath('code', 'BUYER_ORIGIN_REQUIRED');
        $this->getJson('http://seller.localhost/buyer/mobile-capabilities')->assertForbidden()->assertJsonPath('code', 'BUYER_ORIGIN_REQUIRED');
        Http::assertNothingSent();
    }

    private function startRegistration(array $claims = [], bool $remember = false): void
    {
        $this->postJson('/auth/google/native', ['id_token' => $this->googleToken($claims), 'remember' => $remember])
            ->assertOk()->assertExactJson(['registration_required' => true]);
    }

    private function form(string $nonce): array
    {
        return $this->registrationPolicies() + [
            'registration_nonce' => $nonce, 'first_name' => 'Synthetic', 'middle_initial' => '',
            'last_name' => 'Buyer', 'sex' => 'Female', 'birthday' => '1995-05-15',
        ];
    }

    private function completeRegistration(array $overrides = []): TestResponse
    {
        return $this->postJson('/auth/google/native/register', array_replace($this->form(session(GoogleRegistrationService::NATIVE_PENDING.'.nonce') ?? 'expired-nonce'), $overrides));
    }
}

class NativeEnforcedCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
