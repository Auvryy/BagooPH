<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OtpRecoveryGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_and_native_verification_reject_owned_contact_and_legacy_identity_before_sending_mail(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['email' => 'Owner@bagoo.test']);
        $owner->accountEmails()->create(['email' => 'contact@bagoo.test', 'verified_at' => now()]);
        DB::table('users')->insert(['name' => 'Legacy Owner', 'email' => 'Legacy@bagoo.test', 'password' => Hash::make('Password1234')]);
        foreach (['OWNER@BAGOO.TEST', 'CONTACT@BAGOO.TEST', 'LEGACY@BAGOO.TEST'] as $email) {
            $this->postJson('/api/otp/send', ['email' => $email])->assertStatus(422)->assertJsonPath('success', false);
            $this->postJson('/api/v1/rider/registration/email/send', ['email' => $email])->assertStatus(422);
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('email_otps', 0);
    }

    public static function malformedCodes(): array
    {
        return [['12345'], ['1234567'], ['123a56'], ["123456\n"], ['１２３４５６'], ['']];
    }

    #[DataProvider('malformedCodes')]
    public function test_malformed_codes_do_not_consume_a_verification_attempt(string $code): void
    {
        $otp = EmailOtp::create(['email' => 'buyer@bagoo.test', 'purpose' => 'registration', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
        $this->postJson('/api/otp/verify', ['email' => $otp->email, 'code' => $code])->assertJsonValidationErrors('code');
        $this->assertSame(0, $otp->fresh()->attempts);
        $this->assertNull($otp->fresh()->token);
    }

    public function test_web_aliases_share_a_network_send_limit_across_different_email_addresses(): void
    {
        Mail::fake();
        $paths = ['/otp/send', '/api/otp/send', '/otp/resend', '/api/otp/resend'];
        for ($index = 0; $index < 10; $index++) {
            $this->postJson($paths[$index % count($paths)], ['email' => 'buyer'.$index.'@bagoo.test'])->assertOk();
        }
        $this->postJson('/api/otp/send', ['email' => 'another@bagoo.test'])->assertStatus(429);
        $this->assertDatabaseCount('email_otps', 10);
        Mail::assertSentCount(10);
    }

    public function test_raw_whitespace_controls_are_rejected_but_approved_outer_spaces_are_normalized(): void
    {
        Mail::fake();
        $this->postJson('/api/otp/send', ['email' => "buyer@bagoo.test\n"])->assertJsonValidationErrors('email');
        $this->postJson('/api/otp/send', ['email' => '  Buyer@BAGOO.TEST  '])->assertOk();
        Mail::assertSent(OtpVerificationMail::class, fn ($mail) => $mail->hasTo('buyer@bagoo.test'));
        $this->assertDatabaseCount('email_otps', 1);
    }
}
