<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SecretMailProtectionTest extends TestCase
{
    use RefreshDatabase;

    public static function unsafeMailers(): array
    {
        return [['log'], ['logging-fallback'], ['logging-roundrobin'], ['cyclic']];
    }

    #[DataProvider('unsafeMailers')]
    public function test_otp_delivery_fails_without_logging_secret_mail_or_retaining_a_code(string $mailer): void
    {
        config([
            'mail.default' => $mailer,
            'mail.mailers.logging-fallback' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
            'mail.mailers.logging-roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'logging-fallback']],
            'mail.mailers.cyclic' => ['transport' => 'failover', 'mailers' => ['cyclic']],
        ]);
        Mail::fake();
        Log::spy();
        $this->postJson('/api/otp/send', ['email' => 'private.mail@bagoo.test', 'purpose' => 'registration'])
            ->assertStatus(503)->assertJsonPath('success', false)->assertJsonMissingPath('code')->assertJsonMissingPath('token');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('email_otps', 0);
        Log::shouldHaveReceived('warning')->once()->with('Failed to dispatch an OTP verification email.', [
            'email' => 'private.mail@bagoo.test', 'exception' => RuntimeException::class,
        ]);
        Log::shouldNotHaveReceived('debug');
    }

    public function test_verification_and_reset_links_cannot_be_sent_through_logging_mail(): void
    {
        config(['mail.default' => 'log']);
        Notification::fake();
        Log::spy();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post('/email/verification-notification')
            ->assertSessionHasErrors('email')->assertSessionMissing('status');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->post('/logout');
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasErrors('email')->assertSessionMissing('status');
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
        Log::shouldNotHaveReceived('debug');
    }

    public function test_registration_preserves_account_and_reports_undelivered_verification_mail(): void
    {
        config(['mail.default' => 'log']);
        Notification::fake();
        $this->post('/register', [
            'name' => 'Bagoo Mail Applicant', 'email' => 'mail.applicant@bagoo.test', 'role' => 'buyer',
            'password' => 'Password1234', 'password_confirmation' => 'Password1234',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email')->assertSessionMissing('status');
        $user = User::where('email', 'mail.applicant@bagoo.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame('none', $user->kyc_status);
        Notification::assertNothingSent();
    }

    public function test_rejected_transport_does_not_delete_a_previously_delivered_reset_token(): void
    {
        $user = User::factory()->create();
        Password::broker()->getRepository()->create($user);
        config(['mail.default' => 'log']);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_failed_delivery_removes_only_the_new_reset_token_and_logs_no_exception_message(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Private delivery failure with a secret token'));
        Log::spy();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Log::shouldHaveReceived('warning')->once()->with('Failed to dispatch a password reset link.', ['exception' => RuntimeException::class]);
        Log::shouldNotHaveReceived('debug');
    }

    public function test_authentication_secrets_are_not_flashed_after_invalid_input(): void
    {
        $this->post('/register', [
            'email' => 'invalid', 'password' => 'private password', 'password_confirmation' => 'private password',
            'otp_token' => 'private otp token', 'token' => 'private reset token', 'code' => '123456', 'claim_code' => 'private claim',
        ])->assertSessionHasErrors();
        foreach (['password', 'password_confirmation', 'otp_token', 'token', 'code', 'claim_code'] as $key) {
            $this->assertArrayNotHasKey($key, session('_old_input', []));
        }
    }

    public function test_otp_secrets_are_hidden_from_generic_model_serialization(): void
    {
        $otp = new EmailOtp(['code_hash' => 'private hash', 'token' => 'private token', 'purpose' => 'registration']);
        $this->assertArrayNotHasKey('code_hash', $otp->toArray());
        $this->assertArrayNotHasKey('token', $otp->toArray());
    }
}
