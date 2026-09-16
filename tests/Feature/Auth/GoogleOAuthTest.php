<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_redirects_to_google_oauth_provider(): void
    {
        $response = $this->get(route('auth.google'));

        $response->assertStatus(302);
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location') ?? '');
    }

    public function test_google_callback_registers_new_buyer_successfully(): void
    {
        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-id-1001');
        $abstractUser->shouldReceive('getEmail')->andReturn('newuser@gmail.com');
        $abstractUser->shouldReceive('getName')->andReturn('Juan Dela Cruz');
        $abstractUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/photo.jpg');

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('buyer.index'));
        $this->assertAuthenticated();

        $user = User::where('email', 'newuser@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('google-id-1001', $user->google_id);
        $this->assertEquals('buyer', $user->role);
        $this->assertEquals('Juan Dela Cruz', $user->name);
        $this->assertEquals('https://lh3.googleusercontent.com/photo.jpg', $user->avatar);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_google_callback_links_existing_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'existingbuyer@gmail.com',
            'google_id' => null,
            'role' => 'buyer',
            'avatar' => null,
        ]);

        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-id-2002');
        $abstractUser->shouldReceive('getEmail')->andReturn('existingbuyer@gmail.com');
        $abstractUser->shouldReceive('getName')->andReturn('Existing Buyer');
        $abstractUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/new_avatar.jpg');

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('buyer.index'));
        $this->assertAuthenticatedAs($existing);

        $existing->refresh();
        $this->assertEquals('google-id-2002', $existing->google_id);
        $this->assertEquals('https://lh3.googleusercontent.com/new_avatar.jpg', $existing->avatar);
    }

    public function test_google_callback_authenticates_existing_google_id(): void
    {
        $existing = User::factory()->create([
            'email' => 'buyerwithid@gmail.com',
            'google_id' => 'google-id-3003',
            'role' => 'buyer',
        ]);

        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-id-3003');
        $abstractUser->shouldReceive('getEmail')->andReturn('buyerwithid@gmail.com');

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('buyer.index'));
        $this->assertAuthenticatedAs($existing);
    }

    public function test_google_callback_redirects_seller_to_seller_dashboard(): void
    {
        $seller = User::factory()->create([
            'email' => 'merchant@gmail.com',
            'google_id' => 'google-id-seller-99',
            'role' => 'seller',
        ]);

        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-id-seller-99');
        $abstractUser->shouldReceive('getEmail')->andReturn('merchant@gmail.com');

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('seller.dashboard'));
        $this->assertAuthenticatedAs($seller);
    }

    public function test_google_callback_handles_failure_gracefully(): void
    {
        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andThrow(new \Exception('User denied access'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
