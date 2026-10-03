<?php

namespace Tests\Feature\Courier;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CourierAccountClosureTest extends TestCase
{
    use RefreshDatabase;

    public static function custodyStages(): array
    {
        return array_map(fn ($stage) => [$stage], ['assigned_pickup', 'picked_up', 'assigned_to_rider', 'out_for_delivery', 'delivered']);
    }

    #[DataProvider('custodyStages')]
    public function test_self_service_account_deletion_cannot_remove_rider_custody_or_history(string $stage): void
    {
        $rider = User::factory()->create([
            'role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved', 'password' => 'password',
        ]);
        $order = Order::factory()->create(['status' => $stage === 'assigned_pickup' ? 'ready_for_pickup' : $stage]);
        $finalMile = in_array($stage, ['assigned_to_rider', 'out_for_delivery', 'delivered'], true);
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id, 'status' => $stage,
            'courier_id' => $finalMile ? null : $rider->id,
            'assigned_rider_id' => $finalMile ? $rider->id : null,
        ]);
        $before = $delivery->fresh()->getAttributes();

        $this->actingAs($rider)->get('/profile')->assertRedirect(route('courier.profile'));
        $this->from('/courier/profile')->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');
        $this->assertAuthenticatedAs($rider);
        $this->assertNotNull($rider->fresh());
        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $this->assertSame($order->status, $order->fresh()->status);
    }
}
