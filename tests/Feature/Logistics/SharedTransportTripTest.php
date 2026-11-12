<?php

namespace Tests\Feature\Logistics;

use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsTransportTrip;
use App\Services\Logistics\LogisticsManifestService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class SharedTransportTripTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function cargo(bool $includeRts = false): array
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $this->measureReturn($case);
        $reverse = $this->prepareLeg($case);
        $source = $reverse->sourceHub;
        $handler = $this->flowHandler($source);
        $service = app(LogisticsManifestService::class);
        $service->command($reverse, $handler, 'load', $this->flowManifestPayload($reverse, ['barcode' => $case->parcel->barcode]));
        $service->command($reverse->fresh(), $handler, 'seal', $this->flowManifestPayload($reverse));
        $shop = $this->createE2EShop($this->createApprovedUser('seller'), ['city' => $source->city_municipality, 'province' => $source->province]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'ready_for_pickup', ['shipping_city' => $case->order->shop->city]);
        $forward = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $token = (string) Str::uuid();
        $outbound = $service->create($handler, ['source_hub_id' => $source->id, 'destination_hub_id' => $reverse->destination_hub_id,
            'vehicle_id' => $reverse->vehicle_id, 'creation_token' => $token, 'direction' => 'outbound'], $token);
        $service->command($outbound, $handler, 'load', $this->flowManifestPayload($outbound, ['barcode' => $forward->tracking_number,
            'cargo_mode' => 'loose', 'handling' => ['weight_kg' => 16, 'length_cm' => 70, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true]]));
        $service->command($outbound->fresh(), $handler, 'seal', $this->flowManifestPayload($outbound));

        $rts = null;
        $returning = null;
        if ($includeRts) {
            $returning = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
            $this->reportFlowFailure($returning, 'Customer refused');
            $this->receiveFailureFlow($returning);
            $token = (string) Str::uuid();
            $rts = $service->create($handler, ['source_hub_id' => $source->id, 'destination_hub_id' => $reverse->destination_hub_id,
                'vehicle_id' => $reverse->vehicle_id, 'creation_token' => $token, 'direction' => 'return'], $token);
            $service->command($rts, $handler, 'load', $this->flowManifestPayload($rts, ['barcode' => $returning->tracking_number,
                'cargo_mode' => 'loose', 'handling' => ['weight_kg' => 16, 'length_cm' => 70, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true]]));
            $service->command($rts->fresh(), $handler, 'seal', $this->flowManifestPayload($rts));
        }

        return [$reverse->fresh(), $outbound->fresh(), $case, $forward, $handler, $rts?->fresh(), $returning?->fresh()];
    }

    private function grouping(LogisticsManifest $a, LogisticsManifest $b): array
    {
        return ['manifest_ids' => [$a->id, $b->id], 'request_token' => (string) Str::uuid()];
    }

    private function tripPayload(LogisticsTransportTrip $trip): array
    {
        return ['version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid()];
    }

    public function test_a_shared_trip_keeps_separate_custody_and_partial_arrival_without_changing_money(): void
    {
        [$reverse, $outbound, $case, $forward, $handler, $rts, $returning] = $this->cargo(true);
        $original = [$case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes()];
        $before = [$case->parcel->fresh()->getAttributes(), $forward->fresh()->getAttributes()];
        $input = $this->grouping($reverse, $outbound);
        $input['manifest_ids'][] = $rts->id;
        $response = $this->actingAs($handler)->postJson('/hub/manifests/trips', $input)->assertCreated();
        $trip = LogisticsTransportTrip::findOrFail($response->json('trip.id'));
        $this->postJson('/hub/manifests/trips', $input)->assertOk()->assertJsonPath('trip.id', $trip->id);
        $this->assertSame($before, [$case->parcel->fresh()->getAttributes(), $forward->fresh()->getAttributes()]);
        $this->get('/hub/manifests/'.$reverse->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sharedTrip.id', $trip->id)->has('sharedTrip.manifests', 3));
        $this->postJson('/hub/manifests/'.$reverse->id.'/dispatch', $this->flowManifestPayload($reverse))->assertConflict();
        $this->postJson('/hub/manifests/'.$outbound->id.'/reopen', $this->flowManifestPayload($outbound, ['notes' => 'Change the grouped load.']))->assertConflict();
        $payload = $this->tripPayload($trip);
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/dispatch', $payload)->assertOk();
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/dispatch', $payload)->assertOk();
        $this->assertSame('in_transit', $case->parcel->fresh()->status);
        $this->assertSame('in_transit_to_mother_hub', $forward->fresh()->status);
        $this->assertSame('buyer_return', $reverse->fresh()->direction);
        $this->assertSame('outbound', $outbound->fresh()->direction);
        $this->assertSame('return_in_transit', $returning->fresh()->status);
        $this->assertSame('delivery_failed', $returning->order->fresh()->status);
        $receiver = $this->flowHandler($reverse->destinationHub);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$reverse->id.'/receive', $this->flowManifestPayload($reverse, ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->assertSame('dispatched', $outbound->fresh()->status);
        $this->assertSame('in_transit_to_mother_hub', $forward->fresh()->status);
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/close', $this->tripPayload($trip))->assertConflict();
        $this->postJson('/hub/manifests/'.$outbound->id.'/receive', $this->flowManifestPayload($outbound, ['barcode' => $forward->tracking_number]))->assertOk();
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/close', $this->tripPayload($trip))->assertConflict();
        $this->postJson('/hub/manifests/'.$rts->id.'/receive', $this->flowManifestPayload($rts, ['barcode' => $returning->tracking_number]))->assertOk();
        $this->assertSame('return_to_sender', $returning->fresh()->status);
        $this->assertSame('delivery_failed', $returning->order->fresh()->status);
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/close', $this->tripPayload($trip))->assertOk();
        $this->assertSame('closed', $trip->fresh()->status);
        $this->get('/hub/manifests/'.$reverse->id)->assertInertia(fn (AssertableInertia $page) => $page->where('sharedTrip.status', 'closed'));
        $this->assertSame($original, [$case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes()]);
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
        $this->assertSame(0, DB::table('logistics_trip_manifests')->whereNotNull('active_manifest_id')->count());
    }

    public function test_combined_capacity_is_rechecked_at_departure_without_partial_dispatch(): void
    {
        [$reverse, $outbound, $case, $forward, $handler] = $this->cargo();
        $trip = LogisticsTransportTrip::findOrFail($this->actingAs($handler)->postJson('/hub/manifests/trips', $this->grouping($reverse, $outbound))->assertCreated()->json('trip.id'));
        $reverse->vehicle->update(['capacity_kg' => 18]);
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/dispatch', $this->tripPayload($trip))->assertConflict();
        $this->assertSame('sealed', $reverse->fresh()->status);
        $this->assertSame('sealed', $outbound->fresh()->status);
        $this->assertSame('at_hub', $case->parcel->fresh()->status);
        $this->assertSame('arrived_at_origin_hub', $forward->fresh()->status);
        $this->assertSame('ready', $trip->fresh()->status);
    }

    public function test_cancel_only_releases_an_unstarted_grouping_and_replay_cannot_group_a_new_set(): void
    {
        [$reverse, $outbound, $case, $forward, $handler] = $this->cargo();
        $input = $this->grouping($reverse, $outbound);
        $trip = LogisticsTransportTrip::findOrFail($this->actingAs($handler)->postJson('/hub/manifests/trips', $input)->assertCreated()->json('trip.id'));
        $this->postJson('/hub/manifests/trips', $this->grouping($reverse, $outbound))->assertConflict();
        $this->postJson('/hub/manifests/trips', [...$input, 'manifest_ids' => [$outbound->id, $outbound->id]])->assertUnprocessable();
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/cancel', $this->tripPayload($trip))->assertOk();
        $this->postJson('/hub/manifests/'.$outbound->id.'/reopen', $this->flowManifestPayload($outbound, ['notes' => 'Recheck the actual undeparted parcel list.']))->assertOk();
        $this->assertSame('draft', $outbound->fresh()->status);
        $this->assertSame('sealed', $reverse->fresh()->status);
        $this->assertSame('at_hub', $case->parcel->fresh()->status);
    }

    public function test_foreign_or_buyer_accounts_cannot_group_or_depart_transport(): void
    {
        [$reverse, $outbound, $case, $forward, $handler] = $this->cargo();
        $input = $this->grouping($reverse, $outbound);
        $this->actingAs($this->createApprovedUser('buyer'))->postJson('/hub/manifests/trips', $input)->assertForbidden();
        $this->actingAs($this->createApprovedUser('logistics'))->postJson('/hub/manifests/trips', $input)->assertForbidden();
        $this->assertDatabaseCount('logistics_transport_trips', 0);
        $trip = LogisticsTransportTrip::findOrFail($this->actingAs($handler)->postJson('/hub/manifests/trips', $input)->assertCreated()->json('trip.id'));
        $this->actingAs($this->createApprovedUser('logistics'))->postJson('/hub/manifests/trips/'.$trip->id.'/dispatch', $this->tripPayload($trip))->assertForbidden();
        $this->assertSame('ready', $trip->fresh()->status);
    }

    public function test_a_failed_second_departure_rolls_back_the_first_parcels_and_both_manifest_histories(): void
    {
        [$reverse, $outbound, $case, $forward, $handler] = $this->cargo();
        $trip = LogisticsTransportTrip::findOrFail($this->actingAs($handler)->postJson('/hub/manifests/trips', $this->grouping($reverse, $outbound))->assertCreated()->json('trip.id'));
        $before = [$case->parcel->fresh()->getAttributes(), $forward->fresh()->getAttributes(), LogisticsManifestEvent::count()];
        Event::listen('eloquent.creating: '.LogisticsManifestEvent::class, function (LogisticsManifestEvent $event) use ($outbound) {
            if ($event->manifest_id === $outbound->id && $event->event_type === 'parcel_dispatched') {
                throw new \RuntimeException('Simulated receipt-store failure.');
            }
        });
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/dispatch', $this->tripPayload($trip))->assertStatus(500);
        $this->assertSame($before, [$case->parcel->fresh()->getAttributes(), $forward->fresh()->getAttributes(), LogisticsManifestEvent::count()]);
        $this->assertSame('sealed', $reverse->fresh()->status);
        $this->assertSame('sealed', $outbound->fresh()->status);
        $this->assertSame('ready', $trip->fresh()->status);
    }

    public function test_a_different_next_leg_is_rejected_and_stale_departure_keeps_both_manifests_sealed(): void
    {
        [$reverse, $outbound, $case, $forward, $handler] = $this->cargo();
        $other = LogisticsManifest::where('logistics_company_id', $reverse->logistics_company_id)->where('destination_hub_id', '!=', $reverse->destination_hub_id)->firstOrFail();
        $this->actingAs($reverse->sourceHub->company->user)->postJson('/hub/manifests/trips', $this->grouping($reverse, $other))->assertConflict();
        $this->assertDatabaseCount('logistics_transport_trips', 0);
        $trip = LogisticsTransportTrip::findOrFail($this->actingAs($handler)->postJson('/hub/manifests/trips', $this->grouping($reverse, $outbound))->assertCreated()->json('trip.id'));
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/dispatch', [...$this->tripPayload($trip), 'version' => 2])->assertConflict();
        $this->assertSame('sealed', $reverse->fresh()->status);
        $this->assertSame('sealed', $outbound->fresh()->status);
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/dispatch', $this->tripPayload($trip))->assertOk();
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/cancel', $this->tripPayload($trip))->assertConflict();
    }

    public function test_grouping_and_departure_are_available_on_the_hub_subdomain_with_recoverable_web_errors(): void
    {
        [$reverse, $outbound, $case, $forward, $handler] = $this->cargo();
        $this->actingAs($handler)->get('http://hub.localhost/manifests/'.$reverse->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tripCandidates', 2)->where('basePath', '/manifests'));
        $trip = LogisticsTransportTrip::findOrFail($this->postJson('http://hub.localhost/manifests/trips', $this->grouping($reverse, $outbound))->assertCreated()->json('trip.id'));
        $reverse->vehicle->update(['capacity_kg' => 18]);
        $this->from('http://hub.localhost/manifests/'.$reverse->id)->post('http://hub.localhost/manifests/trips/'.$trip->id.'/dispatch', $this->tripPayload($trip))
            ->assertRedirect('http://hub.localhost/manifests/'.$reverse->id)->assertSessionHasErrors('manifest');
        $this->assertSame('ready', $trip->fresh()->status);
    }

    public function test_trip_event_and_original_membership_cannot_be_rewritten_even_through_raw_queries(): void
    {
        [$reverse, $outbound, $case, $forward, $handler] = $this->cargo();
        $trip = LogisticsTransportTrip::findOrFail($this->actingAs($handler)->postJson('/hub/manifests/trips', $this->grouping($reverse, $outbound))->assertCreated()->json('trip.id'));
        foreach ([fn () => DB::table('logistics_trip_events')->where('trip_id', $trip->id)->update(['event_type' => 'dispatch']),
            fn () => DB::table('logistics_trip_events')->where('trip_id', $trip->id)->delete(),
            fn () => DB::table('logistics_trip_manifests')->where('trip_id', $trip->id)->update(['source_snapshot' => '{}']),
            fn () => DB::table('logistics_trip_manifests')->where('trip_id', $trip->id)->delete()] as $mutate) {
            try {
                $mutate();
                $this->fail('Original trip evidence was changed.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Original trip evidence must be retained', $error->getMessage());
            }
        }
        $this->assertSame('ready', $trip->fresh()->status);
    }
}
