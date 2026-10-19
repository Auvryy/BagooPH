<?php

namespace Tests\Feature\Buyer;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\NotificationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerTrackingNoticesTest extends TestCase
{
    use RefreshDatabase;

    private function notice(User $buyer, Order $order, string $source, array $data = [], string $type = 'parcel-event'): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'notifiable_type' => User::class, 'notifiable_id' => $buyer->id,
            'type' => $type, 'source_key' => $source, 'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
            'data' => json_encode($data + ['order_id' => $order->id, 'title' => 'Your parcel reached a sorting hub',
                'milestone' => 'arrived_at_mother_hub', 'proof_image' => 'private/proof.jpg', 'code' => 'private-code'], JSON_THROW_ON_ERROR)]);

        return $id;
    }

    public function test_order_projection_links_each_notice_to_its_own_original_checkpoint_and_keeps_private_sources_out(): void
    {
        $this->withoutVite();
        $order = Order::factory()->create(['status' => 'at_sorting_center']);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'arrived_at_mother_hub']);
        $points = collect(['Origin Mother Hub', 'Destination Mother Hub'])->map(fn ($location) => DeliveryCheckpoint::create([
            'delivery_id' => $parcel->id, 'checkpoint_type' => 'arrived_at_mother_hub', 'location_name' => $location,
        ]));
        $ids = $points->map(fn ($point) => $this->notice($order->buyer, $order, 'checkpoint:'.$point->id));
        $confirmed = $this->notice($order->buyer, $order, 'order:'.$order->id.':confirmed',
            ['title' => 'Your order was confirmed', 'milestone' => 'confirmed'], 'order-event');

        $this->actingAs($order->buyer)->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/OrderDetail')->has('orderNotices', 3)
            ->where('orderNotices', function ($notices) use ($ids, $points, $confirmed) {
                $notices = collect($notices)->keyBy('id');
                foreach ($ids as $index => $id) {
                    $this->assertSame($points[$index]->id, $notices[$id]['checkpoint_id']);
                    $this->assertNull($notices[$id]['milestone']);
                    $this->assertArrayNotHasKey('source_key', $notices[$id]);
                    $this->assertArrayNotHasKey('proof_image', $notices[$id]['data']);
                    $this->assertArrayNotHasKey('code', $notices[$id]['data']);
                    $this->assertNull($notices[$id]['read_at']);
                }
                $this->assertSame('confirmed', $notices[$confirmed]['milestone']);
                $this->assertNull($notices[$confirmed]['checkpoint_id']);

                return true;
            }));
        $this->assertSame(3, $order->buyer->unreadNotifications()->count());
        $this->assertSame(2, $parcel->checkpoints()->count());
    }

    public function test_foreign_mirrored_missing_and_malformed_checkpoint_sources_do_not_become_owned_journey_links(): void
    {
        $order = Order::factory()->create();
        $parcel = Delivery::factory()->create(['order_id' => $order->id]);
        $foreign = DeliveryCheckpoint::create(['delivery_id' => Delivery::factory()->create()->id, 'checkpoint_type' => 'picked_up']);
        $original = DeliveryCheckpoint::create(['delivery_id' => $parcel->id, 'checkpoint_type' => 'picked_up']);
        $mirror = DeliveryCheckpoint::create(['delivery_id' => $parcel->id, 'checkpoint_type' => 'picked_up', 'source_checkpoint_id' => $original->id]);
        foreach (['checkpoint:'.$foreign->id, 'checkpoint:'.$mirror->id, 'checkpoint:999999999999999999999', 'checkpoint:0', 'legacy-notice'] as $source) {
            $this->notice($order->buyer, $order, $source);
        }
        $this->notice($order->buyer, $order, 'unknown-milestone', ['milestone' => 'private-data'], 'order-event');
        foreach (app(NotificationCenterService::class)->forOrder($order->buyer, $order) as $notice) {
            $this->assertNull($notice['checkpoint_id']);
            $this->assertNull($notice['milestone']);
        }
        $this->assertSame(6, $order->buyer->unreadNotifications()->count());
    }

    public function test_owned_order_filter_and_admin_oversight_never_include_someone_elses_notices(): void
    {
        $this->withoutVite();
        $order = Order::factory()->create();
        $otherOrder = Order::factory()->create(['buyer_id' => $order->buyer_id]);
        $owned = $this->notice($order->buyer, $order, 'owned');
        $this->notice($order->buyer, $otherOrder, 'different-order');
        $foreignBuyer = User::factory()->create();
        $this->notice($foreignBuyer, $order, 'different-buyer');
        $this->actingAs($order->buyer)->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orderNotices', 1)->where('orderNotices.0.id', $owned));
        $this->actingAs($foreignBuyer)->get('/buyer/orders/'.$order->id)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orderNotices', 0));
    }
}
