<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticatedGuestPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function roles(): array
    {
        return [
            ['buyer', '', '/buyer', '/buyer'],
            ['seller', 'seller', '/seller/dashboard', '/dashboard'],
            ['courier', 'courier', '/courier/deliveries', '/deliveries'],
            ['logistics', 'hub', '/hub', '/dashboard'],
            ['admin', 'admin', '/admin/dashboard', '/dashboard'],
        ];
    }

    #[DataProvider('roles')]
    public function test_existing_sessions_cannot_open_any_path_login_or_registration_page(string $role, string $prefix, string $path, string $domainPath): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        foreach (['/login', '/register', '/seller/login', '/seller/register', '/courier/login', '/courier/register', '/hub/login', '/hub/register', '/logistics/register', '/admin/login', '/auth/google', '/auth/google/register', '/forgot-password', '/reset-password/unused'] as $page) {
            $this->withSession(['url.intended' => '/register'])->get('http://localhost'.$page)->assertRedirect($path)->assertSessionMissing('url.intended');
            $this->assertAuthenticatedAs($user);
        }
    }

    #[DataProvider('roles')]
    public function test_every_role_host_redirects_to_the_signed_in_account_portal_including_new_tab_aliases(string $role, string $prefix, string $path, string $domainPath): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        foreach (['', 'seller', 'courier', 'hub', 'admin'] as $hostPrefix) {
            $host = 'http://'.($hostPrefix ? $hostPrefix.'.' : '').'localhost:8000';
            $expected = ! $hostPrefix ? $path : ($prefix === $hostPrefix ? $domainPath : 'http://'.($prefix ? $prefix.'.' : '').'localhost:8000'.$domainPath);
            foreach (['/login', '/register', '/seller/login', '/courier/register', '/hub/register', '/admin/login'] as $page) {
                $this->get($host.$page)->assertRedirect($expected);
                $this->assertAuthenticatedAs($user);
            }
        }
    }

    #[DataProvider('roles')]
    public function test_existing_sessions_cannot_change_accounts_or_create_one_through_domain_auth_posts(string $role, string $prefix, string $path, string $domainPath): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->create();
        $this->actingAs($user);
        foreach (['http://localhost', 'http://seller.localhost', 'http://courier.localhost', 'http://hub.localhost', 'http://admin.localhost'] as $host) {
            $this->post($host.'/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect();
            $this->post($host.'/register', ['role' => 'buyer', 'email' => 'new@bagoo.test'])->assertRedirect();
            $this->assertAuthenticatedAs($user);
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('email_otps', 0);
        Mail::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_stale_form_json_reads_and_commands_include_the_own_account_destination(string $role, string $prefix, string $path, string $domainPath): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        foreach (['/register/email/validate', '/register/validate', '/api/otp/send', '/api/otp/verify'] as $endpoint) {
            $this->postJson($endpoint, ['email' => 'unregistered@bagoo.test', 'role' => 'buyer', 'step' => 1])
                ->assertConflict()->assertJsonPath('code', 'ALREADY_AUTHENTICATED')->assertJsonPath('redirect', $path)
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_otps', 0);
        Mail::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_inertia_auth_navigation_and_submission_request_a_full_page_account_redirect(string $role, string $prefix, string $path, string $domainPath): void
    {
        $user = User::factory()->create(['role' => $role]);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()) ?? '', 'Accept' => 'text/html'];
        $this->actingAs($user)->withHeaders($headers)->get('/register')->assertStatus(409)->assertHeader('X-Inertia-Location', $path);
        $this->post('/login', ['email' => 'different@bagoo.test'])->assertStatus(409)->assertHeader('X-Inertia-Location', $path);
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('roles')]
    public function test_a_read_only_session_probe_finds_the_signed_in_account_for_existing_tabs(string $role, string $prefix, string $path, string $domainPath): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => $role]);
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->getJson('/auth/session')->assertOk()->assertExactJson([
            'authenticated' => true, 'code' => 'ALREADY_AUTHENTICATED', 'redirect' => $path,
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_otps', 0);
        Mail::assertNothingSent();
    }

    public static function publicForms(): array
    {
        return [
            ['http://localhost/login', 'Auth/Login'], ['http://localhost/register', 'Auth/Register'],
            ['http://seller.localhost/login', 'Auth/SellerLogin'], ['http://seller.localhost/register', 'Auth/SellerRegister'],
            ['http://courier.localhost/login', 'Auth/CourierLogin'], ['http://courier.localhost/register', 'Auth/CourierRegister'],
            ['http://hub.localhost/login', 'Auth/HubLogin'], ['http://hub.localhost/register', 'Auth/LogisticsRegister'],
            ['http://admin.localhost/login', 'Auth/AdminLogin'],
        ];
    }

    #[DataProvider('publicForms')]
    public function test_signed_out_applicants_can_still_open_every_public_form(string $url, string $component): void
    {
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component)->where('auth.user', null));
        $this->getJson('/auth/session')->assertOk()->assertExactJson(['authenticated' => false]);
    }

    #[DataProvider('roles')]
    public function test_worker_landing_pages_do_not_show_login_to_an_existing_session(string $role, string $prefix, string $path, string $domainPath): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        foreach (['seller', 'courier', 'hub', 'admin'] as $hostPrefix) {
            $expected = $prefix === $hostPrefix ? $domainPath : 'http://'.($prefix ? $prefix.'.' : '').'localhost'.$domainPath;
            $this->get('http://'.$hostPrefix.'.localhost/')->assertRedirect($expected);
        }
        foreach (['/seller', '/courier', '/logistics', '/admin'] as $page) {
            $this->get('http://localhost'.$page)->assertRedirect($path);
        }
    }

    public static function pendingRoles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics']];
    }

    #[DataProvider('pendingRoles')]
    public function test_pending_accounts_go_to_their_review_page_and_keep_portal_restrictions(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'pending_approval', 'kyc_status' => 'pending_approval']);
        $destination = $role === 'buyer' ? '/buyer' : '/pending-approval';
        $this->actingAs($user)->get('/register')->assertRedirect($destination);
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('redirect', $destination);
        $this->assertFalse($user->fresh()->canAccessPortal());
        $this->assertAuthenticatedAs($user);
    }

    public function test_stale_suspended_worker_and_closed_account_sessions_do_not_get_portal_access(): void
    {
        $user = User::factory()->create(['role' => 'courier', 'status' => 'suspended']);
        $this->actingAs($user)->get('/login')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->forceFill(['status' => 'inactive', 'closed_at' => now()])->save();
        $this->actingAs($user)->getJson('/auth/session')->assertForbidden();
        $this->assertGuest();
    }

    public function test_cross_portal_redirect_preserves_https_port_and_ignores_an_untrusted_intended_url(): void
    {
        $user = User::factory()->create(['role' => 'courier']);
        $this->actingAs($user)->withSession(['url.intended' => 'https://untrusted.test/register'])
            ->get('https://seller.localhost:8443/register')->assertRedirect('https://courier.localhost:8443/deliveries')->assertSessionMissing('url.intended');
        $this->get('http://untrusted.test/register')->assertRedirect('/courier/deliveries');
    }

    #[DataProvider('roles')]
    public function test_a_second_tab_uses_the_existing_login_cookie_and_logout_reopens_the_guest_forms(string $role, string $prefix, string $path, string $domainPath): void
    {
        $user = User::factory()->create(['role' => $role]);
        $host = 'http://'.($prefix ? $prefix.'.' : '').'localhost';
        $this->post($host.'/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($domainPath);
        $sessionId = app('session')->driver()->getId();
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $sessionId)->get($host.'/register')->assertRedirect($domainPath);
        $this->assertAuthenticatedAs($user);
        Auth::forgetGuards();
        $this->get($host.'/login')->assertRedirect($domainPath);
        $this->getJson($host.'/auth/session')->assertJsonPath('authenticated', true)->assertJsonPath('redirect', $domainPath);
        $this->post($host.'/logout')->assertRedirect('/');
        Auth::forgetGuards();
        $this->get($host.'/register')->assertOk();
        $this->get($host.'/login')->assertOk();
        $this->assertGuest();
    }
}
