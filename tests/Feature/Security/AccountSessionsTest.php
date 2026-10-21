<?php

namespace Tests\Feature\Security;

use App\Models\AccountLoginSession;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\AccountSessions\AccountSessionActions;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\RiderAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        $this->withCredentials();
    }

    private function owner(string $role = 'buyer'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved', 'password' => 'Password1234']);
    }

    private function signIn(User $user): AccountLoginSession
    {
        Auth::forgetGuards();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'Password1234', 'role' => $user->role]);
        $response->assertRedirect();
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie(config('session.cookie'), $cookie->getValue());

        return AccountLoginSession::where('user_id', $user->id)->whereNotNull('signed_in_at')->latest('created_at')->firstOrFail();
    }

    private function other(User $user, array $attributes = []): AccountLoginSession
    {
        return AccountLoginSession::create($attributes + ['user_id' => $user->id, 'channel' => 'web', 'method' => 'password',
            'session_key_hash' => hash('sha256', Str::random(40)), 'session_version' => (int) $user->fresh()->session_version,
            'credential_fingerprint' => app(AccountSessionService::class)->fingerprint($user->fresh()),
            'device_label' => 'Another browser', 'device_type' => 'computer', 'network_address' => inet_ntop(hex2bin('c000020a')),
            'signed_in_at' => now()->subHour(), 'last_seen_at' => now()->subMinute(), 'expires_at' => now()->addHour()]);
    }

    private function intent(array $extra = []): array
    {
        return $extra + ['request_token' => (string) Str::uuid(), 'confirmation_method' => 'password', 'current_password' => 'Password1234'];
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['buyer', 'seller', 'courier', 'logistics', 'admin']);
    }

    #[DataProvider('roles')]
    public function test_every_role_records_a_real_login_and_sees_only_its_owned_private_sessions(string $role): void
    {
        $user = $this->owner($role);
        $current = $this->signIn($user);
        $foreign = $this->other($this->owner());
        $this->travel(5)->minutes();
        $response = $this->getJson('/account/security')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('sessions.total', 1)->assertJsonPath('currentSessionId', $current->id)
            ->assertJsonPath('sessionOwnerId', $user->id)
            ->assertJsonPath('lastSignIn.id', $current->id)->assertJsonPath('sessions.data.0.current', true)
            ->assertJsonPath('signIns.total', 1);
        $this->assertSame($current->signed_in_at->toIso8601String(), $current->fresh()->signed_in_at->toIso8601String());
        $this->assertSame(1, AccountLoginSession::where('user_id', $user->id)->count());
        $response->assertDontSee($foreign->id)->assertDontSee('session_key_hash')->assertDontSee('credential_fingerprint')->assertDontSee('remember_token');
        $this->assertSame($role, $user->fresh()->role);
        $this->assertSame('approved', $user->fresh()->kyc_status);
    }

    public function test_failed_login_and_ordinary_page_reads_do_not_fabricate_successful_sign_ins(): void
    {
        $user = $this->owner();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseCount('account_login_sessions', 0);
        $this->actingAs($user)->getJson('/account/security')->assertOk()->assertJsonPath('lastSignIn', null)->assertJsonPath('signIns.total', 0);
        $this->assertNull(AccountLoginSession::sole()->signed_in_at);
    }

    public function test_guest_and_foreign_session_actions_do_not_expose_or_revoke_another_account(): void
    {
        $this->getJson('/account/security')->assertUnauthorized();
        $user = $this->owner();
        $this->signIn($user);
        $foreign = $this->other($this->owner());
        $this->postJson('/account/security/sessions/'.$foreign->id.'/revoke', $this->intent())->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
        $this->assertDatabaseCount('account_session_actions', 0);
    }

    #[DataProvider('roles')]
    public function test_ending_one_owned_session_keeps_current_and_unrelated_sessions_and_original_result(string $role): void
    {
        $user = $this->owner($role);
        $current = $this->signIn($user);
        $one = $this->other($user);
        $two = $this->other($user);
        $intent = $this->intent();
        $url = '/account/security/sessions/'.$one->id.'/revoke';
        $first = $this->postJson($url, $intent)->assertOk()->assertJsonPath('affected_count', 1)->json('reference');
        $ended = $one->fresh()->revoked_at;
        $this->travel(2)->minutes();
        $this->postJson($url, $intent)->assertOk()->assertJsonPath('reference', $first)->assertJsonPath('affected_count', 1);
        $this->assertSame($ended->toIso8601String(), $one->fresh()->revoked_at->toIso8601String());
        $this->assertNull($current->fresh()->revoked_at);
        $this->assertNull($two->fresh()->revoked_at);
        $this->assertDatabaseCount('account_session_actions', 1);
        $this->getJson('/account/security')->assertOk()->assertJsonPath('sessions.total', 2);
    }

    public function test_replaying_all_others_cannot_revoke_a_new_session_and_changed_selection_conflicts(): void
    {
        $user = $this->owner();
        $current = $this->signIn($user);
        $one = $this->other($user);
        $intent = $this->intent();
        $this->postJson('/account/security/sessions/revoke-others', $intent)->assertOk()->assertJsonPath('affected_count', 1);
        $new = $this->other($user);
        $this->postJson('/account/security/sessions/revoke-others', $intent)->assertOk()->assertJsonPath('affected_count', 1);
        $this->assertNull($new->fresh()->revoked_at);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertSame(1, $current->fresh()->session_version);
        $this->assertNotNull($one->fresh()->revoked_at);
        $this->postJson('/account/security/sessions/'.$new->id.'/revoke', $intent)->assertConflict();
        $this->assertDatabaseCount('account_session_actions', 1);
    }

    public function test_wrong_password_and_current_session_selection_do_not_change_security_state(): void
    {
        $user = $this->owner();
        $current = $this->signIn($user);
        $other = $this->other($user);
        $token = $user->fresh()->remember_token;
        $this->postJson('/account/security/sessions/'.$other->id.'/revoke', $this->intent(['current_password' => 'incorrect']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/account/security/sessions/'.$current->id.'/revoke', $this->intent())->assertUnprocessable()->assertJsonValidationErrors('session');
        $this->assertNull($other->fresh()->revoked_at);
        $this->assertSame($token, $user->fresh()->remember_token);
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertDatabaseCount('account_session_actions', 0);
    }

    public function test_native_sign_in_is_visible_and_web_revocation_actually_rejects_its_bearer_token(): void
    {
        $user = $this->owner('courier');
        $issued = app(RiderAccountService::class)->session($user, 'Test handset');
        $native = AccountLoginSession::where('channel', 'rider')->sole();
        $this->assertNotNull($native->signed_in_at);
        $this->signIn($user);
        $this->getJson('/account/security')->assertOk()->assertJsonPath('sessions.total', 2);
        $this->postJson('/account/security/sessions/'.$native->id.'/revoke', $this->intent())->assertOk();
        $this->withToken($issued['token'])->getJson('/api/v1/rider/me')->assertUnauthorized();
        $this->assertNotNull($native->fresh()->revoked_at);
    }

    public function test_email_confirmation_is_owner_role_and_purpose_bound_and_wrong_attempts_persist(): void
    {
        $user = $this->owner('seller');
        $this->signIn($user);
        $other = $this->other($user);
        $otp = EmailOtp::create(['user_id' => $user->id, 'role' => 'seller', 'email' => $user->email,
            'purpose' => AccountSessionActions::OTP_PURPOSE, 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);
        $intent = $this->intent(['confirmation_method' => 'email', 'current_password' => null, 'code' => '654321']);
        $url = '/account/security/sessions/'.$other->id.'/revoke';
        $this->postJson($url, $intent)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, $otp->fresh()->attempts);
        $intent['code'] = '123456';
        $this->postJson($url, $intent)->assertOk()->assertJsonPath('affected_count', 1);
        $this->assertNull($otp->fresh()->token);
        $this->postJson($url, $intent)->assertOk()->assertJsonPath('affected_count', 1);
        $another = $this->other($user);
        $this->postJson('/account/security/sessions/'.$another->id.'/revoke', $this->intent(['confirmation_method' => 'email', 'current_password' => null, 'code' => '123456']))->assertUnprocessable();
        $this->assertNull($another->fresh()->revoked_at);
    }

    public function test_network_metadata_is_encrypted_and_neither_a_location_nor_credentials_are_fabricated(): void
    {
        $user = $this->owner();
        $this->signIn($user);
        $other = $this->other($user, ['user_agent' => 'Private original agent']);
        $raw = DB::table('account_login_sessions')->where('id', $other->id)->first();
        $this->assertNotSame($other->network_address, $raw->network_address);
        $this->assertNotSame('Private original agent', $raw->user_agent);
        $response = $this->getJson('/account/security')->assertOk();
        $response->assertDontSee('Private original agent')->assertDontSee('network_address')->assertDontSee('native_token_id')->assertDontSee('session_version');
        $this->assertArrayNotHasKey('city', $response->json('sessions.data.0'));
        $this->assertArrayNotHasKey('token', $response->json('sessions.data.0'));
    }
}
