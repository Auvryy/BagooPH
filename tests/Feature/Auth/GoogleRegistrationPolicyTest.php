<?php

namespace Tests\Feature\Auth;

use App\Models\AccountPolicyAcceptance;
use App\Models\User;
use App\Services\RegistrationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class GoogleRegistrationPolicyTest extends TestCase
{
    use RefreshDatabase, WithRegistrationPolicies;

    private function provider(mixed $verified = true, string $id = 'google-bagoo-applicant'): void
    {
        $identity = (new SocialiteUser)->setRaw(['email_verified' => $verified])->map([
            'id' => $id, 'email' => 'Applicant@Bagoo.test', 'name' => 'Bagoo Applicant', 'avatar' => null,
        ]);
        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_first_google_signup_requires_explicit_current_policies_and_uses_only_provider_identity(): void
    {
        $this->provider();
        $this->get(route('auth.google.callback'))->assertRedirect(route('auth.google.registration'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $nonce = session('registration.google.nonce');
        $data = $this->registrationPolicies() + ['registration_nonce' => $nonce, 'first_name' => 'Bagoo', 'last_name' => 'Applicant', 'sex' => 'Female', 'postal_code' => '0123'];
        foreach ([null, false, 0, 'false', ['true']] as $value) {
            $this->postJson(route('auth.google.registration.store'), array_replace($data, ['policy_accepted' => $value]))->assertJsonValidationErrors('policy_accepted');
            $this->assertDatabaseCount('account_policy_acceptances', 0);
        }
        $this->postJson(route('auth.google.registration.store'), array_replace($data, ['terms_version' => 'old']))->assertJsonValidationErrors('terms_version');
        $this->postJson(route('auth.google.registration.store'), array_replace($data, ['registration_nonce' => 'another-session']))->assertJsonValidationErrors('registration_nonce');
        $this->freezeSecond();
        $this->post(route('auth.google.registration.store'), $data + ['email' => 'attacker@bagoo.test', 'role' => 'admin', 'name' => 'Conflicting Name', 'accepted_at' => '1990-01-01'])
            ->assertRedirect(route('kyc.pending'))->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('buyer', $user->role);
        $this->assertSame('applicant@bagoo.test', $user->email);
        $this->assertSame('Bagoo Applicant', $user->name);
        $this->assertSame('Female', $user->sex);
        $this->assertSame('0123', $user->postal_code);
        $this->assertSame('none', $user->kyc_status);
        $acceptance = AccountPolicyAcceptance::sole();
        $this->assertSame('google', $acceptance->source);
        $this->assertSame($user->id, $acceptance->user_id);
        $this->assertTrue($acceptance->accepted_at->equalTo(now()));
        $this->assertNull(session('registration.google'));
        $this->postJson(route('auth.google.registration.store'), $data)->assertConflict();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_policy_acceptances', 1);
    }

    public function test_expired_or_absent_google_identity_cannot_create_an_account(): void
    {
        $data = $this->registrationPolicies() + ['registration_nonce' => 'missing'];
        $this->postJson(route('auth.google.registration.store'), $data)->assertJsonValidationErrors('email');
        $this->provider();
        $this->get(route('auth.google.callback'))->assertRedirect(route('auth.google.registration'));
        $data['registration_nonce'] = session('registration.google.nonce');
        $this->travel(10)->minutes();
        $this->postJson(route('auth.google.registration.store'), $data)->assertJsonValidationErrors('email');
        $this->assertNull(session('registration.google'));
        $this->get(route('auth.google.registration'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('account_policy_acceptances', 0);
    }

    public function test_a_failed_google_acceptance_write_rolls_back_and_can_be_retried(): void
    {
        $this->provider();
        $this->get(route('auth.google.callback'));
        $data = $this->registrationPolicies() + ['registration_nonce' => session('registration.google.nonce'), 'first_name' => 'Bagoo', 'last_name' => 'Applicant', 'sex' => 'Female'];
        $this->partialMock(RegistrationPolicyService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Acceptance storage unavailable.'));
        $this->postJson(route('auth.google.registration.store'), $data)->assertServerError();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('account_policy_acceptances', 0);
        $this->assertNotNull(session('registration.google'));
        $this->app->forgetInstance(RegistrationPolicyService::class);
        $this->post(route('auth.google.registration.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_policy_acceptances', 1);
    }

    public function test_unverified_provider_email_cannot_create_or_link_an_account(): void
    {
        $existing = User::factory()->create(['email' => 'Applicant@Bagoo.test', 'email_verified_at' => null, 'google_id' => null]);
        $this->provider(false);
        $before = $existing->fresh()->getRawOriginal();
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertNull(session('registration.google'));
        $this->assertDatabaseCount('account_policy_acceptances', 0);
    }

    public function test_google_linking_cannot_replace_a_different_existing_provider_identity(): void
    {
        $existing = User::factory()->create(['email' => 'Applicant@Bagoo.test', 'google_id' => 'original-google-identity']);
        $this->provider();
        $before = $existing->fresh()->getRawOriginal();
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_policy_acceptances', 0);
    }

    public function test_first_google_signup_requires_sex_and_preserves_the_pending_identity_after_rejection(): void
    {
        $this->provider();
        $this->get(route('auth.google.callback'));
        $data = $this->registrationPolicies() + ['registration_nonce' => session('registration.google.nonce'), 'first_name' => 'Ana', 'last_name' => 'Santos'];
        $this->postJson(route('auth.google.registration.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('sex');
        foreach ([null, '', '   ', 'Unknown', ['Female'], true, 1] as $sex) {
            $this->postJson(route('auth.google.registration.store'), $data + ['sex' => $sex])->assertUnprocessable()->assertJsonValidationErrors('sex');
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('account_policy_acceptances', 0);
        $this->assertGuest();
        $this->post(route('auth.google.registration.store'), $data + ['sex' => 'Other'])->assertSessionHasNoErrors();
        $this->assertSame('Other', User::sole()->sex);
    }

    public function test_google_application_validates_name_components_demographics_and_address_before_creation(): void
    {
        $this->provider();
        $this->get(route('auth.google.callback'));
        $data = $this->registrationPolicies() + ['registration_nonce' => session('registration.google.nonce'), 'first_name' => 'María', 'middle_initial' => 'J.', 'last_name' => 'O’Connor'];
        foreach ([['first_name' => 'A'], ['first_name' => 'Ana123'], ['last_name' => str_repeat('A', 51)], ['middle_initial' => 'MM'], ['sex' => 'Unknown'], ['postal_code' => '12345'], ['phone' => '0917ABC4567'], ['address' => '<script>'], ['birthday' => '2000-02-30']] as $invalid) {
            $this->postJson(route('auth.google.registration.store'), array_replace($data, $invalid))->assertJsonValidationErrors(array_keys($invalid));
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('account_policy_acceptances', 0);
        }
        $this->post(route('auth.google.registration.store'), $data + ['sex' => 'Other', 'postal_code' => '0123', 'phone' => '09171234567', 'address' => '12 Bagoo Street', 'city' => 'Makati'])->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertSame('María J. O’Connor', $user->name);
        $this->assertSame('+639171234567', $user->phone);
        $this->assertSame('0123', $user->postal_code);
    }
}
