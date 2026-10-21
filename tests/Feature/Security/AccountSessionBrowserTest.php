<?php

namespace Tests\Feature\Security;

use App\Models\AccountLoginSession;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class AccountSessionBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        $this->withCredentials();
        config(['session.driver' => 'database']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved', 'password' => 'Password1234']);
    }

    /** Separate HTTP cookies and fresh request-scoped auth/session stores, without a browser. */
    private function requestAs(array &$cookies, string $method, string $path, array $data = []): TestResponse
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = $cookies;
        $response = $this->json($method, $path, $data);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === '') {
                unset($cookies[$cookie->getName()]);
            } else {
                $cookies[$cookie->getName()] = $response->getCookie($cookie->getName())->getValue();
            }
        }

        return $response;
    }

    private function signIn(User $user, array &$cookies, bool $remember = false, string $password = 'Password1234'): string
    {
        $this->requestAs($cookies, 'POST', '/login', ['email' => $user->email, 'password' => $password, 'role' => $user->role, 'remember' => $remember])->assertRedirect();

        return $this->requestAs($cookies, 'GET', '/account/security')->assertOk()->json('currentSessionId');
    }

    private function intent(): array
    {
        return ['request_token' => (string) Str::uuid(), 'confirmation_method' => 'password', 'current_password' => 'Password1234'];
    }

    private function legacy(User $user): array
    {
        $id = Str::random(40);
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'user_agent' => 'Earlier browser',
            'ip_address' => inet_ntop(hex2bin('c0000214')), 'last_activity' => now()->subMinute()->timestamp,
            'payload' => base64_encode(serialize([Auth::guard('web')->getName() => $user->id, '_token' => Str::random(40)]))]);

        return [config('session.cookie') => $id];
    }

    public function test_remote_sign_out_rejects_the_real_other_cookie_and_its_remembered_sign_in(): void
    {
        $user = $this->owner();
        $one = [];
        $two = [];
        $first = $this->signIn($user, $one, remember: true);
        $this->travel(1)->minutes();
        $second = $this->signIn($user, $two, remember: true);
        $rememberName = Auth::guard('web')->getRecallerName();
        $rememberOnly = [$rememberName => $two[$rememberName]];
        $this->requestAs($one, 'POST', '/account/security/sessions/'.$second.'/revoke', $this->intent())->assertOk()->assertJsonPath('affected_count', 1);
        $this->requestAs($two, 'GET', '/account/security')->assertUnauthorized();
        $this->requestAs($rememberOnly, 'GET', '/account/security')->assertUnauthorized();
        $this->requestAs($one, 'GET', '/account/security')->assertOk()->assertJsonPath('currentSessionId', $first)->assertJsonPath('sessions.total', 1);
        $this->assertSame(2, AccountLoginSession::where('user_id', $user->id)->whereNotNull('signed_in_at')->count());
    }

    public function test_two_real_sessions_keep_their_original_login_times_and_neither_can_manage_another_account(): void
    {
        $user = $this->owner();
        $foreign = $this->owner();
        $one = [];
        $two = [];
        $three = [];
        $first = $this->signIn($user, $one);
        $this->travel(1)->minutes();
        $second = $this->signIn($user, $two);
        $other = $this->signIn($foreign, $three);
        $firstTime = AccountLoginSession::findOrFail($first)->signed_in_at;
        $this->travel(1)->minutes();
        $this->requestAs($one, 'GET', '/account/security')->assertOk()->assertJsonPath('lastSignIn.id', $second)->assertJsonPath('currentSessionId', $first);
        $this->requestAs($one, 'POST', '/account/security/sessions/'.$other.'/revoke', $this->intent())->assertNotFound();
        $this->requestAs($three, 'GET', '/account/security')->assertOk();
        $this->assertSame($firstTime->toIso8601String(), AccountLoginSession::findOrFail($first)->signed_in_at->toIso8601String());
    }

    public function test_bulk_sign_out_covers_old_untracked_cookies_and_a_retry_keeps_a_later_login(): void
    {
        $user = $this->owner();
        $one = [];
        $two = [];
        $later = [];
        $current = $this->signIn($user, $one);
        $this->signIn($user, $two);
        $old = $this->legacy($user);
        $intent = $this->intent();
        $this->requestAs($one, 'POST', '/account/security/sessions/revoke-others', $intent)->assertOk()->assertJsonPath('affected_count', 2);
        $this->requestAs($old, 'GET', '/account/security')->assertUnauthorized();
        $this->requestAs($two, 'GET', '/account/security')->assertUnauthorized();
        $new = $this->signIn($user->fresh(), $later);
        $this->requestAs($one, 'POST', '/account/security/sessions/revoke-others', $intent)->assertOk()->assertJsonPath('affected_count', 2);
        $this->requestAs($later, 'GET', '/account/security')->assertOk()->assertJsonPath('currentSessionId', $new);
        $this->requestAs($one, 'GET', '/account/security')->assertOk()->assertJsonPath('currentSessionId', $current);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertDatabaseCount('account_session_actions', 1);
    }

    public function test_existing_browser_can_be_ended_individually_without_inventing_a_past_sign_in(): void
    {
        $user = $this->owner();
        $one = [];
        $this->signIn($user, $one);
        $old = $this->legacy($user);
        $page = $this->requestAs($one, 'GET', '/account/security')->assertOk()->assertJsonPath('sessions.total', 2)->assertJsonPath('signIns.total', 1);
        $oldEntry = collect($page->json('sessions.data'))->firstWhere('method', 'existing');
        $this->assertNotNull($oldEntry);
        $this->assertNull($oldEntry['signed_in_at']);
        $this->requestAs($one, 'POST', '/account/security/sessions/'.$oldEntry['id'].'/revoke', $this->intent())->assertOk();
        $this->requestAs($old, 'GET', '/account/security')->assertUnauthorized();
        $this->assertSame(0, $user->fresh()->session_version);
        $this->requestAs($one, 'GET', '/account/security')->assertOk()->assertJsonPath('sessions.total', 1);
    }

    public function test_normal_logout_ends_only_the_current_authenticated_session(): void
    {
        $user = $this->owner();
        $one = [];
        $two = [];
        $first = $this->signIn($user, $one);
        $second = $this->signIn($user, $two);
        $this->requestAs($one, 'POST', '/logout')->assertRedirect();
        $this->requestAs($one, 'GET', '/account/security')->assertUnauthorized();
        $this->requestAs($two, 'GET', '/account/security')->assertOk()->assertJsonPath('currentSessionId', $second);
        $this->assertNotNull(AccountLoginSession::findOrFail($first)->revoked_at);
        $this->assertNull(AccountLoginSession::findOrFail($second)->revoked_at);
    }

    public function test_password_change_retains_current_access_and_ends_other_current_and_legacy_sessions(): void
    {
        $user = $this->owner();
        $one = [];
        $two = [];
        $current = $this->signIn($user, $one);
        $this->signIn($user, $two);
        $old = $this->legacy($user);
        $this->requestAs($one, 'PUT', '/password', ['current_password' => 'Password1234', 'password' => 'ChangedPassword123!', 'password_confirmation' => 'ChangedPassword123!'])->assertRedirect();
        $this->requestAs($one, 'GET', '/account/security')->assertOk()->assertJsonPath('currentSessionId', $current)->assertJsonPath('sessions.total', 1);
        $this->requestAs($two, 'GET', '/account/security')->assertUnauthorized();
        $this->requestAs($old, 'GET', '/account/security')->assertUnauthorized();
        $this->assertSame(1, $user->fresh()->session_version);
    }

    public function test_verified_password_reset_ends_every_old_cookie_including_an_untracked_one(): void
    {
        $user = $this->owner();
        $one = [];
        $two = [];
        $reset = [];
        $this->signIn($user, $one);
        $this->signIn($user, $two);
        $old = $this->legacy($user);
        EmailOtp::create(['user_id' => $user->id, 'email' => $user->email, 'role' => 'buyer', 'purpose' => 'password_reset',
            'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);
        $this->requestAs($reset, 'POST', '/password/reset-otp', ['email' => $user->email, 'role' => 'buyer', 'code' => '123456',
            'password' => 'ResetPassword123!', 'password_confirmation' => 'ResetPassword123!'])->assertOk();
        foreach ([$one, $two, $old] as $cookies) {
            $this->requestAs($cookies, 'GET', '/account/security')->assertUnauthorized();
        }
        $this->assertSame(1, $user->fresh()->session_version);
        $this->signIn($user->fresh(), $reset, password: 'ResetPassword123!');
    }

    public function test_late_failure_rolls_back_remote_revocation_its_receipt_and_generation(): void
    {
        $user = $this->owner();
        $one = [];
        $two = [];
        $current = $this->signIn($user, $one);
        $other = $this->signIn($user, $two);
        User::updating(function (User $record) {
            if ($record->isDirty('remember_token')) {
                throw new RuntimeException('Controlled session rotation failure.');
            }
        });
        $this->requestAs($one, 'POST', '/account/security/sessions/revoke-others', $this->intent())->assertServerError();
        $this->assertNull(AccountLoginSession::findOrFail($other)->revoked_at);
        $this->assertNull(AccountLoginSession::findOrFail($current)->revoked_at);
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertDatabaseCount('account_session_actions', 0);
        $this->requestAs($two, 'GET', '/account/security')->assertOk();
    }

    public function test_expired_cookie_requires_sign_in_and_does_not_invent_a_new_authentication(): void
    {
        config(['session.lifetime' => 2]);
        $user = $this->owner();
        $cookies = [];
        $this->signIn($user, $cookies);
        $this->travel(3)->minutes();
        $this->requestAs($cookies, 'GET', '/account/security')->assertUnauthorized();
        $this->assertSame(1, AccountLoginSession::whereNotNull('signed_in_at')->count());
    }

    public function test_valid_remembered_sign_in_is_visible_on_its_first_authenticated_page(): void
    {
        config(['session.lifetime' => 2]);
        $user = $this->owner();
        $cookies = [];
        $original = $this->signIn($user, $cookies, remember: true);
        $this->travel(3)->minutes();
        $response = $this->requestAs($cookies, 'GET', '/account/security')->assertOk()->assertJsonPath('sessions.total', 1)
            ->assertJsonPath('signIns.total', 2)->assertJsonPath('lastSignIn.method', 'remembered');
        $current = $response->json('currentSessionId');
        $this->assertNotNull($current);
        $this->assertNotSame($original, $current);
        $this->assertSame($current, $response->json('lastSignIn.id'));
    }
}
