<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\PickupClaim;
use App\Models\User;
use App\Services\Logistics\PickupClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class HubParcelWorkstationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function stages(): array
    {
        return [
            'pickup intake' => ['picked_up', 'origin_bayan_hub_id', 'scan', 'Picked up from seller'],
            'origin transfer' => ['arrived_at_origin_hub', 'origin_bayan_hub_id', 'manifest', 'Moving through the hub network'],
            'Mother Hub sort' => ['arrived_at_mother_hub', 'origin_mother_hub_id', 'scan', 'Moving through the hub network'],
            'Mother Hub transfer' => ['sorted_to_line_haul', 'origin_mother_hub_id', 'manifest', 'Moving through the hub network'],
            'destination sort' => ['arrived_at_destination_hub', 'destination_bayan_hub_id', 'sort', 'Moving through the hub network'],
            'assigned rider has not departed' => ['assigned_to_rider', 'destination_bayan_hub_id', 'wait', 'Assigned to delivery rider'],
        ];
    }

    #[DataProvider('stages')]
    public function test_real_flow_stages_show_actual_custody_and_role_scoped_next_actions(string $stage, string $hubField, string $action, string $orderStatus): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $stage);
        $hub = LogisticsHub::findOrFail($parcel->$hubField);
        $handler = $this->flowHandler($hub);
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->get('/hub/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('deliveries.data', 1)->where('deliveries.data.0.guidance.action', $action)
            ->where('deliveries.data.0.guidance.can_act', $action !== 'wait')
            ->where('deliveries.data.0.guidance.order_status', $orderStatus)
            ->where('deliveries.data.0.guidance.custodian', $stage === 'picked_up' ? 'Pickup rider: '.$parcel->courier->name : $hub->name)
            ->where('canScan', true));
        $this->assertSame($before, $this->snapshot($parcel), 'Reading guidance must not record custody, money or claim changes.');
    }

    public static function portals(): array
    {
        return ['root' => ['/hub'], 'subdomain' => ['http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_grouped_filters_match_counts_and_keep_facility_scope(string $base): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_mother_hub');
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $handler = $this->flowHandler($mother);
        $this->actingAs($handler)->get($base.'/deliveries?status=in_hub')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.in_hub', 1)->where('deliveries.total', 1)->where('deliveries.data.0.id', $parcel->id)
            ->where('basePath', $base === '/hub' ? '/hub' : '')
            ->where('deliveries.data.0.guidance.href', ($base === '/hub' ? '/hub' : '').'/scan?hub_id='.$mother->id));
        $this->get($base.'/deliveries?status=in_hub&search=NO-SUCH-PARCEL')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.in_hub', 1)->where('deliveries.total', 0));
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($destination))->get($base.'/deliveries?hub_id='.$destination->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.total', 0)->where('counts.all', 0)->where('counts.in_hub', 0)->where('counts.handovers_today', 0));
        $this->get($base.'/deliveries?hub_id='.$mother->id)->assertForbidden();
    }

    public function test_assignment_does_not_mislabel_the_rider_as_the_custodian(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_to_rider');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($hub->company->user)->get('/hub/deliveries?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.data.0.guidance.custodian', $hub->name)
            ->where('deliveries.data.0.guidance.responsible', $parcel->assignedRider->name)
            ->where('deliveries.data.0.guidance.can_act', false)->where('canScan', false));
    }

    public function test_a_restricted_assigned_rider_requires_review_without_fabricating_a_handover(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_to_rider');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->restrictFlowAccount($parcel->assignedRider, 'suspend');
        $before = $this->snapshot($parcel);
        $this->actingAs($hub->company->user)->get('/hub/deliveries?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.data.0.guidance.can_act', false)->where('deliveries.data.0.guidance.custodian', $hub->name)
            ->where('deliveries.data.0.guidance.next_step', 'Review the assigned rider\'s eligibility'));
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_company_management_can_assign_only_current_eligible_area_riders_and_cannot_scan(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $manager = $hub->company->user;
        $this->actingAs($manager)->get('/hub/deliveries?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.data.0.guidance.can_act', false)->where('deliveries.data.0.guidance.eligible_rider_ids', [])
            ->where('deliveries.data.0.guidance.blocker', 'No available eligible rider serves this facility and barangay.'));
        $rider = $this->flowRider($hub);
        $wrongArea = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $wrongArea->courierProfile->update(['assigned_barangay' => 'Different barangay']);
        $this->actingAs($manager)->get('/hub/deliveries?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.data.0.guidance.action', 'assign')->where('deliveries.data.0.guidance.can_act', true)
            ->where('deliveries.data.0.guidance.eligible_rider_ids', [$rider->id])->where('canScan', false));
        $rider->courierProfile->update(['is_available' => false]);
        $before = $this->snapshot($parcel);
        $this->postJson('/hub/deliveries/'.$parcel->id.'/assign-rider', ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->get('/hub/deliveries?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.data.0.guidance.can_act', false));
        $this->get('/hub/scan?hub_id='.$hub->id)->assertForbidden();
    }

    public function test_missing_custody_is_blocked_and_excluded_from_recorded_hub_counts(): void
    {
        // Explicit invalid legacy fixture: status and facility fields alone are not operational evidence.
        $parcel = $this->newFlowOrder()->delivery;
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $parcel->update(['status' => 'arrived_at_destination_hub', 'current_hub_id' => $hub->id]);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->flowHandler($hub))->get('/hub/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.all', 1)->where('counts.in_hub', 0)->where('counts.handovers_today', 0)
            ->where('deliveries.data.0.guidance.custodian', 'Custody not established')
            ->where('deliveries.data.0.guidance.can_act', false)->where('deliveries.data.0.guidance.href', null));
        $this->get('/hub/deliveries?status=in_hub')->assertOk()->assertInertia(fn (Assert $page) => $page->where('deliveries.total', 0));
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_latest_custody_excludes_correction_and_non_custody_rows(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_destination_hub');
        $source = $parcel->checkpoints()->latest('id')->first();
        DeliveryCheckpoint::record($parcel, 'review_note', evidence: ['custody_after' => ['kind' => 'unknown'], 'source_checkpoint_id' => $source->id]);
        DeliveryCheckpoint::record($parcel, 'review_note');
        $this->assertSame($source->id, $parcel->fresh()->latestCustodyCheckpoint->id);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->get('/hub/deliveries?status=in_hub')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.in_hub', 1)->where('deliveries.data.0.guidance.custodian', $hub->name));
    }

    public function test_recorded_handover_count_survives_departure_and_uses_the_manila_day(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(0, 30));
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->get('/hub/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.handovers_today', 1)->where('counts.all', 0));
        $this->travelTo(now()->setTime(16, 0));
        $this->get('/hub/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.handovers_today', 0));
    }

    #[DataProvider('portals')]
    public function test_counter_arrival_is_not_ready_and_grouped_pickup_filter_matches(string $base): void
    {
        $parcel = $this->selfPickup(false);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->get($base.'/deliveries?status=ready_pickup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.ready_pickup', 1)->where('deliveries.total', 1)
            ->where('deliveries.data.0.guidance.action', 'scan')->where('deliveries.data.0.guidance.ready_for_collection', false));
        $this->get($base.'/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.ready', 0)->where('counterCounts.blocked', 1)->where('counterParcels.data.0.arrived_at', null));
        $hub->update(['operating_hours' => null]);
        $this->get($base.'/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterParcels.data.0.guidance.can_act', false)
            ->where('counterParcels.data.0.guidance.blocker', 'The Mother Hub manager must record actual pickup hours before staging.'));
    }

    public function test_staged_collection_is_ready_only_for_handler_verification_and_requires_retained_custody(): void
    {
        $parcel = $this->selfPickup(true);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $manager = $hub->company->user;
        $this->actingAs($manager)->get('/hub/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.ready', 1)->where('counterParcels.data.0.guidance.can_act', false)->where('canScan', false));
        $this->actingAs($this->flowHandler($hub))->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.ready', 1)->where('counterParcels.data.0.guidance.action', 'counter')->where('counterParcels.data.0.guidance.can_act', true));
        $parcel->update(['current_hub_id' => null]);
        $this->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counterCounts.shown', 0));
    }

    public function test_a_stale_pickup_label_cannot_put_another_facility_parcel_in_the_counter_group(): void
    {
        $parcel = $this->selfPickup(true);
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        // Invalid legacy location: retain the destination checkpoint rather than inventing a receipt at the origin.
        $parcel->update(['current_hub_id' => $origin->id]);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->flowHandler($origin))->get('/hub/deliveries?hub_id='.$origin->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts.all', 1)->where('counts.ready_pickup', 0)->where('deliveries.data.0.guidance.can_act', false));
        $this->get('/hub/deliveries?hub_id='.$origin->id.'&status=ready_pickup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.total', 0)->where('counts.ready_pickup', 0));
        $this->get('/hub/counter?hub_id='.$origin->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('counterCounts.shown', 0));
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($destination))->get('/hub/scan?hub_id='.$destination->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterPickups', 0)->where('counterTotal', 0)->where('stats.ready_pickup', 0));
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_locked_counter_claim_and_natural_expiry_are_read_only_blockers(): void
    {
        config(['session.lifetime' => 60 * 24 * 8]);
        $parcel = $this->selfPickup(true);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $claim = PickupClaim::where('delivery_id', $parcel->id)->sole();
        $this->actingAs($parcel->order->buyer)->postJson('/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertOk();
        $handler = $this->flowHandler($hub);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($handler)->postJson('/hub/release', ['barcode' => $parcel->tracking_number, 'claim_code' => 'WRONG-CODE',
                'hub_id' => $hub->id, 'buyer_id' => $parcel->order->buyer_id, 'recipient_name' => $parcel->order->buyer->name, 'identity_confirmed' => true,
                'request_token' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('message', 'The claim code could not be verified.');
        }
        $before = $this->snapshot($parcel);
        $this->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.ready', 0)->where('counterParcels.data.0.guidance.can_act', false)
            ->where('counterParcels.data.0.guidance.blocker', fn ($value) => str_contains($value, 'verification is locked')));
        $this->get('/hub/scan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.ready_pickup', 1)->where('counterPickups.0.guidance.ready_for_collection', false)
            ->where('counterPickups.0.guidance.blocker', fn ($value) => str_contains($value, 'verification is locked')));
        $this->assertSame($before, $this->snapshot($parcel));
        $this->travelTo($claim->expires_at);
        $this->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.ready', 0)->where('counterParcels.data.0.guidance.can_act', false));
        $this->get('/hub/scan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.ready_pickup', 1)->where('counterTotal', 1)->where('counterPickups.0.guidance.ready_for_collection', false));
        $this->assertSame('ready', $claim->fresh()->status, 'A page read cannot expire a claim.');
        app(PickupClaimService::class)->processDue();
        $this->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterParcels.data.0.guidance.action', 'scan')->where('counterParcels.data.0.guidance.can_act', true));
    }

    public function test_malformed_filters_are_validation_errors_and_not_server_errors(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->getJson('/hub/deliveries?status[]=in_hub')->assertUnprocessable();
        $this->getJson('/hub/deliveries?search[]=BGO')->assertUnprocessable();
        $this->getJson('/hub/counter?search[]=BGO')->assertUnprocessable();
    }

    public function test_inconsistent_commercial_state_cannot_offer_a_sorting_action(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_destination_hub');
        // Deliberately corrupt only the legacy commercial label after a real custody flow.
        $parcel->order->update(['status' => 'placed']);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->flowHandler($hub))->get('/hub/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.data.0.guidance.can_act', false)->where('deliveries.data.0.guidance.order_status', 'Order placed')
            ->where('deliveries.data.0.guidance.next_step', 'Review the order and parcel stages'));
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_counter_guidance_requires_actual_incoming_manifest_closure_before_staging(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $hub = LogisticsHub::where('city_municipality', 'Santa Cruz')->where('tier', 'local_bayan_hub')->sole();
        $hub->update(['allows_self_pickup' => true, 'operating_hours' => '08:00 to 17:00']);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, shipping: ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id]);
        $parcel = $this->flowDelivery($order, 'in_transit_to_destination_hub');
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $handler = $this->flowHandler($hub);
        $this->openFlowMotherBags($manifest, $handler);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->get('/hub/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.ready', 0)->where('counterParcels.data.0.guidance.action', 'manifest')
            ->where('counterParcels.data.0.guidance.href', '/hub/manifests/'.$manifest->id.'?hub_id='.$hub->id));
        $this->actingAs($hub->company->user)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->actingAs($handler)->get('/hub/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterParcels.data.0.guidance.action', 'scan')->where('counterParcels.data.0.guidance.can_act', true)
            ->where('counterParcels.data.0.guidance.ready_for_collection', false));
    }

    public function test_recent_counter_results_require_real_collection_evidence_and_survive_buyer_confirmation(): void
    {
        $parcel = $this->selfPickup(true);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $code = $this->actingAs($parcel->order->buyer)->postJson('/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertOk()->json('code');
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->postJson('/hub/release', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
            'claim_code' => $code, 'buyer_id' => $parcel->order->buyer_id, 'recipient_name' => $parcel->order->buyer->name,
            'identity_confirmed' => true, 'request_token' => (string) Str::uuid(), 'cash_received' => (string) $parcel->order->total_amount,
            'change_given' => '0', 'cash_confirmed' => true])->assertOk();
        $this->actingAs($parcel->order->buyer)->post(route('buyer.orders.confirm', $parcel->order))->assertSessionHas('success');
        $this->assertSame('completed', $parcel->order->fresh()->status);
        $this->assertSame('customer_collected', $parcel->fresh()->status);
        $this->actingAs($handler)->get('/hub/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.shown', 0)->has('recentlyCollected', 1)->where('recentlyCollected.0.id', $parcel->id));
        $this->get('/hub/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.handovers_today', 1));
        $legacy = $this->newFlowOrder()->delivery;
        $legacy->update(['delivery_type' => 'hub_self_pickup', 'status' => 'customer_collected', 'destination_bayan_hub_id' => $hub->id]);
        $this->actingAs($handler)->get('/hub/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('recentlyCollected', 1)->where('recentlyCollected.0.id', $parcel->id));
    }

    #[DataProvider('portals')]
    public function test_recent_collections_follow_actual_handover_time_instead_of_parcel_creation(string $base): void
    {
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(8, 0));
        $older = $this->selfPickup(true);
        $this->travel(1)->hours();
        $newer = $this->selfPickup(true);
        $hub = LogisticsHub::findOrFail($older->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        foreach ([$newer, $older] as $parcel) {
            $code = $this->actingAs($parcel->order->buyer)->postJson('http://localhost/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertOk()->json('code');
            $this->actingAs($handler)->postJson($base.'/release', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
                'claim_code' => $code, 'buyer_id' => $parcel->order->buyer_id, 'recipient_name' => $parcel->order->buyer->name,
                'identity_confirmed' => true, 'request_token' => (string) Str::uuid(), 'cash_received' => (string) $parcel->order->total_amount,
                'change_given' => '0', 'cash_confirmed' => true])->assertOk();
            $this->travel(1)->minutes();
        }
        $this->assertGreaterThan($older->id, $newer->id);
        $this->assertTrue($older->fresh()->pickupClaim->consumed_at->greaterThan($newer->fresh()->pickupClaim->consumed_at));
        $this->actingAs($handler)->get($base.'/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('recentlyCollected', 2)->where('recentlyCollected.0.id', $older->id)->where('recentlyCollected.1.id', $newer->id));
    }

    #[DataProvider('portals')]
    public function test_counter_pages_keep_matching_totals_and_page_readiness_in_both_portals(string $base): void
    {
        $oldest = $this->selfPickup(false);
        $staged = collect(range(1, 15))->map(fn () => $this->selfPickup(true));
        $hub = LogisticsHub::findOrFail($oldest->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $before = $this->snapshot($oldest);
        $path = $base.'/counter?hub_id='.$hub->id.'&search=Mara';

        $this->actingAs($handler)->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterParcels.data', 15)->where('counterParcels.total', 16)->where('counterParcels.last_page', 2)
            ->where('counterParcels.from', 1)->where('counterParcels.to', 15)
            ->where('counterParcels.data.0.id', $staged->last()->id)
            ->where('counterCounts.shown', 15)->where('counterCounts.ready', 15)->where('counterCounts.blocked', 0)
            ->where('counterParcels.next_page_url', fn ($url) => str_contains($url, 'hub_id='.$hub->id)
                && str_contains($url, 'search=Mara') && str_contains($url, 'page=2')
                && parse_url($url, PHP_URL_PATH) === ($base === '/hub' ? '/hub/counter' : '/counter')));
        $this->get($path.'&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterParcels.data', 1)->where('counterParcels.total', 16)->where('counterParcels.current_page', 2)
            ->where('counterParcels.from', 16)->where('counterParcels.to', 16)->where('counterParcels.data.0.id', $oldest->id)
            ->where('counterCounts.shown', 1)->where('counterCounts.ready', 0)->where('counterCounts.blocked', 1));
        $this->get($base.'/counter?hub_id='.$hub->id.'&search='.$oldest->tracking_number)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterParcels.data', 1)->where('counterParcels.total', 1)->where('counterParcels.data.0.id', $oldest->id));
        $this->get($path.'&page=8')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterParcels.data', 0)->where('counterParcels.total', 16)->where('counterParcels.current_page', 8)
            ->where('counterCounts.shown', 0)->where('counterCounts.ready', 0)->where('counterCounts.blocked', 0));
        $this->get($base.'/counter?hub_id='.$hub->id.'&search=NO-MATCH')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterParcels.data', 0)->where('counterParcels.total', 0));
        $this->assertSame($before, $this->snapshot($oldest));
    }

    public function test_counter_queries_and_payload_stay_bounded_when_the_queue_grows(): void
    {
        // Keep session timestamp writes identical while comparing the two queues.
        $this->freezeTime();
        $parcel = $this->selfPickup(true);
        foreach (range(1, 15) as $_) {
            $this->selfPickup(true);
        }
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/hub/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterParcels.data', 15)->where('counterParcels.total', 16)->where('counterCounts.ready', 15));
        $initialQueries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach (range(1, 6) as $_) {
            $this->selfPickup(true);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/hub/counter?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterParcels.data', 15)->where('counterParcels.total', 22)->where('counterCounts.ready', 15));
        $grownQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(count($initialQueries), $grownQueries, 'More off-page parcels must not add per-parcel readiness queries.');
        $checkpointReads = collect($grownQueries)->filter(fn ($query) => str_contains($query['query'], 'select') && str_contains($query['query'], 'from "delivery_checkpoints"'));
        $this->assertCount(2, $checkpointReads, 'Original staging and custody evidence must be read in two eager-loaded batches.');
        $this->get('/hub/scan?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counterPickups', 20)->where('counterTotal', 22)->where('stats.ready_pickup', 22)
            ->where('counterPickups.0.guidance.custodian', $hub->name));
    }

    #[DataProvider('portals')]
    public function test_scanner_reuses_actual_arrival_staging_and_counter_guidance(string $base): void
    {
        $parcel = $this->selfPickup(false);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $path = $base === '/hub' ? '/hub' : '';
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->get($base.'/scan?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('basePath', $path)->where('counterTotal', 1)->where('stats.ready_pickup', 0)
            ->where('counterPickups.0.destination_bin', null)->where('counterPickups.0.guidance.action', 'scan')
            ->where('counterPickups.0.guidance.order_status', 'Moving through the hub network')->where('counterPickups.0.guidance.custodian', $hub->name));
        $this->postJson($base.'/scan', ['hub_id' => $hub->id, 'barcode' => $parcel->tracking_number, 'mode' => 'inspect'])->assertOk()
            ->assertJsonPath('delivery.destination_bin', null)->assertJsonPath('delivery.guidance.action', 'scan')
            ->assertJsonPath('delivery.guidance.ready_for_collection', false)->assertJsonPath('prompt.requires_confirmation', true);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson($base.'/scan', ['hub_id' => $hub->id, 'barcode' => $parcel->tracking_number, 'mode' => 'confirm',
            'action' => 'STAGE_FOR_PICKUP', 'expected_status' => $parcel->status])->assertOk()
            ->assertJsonPath('delivery.destination_bin', null)->assertJsonPath('delivery.guidance.action', 'counter')
            ->assertJsonPath('delivery.guidance.ready_for_collection', true)->assertJsonPath('prompt.requires_confirmation', false)
            ->assertJsonPath('delivery.guidance.href', $path.'/counter?hub_id='.$hub->id.'&search='.$parcel->tracking_number);
        $this->get($base.'/scan?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterPickups.0.destination_bin', null)->where('counterPickups.0.guidance.stage', 'Staged for secure counter collection')
            ->where('stats.ready_pickup', 1)->where('counterPickups.0.guidance.ready_for_collection', true));
        $parcel->update(['destination_bin' => 'COUNTER-B2']);
        $hub->update(['allows_self_pickup' => false]);
        $before = $this->snapshot($parcel);
        $this->get($base.'/scan?hub_id='.$hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterPickups.0.destination_bin', 'COUNTER-B2')->where('counterTotal', 1)
            ->where('counterPickups.0.guidance.can_act', false)->where('counterPickups.0.guidance.ready_for_collection', false));
        $this->postJson($base.'/scan', ['hub_id' => $hub->id, 'barcode' => $parcel->tracking_number, 'mode' => 'inspect'])->assertOk()
            ->assertJsonPath('delivery.destination_bin', 'COUNTER-B2')->assertJsonPath('delivery.guidance.can_act', false)
            ->assertJsonPath('prompt.requires_confirmation', false);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->actingAs($hub->company->user)->get($base.'/scan?hub_id='.$hub->id)->assertForbidden();
    }

    public function test_batched_readiness_keeps_the_latest_original_checkpoint_guard(): void
    {
        $parcel = $this->selfPickup(true);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $source = $parcel->latestCustodyCheckpoint;
        $code = $this->actingAs($parcel->order->buyer)->postJson('/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertOk()->json('code');
        DeliveryCheckpoint::record($parcel, 'review_note', evidence: ['source_checkpoint_id' => $source->id]);
        $this->actingAs($this->flowHandler($hub))->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counterCounts.ready', 1));
        DeliveryCheckpoint::record($parcel, 'review_note');
        $before = $this->snapshot($parcel);
        $this->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterCounts.ready', 0)->where('counterParcels.data.0.guidance.can_act', false)
            ->where('counterParcels.data.0.guidance.blocker', 'The original parcel must remain in its actual staged destination-hub custody.'));
        $this->postJson('/hub/release', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
            'claim_code' => $code, 'buyer_id' => $parcel->order->buyer_id, 'recipient_name' => $parcel->order->buyer->name,
            'identity_confirmed' => true, 'request_token' => (string) Str::uuid(), 'cash_received' => (string) $parcel->order->total_amount,
            'change_given' => '0', 'cash_confirmed' => true])->assertStatus(409);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_legacy_routing_shelf_placeholders_are_read_without_presenting_or_rewriting_a_location(): void
    {
        $parcel = $this->selfPickup(true);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $parcel->update(['destination_bin' => 'STAGE: SELF-PICKUP-SHELF']);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->flowHandler($hub))->get('/hub/counter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterParcels.data.0.destination_bin', null)->where('counterCounts.ready', 1));
        $this->get('/hub/scan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterPickups.0.destination_bin', null)->where('recentScans.0.destination_bin', null)->where('stats.ready_pickup', 1));
        $this->get('/hub/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page->where('deliveries.data.0.destination_bin', null));
        $this->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertOk()
            ->assertJsonPath('delivery.destination_bin', null)->assertJsonPath('delivery.guidance.ready_for_collection', true);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    #[DataProvider('portals')]
    public function test_counter_phone_search_remains_within_the_actual_destination_facility(string $base): void
    {
        $parcel = $this->selfPickup(true);
        $other = $this->selfPickup(true, $parcel->order->buyer);
        $phone = '09170000001';
        $parcel->order->buyer->update(['phone' => $phone]);
        // An explicit stale legacy location must not join the matching counter queue.
        $other->update(['current_hub_id' => $other->origin_bayan_hub_id]);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->flowHandler($hub))->get($base.'/counter?hub_id='.$hub->id.'&search='.$phone)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('search', $phone)->where('counterParcels.total', 1)->has('counterParcels.data', 1)
            ->where('counterParcels.data.0.id', $parcel->id)->where('counterParcels.data.0.buyer_phone', '+639170000001')
            ->where('counterCounts.ready', 1));
        $this->get($base.'/counter?hub_id='.$hub->id.'&search=09170000009')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counterParcels.total', 0)->has('counterParcels.data', 0));
        $this->get($base.'/counter?hub_id='.$other->origin_bayan_hub_id.'&search='.$phone)->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    private function selfPickup(bool $stage, ?User $buyer = null): Delivery
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $hub = LogisticsHub::where('city_municipality', 'Santa Cruz')->where('tier', 'local_bayan_hub')->sole();
        $hub->update(['allows_self_pickup' => true, 'operating_hours' => 'Monday to Saturday, 08:00 to 17:00']);
        $order = $this->checkoutFlowOrder($buyer ?? $this->createApprovedUser('buyer', ['name' => 'Mara Santos']), $shop, shipping: ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id]);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        if ($stage) {
            $this->confirmFlowScan($parcel, $hub, 'STAGE_FOR_PICKUP');
        }

        return $parcel->fresh();
    }

    private function snapshot(Delivery $parcel): array
    {
        return ['parcel' => $parcel->fresh()->getRawOriginal(), 'order' => $parcel->order->fresh()->getRawOriginal(),
            'sources' => collect(['delivery_checkpoints', 'pickup_claims', 'pickup_claim_events', 'cod_custody_entries', 'delivery_return_events', 'parcel_recovery_events'])
                ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all()];
    }
}
