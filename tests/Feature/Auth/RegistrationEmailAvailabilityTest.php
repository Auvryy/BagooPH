<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class RegistrationEmailAvailabilityTest extends TestCase
{
    use RefreshDatabase, WithRegistrationPolicies;

    public static function hosts(): array
    {
        return [['localhost'], ['seller.localhost'], ['courier.localhost'], ['hub.localhost']];
    }

    #[DataProvider('hosts')]
    public function test_role_forms_can_check_normalized_email_without_creating_accounts_or_sending_mail(string $host): void
    {
        Mail::fake();
        $this->postJson('http://'.$host.'/register/email/validate', [
            'email' => '  Applicant@BAGOO.TEST  ', 'role' => 'admin', 'user_id' => 99,
        ])->assertOk()->assertExactJson(['email' => 'applicant@bagoo.test', 'available' => true])
            ->assertHeader('Cache-Control', 'no-store, private');
        foreach (['users', 'account_emails', 'shops', 'courier_profiles', 'logistics_companies', 'email_otps', 'account_policy_acceptances'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Mail::assertNothingSent();
        $this->assertGuest();
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['buyer', 'seller', 'courier', 'logistics', 'admin']);
    }

    #[DataProvider('roles')]
    public function test_primary_emails_are_unavailable_across_roles_and_case_variants(string $role): void
    {
        $user = User::factory()->create(['email' => 'existing@bagoo.test', 'role' => $role]);
        $before = $user->fresh()->getAttributes();
        $this->postJson('/register/email/validate', ['email' => '  EXISTING@BAGOO.TEST  '])
            ->assertOk()->assertExactJson(['email' => 'existing@bagoo.test', 'available' => false]);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_verified_additional_addresses_are_also_reserved_without_disclosing_the_owner(): void
    {
        $user = User::factory()->create();
        $user->accountEmails()->create(['email' => 'contact@bagoo.test', 'verified_at' => now()]);
        $this->postJson('/register/email/validate', ['email' => 'CONTACT@bagoo.test'])
            ->assertOk()->assertExactJson(['email' => 'contact@bagoo.test', 'available' => false]);
        $this->assertDatabaseCount('account_emails', 2);
    }

    public static function malformedEmails(): array
    {
        return array_map(fn ($email) => [$email], [
            null, '', '   ', ['user@bagoo.test'], 123, true,
            'person', '@bagoo.test', 'person@bagoo', 'person @bagoo.test', 'person@@bagoo.test',
            "person@bagoo.test\n", "\tperson@bagoo.test", "person\u{200B}@bagoo.test", str_repeat('a', 256).'@bagoo.test',
        ]);
    }

    #[DataProvider('malformedEmails')]
    public function test_malformed_email_is_rejected_before_any_side_effect(mixed $email): void
    {
        Mail::fake();
        $this->postJson('/register/email/validate', ['email' => $email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_otps', 0);
        Mail::assertNothingSent();
    }

    public function test_available_email_does_not_reserve_it_or_verify_or_consume_an_otp(): void
    {
        Mail::fake();
        $otp = EmailOtp::create(['email' => 'applicant@bagoo.test', 'purpose' => 'registration',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
            'attempts' => 0, 'token' => str_repeat('a', 40)]);
        $before = $otp->fresh()->getAttributes();
        $this->postJson('/register/email/validate', ['email' => $otp->email])->assertJsonPath('available', true);
        $this->postJson('/register/email/validate', ['email' => $otp->email])->assertJsonPath('available', true);
        $this->assertSame($before, $otp->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('account_emails', 0);
        Mail::assertNothingSent();
    }

    #[DataProvider('hosts')]
    public function test_an_existing_session_gets_json_recovery_without_changing_that_session(string $host): void
    {
        $user = User::factory()->buyer()->create();
        $this->actingAs($user)->postJson('http://'.$host.'/register/email/validate', ['email' => 'new@bagoo.test'])
            ->assertConflict()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'You are already signed in. Returning to your account.');
        $this->assertAuthenticatedAs($user);
    }

    public function test_custom_and_demo_domains_remain_eligible_and_missing_email_is_rejected(): void
    {
        foreach (['buyer+orders@bagoo.test', 'dispatch@delivery-team.ph'] as $email) {
            $this->postJson('/register/email/validate', ['email' => $email])
                ->assertOk()->assertExactJson(['email' => $email, 'available' => true]);
        }
        $this->postJson('/register/email/validate', [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_web_email_check_retains_csrf_protection(): void
    {
        // Exercise the web middleware without the testing-only CSRF exemption.
        $this->app['env'] = 'production';
        $this->postJson('/register/email/validate', ['email' => 'new@bagoo.test'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_availability_cannot_bypass_final_uniqueness_when_someone_else_takes_the_address(): void
    {
        $this->postJson('/register/email/validate', ['email' => 'applicant@bagoo.test'])->assertJsonPath('available', true);
        User::factory()->create(['email' => 'applicant@bagoo.test', 'role' => 'seller']);
        $data = $this->registrationPolicies() + ['sex' => 'Female',
            'role' => 'buyer', 'first_name' => 'Ana', 'last_name' => 'Peña', 'email' => 'APPLICANT@BAGOO.TEST',
            'password' => 'Strong Password 2026!', 'password_confirmation' => 'Strong Password 2026!',
        ];
        $this->postJson('/register/validate', $data + ['step' => 1])->assertJsonValidationErrors('email');
        $this->postJson('/register', $data)->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_rate_limit_is_independent_of_full_form_preflight(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/register/email/validate', ['email' => 'applicant@bagoo.test'])->assertOk();
        }
        $this->postJson('/register/email/validate', ['email' => 'applicant@bagoo.test'])->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/register/validate', [
            'role' => 'buyer', 'step' => 1, 'first_name' => 'Ana', 'last_name' => 'Peña', 'sex' => 'Female', 'email' => 'applicant@bagoo.test',
            'password' => 'Strong Password 2026!', 'password_confirmation' => 'Strong Password 2026!',
        ])->assertOk()->assertExactJson(['valid' => true]);
    }
}
