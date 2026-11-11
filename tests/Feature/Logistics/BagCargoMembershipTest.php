<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Services\Logistics\CargoHandlingService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\MotherBagService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class BagCargoMembershipTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function parcel(): Delivery
    {
        return $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
    }

    private function bag(Delivery $parcel): MotherBag
    {
        return app(MotherBagService::class)->create($this->flowHandler($parcel->originBayanHub), [
            'source_hub_id' => $parcel->origin_bayan_hub_id, 'destination_hub_id' => $parcel->origin_mother_hub_id,
            'creation_token' => (string) Str::uuid(),
        ]);
    }

    private function command(MotherBag $bag, string $action, array $extra = []): MotherBag
    {
        return app(MotherBagService::class)->command($bag->fresh(), $this->flowHandler($bag->source), $action, ['version' => $bag->fresh()->version, ...($action === 'add' ? ['handling' => ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true]] : []), ...$extra]);
    }

    private function manifest(Delivery $parcel): LogisticsManifest
    {
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $parcel->logistics_company_id, 'hub_id' => $parcel->origin_bayan_hub_id,
            'plate_number' => 'QA-BAG-001', 'vehicle_type' => 'l300_van', 'capacity_kg' => 10000, 'status' => 'active', 'assigned_driver_id' => $driver->id]);
        $driver->courierProfile->update(['logistics_company_id' => $parcel->logistics_company_id, 'assigned_hub_id' => $parcel->origin_bayan_hub_id, 'vehicle_id' => $vehicle->id]);
        $owner = $parcel->originBayanHub->company->user;
        $this->actingAs($owner)->get('/hub/manifests')->assertOk();
        $response = $this->postJson('/hub/manifests', ['source_hub_id' => $parcel->origin_bayan_hub_id, 'destination_hub_id' => $parcel->origin_mother_hub_id,
            'vehicle_id' => $vehicle->id, 'creation_token' => session()->get('manifest_creation_token')])->assertCreated();

        return LogisticsManifest::findOrFail($response->json('manifest.id'));
    }

    private function reject(callable $work, string $contains): void
    {
        try {
            $work();
            $this->fail('The invalid bag action was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString($contains, $exception->getMessage());
        }
    }

    private function reading(float $kg = 1, float $side = 20): array
    {
        return ['weight_kg' => $kg, 'length_cm' => $side, 'width_cm' => $side, 'height_cm' => $side, 'confirmed' => true];
    }

    private function packInput(Delivery $parcel, array $extra = []): array
    {
        return ['source_hub_id' => $parcel->current_hub_id, 'barcode' => $parcel->tracking_number,
            'request_token' => (string) Str::uuid(), 'handling' => $this->reading(), ...$extra];
    }

    public function test_first_scan_reuses_the_lane_and_replays_only_the_original_request_without_fake_movements(): void
    {
        $first = $this->parcel();
        $second = $this->parcel();
        $actor = $this->flowHandler($first->originBayanHub);
        $input = $this->packInput($first);
        $before = [$first->fresh()->status, $first->checkpoints()->count(), $first->order->fresh()->shipping_quote];
        $service = app(MotherBagService::class);
        $bag = $service->pack($actor, $input);
        $version = $bag->version;
        $this->assertSame($bag->id, $service->pack($actor, $input)->id);
        $this->assertSame($version, $bag->fresh()->version);
        $this->reject(fn () => $service->pack($actor, [...$input, 'barcode' => $second->tracking_number]), 'different parcel');
        $this->assertSame($bag->id, $service->pack($actor, $this->packInput($second))->id);
        $this->assertSame(1, MotherBag::count());
        $this->assertSame(2, $bag->parcels()->count());
        $this->assertSame('open', $bag->fresh()->status);
        $this->assertSame(0, DB::table('mother_bag_seals')->count());
        $this->assertSame($before, [$first->fresh()->status, $first->checkpoints()->count(), $first->order->fresh()->shipping_quote]);
        $this->actingAs($actor)->get('/hub/mother-bags?bag='.$bag->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('packingAvailable', true)->where('selectedBag.capacity.weight_kg', 2)->where('selectedBag.parcels.0.handling.source', 'operator_reading'));
        $this->actingAs($actor)->postJson('/hub/mother-bags/pack', $input)->assertOk()->assertJsonPath('bag.id', $bag->id);
    }

    public function test_full_bag_rollover_does_not_seal_or_move_the_original_contents(): void
    {
        $first = $this->parcel();
        $second = $this->parcel();
        $actor = $this->flowHandler($first->originBayanHub);
        $service = app(MotherBagService::class);
        $bag = $service->pack($actor, $this->packInput($first, ['handling' => $this->reading(13)]));
        $other = $service->pack($actor, $this->packInput($second, ['handling' => $this->reading(13)]));
        $this->assertNotSame($bag->id, $other->id);
        $this->assertNull($bag->fresh()->automatic_lane);
        $this->assertNotNull($other->automatic_lane);
        $this->assertSame('open', $bag->fresh()->status);
        $this->assertSame(0, DB::table('mother_bag_seals')->count());
        $this->assertSame(1, $bag->parcels()->count());
        $this->assertSame(1, DB::table('mother_bag_events')->where('event_type', 'capacity_turnover')->count());
    }

    public function test_manual_packing_rejects_weight_and_volume_overflow_without_partial_membership(): void
    {
        $first = $this->parcel();
        $second = $this->parcel();
        $bag = $this->command($this->bag($first), 'add', ['barcode' => $first->tracking_number, 'handling' => $this->reading(15)]);
        $before = $bag->fresh()->getAttributes();
        $this->reject(fn () => $this->command($bag, 'add', ['barcode' => $second->tracking_number, 'handling' => $this->reading(10.001)]), '25 kg');
        $this->assertSame($before, $bag->fresh()->getAttributes());
        $this->assertSame(1, $bag->parcels()->count());
        $bag = $this->command($bag, 'add', ['barcode' => $second->tracking_number, 'handling' => $this->reading(10)]);
        $this->assertSame(25.0, app(CargoHandlingService::class)->capacity($bag)['weight_kg']);
        $third = $this->parcel();
        $fourth = $this->parcel();
        $volumeBag = $this->command($this->bag($third), 'add', ['barcode' => $third->tracking_number, 'handling' => $this->reading(1, 60)]);
        $this->reject(fn () => $this->command($volumeBag, 'add', ['barcode' => $fourth->tracking_number, 'handling' => $this->reading(1, 60)]), '0.30');
        $this->assertSame(1, $volumeBag->parcels()->count());
    }

    public function test_unknown_multi_item_size_and_oversized_packages_never_create_empty_automatic_bags(): void
    {
        $parcel = $this->parcel();
        $actor = $this->flowHandler($parcel->originBayanHub);
        $service = app(MotherBagService::class);
        $input = $this->packInput($parcel);
        unset($input['handling']);
        $this->reject(fn () => $service->pack($actor, $input), 'outer package');
        $this->reject(fn () => $service->pack($actor, $this->packInput($parcel, ['handling' => $this->reading(1, 60.001)])), 'loose cargo');
        $this->reject(fn () => $service->pack($actor, $this->packInput($parcel, ['handling' => [...$this->reading(), 'confirmed' => false]])), 'Confirm');
        $this->assertSame(0, MotherBag::count());
        $this->assertSame(0, DB::table('bag_packing_requests')->count());
        foreach ([['weight_kg' => 0], ['length_cm' => -1], ['height_cm' => '1e6'], ['width_cm' => 0.0001], ['injected' => 1]] as $invalid) {
            $this->actingAs($actor)->postJson('/hub/mother-bags/pack', $this->packInput($parcel, ['handling' => [...$this->reading(), ...$invalid]]))->assertUnprocessable();
        }
        $this->assertSame(0, MotherBag::count());
    }

    public function test_single_unit_uses_the_purchased_declaration_after_catalogue_dimensions_change(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product, 'quantity' => 1]], 'ready_for_pickup');
        $parcel = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $original = $order->fresh()->shipping_quote;
        $product->update(['weight_kg' => 99, 'length_cm' => 200]);
        $input = $this->packInput($parcel);
        unset($input['handling']);
        $bag = app(MotherBagService::class)->pack($this->flowHandler($parcel->originBayanHub), $input);
        $profile = $bag->parcels()->sole()->handling_snapshot;
        $this->assertSame('purchased_declaration', $profile['source']);
        $this->assertEquals(1, $profile['weight_kg']);
        $this->assertEquals(20, $profile['length_cm']);
        $this->assertSame($original, $order->fresh()->shipping_quote);
    }

    public function test_sealed_membership_and_retained_snapshots_reject_raw_sql_changes(): void
    {
        $parcel = $this->parcel();
        $bag = $this->command($this->bag($parcel), 'add', ['barcode' => $parcel->tracking_number]);
        $this->command($bag, 'seal', ['seal_number' => 'B08-IMMUTABLE']);
        $member = $bag->parcels()->sole();
        foreach ([fn () => DB::table('mother_bag_parcels')->where('id', $member->id)->update(['handling_snapshot' => '{}']),
            fn () => DB::table('mother_bag_parcels')->where('id', $member->id)->update(['removed_at' => now()]),
            fn () => DB::table('mother_bag_parcels')->where('id', $member->id)->update(['active_delivery_id' => null]),
            fn () => DB::table('mother_bag_parcels')->where('id', $member->id)->update(['received_at' => now()]),
            fn () => DB::table('mother_bag_parcels')->where('id', $member->id)->delete(),
            fn () => DB::table('mother_bags')->where('id', $bag->id)->update(['physical_tag' => 'FAKE-REPLACEMENT'])] as $rewrite) {
            try {
                $rewrite();
                $this->fail('Sealed packing evidence was rewritten.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('retained', $e->getMessage());
            }
        }
        $this->assertSame(1, $bag->parcels()->count());
    }

    public function test_loose_cargo_is_explicit_and_receives_individually_without_a_fictitious_bag(): void
    {
        $first = $this->parcel();
        $second = $this->parcel();
        $manifest = $this->manifest($first);
        $sender = $this->flowHandler($first->originBayanHub);
        $service = app(LogisticsManifestService::class);
        $input = $this->flowManifestPayload($manifest, ['barcode' => $first->tracking_number, 'cargo_mode' => 'loose', 'handling' => $this->reading(20)]);
        $result = $service->command($manifest, $sender, 'load', $input);
        $this->assertSame($result['event']->id, $service->command($manifest->fresh(), $sender, 'load', $input)['event']->id);
        $this->reject(fn () => $service->command($manifest->fresh(), $sender, 'load', $this->flowManifestPayload($manifest, ['barcode' => $second->tracking_number, 'cargo_mode' => 'loose', 'handling' => $this->reading()])), 'bagging limits');
        $service->command($manifest->fresh(), $sender, 'load', $this->flowManifestPayload($manifest, ['barcode' => $second->tracking_number, 'cargo_mode' => 'loose', 'handling' => $this->reading(1, 70)]));
        $this->assertSame(0, MotherBag::count());
        $this->assertSame(2, $manifest->parcels()->where('cargo_mode', 'loose')->count());
        $service->command($manifest->fresh(), $sender, 'seal', $this->flowManifestPayload($manifest));
        $service->command($manifest->fresh(), $sender, 'dispatch', $this->flowManifestPayload($manifest));
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $first->tracking_number]));
        $this->assertSame('dispatched', $manifest->fresh()->status);
        $this->assertNull($manifest->parcels()->where('delivery_id', $second->id)->sole()->received_at);
        $this->reject(fn () => $service->command($manifest->fresh(), $receiver, 'close', $this->flowManifestPayload($manifest)), 'received');
        $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $second->tracking_number]));
        $this->assertSame('received', $manifest->fresh()->status);
        $this->assertSame(2, $manifest->parcels()->whereNotNull('received_at')->count());
        $this->assertSame(0, DB::table('mother_bag_seals')->count());
    }

    public function test_loose_loading_and_changed_vehicle_capacity_fail_atomically(): void
    {
        $parcel = $this->parcel();
        $manifest = $this->manifest($parcel);
        $sender = $this->flowHandler($parcel->originBayanHub);
        $service = app(LogisticsManifestService::class);
        $manifest->vehicle->update(['capacity_kg' => 19]);
        $before = [$manifest->fresh()->version, $manifest->events()->count()];
        $this->reject(fn () => $service->command($manifest, $sender, 'load', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number, 'cargo_mode' => 'loose', 'handling' => $this->reading(20)])), 'vehicle capacity');
        $this->assertSame(0, $manifest->parcels()->count());
        $this->assertSame($before, [$manifest->fresh()->version, $manifest->events()->count()]);
        $manifest->vehicle->update(['capacity_kg' => 25]);
        $service->command($manifest, $sender, 'load', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number, 'cargo_mode' => 'loose', 'handling' => $this->reading(20)]));
        $manifest->vehicle->update(['capacity_kg' => 19]);
        $this->reject(fn () => $service->command($manifest->fresh(), $sender, 'seal', $this->flowManifestPayload($manifest)), 'vehicle capacity');
        $this->assertSame('draft', $manifest->fresh()->status);
        $this->reject(fn () => $this->command($this->bag($parcel), 'add', ['barcode' => $parcel->tracking_number]), 'active manifest');
    }

    public function test_reusable_sack_stays_at_its_actual_hub_until_every_member_is_received(): void
    {
        $parcel = $this->parcel();
        $actor = $this->flowHandler($parcel->originBayanHub);
        $service = app(MotherBagService::class);
        $input = $this->packInput($parcel, ['physical_tag' => 'SACK-001', 'physical_checked' => true]);
        $bag = $service->pack($actor, $input);
        $other = $this->parcel();
        $bag = $this->command($bag, 'add', ['barcode' => $other->tracking_number]);
        $this->reject(fn () => $service->create($actor, ['source_hub_id' => $bag->source_hub_id, 'destination_hub_id' => $bag->destination_hub_id,
            'creation_token' => (string) Str::uuid(), 'physical_tag' => 'SACK-001', 'physical_checked' => true]), 'still in use');
        $bag = $this->command($bag, 'seal', ['seal_number' => 'B08-SACK-SEAL-1']);
        $manifest = $this->manifest($parcel);
        $bag = $this->command($bag, 'load', ['barcode' => $bag->reference, 'seal_number' => $bag->seal_number, 'manifest_id' => $manifest->id]);
        $ms = app(LogisticsManifestService::class);
        $ms->command($manifest, $actor, 'seal', $this->flowManifestPayload($manifest));
        $ms->command($manifest->fresh(), $actor, 'dispatch', $this->flowManifestPayload($manifest));
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->openFlowMotherBags($manifest, $receiver);
        $this->assertNotNull($bag->fresh()->active_physical_key);
        $ms->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]));
        $this->assertNotNull($bag->fresh()->active_physical_key);
        $ms->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $other->tracking_number]));
        $this->assertNull($bag->fresh()->active_physical_key);
        $next = app(LogisticsManifestService::class)->nextHub($parcel->fresh());
        $new = $service->create($receiver, ['source_hub_id' => $manifest->destination_hub_id, 'destination_hub_id' => $next,
            'creation_token' => (string) Str::uuid(), 'physical_tag' => 'SACK-001', 'physical_checked' => true]);
        $this->assertSame('SACK-001', $new->physical_tag);
        $this->assertNotSame($bag->reference, $new->reference);
        $this->assertNull($new->seal_number);
        $this->assertSame('B08-SACK-SEAL-1', $bag->fresh()->seal_number);
    }

    public function test_loose_unloading_keeps_the_original_handling_snapshot_when_the_same_parcel_is_reloaded(): void
    {
        $parcel = $this->parcel();
        $manifest = $this->manifest($parcel);
        $actor = $this->flowHandler($parcel->originBayanHub);
        $service = app(LogisticsManifestService::class);
        $load = fn () => $service->command($manifest->fresh(), $actor, 'load', $this->flowManifestPayload($manifest, [
            'barcode' => $parcel->tracking_number, 'cargo_mode' => 'loose', 'handling' => $this->reading(20)]));
        $load();
        $member = $manifest->parcels()->sole();
        $original = $member->handling_snapshot;
        $service->command($manifest->fresh(), $actor, 'remove', $this->flowManifestPayload($manifest, ['parcel_id' => $member->id, 'notes' => 'Recheck the actual loading position before departure.']));
        $this->assertFalse($member->fresh()->included);
        $this->assertNull($member->fresh()->active_delivery_id);
        $this->travel(2)->seconds();
        $load();
        $this->assertSame($original, $member->fresh()->handling_snapshot);
        $this->assertSame(1, $manifest->parcels()->count());
        $this->assertSame(2, $manifest->events()->where('event_type', 'load')->count());
        $this->assertSame(0, MotherBag::count());
        foreach ([fn () => DB::table('logistics_manifest_parcels')->where('id', $member->id)->update(['cargo_mode' => 'bag']),
            fn () => DB::table('logistics_manifest_parcels')->where('id', $member->id)->update(['handling_snapshot' => '{}'])] as $rewrite) {
            try {
                $rewrite();
                $this->fail('Original cargo treatment was rewritten.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('retained', $e->getMessage());
            }
        }
    }

    public function test_checked_bag_commands_replay_the_original_result_and_reject_changed_or_unrecorded_duplicate_readings(): void
    {
        $parcel = $this->parcel();
        $bag = $this->bag($parcel);
        $actor = $this->flowHandler($parcel->originBayanHub);
        $service = app(MotherBagService::class);
        $input = ['version' => 1, 'request_token' => (string) Str::uuid(), 'barcode' => $parcel->tracking_number, 'handling' => $this->reading()];
        $service->command($bag, $actor, 'add', $input);
        $this->assertSame(2, $service->command($bag->fresh(), $actor, 'add', $input)->version);
        $this->reject(fn () => $service->command($bag->fresh(), $actor, 'add', [...$input, 'handling' => $this->reading(2)]), 'different bag action');
        $this->reject(fn () => $service->command($bag->fresh(), $actor, 'add', [...$input, 'version' => 2, 'request_token' => (string) Str::uuid()]), 'already in this bag');
        $this->assertSame(1, DB::table('bag_packing_requests')->count());
        $this->assertSame(1, $bag->parcels()->count());
        try {
            DB::table('bag_packing_requests')->update(['fingerprint' => str_repeat('0', 64)]);
            $this->fail('A retained packing request was rewritten.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('retained', $e->getMessage());
        }
    }

    public function test_digit_only_hub_ids_and_unique_lane_and_physical_keys_guard_first_scan_allocation(): void
    {
        $parcel = $this->parcel();
        $actor = $this->flowHandler($parcel->originBayanHub);
        foreach (['01', '+1', '1.0', '1e0', true] as $bad) {
            $this->actingAs($actor)->postJson('/hub/mother-bags/pack', $this->packInput($parcel, ['source_hub_id' => $bad]))->assertUnprocessable();
        }
        $bag = app(MotherBagService::class)->pack($actor, $this->packInput($parcel, ['physical_tag' => 'SACK-KEY-001', 'physical_checked' => true]));
        foreach (['automatic_lane', 'active_physical_key'] as $key) {
            $copy = $bag->replicate(['id', 'reference', 'creation_token', $key === 'automatic_lane' ? 'active_physical_key' : 'automatic_lane']);
            $copy->reference = 'BAG-'.Str::upper(Str::random(12));
            $copy->creation_token = (string) Str::uuid();
            try {
                $copy->save();
                $this->fail('Competing active allocation was accepted.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('UNIQUE', $e->getMessage());
            }
        }
        $this->assertSame(1, MotherBag::count());
    }

    public function test_fiftieth_small_parcel_fits_but_the_fifty_first_starts_a_new_open_bag(): void
    {
        $service = app(MotherBagService::class);
        $first = null;
        for ($i = 0; $i < 51; $i++) {
            $parcel = $this->parcel();
            $bag = $service->pack($this->flowHandler($parcel->originBayanHub), $this->packInput($parcel, ['handling' => $this->reading(0.1, 1)]));
            $first ??= $bag;
            if ($i < 50) {
                $this->assertSame($first->id, $bag->id);
            } else {
                $this->assertNotSame($first->id, $bag->id);
            }
        }
        $this->assertSame(50, $first->parcels()->count());
        $this->assertSame(1, $bag->parcels()->count());
        $this->assertSame(2, MotherBag::where('status', 'open')->count());
        $this->assertSame(0, DB::table('mother_bag_seals')->count());
    }

    public function test_one_manifest_carries_a_sealed_bag_and_loose_cargo_without_double_counting_or_bulk_receipt(): void
    {
        $small = $this->parcel();
        $bulky = $this->parcel();
        $bag = $this->command($this->bag($small), 'add', ['barcode' => $small->tracking_number]);
        $bag = $this->command($bag, 'seal', ['seal_number' => 'B08-MIXED-001']);
        $manifest = $this->manifest($small);
        $this->command($bag, 'load', ['barcode' => $bag->reference, 'seal_number' => $bag->seal_number, 'manifest_id' => $manifest->id]);
        $sender = $this->flowHandler($small->originBayanHub);
        $service = app(LogisticsManifestService::class);
        $service->command($manifest->fresh(), $sender, 'load', $this->flowManifestPayload($manifest, ['barcode' => $bulky->tracking_number, 'cargo_mode' => 'loose', 'handling' => $this->reading(20)]));
        $capacity = app(CargoHandlingService::class)->manifestCapacity($manifest->fresh());
        $this->assertSame(21.0, $capacity['weight_kg']);
        $this->assertSame(1, $capacity['loose_count']);
        $this->assertSame(2, $capacity['count']);
        $service->command($manifest->fresh(), $sender, 'seal', $this->flowManifestPayload($manifest));
        $service->command($manifest->fresh(), $sender, 'dispatch', $this->flowManifestPayload($manifest));
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $bulky->tracking_number]));
        $this->assertSame('dispatched', $manifest->fresh()->status);
        $this->assertSame('in_transit', $bag->fresh()->status);
        $this->reject(fn () => $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $small->tracking_number])), 'open');
        $this->openFlowMotherBags($manifest, $receiver);
        $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $small->tracking_number]));
        $this->assertSame('received', $manifest->fresh()->status);
        $this->assertSame('received', $bag->fresh()->status);
    }
}
