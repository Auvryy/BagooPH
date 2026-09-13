<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_send_otp_verification_code_for_registration(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/otp/send', [
            'email' => 'newbuyer@example.com',
            'purpose' => 'registration',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'expires_in' => 600,
                'cooldown' => 60,
            ]);

        Mail::assertSent(OtpVerificationMail::class, function ($mail) {
            return $mail->hasTo('newbuyer@example.com')
                && strlen($mail->code) === 6
                && $mail->purpose === 'registration';
        });

        $this->assertDatabaseHas('email_otps', [
            'email' => 'newbuyer@example.com',
            'purpose' => 'registration',
            'attempts' => 0,
        ]);
    }

    public function test_cannot_send_otp_for_already_registered_email_on_registration(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->postJson('/api/otp/send', [
            'email' => 'existing@example.com',
            'purpose' => 'registration',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        Mail::assertNothingSent();
    }

    public function test_cannot_send_password_reset_otp_for_unregistered_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/otp/send', [
            'email' => 'nonexistent@example.com',
            'purpose' => 'password_reset',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);

        Mail::assertNothingSent();
    }

    public function test_can_send_password_reset_otp_for_registered_email(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'validuser@example.com',
        ]);

        $response = $this->postJson('/api/otp/send', [
            'email' => 'validuser@example.com',
            'purpose' => 'password_reset',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        Mail::assertSent(OtpVerificationMail::class, function ($mail) {
            return $mail->hasTo('validuser@example.com')
                && $mail->purpose === 'password_reset';
        });
    }

    public function test_cooldown_rate_limit_prevents_immediate_resend(): void
    {
        Mail::fake();

        $this->postJson('/api/otp/send', [
            'email' => 'cooldown@example.com',
            'purpose' => 'registration',
        ]);

        $response = $this->postJson('/api/otp/send', [
            'email' => 'cooldown@example.com',
            'purpose' => 'registration',
        ]);

        $response->assertStatus(429)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertArrayHasKey('cooldown', $response->json());
    }

    public function test_verify_otp_with_valid_code_issues_token(): void
    {
        $code = '654321';
        EmailOtp::create([
            'email' => 'buyer@example.com',
            'code_hash' => Hash::make($code),
            'purpose' => 'registration',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'email' => 'buyer@example.com',
            'code' => $code,
            'purpose' => 'registration',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json();
        $this->assertNotEmpty($data['token']);

        $otpRecord = EmailOtp::where('email', 'buyer@example.com')->first();
        $this->assertNotNull($otpRecord->verified_at);
        $this->assertEquals($data['token'], $otpRecord->token);
    }

    public function test_verify_otp_with_invalid_code_decrements_attempts(): void
    {
        EmailOtp::create([
            'email' => 'buyer@example.com',
            'code_hash' => Hash::make('123456'),
            'purpose' => 'registration',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'email' => 'buyer@example.com',
            'code' => '999999',
            'purpose' => 'registration',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $otpRecord = EmailOtp::where('email', 'buyer@example.com')->first();
        $this->assertEquals(1, $otpRecord->attempts);
        $this->assertNull($otpRecord->verified_at);
    }

    public function test_verify_otp_fails_for_expired_code(): void
    {
        EmailOtp::create([
            'email' => 'buyer@example.com',
            'code_hash' => Hash::make('123456'),
            'purpose' => 'registration',
            'expires_at' => now()->subMinute(),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'email' => 'buyer@example.com',
            'code' => '123456',
            'purpose' => 'registration',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_buyer_registration_with_valid_otp_token_is_verified_and_logged_in(): void
    {
        $email = 'verified.buyer@example.com';
        $token = 'sample_secure_verification_token_12345';

        EmailOtp::create([
            'email' => $email,
            'code_hash' => Hash::make('123456'),
            'purpose' => 'registration',
            'token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 1,
        ]);

        $response = $this->post('/register', [
            'name' => 'Verified Buyer',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'buyer',
            'otp_token' => $token,
        ]);

        $response->assertRedirect(route('buyer.index'));
        $this->assertAuthenticated();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);

        // Assert token is single-use and burned
        $otpRecord = EmailOtp::where('email', $email)->first();
        $this->assertNull($otpRecord->token);
    }

    public function test_reset_password_with_verified_otp_token(): void
    {
        $email = 'resetuser@example.com';
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('OldPassword123!'),
        ]);

        $token = 'reset_token_secret_abcdef';
        EmailOtp::create([
            'email' => $email,
            'code_hash' => Hash::make('654321'),
            'purpose' => 'password_reset',
            'token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 1,
        ]);

        $response = $this->postJson('/api/password/reset-otp', [
            'email' => $email,
            'token' => $token,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecurePassword123!', $user->password));
    }

    public function test_reset_password_with_direct_otp_code(): void
    {
        $email = 'directreset@example.com';
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('OldPassword123!'),
        ]);

        $code = '888999';
        EmailOtp::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'purpose' => 'password_reset',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $response = $this->post('/password/reset-otp', [
            'email' => $email,
            'code' => $code,
            'password' => 'DirectResetPassword123!',
            'password_confirmation' => 'DirectResetPassword123!',
        ]);

        $response->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('DirectResetPassword123!', $user->password));
    }
}
