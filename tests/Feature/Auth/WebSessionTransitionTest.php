<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebSessionTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function portals(): array
    {
        return [
            'buyer' => ['buyer', 'http://localhost', '/buyer'],
            'seller root' => ['seller', 'http://localhost', '/seller/dashboard'],
            'courier root' => ['courier', 'http://localhost', '/courier/deliveries'],
            'logistics root' => ['logistics', 'http://localhost', '/hub'],
            'admin root' => ['admin', 'http://localhost', '/admin/dashboard'],
            'seller host' => ['seller', 'http://seller.localhost', '/dashboard'],
            'courier host' => ['courier', 'http://courier.localhost', '/deliveries'],
            'hub host' => ['logistics', 'http://hub.localhost', '/dashboard'],
            'admin host' => ['admin', 'http://admin.localhost', '/dashboard'],
        ];
    }

    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('http://localhost')) ?? ''];
    }

    #[DataProvider('portals')]
    public function test_real_login_rotates_session_and_requests_a_fresh_page_on_the_browser_origin(string $role, string $host, string $target): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved']);
        $this->get($host.'/login')->assertOk();
        $session = app('session')->driver();
        $oldId = $session->getId();
        $oldToken = $session->token();

        $this->withHeaders($this->inertiaHeaders())->post($host.'/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', $target)->assertSessionHas('inertia.clear_history', true);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldId, $session->getId());
        $this->assertNotSame($oldToken, $session->token());

        $this->flushHeaders()->get($host.'/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->where('auth.user.id', $user->id)->where('auth.user.role', $role));
    }

    #[DataProvider('portals')]
    public function test_real_logout_clears_authentication_and_refreshes_the_page_without_reusing_portal_state(string $role, string $host, string $target): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved']);
        $this->post($host.'/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($target);
        $this->assertAuthenticatedAs($user);
        $oldId = app('session')->driver()->getId();
        $this->withHeaders($this->inertiaHeaders())->post($host.'/logout')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/')->assertSessionHas('success');
        $this->assertGuest();
        $this->assertNotSame($oldId, app('session')->driver()->getId());
        Auth::forgetGuards();
        $this->flushHeaders()->get($host.'/')->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
    }

    public function test_wrong_password_keeps_visible_validation_feedback_and_does_not_authenticate(): void
    {
        $user = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved']);
        $host = 'http://courier.localhost';
        $this->withHeaders([...$this->inertiaHeaders(), 'Referer' => $host.'/login'])
            ->post($host.'/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(302)->assertHeader('Location', '/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->withCookie(config('session.cookie'), app('session')->driver()->getId())->flushHeaders()->get($host.'/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/CourierLogin')->where('auth.user', null)->where('errors.email', trans('auth.failed')));
    }

    public function test_pending_account_is_sent_to_holding_and_suspended_worker_is_denied(): void
    {
        $worker = User::factory()->create(['role' => 'courier', 'status' => 'pending_approval', 'kyc_status' => 'pending_approval']);
        $host = 'http://courier.localhost';
        $this->withHeaders($this->inertiaHeaders())->post($host.'/login', ['email' => $worker->email, 'password' => 'password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/pending-approval');
        $this->assertAuthenticatedAs($worker);
        $this->get($host.'/deliveries')->assertStatus(302)->assertHeader('Location', '/pending-approval');
        $this->post($host.'/logout')->assertStatus(409);
        $worker->update(['status' => 'suspended']);
        $this->post($host.'/login', ['email' => $worker->email, 'password' => 'password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_current_host_redirect_preserves_query_and_external_redirect_preserves_its_origin(): void
    {
        $user = User::factory()->create(['status' => 'active', 'kyc_status' => 'approved']);
        $this->withSession(['url.intended' => 'http://localhost/buyer/orders?view=recent#orders'])
            ->withHeaders($this->inertiaHeaders())->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/buyer/orders?view=recent#orders');
        $this->post('/logout')->assertStatus(409);
        $this->withSession(['url.intended' => 'https://seller.localhost/dashboard'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://seller.localhost/dashboard');
    }

    public function test_expired_sign_in_refreshes_the_same_portal_with_feedback_and_keeps_csrf_protection(): void
    {
        $this->app->bind(PreventRequestForgery::class, EnforcedWebCsrf::class);
        $user = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved']);
        $this->withHeaders([...$this->inertiaHeaders(), 'Referer' => 'https://localhost/courier/login'])
            ->post('/login', ['email' => $user->email, 'password' => 'password', '_token' => 'expired-token'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/courier/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->withCookie(config('session.cookie'), app('session')->driver()->getId())->flushHeaders()->get('/courier/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/CourierLogin')->where('errors.email', 'Your sign-in page expired. Please try again.'));
        $this->withHeaders($this->inertiaHeaders())->post('/login', ['email' => $user->email, 'password' => 'password',
            '_token' => app('session')->driver()->token()])->assertStatus(409)->assertHeader('X-Inertia-Location', '/courier/deliveries');
        $this->assertAuthenticatedAs($user);
    }

    public static function unsafeRelativePaths(): array
    {
        return [['//seller.localhost/dashboard'], ['/\\seller.localhost/dashboard']];
    }

    #[DataProvider('unsafeRelativePaths')]
    public function test_origin_preservation_does_not_turn_a_local_path_into_an_external_destination(string $path): void
    {
        $user = User::factory()->create(['status' => 'active', 'kyc_status' => 'approved']);
        $target = 'http://localhost'.$path;
        $this->withSession(['url.intended' => $target])->withHeaders($this->inertiaHeaders())
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', $target);
    }
}

class EnforcedWebCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
