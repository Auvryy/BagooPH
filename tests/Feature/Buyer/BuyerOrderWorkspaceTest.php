<?php

namespace Tests\Feature\Buyer;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerOrderWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_stage_and_legacy_alias_has_separate_owned_counts_and_filters(): void
    {
        $buyer = User::factory()->create();
        $stages = [
            'to_ship' => ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging', 'ready_for_pickup'],
            'in_transit' => ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'in_transit'],
            'delivered' => ['delivered'], 'completed' => ['completed'],
            'delivery_failed' => ['delivery_failed', 'failed'], 'returned' => ['returned'],
            'cancelled' => ['cancelled', 'canceled'],
        ];
        $expected = ['all' => 21];
        foreach ($stages as $stage => $statuses) {
            $expected[$stage] = count($statuses);
            foreach ($statuses as $status) {
                Order::factory()->create(['buyer_id' => $buyer->id, 'status' => $status]);
                Order::factory()->create(['status' => $status]);
            }
        }
        $this->actingAs($buyer);
        foreach ($stages as $stage => $statuses) {
            $this->get('/buyer/orders?order_status='.$stage)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Buyer/Profile')->where('ordersCount', 21)->where('orderCounts', $expected)
                ->where('currentOrderStatus', $stage)->where('initialTab', 'orders')
                ->where('orders.total', count($statuses))
                ->where('orders.data', fn ($rows) => collect($rows)->every(fn ($row) => in_array($row['status'], $statuses, true)
                    && $row['buyer_id'] === $buyer->id)));
        }
        $this->get('/buyer/orders?order_status=to_receive')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('currentOrderStatus', 'in_transit')->where('orders.total', 7));
    }

    public function test_equal_dates_paginate_stably_inside_the_purchase_workspace_and_keep_filters(): void
    {
        $buyer = User::factory()->create();
        $orders = Order::factory()->count(13)->create(['buyer_id' => $buyer->id, 'status' => 'delivered', 'created_at' => '2026-10-01 08:00:00']);
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        Order::factory()->create(['status' => 'delivered']);
        $this->actingAs($buyer)->get('/buyer/profile?tab=orders&order_status=delivered')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 12)->where('ordersCount', 14)->where('orders.total', 13)
            ->where('orders.data.0.id', $orders->last()->id)->where('orders.last_page', 2)
            ->where('orders.next_page_url', fn ($url) => str_contains($url, 'order_status=delivered') && str_contains($url, 'tab=orders') && str_contains($url, 'page=2')));
        $this->get('/buyer/profile?tab=orders&order_status=delivered&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.id', $orders->first()->id)->where('orders.current_page', 2));
    }

    public function test_restricted_history_uses_the_same_filters_without_exposing_portal_data(): void
    {
        $buyer = User::factory()->create(['status' => 'suspended']);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivery_failed']);
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        Order::factory()->create(['status' => 'delivery_failed']);
        $this->actingAs($buyer)->get('/buyer/orders?order_status=delivery_failed')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/Orders')->has('orders.data', 1)->where('orders.data.0.id', $order->id)
            ->where('orderCounts.all', 2)->where('orderCounts.completed', 1)->where('currentOrderStatus', 'delivery_failed')
            ->where('canUsePortal', false)->missing('addresses')->missing('wallet')->missing('user')
            ->missing('orders.data.0.shipping_address')->missing('orders.data.0.buyer_id')->missing('orders.data.0.notes'));
    }

    public function test_receipt_flags_require_owned_physical_delivery_and_the_existing_writer_refreshes_counts(): void
    {
        $buyer = User::factory()->create();
        $eligible = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $eligible->id, 'status' => 'delivered']);
        $unfinished = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $unfinished->id, 'status' => 'out_for_delivery']);
        $unsupportedCollection = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $unsupportedCollection->id, 'status' => 'customer_collected', 'delivery_type' => 'hub_self_pickup']);
        $complete = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        Delivery::factory()->create(['order_id' => $complete->id, 'status' => 'delivered']);
        $foreign = Order::factory()->create(['status' => 'delivered']);
        $this->actingAs($buyer)->get('/buyer/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 4)->where('orders.data', fn ($rows) => collect($rows)->where('can_confirm_receipt', true)->pluck('id')->all() === [$eligible->id]));
        $this->post('/buyer/orders/'.$foreign->id.'/confirm')->assertForbidden();
        $this->post('/buyer/orders/'.$unsupportedCollection->id.'/confirm')->assertSessionHas('error');
        $this->post('/buyer/orders/'.$eligible->id.'/confirm')->assertSessionHas('success');
        $this->post('/buyer/orders/'.$eligible->id.'/confirm')->assertSessionHas('success');
        $this->get('/buyer/orders?order_status=completed')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orderCounts.delivered', 2)->where('orderCounts.completed', 2)->has('orders.data', 2)
            ->where('orders.data', fn ($rows) => collect($rows)->every(fn ($row) => ! $row['can_confirm_receipt'])));
        $this->assertDatabaseCount('delivery_checkpoints', 1);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertSame('delivered', $unfinished->fresh()->status);
    }

    public static function invalidSelections(): array
    {
        return [
            ['order_status=to_pack', 'order_status'], ['order_status%5B%5D=all', 'order_status'],
            ['page=0', 'page'], ['page=01', 'page'], ['page=1e1', 'page'], ['page=1.0', 'page'],
            ['page%5B%5D=1', 'page'], ['page=1000001', 'page'],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_filters_and_page_ids_are_rejected(string $query, string $field): void
    {
        $this->actingAs(User::factory()->create())->get('/buyer/orders?'.$query)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('orders', 0);
    }
}
