<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RiderApiAuthTest extends TestCase
{
    use RefreshDatabase;

    private function rider(array $values = []): User
    {
        return User::factory()->create($values + ['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved', 'birthday' => '1998-01-01', 'password' => 'Password1234']);
    }

    private function login(User $user)
    {
        return $this->postJson('/api/v1/auth/tokens', ['email' => strtoupper($user->email), 'password' => 'Password1234', 'device_name' => 'Test rider device']);
    }

    public function test_real_web_account_logs_in_reads_only_own_account_and_revokes_current_device(): void
    {
        $rider = $this->rider();
        $response = $this->login($rider)->assertOk()->assertJsonPath('data.user.id', (string) $rider->id)->assertJsonPath('data.user.can_access_portal', true);
        $token = $response->json('data.token');
        $this->assertNotSame($token, PersonalAccessToken::first()->token);
        $this->assertNotNull(PersonalAccessToken::first()->expires_at);
        $profile = $this->withToken($token)->getJson('/api/v1/rider/me')->assertOk();
        $this->assertStringContainsString('no-store', $profile->headers->get('Cache-Control'));
        $profile->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.id_document_path');
        $other = $this->login($rider)->json('data.token');
        $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($other)->getJson('/api/v1/rider/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/rider/me')->assertUnauthorized();
    }

    public function test_wrong_password_unknown_email_wrong_role_and_managed_fields_do_not_issue_tokens(): void
    {
        $rider = $this->rider();
        $this->postJson('/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'wrong', 'device_name' => 'Test'])->assertStatus(422);
        $this->postJson('/api/v1/auth/tokens', ['email' => 'unknown@example.test', 'password' => 'wrong', 'device_name' => 'Test'])->assertStatus(422);
        $this->login($this->rider(['role' => 'buyer']))->assertForbidden();
        $this->postJson('/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'Password1234', 'device_name' => 'Test', 'role' => 'courier'])->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_pending_and_rejected_are_holding_without_approval_and_restricted_accounts_are_denied(): void
    {
        foreach (['pending_approval', 'rejected'] as $state) {
            $user = $this->rider(['status' => 'pending_approval', 'kyc_status' => $state]);
            $this->login($user)->assertOk()->assertJsonPath('data.user.access_state', 'holding')->assertJsonPath('data.user.can_access_portal', false);
        }
        foreach (['suspended', 'inactive', 'unknown'] as $state) {
            $this->login($this->rider(['status' => $state]))->assertForbidden();
        }
        $this->login($this->rider(['kyc_status' => 'unknown']))->assertForbidden();
    }

    public function test_expiry_password_change_and_account_restriction_are_checked_on_each_read(): void
    {
        $user = $this->rider();
        $token = $this->login($user)->json('data.token');
        $user->update(['password' => 'Different12345']);
        $this->withToken($token)->getJson('/api/v1/rider/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $user = $this->rider();
        $token = $this->login($user)->json('data.token');
        $user->update(['status' => 'suspended']);
        $this->withToken($token)->getJson('/api/v1/rider/me')->assertForbidden();
        $user = $this->rider();
        $token = $this->login($user)->json('data.token');
        $this->travel(2)->days();
        $this->withToken($token)->getJson('/api/v1/rider/me')->assertUnauthorized();
    }

    public function test_login_is_throttled_and_browser_cookie_is_not_a_native_bearer(): void
    {
        $user = $this->rider();
        $this->actingAs($user)->getJson('/api/v1/rider/me')->assertUnauthorized();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/tokens', ['email' => 'missing@example.test', 'password' => 'wrong', 'device_name' => 'Test'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/tokens', ['email' => 'missing@example.test', 'password' => 'wrong', 'device_name' => 'Test'])->assertStatus(429);
    }

    private function verification(string $email): string
    {
        Mail::fake();
        $this->postJson('/api/v1/rider/registration/email/send', ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(OtpVerificationMail::class, function ($mail) use (&$code) {
            $code = $mail->otpCode;

            return true;
        });

        return $this->postJson('/api/v1/rider/registration/email/verify', ['email' => $email, 'code' => $code])->assertOk()->json('token');
    }

    private function application(string $email, string $token): array
    {
        return ['name' => 'Test Rider', 'birthday' => '2000-01-01', 'email' => $email, 'phone' => '09173334444', 'address' => 'Sample Street', 'city' => 'Pasig', 'barangay' => 'San Antonio',
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'ABC-123', 'license_number' => 'N01-22-123456',
            'password' => 'Password1234', 'password_confirmation' => 'Password1234', 'device_name' => 'Test rider', 'otp_token' => $token,
            'id_document' => UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'),
            'driver_license' => UploadedFile::fake()->create('license.pdf', 10, 'application/pdf'),
            'or_cr_document' => UploadedFile::fake()->create('vehicle.pdf', 10, 'application/pdf')];
    }

    public function test_email_verified_application_uses_web_models_private_documents_and_pending_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $email = 'applicant@example.test';
        $token = $this->verification($email);
        $response = $this->postJson('/api/v1/rider/applications', $this->application($email, $token))->assertCreated()
            ->assertJsonPath('data.user.status', 'pending_approval')->assertJsonPath('data.user.kyc_status', 'pending_approval')->assertJsonPath('data.user.email_verified', true);
        $user = User::where('email', $email)->firstOrFail();
        $this->assertNotNull($user->courierProfile);
        $this->assertSame('+639173334444', $user->phone);
        $this->assertTrue(Hash::check('Password1234', $user->password));
        Storage::disk('local')->assertExists($user->getRawOriginal('id_document_path'));
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->withToken($response->json('data.token'))->getJson('/api/v1/rider/me')->assertOk()->assertJsonPath('data.access_state', 'holding');
        $this->postJson('/api/v1/rider/applications', $this->application($email, $token))->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_rejects_unverified_wrong_email_missing_files_underage_and_privileged_inputs(): void
    {
        Storage::fake('local');
        $data = $this->application('new@example.test', 'invalid');
        $this->postJson('/api/v1/rider/applications', $data)->assertJsonValidationErrors('otp_token');
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $token = $this->verification('verified@example.test');
        $this->postJson('/api/v1/rider/applications', $this->application('wrong@example.test', $token))->assertJsonValidationErrors('otp_token');
        $data = $this->application('verified@example.test', $token);
        unset($data['id_document']);
        $this->postJson('/api/v1/rider/applications', $data)->assertJsonValidationErrors('id_document');
        $data = $this->application('verified@example.test', $token);
        $data['birthday'] = now()->subYears(17)->toDateString();
        $this->postJson('/api/v1/rider/applications', $data)->assertJsonValidationErrors('birthday');
        $data['role'] = 'courier';
        $this->postJson('/api/v1/rider/applications', $data)->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_delivery_failure_and_invalid_code_never_claim_verified(): void
    {
        config(['mail.default' => 'log']);
        $this->postJson('/api/v1/rider/registration/email/send', ['email' => 'not-sent@example.test'])->assertStatus(503)->assertJsonPath('success', false);
        $this->assertDatabaseCount('email_otps', 0);
        $this->postJson('/api/v1/rider/registration/email/verify', ['email' => 'not-sent@example.test', 'code' => '123456'])->assertStatus(422);
    }

    public function test_malformed_credentials_receive_json_validation_without_cache_or_token_leakage(): void
    {
        $response = $this->postJson('/api/v1/auth/tokens', ['email' => ['invalid'], 'password' => 'Password1234', 'device_name' => 'Test']);
        $response->assertJsonValidationErrors('email');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_native_registration_keeps_controls_visible_to_the_shared_validator(): void
    {
        Storage::fake('local');
        $email = 'controls@example.test';
        $token = $this->verification($email);
        $data = $this->application($email, $token);
        $data['name'] = "\tTest Rider";
        $this->postJson('/api/v1/rider/applications', $data)->assertJsonValidationErrors('name');
        $data = $this->application($email, $token);
        $data['vehicle_id'] = 1;
        $this->postJson('/api/v1/rider/applications', $data)->assertJsonValidationErrors('vehicle_id');
        $this->postJson('/api/v1/rider/registration/email/verify', ['email' => $email, 'code' => "123456\n"])->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('users', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_account_failure_preserves_verified_email_token_and_removes_new_private_files(): void
    {
        Storage::fake('local');
        $email = 'rollback@example.test';
        $token = $this->verification($email);
        Event::listen('eloquent.created: '.User::class, fn () => throw new \RuntimeException('Simulated account persistence failure'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/rider/applications', $this->application($email, $token));
            $this->fail('A failed account creation succeeded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated account persistence failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.User::class);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotNull(EmailOtp::where('email', $email)->sole()->token);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->postJson('/api/v1/rider/applications', $this->application($email, $token))->assertCreated();
        $this->assertNull(EmailOtp::where('email', $email)->sole()->token);
    }
}
