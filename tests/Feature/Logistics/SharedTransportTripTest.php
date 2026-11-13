<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsTransportTrip;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsTripService;
use App\Services\Logistics\MotherBagService;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Returns\BuyerReturnBagService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private function cargo(bool $includeRts = false, bool $baggedForward = false): array
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
        $forwardBag = null;
        if ($baggedForward) {
            $bags = app(MotherBagService::class);
            $forwardBag = $bags->create($handler, ['source_hub_id' => $source->id, 'destination_hub_id' => $reverse->destination_hub_id,
                'creation_token' => (string) Str::uuid(), 'physical_tag' => 'SHARED-FORWARD-'.$outbound->id, 'physical_checked' => true]);
            $forwardBag = $bags->command($forwardBag, $handler, 'add', ['version' => $forwardBag->version, 'barcode' => $forward->tracking_number,
                'handling' => ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true]]);
            $forwardBag = $bags->command($forwardBag, $handler, 'seal', ['version' => $forwardBag->version, 'seal_number' => 'SHARED-FORWARD-'.$outbound->id]);
            $forwardBag = $bags->command($forwardBag, $handler, 'load', ['version' => $forwardBag->version, 'barcode' => $forwardBag->reference,
                'seal_number' => $forwardBag->seal_number, 'manifest_id' => $outbound->id]);
        } else {
            $service->command($outbound, $handler, 'load', $this->flowManifestPayload($outbound, ['barcode' => $forward->tracking_number,
                'cargo_mode' => 'loose', 'handling' => ['weight_kg' => 16, 'length_cm' => 70, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true]]));
        }
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

        return [$reverse->fresh(), $outbound->fresh(), $case, $forward, $handler, $rts?->fresh(), $returning?->fresh(), $forwardBag];
    }

    public function test_a_shared_trip_with_an_actual_recovered_forward_parcel_still_requires_its_original_bag_arrival(): void
    {
        [$reverse, $outbound, $case, $forward, $handler, , , $forwardBag] = $this->cargo(baggedForward: true);
        $physicalKey = $forwardBag->active_physical_key;
        $this->assertNotNull($physicalKey);
        $trips = app(LogisticsTripService::class);
        $trip = $trips->create($handler, $this->grouping($reverse, $outbound));
        $returnBag = $this->sealTripReturn($trip, $handler, $case->parcel->barcode);
        $trips->command($trip, $handler, 'dispatch', $this->tripPayload($trip));
        $receiver = $this->flowHandler($reverse->destinationHub);
        $other = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $observedManifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($other)['manifest_id']);
        $this->assertSame($reverse->destination_hub_id, $observedManifest->destination_hub_id);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$observedManifest->id.'/report-discrepancy',
            $this->flowManifestPayload($observedManifest, ['kind' => 'extra', 'barcode' => $forward->tracking_number,
                'notes' => 'The actual separate forward parcel arrived loose with this other transport; its original sealed bag is not here.']))->assertOk();
        $observation = $observedManifest->events()->where('event_type', 'report-discrepancy')->sole();
        $manager = $reverse->company->user;
        $recoveries = app(ParcelRecoveryService::class);
        $proposal = $recoveries->proposal($manager, $observation);
        $recovery = $recoveries->authorize($manager, $observation, ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(),
            'notes' => 'Review the actual unexpected loose parcel and its original expected receiving hub.']);
        $recoveries->intake($receiver, $recovery, ['version' => $recovery->version, 'request_token' => (string) Str::uuid(),
            'barcode' => $forward->tracking_number, 'notes' => 'The assigned receiving handler physically holds this separate original forward parcel.']);
        $this->assertSame('completed', $recovery->fresh()->state);
        $this->openTripReturn($trip, $returnBag, $receiver);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$reverse->id.'/receive', $this->flowManifestPayload($reverse,
            ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->assertSame('received', $outbound->fresh()->status);
        $this->assertSame('received', $reverse->fresh()->status);
        $this->assertSame('in_transit', $forwardBag->fresh()->status);
        $this->assertSame($physicalKey, $forwardBag->fresh()->active_physical_key);
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/close', $this->tripPayload($trip))->assertConflict()
            ->assertJsonPath('message', 'Record the actual arrival and member accountability of each original bag before closing the trip.');
        $this->assertSame('dispatched', $trip->fresh()->status);
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($outbound->id)->exists());
        $this->postJson('/hub/mother-bags/'.$forwardBag->id.'/open', ['version' => $forwardBag->fresh()->version,
            'barcode' => $forwardBag->reference, 'seal_number' => $forwardBag->seal_number, 'seal_intact' => true])->assertOk();
        $this->assertSame('received', $forwardBag->fresh()->status);
        $this->assertNull($forwardBag->fresh()->active_physical_key);
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/close', $this->tripPayload($trip))->assertOk();
        $this->assertSame('closed', $trip->fresh()->status);
        $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($outbound->id)->exists());
        $reused = app(MotherBagService::class)->create($receiver, ['source_hub_id' => $reverse->destination_hub_id,
            'destination_hub_id' => $reverse->source_hub_id, 'creation_token' => (string) Str::uuid(),
            'physical_tag' => $forwardBag->physical_tag, 'physical_checked' => true]);
        $this->assertSame($physicalKey, $reused->active_physical_key);
        $this->assertNotSame($forwardBag->id, $reused->id);
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
        $bag = $this->sealTripReturn($trip, $handler, $case->parcel->barcode);
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
        $this->openTripReturn($trip, $bag, $receiver);
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

    public function test_co_loaded_returns_split_at_the_common_mother_hub_and_reach_only_their_original_sellers(): void
    {
        $cases = [];
        $originals = [];
        $manifests = [];
        $service = app(LogisticsManifestService::class);
        foreach (['Calamba City', 'Los Baños'] as $index => $city) {
            $shop = $this->createE2EShop($this->createApprovedUser('seller'), ['city' => $city, 'pickup_province' => 'Laguna']);
            $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'ready_for_pickup');
            $case = $this->approvedReturn($order, $this->createApprovedUser('courier'));
            $this->assertCount(3, $case->parcel->hub_ids);
            $originals[] = [$case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes()];
            $this->intake($case);
            $this->measureReturn($case);
            if ($index === 0) {
                $manifest = $this->prepareLeg($case);
                $handler = $this->flowHandler($manifest->sourceHub);
            } else {
                $first = $manifests[0];
                $token = (string) Str::uuid();
                $manifest = $service->create($handler, ['source_hub_id' => $first->source_hub_id, 'destination_hub_id' => $first->destination_hub_id,
                    'vehicle_id' => $first->vehicle_id, 'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id, 'creation_token' => $token], $token);
            }
            foreach (['load', 'seal'] as $action) {
                $service->command($manifest->fresh(), $handler, $action,
                    $this->flowManifestPayload($manifest, $action === 'load' ? ['barcode' => $case->parcel->barcode] : []));
            }
            $cases[] = $case;
            $manifests[] = $manifest->fresh();
        }
        $this->assertSame(array_slice($cases[0]->parcel->hub_ids, 0, 2), array_slice($cases[1]->parcel->hub_ids, 0, 2));
        $this->assertNotSame($cases[0]->parcel->hub_ids[2], $cases[1]->parcel->hub_ids[2]);
        $trip = app(LogisticsTripService::class)->create($handler, $this->grouping(...$manifests));
        $bags = app(BuyerReturnBagService::class);
        foreach ($cases as $case) {
            $bag = $bags->pack($trip, $handler, ['version' => 1, 'request_token' => (string) Str::uuid(), 'barcode' => $case->parcel->barcode,
                'physical_tag' => 'COMMON-LEG-RETURN-SACK', 'physical_checked' => true]);
        }
        $bag = $bags->command($trip, $bag, $handler, 'seal', ['version' => 1, 'bag_version' => $bag->version,
            'request_token' => (string) Str::uuid(), 'barcode' => $bag->reference, 'seal_number' => 'COMMON-LEG-RETURN-SEAL']);
        app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', $this->tripPayload($trip));
        $mother = $manifests[0]->destinationHub;
        $this->assertTrue($mother->isMotherHub());
        $receiver = $this->flowHandler($mother);
        $this->openTripReturn($trip, $bag, $receiver);
        foreach ($manifests as $index => $manifest) {
            $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive',
                $this->flowManifestPayload($manifest, ['barcode' => $cases[$index]->parcel->barcode]))->assertOk();
            $this->assertSame($mother->id, $cases[$index]->parcel->fresh()->current_hub_id);
        }
        app(LogisticsTripService::class)->command($trip->fresh(), $receiver, 'close', $this->tripPayload($trip));

        $branches = [];
        foreach ($cases as $index => $case) {
            $this->measureReturn($case);
            if ($index === 0) {
                $branch = $this->prepareLeg($case);
                $token = (string) Str::uuid();
                $count = LogisticsManifest::count();
                try {
                    $service->create($receiver, ['source_hub_id' => $mother->id, 'destination_hub_id' => $cases[1]->parcel->hub_ids[2],
                        'vehicle_id' => $branch->vehicle_id, 'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id, 'creation_token' => $token], $token);
                    $this->fail('A common Mother Hub cannot send a return to the other seller’s original child hub.');
                } catch (\DomainException $error) {
                    $this->assertStringContainsString('next original reverse leg', $error->getMessage());
                }
                $this->assertSame($count, LogisticsManifest::count());
                $this->actingAs($receiver)->postJson('/hub/manifests/'.$branch->id.'/load',
                    $this->flowManifestPayload($branch, ['barcode' => $cases[1]->parcel->barcode]))->assertUnprocessable();
                $this->assertSame(0, $branch->buyerReturnParcels()->count());
            } else {
                $token = (string) Str::uuid();
                $branch = $service->create($receiver, ['source_hub_id' => $mother->id, 'destination_hub_id' => $case->parcel->hub_ids[2],
                    'vehicle_id' => $branches[0]->vehicle_id, 'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id, 'creation_token' => $token], $token);
            }
            foreach (['load', 'seal'] as $action) {
                $service->command($branch->fresh(), $receiver, $action,
                    $this->flowManifestPayload($branch, $action === 'load' ? ['barcode' => $case->parcel->barcode] : []));
            }
            $branches[] = $branch->fresh();
        }
        $this->actingAs($receiver)->postJson('/hub/manifests/trips', $this->grouping(...$branches))->assertConflict();
        $this->assertNotSame($branches[0]->destination_hub_id, $branches[1]->destination_hub_id);
        $this->assertSame($branches[0]->vehicle_id, $branches[1]->vehicle_id);
        $this->assertSame('sealed', $branches[0]->fresh()->status);
        $this->assertSame('sealed', $branches[1]->fresh()->status);

        foreach ($branches as $index => $branch) {
            $case = $cases[$index];
            $other = $cases[1 - $index];
            $branchTrip = app(LogisticsTripService::class)->create($receiver, ['manifest_ids' => [$branch->id], 'request_token' => (string) Str::uuid()]);
            $branchBag = $this->sealTripReturn($branchTrip, $receiver, $case->parcel->barcode);
            app(LogisticsTripService::class)->command($branchTrip, $receiver, 'dispatch', $this->tripPayload($branchTrip));
            $origin = LogisticsHub::findOrFail($branch->destination_hub_id);
            $originHandler = $this->flowHandler($origin);
            $this->openTripReturn($branchTrip, $branchBag, $originHandler);
            $this->actingAs($originHandler)->postJson('/hub/manifests/'.$branch->id.'/receive',
                $this->flowManifestPayload($branch, ['barcode' => $case->parcel->barcode]))->assertOk();
            app(LogisticsTripService::class)->command($branchTrip->fresh(), $originHandler, 'close', $this->tripPayload($branchTrip));
            $this->assertSame($origin->id, $case->parcel->fresh()->current_hub_id);
            $this->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $origin->id]))
                ->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();
            $receipt = $this->custodyPayload($case, 'seller-receive', ['condition' => 'accepted', 'photo' => UploadedFile::fake()->image('branched-return.png')]);
            $before = [$case->fresh()->getAttributes(), $case->parcel->fresh()->getAttributes(), $case->events()->count()];
            $this->actingAs($other->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $receipt)
                ->assertRedirect()->assertSessionHasErrors('custody');
            $this->assertSame($before, [$case->fresh()->getAttributes(), $case->parcel->fresh()->getAttributes(), $case->events()->count()]);
            $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $receipt)
                ->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();
            $this->assertSame('seller_received', $case->fresh()->status);
            $this->assertSame(['kind' => 'seller', 'user_id' => $case->seller_id], $case->parcel->fresh()->custody);
            $this->assertSame($originals[$index], [$case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes()]);
        }
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
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
        $bag = $this->sealTripReturn($trip, $handler, $case->parcel->barcode);
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
        $this->assertSame('sealed', $bag->fresh()->status);
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
        $this->sealTripReturn($trip, $handler, $case->parcel->barcode);
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
