<?php

namespace Tests\Feature\Seller;

use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerOrderWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Shop $shop;

    private Product $product;

    private array $route;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->seller()->create();
        $this->shop = Shop::factory()->approved()->create(['user_id' => $this->seller->id]);
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'stock' => 10, 'price' => 250, 'featured_image' => null]);
        $company = LogisticsCompany::create(['name' => 'Bagoo Test Logistics', 'slug' => 'bagoo-test-logistics', 'code' => 'BTL', 'status' => 'active', 'is_active' => true]);
        $hubs = [];
        foreach (['local_bayan_hub', 'regional_mother_hub', 'local_bayan_hub'] as $index => $tier) {
            $hubs[] = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Test Hub '.$index,
                'code' => 'TH-'.$index, 'tier' => $tier, 'province' => 'Leyte', 'city_municipality' => 'Tacloban City',
                'address' => 'Hub address '.$index, 'is_active' => true]);
        }
        $this->route = ['logistics_company_id' => $company->id, 'origin_bayan_hub_id' => $hubs[0]->id,
            'origin_mother_hub_id' => $hubs[1]->id, 'destination_mother_hub_id' => $hubs[1]->id, 'destination_bayan_hub_id' => $hubs[2]->id];
    }

    private function order(string $status = 'placed', int $items = 1, ?Shop $shop = null): Order
    {
        $shop ??= $this->shop;
        $product = $shop->id === $this->shop->id ? $this->product : Product::factory()->create(['shop_id' => $shop->id, 'featured_image' => null]);
        $order = Order::factory()->create(['status' => $status, 'subtotal' => 250 * $items, 'shipping_fee' => 50, 'total_amount' => 250 * $items + 50]);
        OrderItem::factory()->count($items)->create(['order_id' => $order->id, 'shop_id' => $shop->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_price' => 250, 'subtotal' => 250, 'size' => null, 'color' => null]);
        Delivery::factory()->create(['order_id' => $order->id, ...$this->route, 'status' => 'unassigned']);

        return $order;
    }

    private function packed(): Order
    {
        $order = $this->order();
        app(OrderLifecycleService::class)->sellerAcceptAndPack($order, $this->shop, $this->seller);

        return $order->fresh();
    }

    private function assertStillPacked(array $orders): void
    {
        foreach ($orders as $order) {
            $this->assertSame('preparing', $order->fresh()->status);
            $this->assertSame('unassigned', $order->delivery->fresh()->status);
        }
        $this->assertDatabaseMissing('delivery_checkpoints', ['checkpoint_type' => 'ready_for_pickup']);
        $this->assertSame(0, DB::table('notifications')->where('data->milestone', 'ready_for_pickup')->count());
        $this->assertSame(0, NotificationDelivery::where('data->milestone', 'ready_for_pickup')->count());
    }

    public function test_multiple_items_are_one_order_count_and_stably_paginated_with_original_actions(): void
    {
        $orders = collect();
        for ($i = 0; $i < 11; $i++) {
            $order = $this->order('placed', 3);
            $order->update(['created_at' => '2026-10-01 08:00:00']);
            $orders->push($order);
        }
        $this->order('completed');
        $this->actingAs($this->seller)->get(route('seller.orders.index', ['status' => 'to_pack']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 10)->where('orders.total', 11)->where('counts.all', 12)->where('counts.to_pack', 11)
            ->where('orders.data.0.id', $orders->last()->id)->has('orders.data.0.items', 3)
            ->where('orders.data.0.can_accept_and_pack', true)->where('orders.data.0.has_mixed_shops', false)
            ->where('orders.next_page_url', fn ($url) => str_contains($url, 'status=to_pack') && str_contains($url, 'page=2')));
        $this->get(route('seller.orders.index', ['status' => 'to_pack', 'page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.id', $orders->first()->id));
    }

    public function test_all_stages_keep_delivered_completed_and_exceptions_distinct_in_orders_and_dashboard(): void
    {
        $stages = [
            'to_pack' => ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging'],
            'to_pickup' => ['ready_for_pickup'],
            'in_transit' => ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'in_transit'],
            'delivered' => ['delivered'], 'completed' => ['completed'], 'delivery_failed' => ['delivery_failed', 'failed'],
            'returned' => ['returned'], 'cancelled' => ['cancelled', 'canceled'],
        ];
        foreach ($stages as $statuses) {
            foreach ($statuses as $status) {
                $this->order($status, 2);
            }
        }
        $foreignShop = Shop::factory()->approved()->create();
        $this->order('completed', 1, $foreignShop);
        $this->actingAs($this->seller);
        foreach ($stages as $stage => $statuses) {
            $this->get(route('seller.orders.index', ['status' => $stage]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('counts.all', 21)->where('counts.delivered', 1)->where('counts.completed', 1)
                ->where('counts.delivery_failed', 2)->where('counts.returned', 1)->where('counts.cancelled', 2)
                ->where('orders.total', count($statuses))->where('orders.data', fn ($rows) => collect($rows)->every(fn ($row) => in_array($row['status'], $statuses, true))));
        }
        $this->get(route('seller.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.deliveredCount', 1)->where('stats.completedCount', 1)->where('stats.deliveryIssueCount', 2)
            ->where('stats.returnedCount', 1)->where('stats.cancelledCount', 2)->has('recentOrders', 6)
            ->has('recentOrders.0.items', 2)->missing('recentOrders.0.order'));
    }

    public function test_marking_the_last_filtered_page_order_ready_refreshes_the_remaining_orders_instead_of_an_empty_workspace(): void
    {
        $orders = [];
        for ($i = 0; $i < 11; $i++) {
            $orders[] = $this->packed();
        }
        $this->actingAs($this->seller)->from(route('seller.orders.index', ['status' => 'to_pack', 'page' => 2]))
            ->post(route('seller.orders.ready', $orders[0]))->assertSessionHas('success');
        $this->get(route('seller.orders.index', ['status' => 'to_pack', 'page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 10)->where('orders.total', 10)->where('orders.current_page', 1)
            ->where('counts.to_pack', 10)->where('counts.to_pickup', 1));
        $this->get(route('seller.orders.index', ['status' => 'returned', 'page' => 99]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 0)->where('orders.total', 0)->where('orders.current_page', 1));
    }

    public function test_reverse_custody_filter_uses_actual_parcels_instead_of_a_dispute_claim_count(): void
    {
        $reverse = $this->order('delivery_failed');
        $reverse->delivery->update(['status' => 'return_to_sender']);
        $this->order('delivery_failed');
        $this->order('cancelled');
        $this->actingAs($this->seller)->get(route('seller.orders.index', ['status' => 'return_custody']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.return_custody', 1)->where('counts.delivery_failed', 2)->where('orders.total', 1)
            ->where('orders.data.0.id', $reverse->id)->where('orders.data.0.delivery.return_ready_for_receipt', false));
        $this->get(route('seller.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.returnCount', 1));
    }

    public function test_mixed_shop_legacy_history_exposes_only_owned_items_and_disables_every_fulfilment_action(): void
    {
        $order = $this->order();
        $foreignShop = Shop::factory()->approved()->create();
        $foreignProduct = Product::factory()->create(['shop_id' => $foreignShop->id, 'featured_image' => null]);
        OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $foreignShop->id, 'product_id' => $foreignProduct->id]);
        CommissionLedger::create(['order_id' => $order->id, 'seller_id' => $foreignShop->user_id, 'gross_amount' => 500,
            'seller_amount' => 450, 'platform_commission' => 50, 'delivery_fee' => 50, 'status' => 'pending']);
        $this->actingAs($this->seller)->get(route('seller.orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->has('orders.data.0.items', 1)->where('counts.all', 1)
            ->where('orders.data.0.items.0.product_id', $this->product->id)->where('orders.data.0.has_mixed_shops', true)
            ->where('orders.data.0.can_fulfill', false)->where('orders.data.0.can_accept_and_pack', false)
            ->where('orders.data.0.can_cancel', false)->where('orders.data.0.can_print_waybill', false)
            ->where('orders.data.0.commission_ledger', null));
        $this->post(route('seller.orders.acceptAndPack', $order))->assertForbidden();
        $this->post(route('seller.orders.cancel', $order), ['reason' => 'Other reason', 'notes' => 'The parcel cannot be prepared.'])->assertForbidden();
        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_restricted_shop_history_keeps_owned_orders_and_the_existing_waybill_without_new_work(): void
    {
        $order = $this->order();
        $this->shop->update(['status' => 'suspended']);
        $this->actingAs($this->seller)->get(route('seller.orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('shopEligible', false)->has('orders.data', 1)->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.can_accept_and_pack', false)->where('orders.data.0.can_cancel', false)
            ->where('orders.data.0.can_print_waybill', true));
        $this->post(route('seller.orders.acceptAndPack', $order))->assertRedirect()->assertSessionHas('error');
        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_valid_batch_records_each_original_order_once_and_a_stale_retry_preserves_evidence(): void
    {
        $orders = [$this->packed(), $this->packed()];
        $this->actingAs($this->seller)->post(route('seller.orders.batchReady'), ['order_ids' => [$orders[1]->id, (string) $orders[0]->id]])->assertSessionHas('success');
        foreach ($orders as $order) {
            $this->assertSame('ready_for_pickup', $order->fresh()->status);
            $this->assertSame('unassigned', $order->delivery->fresh()->status);
        }
        $this->assertSame(2, DeliveryCheckpoint::where('checkpoint_type', 'ready_for_pickup')->count());
        $this->assertSame(2, DB::table('notifications')->where('data->milestone', 'ready_for_pickup')->count());
        $this->post(route('seller.orders.batchReady'), ['order_ids' => [$orders[0]->id, $orders[1]->id]])->assertSessionHas('error');
        $this->assertSame(2, DeliveryCheckpoint::where('checkpoint_type', 'ready_for_pickup')->count());
    }

    public function test_later_invalid_order_prevents_all_ready_checkpoints_and_notices(): void
    {
        $first = $this->packed();
        $later = $this->order('placed');
        $this->actingAs($this->seller)->post(route('seller.orders.batchReady'), ['order_ids' => [$first->id, $later->id]])->assertSessionHas('error');
        $this->assertStillPacked([$first]);
        $this->assertSame('placed', $later->fresh()->status);
    }

    public function test_checkpoint_failure_after_the_first_order_was_written_rolls_back_the_entire_batch(): void
    {
        $orders = [$this->packed(), $this->packed()];
        $beforeNotices = DB::table('notifications')->count();
        $beforeIntents = NotificationDelivery::count();
        $writes = 0;
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, function (DeliveryCheckpoint $checkpoint) use (&$writes) {
            if ($checkpoint->checkpoint_type === 'ready_for_pickup' && ++$writes === 2) {
                throw new \RuntimeException('The later checkpoint could not be saved.');
            }
        });
        $this->actingAs($this->seller)->post(route('seller.orders.batchReady'), ['order_ids' => [$orders[0]->id, $orders[1]->id]])
            ->assertSessionHas('error', 'The later checkpoint could not be saved.');
        $this->assertSame(2, $writes);
        $this->assertStillPacked($orders);
        $this->assertSame($beforeNotices, DB::table('notifications')->count());
        $this->assertSame($beforeIntents, NotificationDelivery::count());
        $this->assertDatabaseCount('delivery_checkpoints', 2);
    }

    public function test_foreign_or_mixed_selection_is_rejected_before_an_owned_order_advances(): void
    {
        $first = $this->packed();
        $foreignShop = Shop::factory()->approved()->create();
        $foreign = $this->order('preparing', 1, $foreignShop);
        $this->actingAs($this->seller)->post(route('seller.orders.batchReady'), ['order_ids' => [$first->id, $foreign->id]])->assertForbidden();
        $this->assertStillPacked([$first]);
        OrderItem::factory()->create(['order_id' => $first->id, 'shop_id' => $foreignShop->id, 'product_id' => $foreign->items->first()->product_id]);
        $this->post(route('seller.orders.batchReady'), ['order_ids' => [$first->id]])->assertForbidden();
        $this->assertStillPacked([$first]);
    }

    public static function malformedBatchIds(): array
    {
        return [[[]], [['1', 1]], [['01']], [['1e0']], [[1.0]], [[true]], [['١']], [['1', '999999999']], [array_fill(0, 51, 1)]];
    }

    #[DataProvider('malformedBatchIds')]
    public function test_invalid_batch_ids_never_commit_a_partial_selection(array $ids): void
    {
        $order = $this->packed();
        $this->actingAs($this->seller)->post(route('seller.orders.batchReady'), ['order_ids' => $ids])->assertSessionHasErrors();
        $this->assertStillPacked([$order]);
    }

    public static function staleParcelChanges(): array
    {
        return [['courier_id'], ['assigned_rider_id'], ['status'], ['route'], ['packing']];
    }

    #[DataProvider('staleParcelChanges')]
    public function test_a_claim_or_missing_waybill_prerequisite_rejects_stale_ready_actions(string $change): void
    {
        $order = $this->packed();
        $courier = User::factory()->courier()->create();
        if ($change === 'route') {
            $order->delivery->update(['origin_mother_hub_id' => null]);
        } elseif ($change === 'packing') {
            // Simulate a historical row that has a preparing state without real packing evidence.
            $order = $this->order('preparing');
        } else {
            $order->delivery->update([$change => $change === 'status' ? 'assigned_pickup' : $courier->id]);
        }
        $this->actingAs($this->seller)->get(route('seller.orders.index', ['status' => 'to_pack']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.can_mark_ready', false));
        $this->post(route('seller.orders.ready', $order))->assertSessionHas('error');
        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertDatabaseMissing('delivery_checkpoints', ['checkpoint_type' => 'ready_for_pickup']);
        if (in_array($change, ['courier_id', 'assigned_rider_id', 'status'], true)) {
            $before = $order->delivery->fresh()->getRawOriginal();
            $this->post(route('seller.orders.cancel', $order), ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('error');
            $this->assertSame($before, $order->delivery->fresh()->getRawOriginal());
            $this->assertSame(10, $this->product->fresh()->stock);
        }
    }

    public function test_cancellation_allowlist_and_other_notes_restore_stock_exactly_once(): void
    {
        $order = $this->order('placed', 2);
        $this->actingAs($this->seller)->post(route('seller.orders.cancel', $order), ['reason' => 'Invented reason'])->assertSessionHasErrors('reason');
        $this->post(route('seller.orders.cancel', $order), ['reason' => 'Other reason'])->assertSessionHasErrors('notes');
        $this->post(route('seller.orders.cancel', $order), ['reason' => 'Other reason', 'notes' => '<script>cancel()</script>'])->assertSessionHasErrors('notes');
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);
        $this->post(route('seller.orders.cancel', $order), ['reason' => 'Other reason', 'notes' => 'The parcel was damaged before pickup.'])->assertSessionHas('success');
        $this->assertSame('Other reason: The parcel was damaged before pickup.', $order->fresh()->notes);
        $this->assertSame(12, $this->product->fresh()->stock);
        $this->post(route('seller.orders.cancel', $order), ['reason' => 'Other reason', 'notes' => 'The parcel was damaged before pickup.'])->assertSessionHas('error');
        $this->assertSame(12, $this->product->fresh()->stock);
        $this->assertSame('cancelled', $order->delivery->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('data->milestone', 'cancelled')->count());
    }

    public function test_the_writer_rechecks_account_approval_instead_of_trusting_an_old_seller_object(): void
    {
        $order = $this->packed();
        User::whereKey($this->seller->id)->update(['kyc_status' => 'pending']);
        try {
            app(OrderLifecycleService::class)->sellerBatchReady(['order_ids' => [$order->id]], $this->shop, $this->seller);
            $this->fail('A stale seller object must not bypass the current approval.');
        } catch (AuthorizationException) {
            $this->assertStillPacked([$order]);
        }
    }
}
