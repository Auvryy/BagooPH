<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mail\OtpVerificationMail;
use App\Models\AccountLoginSession;
use App\Models\CourierProfile;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\AccountSessions\AccountSessionActions;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\RiderAccountService;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSessionBoundariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
    }

    private function owner(array $values = []): User
    {
        return User::factory()->create($values + ['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved', 'password' => 'Password1234']);
    }

    private function peer(User $user, array $values = []): AccountLoginSession
    {
        return AccountLoginSession::create($values + ['user_id' => $user->id, 'channel' => 'web', 'method' => 'password',
            'session_key_hash' => hash('sha256', Str::random(40)), 'session_version' => (int) $user->session_version,
            'credential_fingerprint' => app(AccountSessionService::class)->fingerprint($user), 'device_label' => 'Earlier browser',
            'device_type' => 'computer', 'network_address' => inet_ntop(hex2bin('c0000214')), 'user_agent' => 'Earlier browser agent',
            'signed_in_at' => now()->subHour(), 'last_seen_at' => now()->subMinute(), 'expires_at' => now()->addHour()]);
    }

    private function intent(array $values = []): array
    {
        return $values + ['request_token' => (string) Str::uuid(), 'confirmation_method' => 'password', 'current_password' => 'Password1234'];
    }

    public static function restrictedAccounts(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['suspended', 'inactive', 'pending_approval'] as $status) {
                $cases[$role.' '.$status] = [$role, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('restrictedAccounts')]
    public function test_restricted_accounts_keep_private_security_without_operational_access(string $role, string $status): void
    {
        $user = $this->owner(['role' => $role, 'status' => $status, 'kyc_status' => 'rejected', 'email_verified_at' => null]);
        $other = $this->peer($user);
        $this->actingAs($user)->getJson('/account/security')->assertOk()->assertJsonPath('canUsePortal', false)->assertJsonPath('canConfirmByEmail', false);
        $this->postJson('/account/security/sessions/'.$other->id.'/revoke', $this->intent())->assertOk();
        $this->assertFalse($user->fresh()->canAccessPortal());
        $this->assertSame($status, $user->fresh()->status);
        $this->assertSame('rejected', $user->fresh()->kyc_status);
    }

    public function test_closed_account_cannot_use_security_or_receive_a_code(): void
    {
        Mail::fake();
        $user = $this->owner(['status' => 'inactive', 'closed_at' => now()]);
        $this->actingAs($user)->getJson('/account/security')->assertForbidden();
        $this->postJson('/account/security/code')->assertUnauthorized();
        $this->assertDatabaseCount('account_session_actions', 0);
        Mail::assertNothingSent();
    }

    public function test_verified_original_email_is_used_with_actual_role_and_cooldown(): void
    {
        Mail::fake();
        $user = $this->owner(['role' => 'seller']);
        $this->actingAs($user)->postJson('/account/security/code')->assertOk()->assertJsonPath('success', true)->assertJsonPath('cooldown', 60);
        Mail::assertSent(OtpVerificationMail::class, fn ($mail) => $mail->hasTo($user->email)
            && $mail->purpose === AccountSessionActions::OTP_PURPOSE && ! str_contains($mail->envelope()->subject, $mail->code));
        $this->assertDatabaseHas('email_otps', ['user_id' => $user->id, 'email' => $user->email, 'role' => 'seller', 'purpose' => AccountSessionActions::OTP_PURPOSE]);
        $this->postJson('/account/security/code')->assertTooManyRequests()->assertJsonPath('success', false);
        $this->postJson('/account/security/code', ['email' => 'other@bagoo.test', 'user_id' => 999, 'role' => 'admin', 'purpose' => 'password_reset'])->assertUnprocessable();
        Mail::assertSentCount(1);
    }

    public function test_unverified_email_and_failed_mail_do_not_claim_a_sent_confirmation(): void
    {
        $user = $this->owner(['email_verified_at' => null]);
        $this->actingAs($user)->postJson('/account/security/code')->assertUnprocessable()->assertJsonValidationErrors('code');
        $user->forceFill(['email_verified_at' => now()])->save();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Controlled mail transport failure'));
        $this->postJson('/account/security/code')->assertStatus(503)->assertJsonPath('success', false);
        $this->assertSame(0, EmailOtp::where('user_id', $user->id)->count());
    }

    public static function invalidChallenges(): array
    {
        return ['other purpose' => [['purpose' => 'password_reset']], 'other owner' => [['foreign' => true]],
            'other role' => [['role' => 'seller']], 'expired' => [['expired' => true]], 'exhausted' => [['attempts' => 5]]];
    }

    #[DataProvider('invalidChallenges')]
    public function test_email_confirmation_is_bound_to_purpose_owner_role_expiry_and_attempts(array $values): void
    {
        $user = $this->owner();
        $other = $this->peer($user);
        $foreign = $this->owner();
        EmailOtp::create(['user_id' => isset($values['foreign']) ? $foreign->id : $user->id,
            'email' => $user->email, 'role' => $values['role'] ?? 'buyer', 'purpose' => $values['purpose'] ?? AccountSessionActions::OTP_PURPOSE,
            'code_hash' => Hash::make('123456'), 'attempts' => $values['attempts'] ?? 0,
            'expires_at' => isset($values['expired']) ? now()->subSecond() : now()->addMinutes(10)]);
        $this->actingAs($user)->postJson('/account/security/sessions/'.$other->id.'/revoke',
            $this->intent(['confirmation_method' => 'email', 'current_password' => null, 'code' => '123456']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($other->fresh()->revoked_at);
        $this->assertDatabaseCount('account_session_actions', 0);
    }

    public function test_wrong_code_attempt_limit_survives_rejected_actions(): void
    {
        $user = $this->owner();
        $other = $this->peer($user);
        $otp = EmailOtp::create(['user_id' => $user->id, 'email' => $user->email, 'role' => 'buyer', 'purpose' => AccountSessionActions::OTP_PURPOSE,
            'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);
        $this->actingAs($user);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/account/security/sessions/'.$other->id.'/revoke', $this->intent(['confirmation_method' => 'email', 'code' => '000000']))->assertUnprocessable();
            $this->assertSame($attempt, $otp->fresh()->attempts);
        }
        $this->postJson('/account/security/sessions/'.$other->id.'/revoke', $this->intent(['confirmation_method' => 'email', 'code' => '123456']))->assertUnprocessable();
        $this->assertNull($other->fresh()->revoked_at);
    }

    public function test_malformed_confirmation_and_client_selected_identity_cannot_change_authentication(): void
    {
        $user = $this->owner();
        $other = $this->peer($user);
        $this->actingAs($user);
        foreach ([['request_token' => 'bad'], ['confirmation_method' => 'none'], ['current_password' => ''],
            ['confirmation_method' => 'email', 'code' => ' 123456 '], ['user_id' => $user->id], ['token_id' => 1]] as $values) {
            $this->postJson('/account/security/sessions/'.$other->id.'/revoke', $this->intent($values))->assertUnprocessable();
        }
        $this->assertNull($other->fresh()->revoked_at);
        $this->assertDatabaseCount('account_session_actions', 0);
    }

    public function test_page_limits_are_bounded_and_other_accounts_never_enter_either_page(): void
    {
        $user = $this->owner();
        for ($index = 0; $index < 14; $index++) {
            $this->peer($user);
        }
        $foreign = $this->peer($this->owner());
        $this->actingAs($user)->getJson('/account/security?history_page=2&sessions_page=1')->assertOk()
            ->assertJsonCount(12, 'sessions.data')->assertJsonPath('sessions.total', 15)
            ->assertJsonCount(2, 'signIns.data')->assertJsonPath('signIns.total', 14)->assertDontSee($foreign->id);
        $this->getJson('/account/security?sessions_page=2')->assertOk()->assertJsonCount(3, 'sessions.data');
        $this->getJson('/account/security?sessions_page=1000000')->assertOk()->assertJsonCount(0, 'sessions.data');
        $this->getJson('/account/security?sessions_page=-1')->assertUnprocessable();
        $this->getJson('/account/security?history_page=1000001')->assertUnprocessable();
    }

    public function test_metadata_cleanup_keeps_ended_authentication_and_action_receipts(): void
    {
        $user = $this->owner();
        $other = $this->peer($user);
        $this->actingAs($user)->postJson('/account/security/sessions/'.$other->id.'/revoke', $this->intent())->assertOk();
        $other->refresh();
        $raw = $other->getRawOriginal('network_address');
        $this->assertNotSame(inet_ntop(hex2bin('c0000214')), $raw);
        $this->travel(91)->days();
        $this->artisan('account-security:minimise-metadata')->assertSuccessful();
        $other->refresh();
        $this->assertNull($other->network_address);
        $this->assertNull($other->user_agent);
        $this->assertNotNull($other->revoked_at);
        $this->assertNotNull($other->metadata_cleared_at);
        $this->assertDatabaseCount('account_session_actions', 1);
        $this->assertFalse(app(AccountSessionService::class)->active($other, $user->fresh()));
    }

    public function test_native_sessions_and_old_tokens_are_revoked_without_changing_rider_duty(): void
    {
        $user = $this->owner(['role' => 'courier']);
        $profile = CourierProfile::factory()->create(['user_id' => $user->id, 'is_available' => true]);
        $issued = app(RiderAccountService::class)->session($user, 'Current rider app');
        $old = $user->createToken('rider:Earlier app', ['rider:account', 'rider:logout'], now()->addDay());
        $old->accessToken->forceFill(['credential_fingerprint' => app(AccountSessionService::class)->fingerprint($user)])->save();
        $this->actingAs($user)->getJson('/account/security')->assertOk()->assertJsonPath('sessions.total', 3)->assertJsonPath('signIns.total', 1);
        $this->assertNull(AccountLoginSession::where('native_token_id', $old->accessToken->id)->sole()->signed_in_at);
        $this->postJson('/account/security/sessions/revoke-others', $this->intent())->assertOk()->assertJsonPath('affected_count', 2);
        $this->withToken($issued['token'])->getJson('/api/v1/rider/me')->assertUnauthorized();
        $this->withToken($old->plainTextToken)->getJson('/api/v1/rider/me')->assertUnauthorized();
        $this->assertTrue($profile->fresh()->is_available);
        $this->assertSame('courier', $user->fresh()->role);
        $this->getJson('/account/security')->assertOk()->assertJsonPath('sessions.total', 1);
    }

    public function test_authentication_that_loses_to_a_generation_change_is_not_recorded_as_successful(): void
    {
        $user = $this->owner();
        Event::listen(Login::class, function (Login $event) {
            if ($event->guard === 'web') {
                DB::table('users')->where('id', $event->user->id)->increment('session_version');
            }
        });
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Password1234'])->assertUnauthorized();
        $this->assertDatabaseCount('account_login_sessions', 0);
        $this->assertGuest();
    }

    public function test_native_issuance_rejects_a_credential_check_that_precedes_password_or_session_revocation(): void
    {
        foreach (['password', 'session_version'] as $change) {
            $user = $this->owner(['role' => 'courier']);
            $fresh = $user->fresh();
            $fresh->forceFill($change === 'password' ? ['password' => 'ChangedPassword123!'] : ['session_version' => 1])->save();
            try {
                app(RiderAccountService::class)->session($user, 'Rider app');
                $this->fail('A stale authentication check must not issue a new token.');
            } catch (HttpResponseException $exception) {
                $this->assertSame(401, $exception->getResponse()->getStatusCode());
            }
            $this->assertSame(0, $user->tokens()->count());
            $this->assertSame(0, AccountLoginSession::where('user_id', $user->id)->count());
        }
    }

    public function test_email_confirmation_can_retry_a_failed_save_but_cannot_authorize_a_second_action(): void
    {
        $user = $this->owner();
        $other = $this->peer($user);
        $otp = EmailOtp::create(['user_id' => $user->id, 'email' => $user->email, 'role' => 'buyer', 'purpose' => AccountSessionActions::OTP_PURPOSE,
            'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);
        $fail = true;
        User::updating(function (User $record) use (&$fail) {
            if ($fail && $record->isDirty('remember_token')) {
                throw new \RuntimeException('Controlled sign-out save failure');
            }
        });
        $intent = $this->intent(['confirmation_method' => 'email', 'current_password' => null, 'code' => '123456']);
        $this->actingAs($user)->postJson('/account/security/sessions/'.$other->id.'/revoke', $intent)->assertServerError();
        $this->assertNull($other->fresh()->revoked_at);
        $this->assertNotNull($otp->fresh()->token);
        $this->assertDatabaseCount('account_session_actions', 0);
        $fail = false;
        $this->postJson('/account/security/sessions/'.$other->id.'/revoke', $intent)->assertOk();
        $this->assertNull($otp->fresh()->token);
        $this->postJson('/account/security/sessions/'.$other->id.'/revoke', $intent)->assertOk();
        $this->postJson('/account/security/sessions/revoke-others', $this->intent(['confirmation_method' => 'email', 'code' => '123456']))->assertUnprocessable();
        $this->assertDatabaseCount('account_session_actions', 1);
    }

    public function test_private_page_loads_on_plain_http_and_encrypts_history_on_supported_origins(): void
    {
        $this->actingAs($this->owner());
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()) ?? ''];
        foreach (['http://preview.example.test' => false, 'http://localhost' => true, 'https://preview.example.test' => true] as $origin => $encrypted) {
            $this->get($origin.'/account/security', $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('component', 'Account/Security')->assertJsonPath('encryptHistory', $encrypted);
        }
    }
}
