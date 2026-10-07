<?php

namespace Tests\Feature\Notifications;

use App\Models\DeliveryCheckpoint;
use App\Models\NotificationDelivery;
use App\Services\Notifications\LifecycleNoticeService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class LifecycleNotificationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_real_checkout_preparation_custody_dispatch_delivery_and_completion_notify_correct_accounts(): void
    {
        $order = $this->newFlowOrder();
        $seller = $order->shop->user;
        $buyer = $order->buyer;
        $this->assertSame(1, $seller->notifications()->where('source_key', 'order:'.$order->id.':placed')->count());
        $this->assertSame(0, $buyer->notifications()->count());
        $parcel = $this->flowDelivery($order, 'delivered');
        foreach (['confirmed', 'preparing', 'ready_for_pickup'] as $milestone) {
            $this->assertSame(1, $buyer->notifications()->where('source_key', 'order:'.$order->id.':'.$milestone)->count());
        }
        foreach (['picked_up', 'arrived_at_origin_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul', 'out_for_delivery', 'delivered'] as $milestone) {
            $this->assertSame(1, $buyer->notifications()->where('data->milestone', $milestone)->count());
        }
        $dispatch = $buyer->notifications()->where('data->milestone', 'out_for_delivery')->sole();
        $this->assertStringContainsString('PHP '.number_format((float) $order->total_amount, 2), $dispatch->data['body']);
        $this->assertStringContainsString('Confirm receipt', $buyer->notifications()->where('data->milestone', 'delivered')->sole()->data['body']);
        $this->assertSame(1, $seller->notifications()->where('data->milestone', 'assigned_pickup')->count());
        $checkpoint = DeliveryCheckpoint::where('delivery_id', $parcel->id)->where('checkpoint_type', 'picked_up')->sole();
        $before = NotificationDelivery::count();
        app(LifecycleNoticeService::class)->checkpoint($checkpoint);
        $this->assertSame($before, NotificationDelivery::count());
        app(OrderLifecycleService::class)->buyerComplete($order, $buyer);
        app(OrderLifecycleService::class)->buyerComplete($order, $buyer);
        $this->assertSame(1, $seller->notifications()->where('data->milestone', 'completed')->count());
        $this->assertStringContainsString('separate steps', $seller->notifications()->where('data->milestone', 'completed')->sole()->data['body']);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_notice_outage_does_not_undo_a_real_seller_confirmation(): void
    {
        $order = $this->newFlowOrder();
        DB::unprepared("CREATE TRIGGER seller_notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Notice storage unavailable'); END;");
        app(OrderLifecycleService::class)->sellerTransition($order, $order->shop, $order->shop->user, 'confirmed');
        $this->assertSame('confirmed', $order->fresh()->status);
        $intent = NotificationDelivery::where('source_key', 'order:'.$order->id.':confirmed')->sole();
        $this->assertNull($intent->delivered_at);
        $this->assertSame(0, $order->buyer->notifications()->count());
        DB::unprepared('DROP TRIGGER seller_notice_outage');
        $this->travel(2)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->assertSame(1, $order->buyer->notifications()->count());
    }

    public function test_order_link_is_removed_when_current_ownership_or_eligibility_changes(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $buyer = $order->buyer;
        $this->actingAs($buyer)->get('/notifications')->assertInertia(fn ($page) => $page->where('notices.data.0.href', '/buyer/orders/'.$order->id));
        $buyer->update(['kyc_status' => 'rejected']);
        $this->get('/notifications')->assertInertia(fn ($page) => $page->where('notices.data.0.href', null));
        $this->getJson('/buyer/orders/'.$order->id)->assertForbidden();
    }
}
