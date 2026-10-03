<?php

namespace Tests\Feature\Courier;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CourierPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function unapprovedAccounts(): array
    {
        $cases = [];
        foreach (['/courier', 'http://courier.localhost'] as $prefix) {
            foreach ([['active', 'none'], ['active', 'pending_approval'], ['active', 'rejected'], ['pending_approval', 'approved'], ['inactive', 'approved']] as [$status, $kyc]) {
                $cases[$prefix.' '.$status.' '.$kyc] = [$prefix, $status, $kyc];
            }
        }

        return $cases;
    }

    #[DataProvider('unapprovedAccounts')]
    public function test_inactive_or_unapproved_riders_cannot_read_or_mutate_the_portal(string $prefix, string $status, string $kyc): void
    {
        $rider = User::factory()->create(['role' => 'courier', 'status' => $status, 'kyc_status' => $kyc]);
        $order = Order::factory()->create(['status' => 'ready_for_pickup']);
        $delivery = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'assigned_pickup', 'courier_id' => $rider->id]);
        $before = $delivery->fresh()->getAttributes();
        $this->actingAs($rider);

        foreach (['deliveries', 'earnings', 'messages', 'profile'] as $page) {
            $response = $this->get($prefix.'/'.$page)->assertRedirect();
            $this->assertStringEndsWith('/pending-approval', $response->headers->get('Location'));
        }
        $this->post($prefix.'/deliveries/'.$delivery->id.'/claim')->assertRedirect();
        $this->patch($prefix.'/deliveries/'.$delivery->id.'/status', ['status' => 'picked_up'])->assertRedirect();
        $this->post($prefix.'/profile/toggle-duty', ['is_available' => true])->assertRedirect();
        $this->post($prefix.'/messages/send', ['delivery_id' => $delivery->id, 'message' => 'Pickup note'])->assertRedirect();

        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public static function foreignRoles(): array
    {
        $cases = [];
        foreach (['/courier', 'http://courier.localhost'] as $prefix) {
            foreach (['buyer', 'seller', 'logistics', 'admin'] as $role) {
                $cases[$prefix.' '.$role] = [$prefix, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('foreignRoles')]
    public function test_other_roles_cannot_use_courier_portal_even_with_admin_access(string $prefix, string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved']));
        $this->get($prefix.'/deliveries')->assertForbidden();
        $this->post($prefix.'/profile/toggle-duty', ['is_available' => true])->assertForbidden();
    }

    public function test_suspended_riders_are_logged_out_on_both_portal_urls(): void
    {
        foreach (['/courier', 'http://courier.localhost'] as $prefix) {
            $rider = User::factory()->create(['role' => 'courier', 'status' => 'suspended', 'kyc_status' => 'approved']);
            $this->actingAs($rider)->get($prefix.'/deliveries')->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_guest_is_redirected_and_approved_rider_can_use_both_portals(): void
    {
        $this->get('/courier/deliveries')->assertRedirect(route('login'));
        $this->get('http://courier.localhost/deliveries')->assertRedirect();
        $rider = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved']);
        $this->actingAs($rider)->get('http://localhost/courier/deliveries')->assertOk();
        $this->get('http://courier.localhost/deliveries')->assertOk();
    }
}
