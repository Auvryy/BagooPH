<?php

namespace Tests\Feature\Flows;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Orders\OrderTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class OrderTrackingConsistencyTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function preparation(): array
    {
        return [
            ['placed', 'Order placed'], ['confirmed', 'Seller confirmed'],
            ['preparing', 'Seller preparing'], ['ready_for_pickup', 'Ready for pickup'],
        ];
    }

    #[DataProvider('preparation')]
    public function test_real_seller_preparation_has_consistent_labels_without_claiming_pickup_readiness(string $status, string $label): void
    {
        $order = $this->newFlowOrder($status);
        $tracking = $this->assertOwnedViews($order, $status, $label);
        $this->assertStringNotContainsString('rider (handover recorded)', $tracking['custodian']);
        if ($status === 'preparing') {
            $this->assertSame('Seller', $tracking['responsible']);
            $this->assertStringContainsString('finish preparation', $tracking['next_step']);
        }
    }

    public static function journey(): array
    {
        return [
            ['assigned_pickup', 'ready_for_pickup', 'Ready for pickup', 'Seller (handover recorded)'],
            ['picked_up', 'picked_up', 'Picked up from seller', 'Pickup rider (handover recorded)'],
            ['arrived_at_origin_hub', 'at_sorting_center', 'Moving through the hub network', null],
            ['in_transit_to_mother_hub', 'at_sorting_center', 'Moving through the hub network', 'Hub transport (handover recorded)'],
            ['arrived_at_mother_hub', 'at_sorting_center', 'Moving through the hub network', null],
            ['sorted_to_line_haul', 'at_sorting_center', 'Moving through the hub network', null],
            ['in_transit_to_destination_hub', 'at_sorting_center', 'Moving through the hub network', 'Hub transport (handover recorded)'],
            ['arrived_at_destination_hub', 'at_sorting_center', 'Moving through the hub network', null],
            ['sorted_to_barangay_bin', 'sorted', 'Sorted at destination hub', null],
            ['assigned_to_rider', 'assigned_to_rider', 'Assigned to delivery rider', null],
            ['out_for_delivery', 'out_for_delivery', 'Out for delivery', 'Delivery rider (handover recorded)'],
            ['delivered', 'delivered', 'Delivered · awaiting buyer confirmation', 'Buyer (handover recorded)'],
        ];
    }

    #[DataProvider('journey')]
    public function test_real_hub_and_rider_journey_agrees_across_owned_public_and_operational_views(string $stage, string $status, string $label, ?string $holder): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $stage);
        $tracking = $this->assertOwnedViews($parcel->order, $status, $label);
        $this->assertNull($tracking['notice']);
        $this->assertSame($holder ?? $parcel->currentHub->name, $tracking['custodian']);
        if ($parcel->current_hub_id) {
            $hub = $parcel->currentHub;
            $this->actingAs($this->flowHandler($hub))->get('/hub/deliveries?hub_id='.$hub->id)
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('deliveries.data.0.guidance.order_status', $label)
                ->where('deliveries.data.0.guidance.custodian', $hub->name));
        }
        if (in_array($stage, ['assigned_pickup', 'picked_up', 'assigned_to_rider', 'out_for_delivery'], true)) {
            $pickup = in_array($stage, ['assigned_pickup', 'picked_up'], true);
            $rider = User::findOrFail($pickup ? $parcel->courier_id : $parcel->assigned_rider_id);
            $key = $pickup ? 'pickupTasks' : 'finalMileTasks';
            $this->actingAs($rider)->get('/courier/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('queues.'.$key.'.0.tracking.commercial_status', $status)
                ->where('queues.'.$key.'.0.tracking.status_label', $label)
                ->where('queues.'.$key.'.0.tracking.custodian', $tracking['custodian']));
        }
        if ($stage === 'delivered') {
            $this->assertNotNull($tracking['custody_recorded_at']);
            $this->completeFlowOrder($parcel->order);
            $completed = $this->assertOwnedViews($parcel->order, 'completed', 'Buyer confirmed receipt');
            $this->assertSame('Buyer confirmed receipt', collect($completed['history'])->last()['label']);
            $this->assertSame('pending', $parcel->order->fresh()->payment_status);
            $this->assertDatabaseCount('commission_ledgers', 0);
        }
    }

    public function test_failure_hub_return_and_retry_dates_follow_the_actual_current_attempt(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($parcel);
        $tracking = $this->assertOwnedViews($parcel->order, 'delivery_failed', 'Delivery issue');
        $this->assertSame('Delivery rider', $tracking['responsible']);
        $this->assertSame('Customer unreachable', $tracking['failure']['reason']);
        $this->assertNull($tracking['failure']['retry_at']);
        $this->receiveFailureFlow($parcel);
        $parcel->refresh();
        $tracking = $this->assertOwnedViews($parcel->order, 'delivery_failed', 'Delivery issue');
        $this->assertSame('Destination hub', $tracking['responsible']);
        $this->assertContains('Failed delivery returned to destination hub', array_column($tracking['history'], 'label'));
        $attempt = DeliveryAttempt::where('delivery_id', $parcel->id)->sole();
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $retryAt = now('Asia/Manila')->addMinutes(30)->startOfMinute();
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), [
            'attempt_reference' => $attempt->reference, 'request_token' => (string) Str::uuid(),
            'retry_at' => $retryAt->format('Y-m-d\TH:i'), 'notes' => 'The buyer will be available at the saved address.',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $tracking = $this->assertOwnedViews($parcel->order, 'delivery_failed', 'Delivery issue');
        $this->assertSame($retryAt->utc()->toIso8601String(), $tracking['failure']['retry_at']);
        $this->assertStringContainsString('retry is approved', $tracking['next_step']);
        $this->travel(31)->minutes();
        $this->confirmReferencedScan($parcel, $hub, 'RELEASE_APPROVED_RETRY', 'attempt_reference');
        $tracking = $this->assertOwnedViews($parcel->order, 'at_sorting_center', 'Moving through the hub network');
        $this->assertNull($tracking['failure']['retry_at']);
        $this->assertContains('Approved retry released for sorting', array_column($tracking['history'], 'label'));
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/sort', ['delivery_id' => $parcel->id, 'barangay' => $parcel->order->destination_barangay])->assertOk();
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $attempt->rider_id])->assertOk();
        $rider = User::findOrFail($attempt->rider_id);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), ['status' => 'out_for_delivery', 'barcode' => $parcel->tracking_number])->assertSessionHas('success');
        $this->patch(route('courier.updateStatus', $parcel), $this->flowFailurePayload($parcel))->assertSessionHas('success');
        $tracking = $this->assertOwnedViews($parcel->order, 'delivery_failed', 'Delivery issue');
        $this->assertSame(2, $tracking['failure']['attempt_number']);
        $this->assertNull($tracking['failure']['retry_at'], 'An older approval cannot schedule a newer failed attempt.');
    }

    public function test_return_transit_and_seller_receipt_keep_the_reverse_journey_and_do_not_restart_preparation(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $hub = LogisticsHub::findOrFail($parcel->fresh()->current_hub_id);
        $this->confirmFlowManifest($parcel, $hub, true, direction: 'return');
        $tracking = $this->assertOwnedViews($parcel->order, 'delivery_failed', 'Delivery issue');
        $this->assertSame('Hub transport (handover recorded)', $tracking['custodian']);
        $this->assertStringContainsString('travelling back', $tracking['next_step']);
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $this->confirmFlowManifest($parcel, $mother, false, direction: 'return');
        $this->confirmFlowManifest($parcel, $mother, true, direction: 'return');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->confirmFlowManifest($parcel, $origin, false, direction: 'return');
        $this->confirmReferencedScan($parcel, $origin, 'STAGE_SELLER_RETURN', 'route_reference');
        $tracking = $this->assertOwnedViews($parcel->order, 'delivery_failed', 'Delivery issue');
        $this->assertSame('Seller', $tracking['responsible']);
        $route = app(DeliveryReturnService::class)->route($parcel->fresh());
        $shop = $parcel->order->shop;
        $this->actingAs($shop->user)->withSession(['active_seller_shop_id' => $shop->id])->postJson(route('seller.orders.return-receipt', $parcel->order), [
            'barcode' => $parcel->tracking_number, 'route_reference' => $route->reference,
            'notes' => 'The returned original parcel was checked.', 'request_token' => (string) Str::uuid(),
        ])->assertOk();
        $tracking = $this->assertOwnedViews($parcel->order, 'returned', 'Returned to seller');
        $this->assertSame('Seller received the returned parcel', collect($tracking['history'])->last()['label']);
    }

    public function test_cancellation_and_restricted_owned_views_preserve_history_and_foreign_ownership(): void
    {
        $order = $this->newFlowOrder('preparing');
        $shop = $order->shop;
        $this->actingAs($shop->user)->withSession(['active_seller_shop_id' => $shop->id])->post(route('seller.orders.cancel', $order), [
            'reason' => 'Out of stock / Inventory shortage',
        ])->assertSessionHas('success');
        $tracking = $this->assertOwnedViews($order, 'cancelled', 'Order cancelled');
        $this->assertSame('No delivery action', $tracking['responsible']);
        $buyer = $order->buyer;
        $buyer->update(['status' => 'suspended']);
        $this->actingAs($buyer)->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canUsePortal', false)->where('canConfirmReceipt', false)
            ->where('order.tracking.commercial_status', 'cancelled'));
        $this->actingAs($this->createApprovedUser('buyer'))->get('/buyer/orders/'.$order->id)->assertForbidden();
        $foreignShop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->actingAs($foreignShop->user)->withSession(['active_seller_shop_id' => $foreignShop->id])
            ->get('/seller/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    }

    public function test_only_original_chronological_events_establish_history_and_holder_without_private_fields(): void
    {
        $order = $this->newFlowOrder('preparing');
        $parcel = $order->delivery;
        $original = $parcel->latestCustodyCheckpoint;
        DeliveryCheckpoint::create([
            'delivery_id' => $parcel->id, 'checkpoint_type' => 'out_for_delivery', 'location_name' => 'Private mirrored location',
            'source_checkpoint_id' => $original->id, 'custody_after' => ['kind' => 'courier', 'user_id' => 999],
            'notes' => 'Private audit text', 'proof_image' => 'private/proof.jpg',
        ]);
        $tracking = $this->assertOwnedViews($order, 'preparing', 'Seller preparing');
        $this->assertSame('Seller (handover recorded)', $tracking['custodian']);
        $this->assertCount(1, $tracking['history']);
        $this->assertSame($original->id, $tracking['history'][0]['id']);
        $json = json_encode($tracking);
        foreach (['Private', 'custody_after', 'source_state', 'proof_image', 'scanned_by_id', 'source_checkpoint_id'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->getJson('/api/track/'.$parcel->tracking_number)->assertOk()->assertJsonCount(1, 'parcel.checkpoints');
        $this->travel(-1)->days();
        $earlier = DeliveryCheckpoint::create(['delivery_id' => $parcel->id, 'checkpoint_type' => 'confirmed', 'location_name' => 'Shop review']);
        $this->travelBack();
        $tracking = $this->assertOwnedViews($order, 'preparing', 'Seller preparing');
        $this->assertSame($earlier->id, $tracking['history'][0]['id']);
    }

    public static function legacyStates(): array
    {
        return [
            ['pending', 'unassigned', 'placed'], ['processing', 'unassigned', 'preparing'], ['packaging', 'unassigned', 'preparing'],
            ['failed', 'delivery_failed', 'delivery_failed'], ['canceled', 'cancelled', 'cancelled'],
            ['shipped', 'arrived_at_mother_hub', 'at_sorting_center'], ['in_transit', 'out_for_delivery', 'out_for_delivery'],
            ['shipped', 'unassigned', 'unknown'], ['invalid_state', 'delivered', 'unknown'],
        ];
    }

    #[DataProvider('legacyStates')]
    public function test_legacy_read_fixtures_normalize_known_aliases_and_do_not_invent_unknown_progress(string $orderStatus, string $parcelStatus, string $expected): void
    {
        $order = Order::factory()->create(['status' => $orderStatus]);
        Delivery::factory()->create(['order_id' => $order->id, 'status' => $parcelStatus]);
        $order->load(OrderTrackingService::relations());
        $tracking = app(OrderTrackingService::class)->present($order);
        $this->assertSame($expected, $tracking['commercial_status']);
        $this->assertSame('Parcel holder not recorded', $tracking['custodian']);
        $this->assertSame([], $tracking['history']);
        if ($expected === 'unknown') {
            $this->assertNotNull($tracking['notice']);
            $this->assertSame('Bagoo support', $tracking['responsible']);
        }
        $this->assertSame($orderStatus, $order->fresh()->status);
    }

    public function test_missing_or_conflicting_records_require_review_without_guessed_holders(): void
    {
        $order = Order::factory()->create(['status' => 'placed']);
        $tracking = app(OrderTrackingService::class)->present($order);
        $this->assertSame('Parcel record not available', $tracking['stage_label']);
        $this->assertNotNull($tracking['notice']);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'out_for_delivery', 'assigned_rider_id' => $this->createApprovedUser('courier')->id]);
        $tracking = app(OrderTrackingService::class)->present($order->fresh());
        $this->assertSame('placed', $tracking['commercial_status']);
        $this->assertSame('Parcel holder not recorded', $tracking['custodian']);
        $this->assertNotNull($tracking['notice']);
        $this->assertSame('Bagoo support', $tracking['responsible']);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public static function missingHolderSnapshots(): array
    {
        return ['no snapshot' => [null], 'unknown holder' => [['kind' => 'unknown']]];
    }

    #[DataProvider('missingHolderSnapshots')]
    public function test_legacy_delivery_history_does_not_claim_the_handover_event_is_missing(?array $snapshot): void
    {
        $order = Order::factory()->create(['status' => 'completed']);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        foreach (['delivered', 'buyer_completed'] as $type) {
            DeliveryCheckpoint::create([
                'delivery_id' => $parcel->id, 'checkpoint_type' => $type,
                'custody_after' => $snapshot,
            ]);
        }

        $tracking = app(OrderTrackingService::class)->present($order->fresh());

        $this->assertSame('Parcel holder not recorded', $tracking['custodian']);
        $this->assertNull($tracking['custody_recorded_at']);
        $this->assertSame('The parcel holder is not recorded. The handover details need review.', $tracking['notice']);
        $this->assertSame(['Doorstep handover recorded', 'Buyer confirmed receipt'], array_column($tracking['history'], 'label'));
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 2);
    }

    private function confirmReferencedScan(Delivery $parcel, LogisticsHub $hub, string $action, string $reference): void
    {
        $prompt = $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), [
            'barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect',
        ])->assertOk()->assertJsonPath('prompt.action', $action)->json('prompt');
        $this->postJson(route('hub.scan'), [
            'barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => $action, 'expected_status' => $prompt['expected_status'], $reference => $prompt[$reference],
        ])->assertOk()->assertJsonPath('confirmed', true);
        $parcel->refresh();
    }

    public static function conflictingHandoverRecords(): array
    {
        return [
            'seller after departure' => ['out_for_delivery', 'out_for_delivery', ['kind' => 'seller']],
            'wrong rider after departure' => ['out_for_delivery', 'out_for_delivery', ['kind' => 'courier', 'user_id' => 99999]],
            'wrong recipient' => ['delivered', 'delivered', ['kind' => 'buyer', 'user_id' => 99999]],
            'wrong current hub' => ['assigned_to_rider', 'assigned_to_rider', ['kind' => 'hub', 'hub_id' => 99999]],
        ];
    }

    #[DataProvider('conflictingHandoverRecords')]
    public function test_conflicting_retained_handover_records_do_not_offer_ordinary_progress(string $orderStatus, string $parcelStatus, array $custody): void
    {
        $order = Order::factory()->create(['status' => $orderStatus]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => $parcelStatus]);
        DeliveryCheckpoint::create(['delivery_id' => $parcel->id, 'checkpoint_type' => $parcelStatus, 'custody_after' => $custody]);
        $before = $parcel->fresh()->getRawOriginal();
        $tracking = app(OrderTrackingService::class)->present($order->fresh());
        $this->assertNotNull($tracking['notice']);
        $this->assertSame('Bagoo support', $tracking['responsible']);
        $this->assertStringContainsString('Contact support', $tracking['next_step']);
        $this->assertSame($before, $parcel->fresh()->getRawOriginal());
    }

    private function assertOwnedViews(Order $order, string $status, string $label): array
    {
        $order = $order->fresh(['buyer', 'shop.user', 'delivery']);
        $before = [$order->getRawOriginal(), $order->delivery->getRawOriginal(), DB::table('delivery_checkpoints')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), DB::table('products')->pluck('stock')->all()];
        $response = $this->actingAs($order->buyer)->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.tracking.commercial_status', $status)->where('order.tracking.status_label', $label)
            ->missing('order.delivery.checkpoints')->missing('order.delivery.latest_custody_checkpoint')
            ->missing('order.delivery.latest_attempt')->missing('order.delivery.pickup_claim'));
        $tracking = $response->inertiaProps('order.tracking');
        $this->actingAs($order->shop->user)->withSession(['active_seller_shop_id' => $order->shop->id])->get('/seller/orders')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.tracking.commercial_status', $status)->where('orders.data.0.tracking.status_label', $label)
            ->where('orders.data.0.tracking.custodian', $tracking['custodian'])->where('orders.data.0.tracking.history', $tracking['history'])
            ->missing('orders.data.0.delivery.latest_attempt')->missing('orders.data.0.delivery.checkpoints'));
        $this->getJson('/api/track/'.$order->delivery->tracking_number)->assertOk()->assertJsonPath('parcel.status', $status);
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $order->delivery->fresh()->getRawOriginal(), DB::table('delivery_checkpoints')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), DB::table('products')->pluck('stock')->all()]);

        return $tracking;
    }
}
