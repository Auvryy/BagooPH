<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnEvent;
use App\Models\LogisticsHub;
use App\Models\LogisticsTransportTrip;
use App\Models\MotherBag;
use App\Models\MotherBagReturnParcel;
use App\Services\Logistics\CargoHandlingService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsTripService;
use App\Services\Logistics\MotherBagService;
use App\Services\Returns\BuyerReturnBagService;
use App\Services\Returns\BuyerReturnCargoService;
use App\Services\Returns\BuyerReturnCustodyService;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class BuyerReturnBagTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function trip(array $readings = []): array
    {
        $cases = [];
        $manifests = [];
        $service = app(LogisticsManifestService::class);
        foreach ([0, 1] as $index) {
            $case = $this->approvedReturn();
            $this->intake($case);
            $this->measureReturn($case, $readings[$index] ?? []);
            if ($index === 0) {
                $manifest = $this->prepareLeg($case);
                $handler = $this->flowHandler($manifest->sourceHub);
            } else {
                $first = $manifests[0];
                $token = (string) Str::uuid();
                $manifest = $service->create($handler, ['source_hub_id' => $first->source_hub_id, 'destination_hub_id' => $first->destination_hub_id,
                    'vehicle_id' => $first->vehicle_id, 'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id, 'creation_token' => $token], $token);
            }
            $service->command($manifest, $handler, 'load', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]));
            $service->command($manifest->fresh(), $handler, 'seal', $this->flowManifestPayload($manifest));
            $cases[] = $case;
            $manifests[] = $manifest->fresh();
        }
        $trip = app(LogisticsTripService::class)->create($handler, ['manifest_ids' => array_map(fn ($manifest) => $manifest->id, $manifests), 'request_token' => (string) Str::uuid()]);

        return [$trip, $cases, $manifests, $handler];
    }

    private function packInput(LogisticsTransportTrip $trip, string $barcode): array
    {
        return ['version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid(), 'barcode' => $barcode,
            'physical_tag' => 'RETURN-SACK-01', 'physical_checked' => true];
    }

    private function bagInput(LogisticsTransportTrip $trip, MotherBag $bag, array $extra = []): array
    {
        return ['version' => $trip->fresh()->version, 'bag_version' => $bag->fresh()->version, 'request_token' => (string) Str::uuid(),
            'barcode' => $bag->reference, ...$extra];
    }

    public function test_two_return_cases_share_one_sealed_sack_and_keep_individual_receipts(): void
    {
        [$trip, $cases, $manifests, $handler] = $this->trip();
        $service = app(BuyerReturnBagService::class);
        $before = array_map(fn ($case) => [$case->parcel->fresh()->getAttributes(), $case->order->fresh()->getAttributes()], $cases);
        $input = $this->packInput($trip, $cases[0]->parcel->barcode);
        $bag = $service->pack($trip, $handler, $input);
        $this->assertSame($bag->id, $service->pack($trip, $handler, $input)->id);
        $this->rejected(fn () => $service->pack($trip, $handler, [...$input, 'barcode' => $cases[1]->parcel->barcode]), 'different details');
        $this->assertSame($bag->id, $service->pack($trip, $handler, $this->packInput($trip, $cases[1]->parcel->barcode))->id);
        $this->assertSame(2, $bag->returnParcels()->count());
        $this->assertSame($before, array_map(fn ($case) => [$case->parcel->fresh()->getAttributes(), $case->order->fresh()->getAttributes()], $cases));
        $bag = $service->command($trip, $bag, $handler, 'seal', $this->bagInput($trip, $bag, ['seal_number' => 'RETURN-SEAL-01']));
        $this->assertSame('sealed', $bag->status);
        app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        $this->assertSame('in_transit', $bag->fresh()->status);
        $receiver = $this->flowHandler($manifests[0]->destinationHub);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifests[0]->id.'/receive',
            $this->flowManifestPayload($manifests[0], ['barcode' => $cases[0]->parcel->barcode]))->assertConflict();
        $bag = $service->command($trip->fresh(), $bag, $receiver, 'open', $this->bagInput($trip, $bag, ['seal_number' => 'RETURN-SEAL-01', 'seal_intact' => true]));
        $this->assertSame('opened', $bag->status);
        foreach ($manifests as $index => $manifest) {
            $this->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $cases[$index]->parcel->barcode]))->assertOk();
            $this->assertSame($index === 0 ? 'opened' : 'received', $bag->fresh()->status);
            $this->assertSame(1 - $index, MotherBagReturnParcel::whereNotNull('active_return_parcel_id')->count());
        }
        $this->assertNull($bag->fresh()->active_physical_key);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
        $this->assertSame('closed', app(LogisticsTripService::class)->command($trip->fresh(), $receiver, 'close', ['version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid()])->status);
    }

    private function rejected(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('The invalid return-sack operation succeeded.');
        } catch (\DomainException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    public function test_actual_weight_and_volume_cannot_overfill_a_sack_or_leave_partial_membership(): void
    {
        foreach ([['weight_kg' => '13'], ['length_cm' => '60', 'width_cm' => '60', 'height_cm' => '60']] as $reading) {
            [$trip, $cases, $manifests, $handler] = $this->trip([$reading, $reading]);
            $service = app(BuyerReturnBagService::class);
            $first = $this->packInput($trip, $cases[0]->parcel->barcode);
            $first['physical_tag'] = 'CAPACITY-SACK-'.$trip->id;
            $bag = $service->pack($trip, $handler, $first);
            $before = $bag->fresh()->getAttributes();
            $second = $this->packInput($trip, $cases[1]->parcel->barcode);
            $second['physical_tag'] = $first['physical_tag'];
            $this->rejected(fn () => $service->pack($trip, $handler, $second), 'would exceed');
            $this->assertSame($before, $bag->fresh()->getAttributes());
            $this->assertSame(1, $bag->returnParcels()->count());
            $this->assertSame('at_hub', $cases[1]->parcel->fresh()->status);
        }
    }

    public function test_exact_weight_and_side_limits_fit_but_oversized_returns_stay_individual(): void
    {
        [$trip, $cases, $manifests, $handler] = $this->trip([['weight_kg' => '15', 'length_cm' => '60'], ['weight_kg' => '10']]);
        $service = app(BuyerReturnBagService::class);
        $bag = $service->pack($trip, $handler, $this->packInput($trip, $cases[0]->parcel->barcode));
        $service->pack($trip, $handler, $this->packInput($trip, $cases[1]->parcel->barcode));
        $bag = $service->command($trip, $bag, $handler, 'seal', $this->bagInput($trip, $bag, ['seal_number' => 'EXACT-25-KG']));
        $this->assertSame('sealed', $bag->status);
        $this->assertSame(25.0, app(CargoHandlingService::class)->capacity($bag)['weight_kg']);
    }

    public function test_bulky_returns_share_transport_without_being_hidden_inside_a_sack(): void
    {
        [$trip, $cases, $manifests, $handler] = $this->trip([['length_cm' => '60.001']]);
        $service = app(BuyerReturnBagService::class);
        $this->rejected(fn () => $service->pack($trip, $handler, $this->packInput($trip, $cases[0]->parcel->barcode)), 'loose cargo');
        $this->assertSame(0, MotherBagReturnParcel::where('buyer_return_parcel_id', $cases[0]->parcel->id)->count());
        $bag = $this->sealTripReturn($trip, $handler, $cases[1]->parcel->barcode);
        app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        $receiver = $this->flowHandler($manifests[0]->destinationHub);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifests[0]->id.'/receive', $this->flowManifestPayload($manifests[0], ['barcode' => $cases[0]->parcel->barcode]))->assertOk();
        $this->assertSame('in_transit', $bag->fresh()->status);
        $this->assertSame('in_transit', $cases[1]->parcel->fresh()->status);
    }

    public function test_cancel_requires_actual_unpacking_and_the_empty_sack_can_be_reused_at_its_real_source(): void
    {
        [$trip, $cases, $manifests, $handler] = $this->trip();
        $service = app(BuyerReturnBagService::class);
        $bag = $this->sealTripReturn($trip, $handler, $cases[0]->parcel->barcode);
        $this->rejected(fn () => app(LogisticsTripService::class)->command($trip, $handler, 'cancel', ['version' => 1, 'request_token' => (string) Str::uuid()]), 'Unpack');
        $this->assertSame('sealed', $bag->fresh()->status);
        $bag = $service->command($trip, $bag, $handler, 'reopen', $this->bagInput($trip, $bag, ['notes' => 'The actual unstarted sack needs a corrected physical load.']));
        $bag = $service->command($trip, $bag, $handler, 'remove', $this->bagInput($trip, $bag, ['return_barcode' => $cases[0]->parcel->barcode, 'notes' => 'The original return remains at its sending hub for rechecking.']));
        $this->assertSame('emptied_at_source', $bag->status);
        $this->assertNull($bag->active_physical_key);
        $this->assertSame('cancelled', app(LogisticsTripService::class)->command($trip, $handler, 'cancel', ['version' => 1, 'request_token' => (string) Str::uuid()])->status);
        $new = app(MotherBagService::class)->create($handler, ['source_hub_id' => $trip->source_hub_id, 'destination_hub_id' => $trip->destination_hub_id,
            'physical_tag' => $bag->physical_tag, 'physical_checked' => true, 'creation_token' => (string) Str::uuid()]);
        $this->assertNotSame($new->id, $bag->id);
        $this->assertSame('at_hub', $cases[0]->parcel->fresh()->status);
        $this->assertSame('sealed', $manifests[0]->fresh()->status);
    }

    public function test_a_seal_cannot_be_reused_and_raw_queries_cannot_change_its_original_members(): void
    {
        [$trip, $cases, $manifests, $handler] = $this->trip();
        $bag = $this->sealTripReturn($trip, $handler, $cases[0]->parcel->barcode);
        $service = app(BuyerReturnBagService::class);
        $original = $bag->returnParcels()->sole();
        foreach ([fn () => DB::table('mother_bag_return_parcels')->where('id', $original->id)->update(['handling_snapshot' => '{}']),
            fn () => DB::table('mother_bag_return_parcels')->where('id', $original->id)->delete(),
            fn () => DB::table('mother_bag_return_parcels')->where('id', $original->id)->update(['active_return_parcel_id' => null, 'removed_at' => now()])] as $mutate) {
            try {
                $mutate();
                $this->fail('Sealed return membership changed.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Retain actual return sack membership.', $error->getMessage());
            }
        }
        $seal = $bag->seal_number;
        $bag = $service->command($trip, $bag, $handler, 'reopen', $this->bagInput($trip, $bag, ['notes' => 'The checked unstarted sack needs a new numbered seal.']));
        $this->rejected(fn () => $service->command($trip, $bag, $handler, 'seal', $this->bagInput($trip, $bag, ['seal_number' => $seal])), 'already used');
        $this->assertSame('open', $bag->fresh()->status);
    }

    public function test_only_the_assigned_floor_operator_can_pack_and_failed_seal_checks_do_not_receive_any_return(): void
    {
        [$trip, $cases, $manifests, $handler] = $this->trip();
        $url = '/hub/manifests/trips/'.$trip->id.'/return-sacks';
        foreach ([$this->createApprovedUser('logistics'), $this->createApprovedUser('admin'), $cases[0]->order->buyer, $cases[0]->order->shop->user,
            $this->flowHandler($manifests[0]->destinationHub)] as $foreign) {
            $this->actingAs($foreign)->postJson($url, $this->packInput($trip, $cases[0]->parcel->barcode))->assertForbidden();
        }
        $bag = $this->sealTripReturn($trip, $handler, $cases[0]->parcel->barcode);
        $service = app(BuyerReturnBagService::class);
        $bag = $service->command($trip, $bag, $handler, 'reopen', $this->bagInput($trip, $bag, ['notes' => 'The second checked return needs packing before departure.']));
        $service->pack($trip, $handler, [...$this->packInput($trip, $cases[1]->parcel->barcode), 'physical_tag' => $bag->physical_tag]);
        $bag = $service->command($trip, $bag, $handler, 'seal', $this->bagInput($trip, $bag, ['seal_number' => 'AUTHORITY-NEW-SEAL']));
        app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        $open = '/hub/manifests/trips/'.$trip->id.'/return-sacks/'.$bag->id.'/open';
        $this->actingAs($handler)->postJson($open, $this->bagInput($trip, $bag, ['seal_number' => $bag->seal_number, 'seal_intact' => true]))->assertForbidden();
        $this->actingAs($this->flowHandler($manifests[0]->destinationHub))->postJson($open,
            $this->bagInput($trip, $bag, ['seal_number' => 'WRONG-SEAL', 'seal_intact' => true]))->assertConflict();
        $this->postJson($open, $this->bagInput($trip, $bag, ['seal_number' => $bag->seal_number, 'seal_intact' => false]))->assertUnprocessable();
        $this->assertSame('in_transit', $bag->fresh()->status);
        $this->assertSame(2, $bag->returnParcels()->whereNotNull('active_return_parcel_id')->count());
    }

    public function test_failed_packing_rolls_back_the_sack_tag_membership_and_retry_record(): void
    {
        [$trip, $cases, $manifests, $handler] = $this->trip();
        $service = app(BuyerReturnBagService::class);
        $input = $this->packInput($trip, $cases[0]->parcel->barcode);
        $baseline = [MotherBag::count(), MotherBagReturnParcel::count(), DB::table('bag_packing_requests')->count()];
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "mother_bag_return_parcels"')) {
                $fail = false;
                throw new \RuntimeException('Simulated return-packing persistence failure.');
            }
        });
        try {
            $service->pack($trip, $handler, $input);
            $this->fail('Incomplete packing was committed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated return-packing persistence failure.', $error->getMessage());
        }
        $this->assertSame($baseline, [MotherBag::count(), MotherBagReturnParcel::count(), DB::table('bag_packing_requests')->count()]);
        $this->assertSame('open', $service->pack($trip, $handler, $input)->status);
    }

    public function test_fifty_actual_return_cases_fit_and_the_fifty_first_needs_a_second_sack(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 100, 'price' => '1.00', 'weight_kg' => '0.01', 'length_cm' => 1, 'width_cm' => 1, 'height_cm' => 1]);
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product, 'quantity' => 51]], 'ready_for_pickup');
        $this->flowDelivery($order, 'delivered');
        $returns = app(BuyerReturnService::class);
        $transport = app(LogisticsManifestService::class);
        $cases = [];
        $manifests = [];
        for ($index = 0; $index < 51; $index++) {
            $order = $order->fresh(['buyer', 'items']);
            $case = $returns->request($buyer, $order, ['request_token' => $returns->form($buyer, $order)['requestToken'],
                'reason' => 'damaged', 'description' => 'This individually returned purchased unit has a broken fastening.',
                'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('original-unit.png')]);
            $returns->command($seller, $case, 'approve', ['request_token' => (string) Str::uuid(), 'expected_version' => 1,
                'reason' => 'The reviewed original unit has its own approved return.',
                ...((($order->shipping_quote['refund_policy_version'] ?? 1) === 2) ? ['fault' => 'seller'] : [])]);
            $case = $case->fresh(['parcel', 'order.delivery', 'order.buyer']);
            $source = LogisticsHub::findOrFail($case->parcel->hub_ids[0]);
            $handler = $this->flowHandler($source);
            app(BuyerReturnCustodyService::class)->command($handler, $case, 'intake', $this->custodyPayload($case, 'intake', [
                'hub_id' => $source->id, 'weight_kg' => '0.010', 'condition' => 'intact',
            ]), UploadedFile::fake()->image('individual-intake.png'));
            $this->measureReturn($case, ['weight_kg' => '0.010', 'length_cm' => '1', 'width_cm' => '1', 'height_cm' => '1']);
            $measured = $case->parcel->fresh();
            $reading = app(BuyerReturnCargoService::class)->reading($measured);
            $this->assertNotNull($reading, 'Actual reading for unit '.$index);
            $event = BuyerReturnEvent::findOrFail($reading->buyer_return_event_id);
            $this->assertSame($event->context['handling'], $reading->profile, 'Retained handling for unit '.$index);
            $this->assertSame($measured->weight_grams, $reading->profile['intake_weight_grams'], 'Intake source for unit '.$index);
            app(BuyerReturnCargoService::class)->profile($measured);
            if ($index === 0) {
                $manifest = $this->prepareLeg($case);
                $vehicle = $manifest->vehicle_id;
            } else {
                $token = (string) Str::uuid();
                $manifest = $transport->create($handler, ['source_hub_id' => $source->id, 'destination_hub_id' => $case->parcel->hub_ids[1],
                    'vehicle_id' => $vehicle, 'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id, 'creation_token' => $token], $token);
            }
            $transport->command($manifest, $handler, 'load', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]));
            $transport->command($manifest->fresh(), $handler, 'seal', $this->flowManifestPayload($manifest));
            $manifests[] = $manifest->id;
            $cases[] = $case;
            // Simulate individual operator scans within the existing request-rate boundary.
            $this->travel(3)->seconds();
        }
        $trip = app(LogisticsTripService::class)->create($handler, ['manifest_ids' => $manifests, 'request_token' => (string) Str::uuid()]);
        $service = app(BuyerReturnBagService::class);
        foreach (array_slice($cases, 0, 50) as $case) {
            $bag = $service->pack($trip, $handler, $this->packInput($trip, $case->parcel->barcode));
        }
        $before = $bag->fresh()->getAttributes();
        $this->rejected(fn () => $service->pack($trip, $handler, $this->packInput($trip, $cases[50]->parcel->barcode)), 'would exceed');
        $this->assertSame($before, $bag->fresh()->getAttributes());
        $this->assertSame(50, $bag->returnParcels()->count());
        $service->command($trip, $bag, $handler, 'seal', $this->bagInput($trip, $bag, ['seal_number' => 'FIFTY-RETURN-SEAL']));
        $this->rejected(fn () => app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]), 'Pack baggable');
        $second = $service->pack($trip, $handler, [...$this->packInput($trip, $cases[50]->parcel->barcode), 'physical_tag' => 'SECOND-RETURN-SACK']);
        $service->command($trip, $second, $handler, 'seal', $this->bagInput($trip, $second, ['seal_number' => 'FIFTY-FIRST-SEAL']));
        $this->assertSame('dispatched', app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()])->status);
        $this->assertSame(51, DB::table('buyer_return_unit_claims')->where('order_item_id', $order->items->first()->id)->count());
        $this->assertSame(2, MotherBag::where('return_trip_id', $trip->id)->where('status', 'in_transit')->count());
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public function test_a_single_buyer_return_can_use_its_own_sack_trip_without_waiting_for_another_parcel(): void
    {
        [$group, $cases, $manifests, $handler] = $this->trip();
        app(LogisticsTripService::class)->command($group, $handler, 'cancel', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        $trip = app(LogisticsTripService::class)->create($handler, ['manifest_ids' => [$manifests[0]->id], 'request_token' => (string) Str::uuid()]);
        $bag = $this->sealTripReturn($trip, $handler, $cases[0]->parcel->barcode);
        app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        $this->assertSame('in_transit', $bag->fresh()->status);
        $this->assertSame('in_transit', $cases[0]->parcel->fresh()->status);
        $this->assertSame('at_hub', $cases[1]->parcel->fresh()->status);
        $this->assertSame('sealed', $manifests[1]->fresh()->status);
    }
}
