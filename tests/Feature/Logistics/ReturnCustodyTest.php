<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryReturnEvent;
use App\Models\DeliveryReturnRoute;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use Throwable;

class ReturnCustodyTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return ['root' => ['/hub', '/seller'], 'subdomains' => ['http://hub.localhost', 'http://seller.localhost']];
    }

    private function returning(): Delivery
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->assertSame('return_to_sender', $parcel->fresh()->status);

        return $parcel->fresh();
    }

    private function reverse(Delivery $parcel, string $base = '/hub'): void
    {
        $route = app(DeliveryReturnService::class)->route($parcel);
        foreach (array_slice($route->hub_ids, 1) as $hubId) {
            $source = LogisticsHub::findOrFail($parcel->fresh()->current_hub_id);
            $this->confirmFlowManifest($parcel, $source, true, direction: 'return', base: $base);
            $this->assertSame('delivery_failed', $parcel->order->fresh()->status);
            $this->assertSame('return_in_transit', $parcel->fresh()->status);
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($hubId), false, direction: 'return', base: $base);
            $this->assertSame('return_to_sender', $parcel->fresh()->status);
            $this->assertFalse(Delivery::riderHasActiveWork($parcel->courier_id));
            $this->assertFalse(Delivery::riderHasActiveWork(DeliveryAttempt::where('delivery_id', $parcel->id)->latest('attempt_number')->firstOrFail()->rider_id));
        }
    }

    private function stage(Delivery $parcel, string $base = '/hub'): array
    {
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub));
        $prompt = $this->postJson($base.'/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('prompt.action', 'STAGE_SELLER_RETURN')->json('prompt');
        $payload = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'STAGE_SELLER_RETURN', 'expected_status' => $prompt['expected_status'], 'route_reference' => $prompt['route_reference']];
        $this->postJson($base.'/scan', $payload)->assertOk();

        return $payload;
    }

    private function receipt(Delivery $parcel): array
    {
        return ['barcode' => $parcel->tracking_number, 'route_reference' => DeliveryReturnRoute::where('delivery_id', $parcel->id)->sole()->reference,
            'notes' => 'I received the original parcel from the origin hub.', 'request_token' => (string) Str::uuid()];
    }

    #[DataProvider('portals')]
    public function test_actual_reverse_manifests_and_owning_seller_receipt_alone_finish_return(string $hub, string $seller): void
    {
        $parcel = $this->returning();
        $stock = $parcel->order->items->mapWithKeys(fn ($item) => [$item->product_id => $item->product->stock])->all();
        $tracking = $parcel->tracking_number;
        $outbound = DeliveryCheckpoint::where('delivery_id', $parcel->id)->get()->map->getAttributes()->all();
        $this->reverse($parcel, $hub);
        $this->assertSame('delivery_failed', $parcel->order->fresh()->status);
        $stage = $this->stage($parcel, $hub);
        $count = DeliveryCheckpoint::count();
        $this->postJson($hub.'/scan', $stage)->assertOk();
        $this->assertSame($count, DeliveryCheckpoint::count());
        $owner = $parcel->order->shop->user;
        $this->actingAs($owner)->get($seller.'/orders')->assertOk()->assertSee('return_ready_for_receipt');
        $payload = $this->receipt($parcel);
        $this->postJson($seller.'/orders/'.$parcel->order_id.'/return-receipt', $payload)->assertOk()->assertJsonPath('status', 'returned');
        $before = $this->snapshot($parcel);
        $this->postJson($seller.'/orders/'.$parcel->order_id.'/return-receipt', $payload)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson($seller.'/orders/'.$parcel->order_id.'/return-receipt', array_replace($payload, ['notes' => 'Changed receipt']))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame('returned', $parcel->order->fresh()->status);
        $this->assertSame($tracking, $parcel->fresh()->tracking_number);
        $this->assertSame('seller', DeliveryCheckpoint::lastCustody($parcel->fresh())['kind']);
        $this->assertSame($owner->id, DeliveryCheckpoint::lastCustody($parcel->fresh())['user_id']);
        $this->assertSame($stock, $parcel->order->items->mapWithKeys(fn ($item) => [$item->product_id => $item->product->fresh()->stock])->all());
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertNull($parcel->order->fresh()->commissionLedger);
        $this->assertSame($outbound, DeliveryCheckpoint::where('delivery_id', $parcel->id)->orderBy('id')->limit(count($outbound))->get()->map->getAttributes()->all());
        $this->assertSame(1, DeliveryReturnEvent::where('event_type', 'seller_received')->count());
    }

    public function test_seller_receipt_and_origin_staging_are_blocked_before_reverse_network(): void
    {
        $parcel = $this->returning();
        $before = $this->snapshot($parcel);
        $this->actingAs($parcel->order->shop->user)->postJson('/seller/orders/'.$parcel->order_id.'/return-receipt', $this->receipt($parcel))->assertConflict();
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->expectException(DomainException::class);
        try {
            app(DeliveryReturnService::class)->stage($parcel, $this->flowHandler($hub), $hub, $parcel->tracking_number, 'return_to_sender', $this->receipt($parcel)['route_reference']);
        } finally {
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_status_only_reverse_transport_is_barred(): void
    {
        $parcel = $this->returning();
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $this->snapshot($parcel);
        $this->expectException(DomainException::class);
        try {
            app(OrderStateMachineService::class)->transition($parcel, 'return_in_transit', $this->flowHandler($hub), ['hub_id' => $hub->id, 'barcode' => $parcel->tracking_number]);
        } finally {
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_foreign_and_platform_actors_cannot_receive_the_sellers_parcel(): void
    {
        $parcel = $this->returning();
        $this->reverse($parcel);
        $this->stage($parcel);
        $before = $this->snapshot($parcel);
        foreach ([$this->createApprovedUser('seller'), $this->createApprovedUser('admin'), $this->createApprovedUser('buyer')] as $actor) {
            $response = $this->actingAs($actor)->postJson('/seller/orders/'.$parcel->order_id.'/return-receipt', $this->receipt($parcel));
            $this->assertContains($response->status(), [403, 409]);
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_wrong_barcode_stale_route_and_markup_notes_preserve_receipt(): void
    {
        $parcel = $this->returning();
        $this->reverse($parcel);
        $this->stage($parcel);
        $before = $this->snapshot($parcel);
        foreach (['barcode' => 'BGO-OTHER', 'route_reference' => 'DRR-OTHER', 'notes' => '<b>Received</b>'] as $key => $value) {
            $response = $this->actingAs($parcel->order->shop->user)->postJson('/seller/orders/'.$parcel->order_id.'/return-receipt', array_replace($this->receipt($parcel), [$key => $value]));
            $this->assertContains($response->status(), [409, 422]);
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_receipt_audit_failure_rolls_back_terminal_state_and_evidence(): void
    {
        $parcel = $this->returning();
        $this->reverse($parcel);
        $this->stage($parcel);
        $before = $this->snapshot($parcel);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, function ($checkpoint) {
            if ($checkpoint->checkpoint_type === 'parcel_returned') {
                throw new RuntimeException('Simulated receipt audit failure');
            }
        });
        $this->expectException(RuntimeException::class);
        try {
            app(DeliveryReturnService::class)->sellerReceive($parcel, $parcel->order->shop->user, $parcel->order->shop, $this->receipt($parcel));
        } finally {
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_return_history_and_manifest_direction_reject_direct_sql_changes(): void
    {
        $parcel = $this->returning();
        $this->reverse($parcel);
        $this->stage($parcel);
        $before = $this->snapshot($parcel);
        foreach (['delivery_return_routes', 'delivery_return_events'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    DB::transaction(fn () => $action === 'update' ? DB::table($table)->update(['created_at' => now()->subDay()]) : DB::table($table)->delete());
                    $this->fail('Return evidence changed.');
                } catch (Throwable $error) {
                    $this->assertStringContainsString('immutable', $error->getMessage());
                }
            }
        }
        try {
            DB::transaction(fn () => DB::table('logistics_manifests')->where('direction', 'return')->update(['direction' => 'outbound']));
            $this->fail('Manifest direction changed.');
        } catch (Throwable $error) {
            $this->assertStringContainsString('direction', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_distinct_mother_hubs_reverse_in_their_original_order(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop, 'Antipolo');
        $companyId = LogisticsHub::where('code', 'FLOW-MH')->value('logistics_company_id');
        LogisticsHub::where('city_municipality', 'Antipolo')->update(['province' => 'Rizal']);
        LogisticsHub::create(['logistics_company_id' => $companyId, 'code' => 'FLOW-RIZAL-MH', 'name' => 'Rizal Mother Hub',
            'tier' => 'regional_mother_hub', 'province' => 'Rizal', 'city_municipality' => 'Antipolo', 'address' => '200 Hub Road', 'is_active' => true]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'placed', ['shipping_city' => 'Antipolo', 'shipping_province' => 'Rizal']);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->assertNotSame($parcel->origin_mother_hub_id, $parcel->destination_mother_hub_id);
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->assertSame([$parcel->destination_bayan_hub_id, $parcel->destination_mother_hub_id, $parcel->origin_mother_hub_id, $parcel->origin_bayan_hub_id], DeliveryReturnRoute::sole()->hub_ids);
        $this->reverse($parcel);
        $this->stage($parcel);
        $this->actingAs($shop->user)->postJson('/seller/orders/'.$order->id.'/return-receipt', $this->receipt($parcel))->assertOk();
        $this->assertSame(3, LogisticsManifest::where('direction', 'return')->count());
        $this->assertSame(1, LogisticsManifest::where('direction', 'return')->where('type', 'line_haul')->count());
    }

    public function test_same_bayan_endpoint_still_visits_the_mother_hub_before_seller_staging(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'), ['city' => 'Santa Cruz']);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->assertSame($parcel->origin_bayan_hub_id, $parcel->destination_bayan_hub_id);
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->assertFalse(app(DeliveryReturnService::class)->readyForSeller($parcel->fresh()));
        $this->assertSame(3, count(DeliveryReturnRoute::sole()->hub_ids));
        $this->reverse($parcel);
        $this->stage($parcel);
        $this->actingAs($shop->user)->postJson('/seller/orders/'.$order->id.'/return-receipt', $this->receipt($parcel))->assertOk();
        $this->assertSame(2, LogisticsManifest::where('direction', 'return')->count());
    }

    public function test_retryable_first_attempt_does_not_fabricate_a_return_route(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($parcel);
        $this->receiveFailureFlow($parcel);
        $this->assertSame(0, DeliveryReturnRoute::count());
        $this->expectException(DomainException::class);
        app(DeliveryReturnService::class)->nextHub($parcel->fresh());
    }

    public function test_mutated_legacy_route_is_refused_without_fabricating_reverse_evidence(): void
    {
        $parcel = $this->returning();
        DB::table('deliveries')->where('id', $parcel->id)->update(['origin_mother_hub_id' => null]);
        $before = $this->snapshot($parcel);
        $this->expectException(DomainException::class);
        try {
            app(DeliveryReturnService::class)->nextHub($parcel->fresh());
        } finally {
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_return_load_rejects_outbound_direction_and_skipped_reverse_mother_hub(): void
    {
        $parcel = $this->returning();
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $nextId = app(DeliveryReturnService::class)->nextHub($parcel);
        $wrongMother = LogisticsHub::create(['logistics_company_id' => $parcel->logistics_company_id, 'code' => 'OTHER-MH', 'name' => 'Other Mother Hub',
            'tier' => 'regional_mother_hub', 'province' => 'Rizal', 'city_municipality' => 'Antipolo', 'address' => '200 Hub Road', 'is_active' => true]);
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $parcel->logistics_company_id, 'hub_id' => $hub->id,
            'plate_number' => 'RETURN-1001', 'vehicle_type' => 'l300_van', 'model' => 'Bagoo Van', 'capacity_kg' => 1000,
            'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $parcel->logistics_company_id, 'assigned_hub_id' => $hub->id,
            'vehicle_id' => $vehicle->id, 'is_available' => true]);
        foreach ([['outbound', $nextId], ['return', $wrongMother->id]] as [$direction, $destination]) {
            $manager = $hub->company->user;
            $this->actingAs($manager)->get('/hub/manifests')->assertOk();
            $id = $this->postJson('/hub/manifests', ['direction' => $direction, 'source_hub_id' => $hub->id, 'destination_hub_id' => $destination,
                'vehicle_id' => $vehicle->id, 'creation_token' => session()->get('manifest_creation_token')])->assertCreated()->json('manifest.id');
            $manifest = LogisticsManifest::findOrFail($id);
            $before = $this->snapshot($parcel);
            $this->actingAs($this->flowHandler($hub))->postJson('/hub/manifests/'.$id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertConflict();
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_suspended_seller_cannot_finish_a_staged_return(): void
    {
        $parcel = $this->returning();
        $this->reverse($parcel);
        $this->stage($parcel);
        $seller = $parcel->order->shop->user;
        $seller->update(['status' => 'suspended']);
        $before = $this->snapshot($parcel);
        $this->actingAs($seller)->postJson('/seller/orders/'.$parcel->order_id.'/return-receipt', $this->receipt($parcel))->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    private function snapshot(Delivery $parcel): array
    {
        $tables = ['orders', 'deliveries', 'delivery_checkpoints', 'delivery_attempts', 'delivery_recovery_events', 'delivery_return_routes', 'delivery_return_events', 'logistics_manifests', 'logistics_manifest_parcels', 'logistics_manifest_events', 'products'];
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }
}
