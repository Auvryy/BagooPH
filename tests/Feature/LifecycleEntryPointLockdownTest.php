<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LifecycleEntryPointLockdownTest extends TestCase
{
    use RefreshDatabase;

    public static function simulatorActors(): array
    {
        $cases = [];
        foreach (['local', 'testing', 'production'] as $environment) {
            foreach ([null, 'buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
                $cases[$environment.' '.($role ?? 'guest')] = [$environment, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('simulatorActors')]
    public function test_simulator_urls_cannot_change_custody_or_money_in_any_environment(string $environment, ?string $role): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $order = Order::factory()->create(['status' => 'ready_for_pickup', 'payment_status' => 'pending']);
        $delivery = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'unassigned']);
        $orderBefore = $order->fresh()->getAttributes();
        $deliveryBefore = $delivery->fresh()->getAttributes();

        if ($role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved']));
        }

        foreach (['advance', 'reset'] as $action) {
            $this->postJson('/simulator/orders/'.$order->id.'/'.$action)->assertNotFound();
            $this->postJson('/simulator/orders/'.$order->id.'/'.$action)->assertNotFound();
        }

        $this->assertFalse(Route::has('simulator.orders.advance'));
        $this->assertFalse(Route::has('simulator.orders.reset'));
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($deliveryBefore, $delivery->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }
}
