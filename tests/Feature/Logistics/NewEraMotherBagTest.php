<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\MotherBagService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class NewEraMotherBagTest extends TestCase
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
        return app(MotherBagService::class)->command($bag->fresh(), $this->flowHandler($bag->source), $action, ['version' => $bag->fresh()->version, ...$extra]);
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

    public function test_numbered_seals_duplicate_membership_master_tags_and_whole_bag_corrections_retain_their_evidence(): void
    {
        $parcel = $this->parcel();
        $bag = $this->bag($parcel);
        $this->reject(fn () => $this->command($bag, 'seal', ['seal_number' => 'QA-SEAL-001']), 'Add parcels');
        $bag = $this->command($bag, 'add', ['barcode' => $parcel->tracking_number]);
        $version = $bag->version;
        $this->assertSame($version, $this->command($bag, 'add', ['barcode' => $parcel->tracking_number])->version);
        $other = $this->bag($parcel);
        $this->reject(fn () => $this->command($other, 'add', ['barcode' => $parcel->tracking_number]), 'another active');
        $bag = $this->command($bag, 'seal', ['seal_number' => 'QA-SEAL-001']);
        $manifest = $this->manifest($parcel);
        $this->reject(fn () => $this->command($bag, 'load', ['barcode' => 'BAG-WRONG', 'seal_number' => $bag->seal_number, 'manifest_id' => $manifest->id]), 'does not match');
        $this->assertSame(0, $manifest->parcels()->count());
        $bag = $this->command($bag, 'load', ['barcode' => $bag->reference, 'seal_number' => $bag->seal_number, 'manifest_id' => $manifest->id]);
        $this->assertSame(1, $manifest->parcels()->where('included', true)->count());
        $owner = $parcel->originBayanHub->company->user;
        $member = $manifest->parcels()->sole();
        $this->actingAs($owner)->postJson('/hub/manifests/'.$manifest->id.'/remove', $this->flowManifestPayload($manifest, ['parcel_id' => $member->id, 'notes' => 'Attempt to split a loaded sealed bag.']))->assertConflict();
        $bag = $this->command($bag, 'unload', ['barcode' => $bag->reference, 'seal_number' => $bag->seal_number, 'notes' => 'Correct the truck loading before departure.']);
        $this->assertSame(0, $manifest->parcels()->where('included', true)->count());
        $this->assertNull($bag->manifest_id);
        $bag = $this->command($bag, 'reopen', ['notes' => 'Recheck the packed contents at the original hub.']);
        $this->reject(fn () => $this->command($bag, 'seal', ['seal_number' => 'QA-SEAL-001']), 'already used');
        $bag = $this->command($bag, 'remove', ['barcode' => $parcel->tracking_number, 'notes' => 'The parcel is set aside for a different loading batch.']);
        $this->assertSame(0, $bag->parcels()->whereNotNull('active_delivery_id')->count());
        $this->assertSame(1, DB::table('mother_bag_seals')->count());
        $this->assertSame(1, DB::table('mother_bag_events')->where('mother_bag_id', $bag->id)->where('event_type', 'unload')->count());
    }

    public function test_departure_is_atomic_for_every_bag_member_and_receiving_requires_the_seal_and_each_parcel(): void
    {
        $first = $this->parcel();
        $second = $this->parcel();
        $bag = $this->bag($first);
        $bag = $this->command($bag, 'add', ['barcode' => $first->tracking_number]);
        $bag = $this->command($bag, 'add', ['barcode' => $second->tracking_number]);
        $bag = $this->command($bag, 'seal', ['seal_number' => 'QA-BATCH-001']);
        $manifest = $this->manifest($first);
        $bag = $this->command($bag, 'load', ['barcode' => $bag->reference, 'seal_number' => $bag->seal_number, 'manifest_id' => $manifest->id]);
        $service = app(LogisticsManifestService::class);
        $owner = $first->originBayanHub->company->user;
        $sender = $this->flowHandler($first->originBayanHub);
        $service->command($manifest->fresh(), $owner, 'seal', $this->flowManifestPayload($manifest));
        $before = [$manifest->fresh()->getAttributes(), $first->fresh()->getAttributes(), $second->fresh()->getAttributes(), DB::table('delivery_checkpoints')->count(), DB::table('logistics_manifest_events')->count()];
        Event::listen('eloquent.updating: '.Delivery::class, function (Delivery $parcel) use ($second): void {
            if ($parcel->id === $second->id && $parcel->status === 'in_transit_to_mother_hub') {
                throw new \RuntimeException('Simulated second-parcel write failure.');
            }
        });
        try {
            $service->command($manifest->fresh(), $sender, 'dispatch', $this->flowManifestPayload($manifest));
            $this->fail('A partial batch departure was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated second-parcel write failure.', $exception->getMessage());
        }
        Event::forget('eloquent.updating: '.Delivery::class);
        $this->assertSame($before, [$manifest->fresh()->getAttributes(), $first->fresh()->getAttributes(), $second->fresh()->getAttributes(), DB::table('delivery_checkpoints')->count(), DB::table('logistics_manifest_events')->count()]);
        $this->assertSame('sealed', $bag->fresh()->status);
        $departure = $this->flowManifestPayload($manifest);
        $service->command($manifest->fresh(), $sender, 'dispatch', $departure);
        $service->command($manifest->fresh(), $sender, 'dispatch', $departure);
        $this->assertSame('in_transit', $bag->fresh()->status);
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->reject(fn () => $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $first->tracking_number])), 'open');
        $this->reject(fn () => app(MotherBagService::class)->command($bag->fresh(), $receiver, 'open', ['version' => $bag->fresh()->version, 'barcode' => $bag->reference, 'seal_number' => 'WRONG-SEAL', 'seal_intact' => true]), 'does not match');
        $this->openFlowMotherBags($manifest, $receiver);
        $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $first->tracking_number]));
        $this->assertSame('opened', $bag->fresh()->status);
        $this->assertSame('in_transit_to_mother_hub', $second->fresh()->status);
        $service->command($manifest->fresh(), $receiver, 'receive', $this->flowManifestPayload($manifest, ['barcode' => $second->tracking_number]));
        $this->assertSame('received', $bag->fresh()->status);
        $this->assertSame(0, $bag->parcels()->whereNotNull('active_delivery_id')->count());
    }

    public function test_operator_sees_same_company_receiving_choices_without_foreign_or_remote_scan_permission(): void
    {
        $parcel = $this->parcel();
        $origin = $parcel->originBayanHub;
        $mother = $parcel->originMotherHub;
        $operator = $this->flowHandler($origin);
        $foreignOwner = $this->createApprovedUser('logistics');
        $foreignCompany = LogisticsCompany::create(['user_id' => $foreignOwner->id, 'name' => 'Foreign Acceptance Network',
            'slug' => 'foreign-acceptance-network', 'code' => 'FOREIGN-ACCEPTANCE', 'status' => 'active', 'is_active' => true]);
        $foreign = LogisticsHub::create(['logistics_company_id' => $foreignCompany->id, 'code' => 'FOREIGN-MH', 'name' => 'Foreign Mother',
            'tier' => 'regional_mother_hub', 'province' => 'Laguna', 'city_municipality' => 'Calamba', 'address' => '100 Test Road', 'is_active' => true]);
        $this->actingAs($operator)->get('/hub/mother-bags')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('hubs', 3)->where('hubs', fn ($hubs) => collect($hubs)->contains(fn ($hub) => $hub['id'] === $mother->id && ! $hub['canScan'])
                && ! collect($hubs)->contains(fn ($hub) => $hub['id'] === $foreign->id)));
        $bag = $this->bag($parcel);
        $this->actingAs($this->flowHandler($mother))->post('/hub/mother-bags/'.$bag->id.'/add', ['version' => 1, 'barcode' => $parcel->tracking_number])->assertForbidden();
        $this->actingAs($this->flowHandler($foreign))->post('/hub/mother-bags/'.$bag->id.'/add', ['version' => 1, 'barcode' => $parcel->tracking_number])->assertForbidden();
        $this->actingAs($operator)->post('/hub/mother-bags', ['source_hub_id' => $origin->id, 'destination_hub_id' => $foreign->id,
            'creation_token' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(0, $bag->parcels()->count());
        $this->assertSame(1, MotherBag::count());
    }

    public function test_original_earnings_bag_events_and_seal_records_reject_direct_sql_rewrites(): void
    {
        $parcel = $this->parcel();
        $bag = $this->command($this->bag($parcel), 'add', ['barcode' => $parcel->tracking_number]);
        $this->command($bag, 'seal', ['seal_number' => 'QA-RETAIN-001']);
        foreach ([fn () => DB::table('logistics_earnings')->update(['amount_cents' => 99999]),
            fn () => DB::table('mother_bag_events')->update(['event_type' => 'changed']),
            fn () => DB::table('mother_bag_seals')->delete()] as $rewrite) {
            try {
                $rewrite();
                $this->fail('Original retained evidence was rewritten.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('retained', $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('logistics_earnings', ['delivery_id' => $parcel->id, 'amount_cents' => 1000]);
    }
}
