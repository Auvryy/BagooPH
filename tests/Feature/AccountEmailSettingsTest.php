<?php

namespace Tests\Feature;

use App\Mail\OtpVerificationMail;
use App\Models\AccountEmail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\AccountRecoveryNotification;
use App\Services\AccountEmailService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountEmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->buyer()->create(['password' => 'Password1234']);
    }

    private function added(User $user, string $email = 'contact@bagoo.test'): AccountEmail
    {
        return $user->accountEmails()->create(['email' => $email, 'verified_at' => now()]);
    }

    private function issue(User $user, string $email = 'contact@bagoo.test', string $purpose = 'account_email'): EmailOtp
    {
        return EmailOtp::create(['user_id' => $user->id, 'email' => $email, 'purpose' => $purpose,
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
    }

    public function test_additional_address_requires_password_and_mailed_otp_then_updates_settings(): void
    {
        Mail::fake();
        $buyer = $this->buyer();
        $original = $buyer->only(['email', 'email_verified_at', 'role', 'kyc_status']);
        $this->actingAs($buyer)->postJson('/account/emails/send', ['email' => 'contact@bagoo.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/account/emails/send', ['email' => 'CONTACT@Bagoo.Test', 'current_password' => 'Password1234'])->assertOk();
        $this->assertDatabaseCount('account_emails', 1);
        $code = null;
        Mail::assertSent(OtpVerificationMail::class, function ($mail) use (&$code) {
            $code = $mail->otpCode;

            return $mail->hasTo('contact@bagoo.test') && $mail->purpose === 'account_email';
        });
        $this->postJson('/account/emails/confirm', ['email' => 'contact@bagoo.test', 'current_password' => 'Password1234', 'code' => $code])->assertOk();
        $this->assertEquals($original, $buyer->fresh()->only(array_keys($original)));
        $this->assertDatabaseCount('account_emails', 2);
        $this->assertNull(EmailOtp::first()->token);
        $this->get('/buyer/profile?tab=account')->assertInertia(fn (Assert $page) => $page
            ->has('emailSettings.addresses', 2)->where('emailSettings.addresses.1.email', 'contact@bagoo.test')->where('emailSettings.addresses.1.verified', true));
        // Retrying a response lost after verification does not create a duplicate or change contact preference.
        $this->postJson('/account/emails/confirm', ['email' => 'contact@bagoo.test', 'current_password' => 'Password1234', 'code' => $code])->assertOk();
        $this->assertDatabaseCount('account_emails', 2);
    }

    public function test_verification_is_bound_to_the_actor_purpose_and_current_eligibility(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer();
        $otp = $this->issue($buyer);
        $payload = ['email' => 'contact@bagoo.test', 'current_password' => 'Password1234', 'code' => '123456'];
        $this->actingAs($other)->postJson('/account/emails/confirm', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(0, $otp->fresh()->attempts);
        $this->app['auth']->guard()->logout();
        $this->postJson('/api/otp/verify', ['email' => 'contact@bagoo.test', 'code' => '123456', 'purpose' => 'registration'])->assertUnprocessable();
        $buyer->update(['status' => 'suspended']);
        $this->actingAs($buyer)->postJson('/account/emails/confirm', $payload)->assertForbidden();
        $this->assertDatabaseCount('account_emails', 2);
    }

    public function test_expired_and_exhausted_codes_do_not_verify_and_wrong_attempts_persist(): void
    {
        $buyer = $this->buyer();
        $otp = $this->issue($buyer);
        $this->actingAs($buyer);
        $payload = ['email' => 'contact@bagoo.test', 'current_password' => 'Password1234'];
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/account/emails/confirm', $payload + ['code' => '000000'])->assertUnprocessable();
            $this->assertSame($i, $otp->fresh()->attempts);
        }
        $this->postJson('/account/emails/confirm', $payload + ['code' => '123456'])->assertUnprocessable();
        $otp->update(['attempts' => 0, 'expires_at' => now()->subSecond()]);
        $this->postJson('/account/emails/confirm', $payload + ['code' => '123456'])->assertUnprocessable();
        $this->assertDatabaseCount('account_emails', 1);
    }

    public function test_primary_and_additional_email_collisions_are_rejected_case_insensitively(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer();
        $address = $this->added($other);
        $this->actingAs($buyer);
        foreach ([$other->email, strtoupper($address->email), $buyer->email] as $email) {
            $this->postJson('/account/emails/send', ['email' => $email, 'current_password' => 'Password1234'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->app['auth']->guard()->logout();
        $this->postJson('/api/otp/send', ['email' => strtoupper($address->email), 'purpose' => 'registration'])->assertUnprocessable();
        $this->post('/register', ['name' => 'New Buyer', 'email' => strtoupper($address->email), 'password' => 'Password1234', 'password_confirmation' => 'Password1234'])->assertSessionHasErrors('email');
        try {
            User::factory()->create(['email' => strtoupper($address->email)]);
            $this->fail('A racing primary registration must respect additional email ownership.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('account_emails.email', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 2);
    }

    public function test_contact_preference_and_removal_are_owned_and_original_email_stays(): void
    {
        $buyer = $this->buyer();
        $address = $this->added($buyer);
        $original = $buyer->accountEmails()->where('is_original', true)->firstOrFail();
        $foreign = $this->added($this->buyer(), 'foreign@bagoo.test');
        $this->actingAs($buyer);
        $credentials = ['current_password' => 'Password1234'];
        $this->patchJson('/account/emails/'.$foreign->id.'/preferred', $credentials)->assertForbidden();
        $this->deleteJson('/account/emails/'.$foreign->id, $credentials)->assertForbidden();
        $this->patchJson('/account/emails/'.$address->id.'/preferred', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->patchJson('/account/emails/'.$address->id.'/preferred', $credentials)->assertOk();
        $this->assertSame($address->email, $buyer->fresh()->routeNotificationForMail(null));
        $this->assertSame($buyer->email, $buyer->fresh()->routeNotificationForMail(new VerifyEmail));
        $this->deleteJson('/account/emails/'.$original->id, $credentials)->assertUnprocessable();
        $this->deleteJson('/account/emails/'.$address->id, $credentials)->assertOk();
        $this->assertNull($buyer->fresh()->preferred_contact_email_id);
        $this->assertSame($buyer->email, $buyer->fresh()->routeNotificationForMail(null));
        $this->assertNull(app(AccountEmailService::class)->recoveryOwner($address->email));
    }

    public function test_verified_address_recovers_its_owner_by_otp_and_does_not_become_a_login_identity(): void
    {
        $buyer = $this->buyer();
        $this->added($buyer);
        $this->issue($buyer, 'contact@bagoo.test', 'password_reset');
        $payload = ['email' => 'contact@bagoo.test', 'code' => '123456', 'password' => 'RecoveredPassword1234', 'password_confirmation' => 'RecoveredPassword1234'];
        $this->postJson('/api/password/reset-otp', $payload)->assertOk();
        $this->assertTrue(Hash::check('RecoveredPassword1234', $buyer->fresh()->password));
        $this->postJson('/api/password/reset-otp', $payload)->assertUnprocessable();
        $this->post('/login', ['email' => 'contact@bagoo.test', 'password' => 'RecoveredPassword1234'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $buyer->email, 'password' => 'RecoveredPassword1234'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($buyer);
    }

    public function test_recovery_tokens_do_not_transfer_when_an_additional_address_changes_owner(): void
    {
        $buyer = $this->buyer();
        $address = $this->added($buyer);
        $otp = $this->issue($buyer, $address->email, 'password_reset');
        $otp->update(['verified_at' => now(), 'token' => 'old-owner-token']);
        $this->actingAs($buyer)->deleteJson('/account/emails/'.$address->id, ['current_password' => 'Password1234'])->assertOk();
        $next = $this->buyer();
        $this->added($next);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/password/reset-otp', ['email' => 'contact@bagoo.test', 'token' => 'old-owner-token', 'password' => 'AttackerPassword1234', 'password_confirmation' => 'AttackerPassword1234'])->assertUnprocessable();
        $this->assertTrue(Hash::check('Password1234', $next->fresh()->password));
    }

    public function test_link_recovery_goes_to_the_requested_verified_email_and_removal_revokes_it(): void
    {
        Notification::fake();
        $buyer = $this->buyer();
        $address = $this->added($buyer);
        $this->post('/forgot-password', ['email' => $address->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($buyer, AccountRecoveryNotification::class, function ($notification) use ($buyer, $address) {
            $this->assertSame($address->email, $buyer->routeNotificationForMail($notification));
            $this->assertStringContainsString(rawurlencode($buyer->email), $notification->toMail($buyer)->actionUrl);
            $this->assertTrue(Password::broker()->tokenExists($buyer, $notification->token));

            return true;
        });
        $this->actingAs($buyer)->deleteJson('/account/emails/'.$address->id, ['current_password' => 'Password1234'])->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_unverified_additional_address_cannot_be_preferred_or_recover_an_account(): void
    {
        $buyer = $this->buyer();
        $address = $buyer->accountEmails()->create(['email' => 'pending@bagoo.test']);
        $this->actingAs($buyer)->patchJson('/account/emails/'.$address->id.'/preferred', ['current_password' => 'Password1234'])->assertUnprocessable();
        $this->assertNull(app(AccountEmailService::class)->recoveryOwner($address->email));
    }

    public function test_original_email_remains_fixed_even_for_direct_database_writes(): void
    {
        $buyer = $this->buyer();
        $this->actingAs($buyer)->patchJson('/profile', ['name' => $buyer->name, 'email' => 'replacement@bagoo.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        try {
            DB::table('users')->where('id', $buyer->id)->update(['email' => 'replacement@bagoo.test']);
            $this->fail('The original identity must be retained by the database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Original sign-in email', $exception->getMessage());
        }
        $this->assertNotSame('replacement@bagoo.test', $buyer->fresh()->email);
        $this->assertNotNull($buyer->fresh()->email_verified_at);
    }

    public function test_contact_update_preserves_exact_legacy_original_email_and_verification(): void
    {
        $buyer = User::factory()->buyer()->create(['email' => 'Buyer@BAGOO.TEST']);
        $verified = $buyer->fresh()->email_verified_at;
        $this->actingAs($buyer)->from('/profile')->patch('/profile', ['name' => $buyer->name, 'email' => 'buyer@bagoo.test', 'phone' => '0917 123 4567'])
            ->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertSame('Buyer@BAGOO.TEST', $buyer->fresh()->email);
        $this->assertEquals($verified, $buyer->fresh()->email_verified_at);
        $this->assertSame('+639171234567', $buyer->fresh()->phone);
        $this->assertNotNull($buyer->accountEmails()->first()->verified_at);
    }
}
