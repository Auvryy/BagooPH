<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoogleLinkedPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_password_login_is_rejected_while_fresh_verification_is_required(): void
    {
        $user = User::factory()->create([
            'google_id' => 'provider-account-login-disabled',
            'password' => 'ExistingPassword2026!',
            'password_login_enabled' => false,
        ]);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'ExistingPassword2026!'])
            ->assertSessionHasErrors(['email' => 'Email or password is incorrect. Please try again.']);
        $this->assertGuest();
    }

    public function test_password_login_state_migration_marks_legacy_google_accounts_for_verified_setup(): void
    {
        $google = User::factory()->create(['google_id' => 'legacy-provider-account', 'password' => 'random-provider-password']);
        $local = User::factory()->create(['google_id' => null, 'password' => 'LocalPassword2026!']);

        Schema::table('users', fn ($table) => $table->dropColumn('password_login_enabled'));
        $migration = require database_path('migrations/2026_11_06_030000_add_password_login_state_to_users.php');
        $migration->up();

        $this->assertFalse((bool) $google->fresh()->password_login_enabled);
        $this->assertTrue((bool) $local->fresh()->password_login_enabled);
    }

    public function test_google_linked_account_must_request_a_fresh_verified_email_code_before_setting_a_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['google_id' => 'provider-account-1', 'password' => 'random-provider-password', 'password_login_enabled' => false]);
        $this->actingAs($user)->postJson('/password/google/code')->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('cooldown', 60);
        $otp = EmailOtp::where('user_id', $user->id)->where('purpose', 'google_password_setup')->sole();
        $code = null;
        Mail::assertSent(OtpVerificationMail::class, function (OtpVerificationMail $mail) use (&$code, $user): bool {
            $code = $mail->otpCode;

            return $mail->hasTo($user->email) && $mail->purpose === 'google_password_setup';
        });

        $payload = ['code' => '000000', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'];
        $this->putJson('/password/google', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, $otp->fresh()->attempts);
        $this->assertTrue(Hash::check('random-provider-password', $user->fresh()->password));

        $payload['code'] = $code;
        $this->putJson('/password/google', $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertTrue(Hash::check('NewPassword2026!', $user->fresh()->password));
        $this->assertTrue($user->fresh()->password_login_enabled);
        $this->assertSame(1, (int) $user->fresh()->session_version);
        $this->assertNotNull($otp->fresh()->verified_at);
        $this->assertNull($otp->fresh()->token);
        $this->postJson('/password/google/code')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/password/google', $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('NewPassword2026!', $user->fresh()->password));
    }

    public function test_verified_otp_password_reset_enables_google_password_login(): void
    {
        $user = User::factory()->create(['google_id' => 'provider-account-reset', 'password_login_enabled' => false]);
        EmailOtp::create(['user_id' => $user->id, 'role' => $user->role, 'email' => $user->email, 'purpose' => 'password_reset',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);

        $this->postJson('/api/password/reset-otp', [
            'email' => $user->email, 'code' => '123456',
            'password' => 'ResetPassword2026!', 'password_confirmation' => 'ResetPassword2026!',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('ResetPassword2026!', $user->fresh()->password));
        $this->assertTrue($user->fresh()->password_login_enabled);
    }

    public function test_google_password_setup_is_owned_and_requires_a_verified_email_and_google_link(): void
    {
        $notLinked = User::factory()->create(['google_id' => null]);
        $this->actingAs($notLinked)->postJson('/password/google/code')->assertForbidden();
        $this->putJson('/password/google', ['code' => '123456', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'])->assertForbidden();

        $unverified = User::factory()->unverified()->create(['google_id' => 'provider-account-2']);
        $this->actingAs($unverified)->postJson('/password/google/code')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/password/google', ['code' => '123456', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('password', $unverified->fresh()->password));

        $owner = User::factory()->create(['google_id' => 'provider-account-3', 'password_login_enabled' => false]);
        EmailOtp::create(['user_id' => $owner->id, 'role' => $owner->role, 'email' => $owner->email, 'purpose' => 'google_password_setup',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
        $other = User::factory()->create(['google_id' => 'provider-account-4', 'password_login_enabled' => false]);
        $this->actingAs($other)->putJson('/password/google', ['code' => '123456', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
    }

    public function test_google_password_setup_rejects_expired_codes_and_outside_password_policy(): void
    {
        $user = User::factory()->create(['google_id' => 'provider-account-5', 'password_login_enabled' => false]);
        $otp = EmailOtp::create(['user_id' => $user->id, 'role' => $user->role, 'email' => $user->email, 'purpose' => 'google_password_setup',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->subSecond(), 'attempts' => 0]);
        $this->actingAs($user)->putJson('/password/google', ['code' => '123456', 'password' => 'Short1!', 'password_confirmation' => 'Short1!'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/password/google', ['code' => '123456', 'password' => 'ValidPassword2026!', 'password_confirmation' => 'ValidPassword2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(0, $otp->fresh()->attempts);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_google_account_with_an_existing_password_cannot_use_the_setup_endpoint_as_a_reset(): void
    {
        $user = User::factory()->create(['google_id' => 'provider-account-with-password', 'password' => 'ExistingPassword2026!']);
        $this->actingAs($user)->postJson('/password/google/code')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/password/google', ['code' => '123456', 'password' => 'ReplacementPassword2026!',
            'password_confirmation' => 'ReplacementPassword2026!'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('ExistingPassword2026!', $user->fresh()->password));
    }
}
