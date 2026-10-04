<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerOAuthPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function accountStates(): array
    {
        $cases = [];
        foreach (['seller', 'courier', 'logistics'] as $role) {
            foreach ([
                ['active', 'approved'], ['active', 'verified'], ['active', 'none'],
                ['active', 'pending_approval'], ['active', 'rejected'], ['active', 'unknown'],
                ['pending_approval', 'approved'], ['inactive', 'approved'],
                ['suspended', 'approved'], ['unknown', 'approved'],
            ] as [$status, $kyc]) {
                $cases[$role.' '.$status.' '.$kyc] = [$role, $status, $kyc];
            }
        }
        foreach (['active', 'pending_approval', 'inactive', 'suspended', 'unknown'] as $status) {
            $cases['admin '.$status] = ['admin', $status, 'none'];
        }

        return $cases;
    }

    #[DataProvider('accountStates')]
    public function test_oauth_cannot_grant_unreviewed_or_restricted_worker_access(string $role, string $status, string $kyc): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => $kyc, 'google_id' => 'oauth-access-test']);
        $providerUser = Mockery::mock(SocialiteUser::class);
        $providerUser->shouldReceive('getId')->andReturn($actor->google_id);
        $providerUser->shouldReceive('getEmail')->andReturn($actor->email);
        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($providerUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'))->assertRedirect();
        if ($status === 'suspended' || ($role === 'admin' && $status !== 'active')) {
            $response->assertRedirect(route('login'))->assertSessionHasErrors('email');
            $this->assertGuest();
        } elseif ($status !== 'active' || ! in_array($kyc, ['approved', 'verified'], true) && $role !== 'admin') {
            $this->assertStringEndsWith('/pending-approval', $response->headers->get('Location'));
            $this->assertAuthenticatedAs($actor);
        } else {
            $route = match ($role) {
                'seller' => 'seller.dashboard',
                'courier' => 'courier.deliveries',
                'logistics' => 'hub.index',
                'admin' => 'admin.dashboard',
            };
            $response->assertRedirect(route($route));
            $this->assertAuthenticatedAs($actor);
        }

        $this->assertSame($status, $actor->fresh()->status);
        $this->assertSame($kyc, $actor->fresh()->kyc_status);
    }
}
