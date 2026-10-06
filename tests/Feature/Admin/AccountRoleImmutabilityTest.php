<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountRoleImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function roleChanges(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $currentRole) {
            foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $requestedRole) {
                if ($currentRole === $requestedRole) {
                    continue;
                }
                foreach (['active', 'pending_approval'] as $status) {
                    $cases[$currentRole.' to '.$requestedRole.' '.$status] = [$currentRole, $requestedRole, $status];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('roleChanges')]
    public function test_saved_account_roles_cannot_change_even_without_transaction_history(string $currentRole, string $requestedRole, string $status): void
    {
        $user = User::factory()->create([
            'role' => $currentRole,
            'status' => $status,
            'kyc_status' => $status === 'active' ? 'approved' : 'pending_approval',
        ]);
        $before = $user->fresh()->getAttributes();

        try {
            $user->update(['role' => $requestedRole, 'status' => 'suspended', 'name' => 'Changed with role']);
            $this->fail('An existing account role must not change.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public static function adminRoleRequests(): array
    {
        $cases = [];
        foreach (['http://localhost/admin', 'http://admin.localhost'] as $prefix) {
            foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
                $cases[$prefix.' '.$role] = [$prefix, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('adminRoleRequests')]
    public function test_old_admin_role_routes_cannot_change_roles_or_activate_accounts(string $prefix, string $role): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['role' => $role, 'status' => 'suspended']);
        $before = $target->fresh()->getAttributes();

        $this->actingAs($admin)->patch($prefix.'/users/'.$target->id.'/role', [
            'role' => $role === 'buyer' ? 'seller' : 'buyer',
            'status' => 'active',
        ])->assertNotFound();
        $this->assertSame($before, $target->fresh()->getAttributes());

        $this->patch($prefix.'/users/'.$target->id.'/role', ['role' => $role, 'status' => 'active'])->assertNotFound();
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public function test_admin_cannot_convert_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['http://localhost/admin', 'http://admin.localhost'] as $prefix) {
            $this->actingAs($admin)->patch($prefix.'/users/'.$admin->id.'/role', [
                'role' => 'buyer', 'status' => 'active',
            ])->assertNotFound();
            $this->assertSame('admin', $admin->fresh()->role);
            $this->get($prefix.'/users')->assertOk();
        }
    }

    public static function accountRoles(): array
    {
        return array_map(fn (string $role) => [$role], ['buyer', 'seller', 'courier', 'logistics', 'admin']);
    }

    #[DataProvider('accountRoles')]
    public function test_roles_can_be_selected_at_creation_and_ordinary_updates_remain_available(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->assertSame($role, $user->fresh()->role);

        $user->update(['role' => $role, 'name' => 'Updated account name']);
        $this->assertSame($role, $user->fresh()->role);
        $this->assertSame('Updated account name', $user->fresh()->name);
    }

    public function test_profile_payload_cannot_convert_a_buyer_to_a_seller(): void
    {
        $buyer = User::factory()->buyer()->create();
        $this->actingAs($buyer)->patch('/profile', [
            'name' => $buyer->name, 'email' => $buyer->email, 'role' => 'seller',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertSame('buyer', $buyer->fresh()->role);
        $this->assertSame($buyer->name, $buyer->fresh()->name);
    }

    public static function adminPortals(): array
    {
        return [['http://localhost/admin'], ['http://admin.localhost']];
    }

    #[DataProvider('adminPortals')]
    public function test_user_directory_keeps_role_filters_and_account_details(string $prefix): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create(['name' => 'Directory Buyer']);
        User::factory()->seller()->create(['name' => 'Directory Seller']);

        $this->actingAs($admin)->get($prefix.'/users?search=Directory&role=buyer')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users')
                ->has('users.data', 1)
                ->where('users.data.0.id', $buyer->id)
                ->where('users.data.0.role', 'buyer')
                ->where('users.data.0.status', 'active')
                ->where('filters.role', 'buyer'));
    }
}
