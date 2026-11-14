<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\ShipmentLossAccounting;
use App\Models\User;
use App\Models\WholeTransportLossConfirmation;
use App\Services\Finance\ShipmentLossAccountingService;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsTripService;
use App\Services\Logistics\MotherBagService;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Logistics\TransportArrivalService;
use App\Services\Logistics\WholeTransportLossService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class WholeTransportLossTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function accountConfirmedLoss(LogisticsManifestEvent $observation, User $admin, string $protection = '180.00'): ShipmentLossAccounting
    {
        $plan = app(ShipmentLossReview::class)->approve(
            $admin,
            $observation,
            $this->approval($observation, $admin, $protection),
            UploadedFile::fake()->image('loss-review.png')
        );
        app(ShipmentLossDispositionService::class)->record($admin, $plan, [
            'expected_version' => 1,
            'request_token' => (string) Str::uuid(),
            'reason' => 'Confirm unrecoverable operational loss after independent review.',
            'disposition_confirmed' => true,
        ], UploadedFile::fake()->image('disposition.png'));

        return app(ShipmentLossAccountingService::class)->record($admin, $plan, [
            'expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(),
            'reason' => 'Independent loss accounting completed without releasing unarrived transport.',
            'loss_accounting_confirmed' => true,
        ]);
    }

    private function validPayload(LogisticsManifest $manifest, array $overrides = []): array
    {
        return array_merge([
            'expected_version' => $manifest->fresh()->version,
            'request_token' => (string) Str::uuid(),
            'reason' => 'Private investigation confirmed the original transport vanished in transit without arriving.',
            'transport_loss_confirmed' => true,
            'proof' => UploadedFile::fake()->image('transport-loss-report.png'),
        ], $overrides);
    }

    public function test_loose_manifest_whole_transport_loss_enforces_independent_admin_gates_privacy_and_releases_driver_and_vehicle(): void
    {
        $admin = $this->createApprovedUser('admin');
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => '100.00']), 'quantity' => 2]]);
        $order->update(['logistics_flow_version' => 1, 'shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);

        $parcel = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->current_hub_id), true);
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel->fresh())['manifest_id']);
        $member = $manifest->parcels()->where('delivery_id', $parcel->id)->sole();
        $handler = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $owner = $manifest->company->user;

        // Missing before loss accounting -> whole-transport-loss blocked.
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest, [
            'kind' => 'missing',
            'parcel_id' => $member->id,
            'notes' => 'The original loose vehicle and parcel did not arrive at the destination hub.',
        ]))->assertOk();
        $observation = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest))->assertConflict();

        // Account loss + close manifest; still held awaiting transport or whole-transport loss.
        $this->accountConfirmedLoss($observation, $admin);
        $this->actingAs($owner)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());

        // Role / portal / source isolation.
        foreach ([$handler, $owner, $manifest->driver, $buyer, $shop->user] as $nonAdmin) {
            $this->actingAs($nonAdmin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest))->assertForbidden();
        }
        $unverifiedAdmin = $this->createApprovedUser('admin');
        $unverifiedAdmin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($unverifiedAdmin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest))->assertForbidden();
        $this->actingAs($admin)->get('/hub/manifests/'.$manifest->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedManifest.wholeTransportLoss.can_record', true)
                ->where('selectedManifest.wholeTransportLoss.eligible_scope_kind', 'whole_transport'));

        // Bad inputs / extra fields / bad version / bad proof fail without saving proof files.
        $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest, [
            'amount' => '500.00',
        ]))->assertUnprocessable();
        $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest, [
            'proof' => UploadedFile::fake()->create('fake.txt', 10, 'text/plain'),
        ]))->assertUnprocessable();
        $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest, [
            'expected_version' => $manifest->fresh()->version + 99,
        ]))->assertConflict();
        $this->assertCount(0, Storage::disk('local')->allFiles('whole-transport-losses'));

        // Simulated transaction rollback leaves zero orphaned proof files.
        $failManifestEvent = true;
        Event::listen('eloquent.creating: '.LogisticsManifestEvent::class, function (LogisticsManifestEvent $event) use (&$failManifestEvent): void {
            if ($failManifestEvent && $event->event_type === 'whole-transport-loss') {
                throw new RuntimeException('Simulated event failure');
            }
        });
        try {
            app(WholeTransportLossService::class)->confirm($admin, $manifest->fresh(), [
                'expected_version' => $manifest->fresh()->version,
                'request_token' => (string) Str::uuid(),
                'reason' => 'Testing rollback cleanup of uploaded whole-transport proof file.',
                'transport_loss_confirmed' => true,
            ], UploadedFile::fake()->image('rollback.png'));
            $this->fail('Expected simulated rollback failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated event failure', $error->getMessage());
        } finally {
            $failManifestEvent = false;
        }
        $this->assertCount(0, Storage::disk('local')->allFiles('whole-transport-losses'));

        // Confirm valid whole-transport loss + verify idempotency and conflict on mismatched token.
        $payload = $this->validPayload($manifest, [
            'reason' => 'Sensitive police report ref WTL-SECRET-123 confirms total transit vehicle loss.',
        ]);
        $first = $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $payload)->assertOk();
        $ref = $first->json('confirmation_reference');
        $this->assertSame('whole_transport', $first->json('scope_kind'));
        $retry = $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $payload)->assertOk();
        $this->assertSame($ref, $retry->json('confirmation_reference'));
        $this->assertSame(1, WholeTransportLossConfirmation::count());
        $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', [
            ...$payload,
            'reason' => 'Modified reason text with the same request token should fail conflict.',
        ])->assertConflict();
        $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest))->assertConflict();

        // Zero fake arrival/receipt events, fixed non-private reason in manifest event, and transportArrival blocked.
        $this->assertFalse($manifest->events()->where('event_type', 'transport-arrived')->exists());
        $this->assertFalse($manifest->events()->where('event_type', 'parcel_received')->exists());
        $event = $manifest->events()->where('event_type', 'whole-transport-loss')->sole();
        $this->assertSame(WholeTransportLossService::FLOOR_REASON, $event->reason);
        $this->assertStringNotContainsString('WTL-SECRET-123', json_encode($event->toArray()));
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/transport-arrival', [
            'version' => $manifest->fresh()->version,
            'request_token' => (string) Str::uuid(),
            'vehicle_plate' => $manifest->vehicle->plate_number,
            'arrival_confirmed' => true,
            'driver_identity_confirmed' => true,
            'reason' => 'Attempting to record arrival after whole-transport loss confirmation must fail.',
        ])->assertConflict();
        $this->assertFalse(app(TransportArrivalService::class)->presentation($handler, $manifest->fresh())['can_record']);

        // Releases manifest awaitingPhysicalReceipt, driver, and vehicle for new work.
        $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());
        app(LogisticsEligibilityService::class)->assertCourierScope($manifest->driver->courierProfile->fresh(), $manifest->logistics_company_id, $manifest->source_hub_id, newWork: true);
        app(LogisticsManifestService::class)->vehicle($manifest->logistics_company_id, $manifest->source_hub_id, $manifest->vehicle_id, $manifest->driver_id, true, $manifest->type);

        // Privacy isolation: non-admins cannot read private reason or proof_url or download proof.
        foreach ([$handler, $owner] as $floorUser) {
            $this->actingAs($floorUser)->get('/hub/manifests/'.$manifest->id)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('selectedManifest.wholeTransportLoss.record.reference', $ref)
                    ->where('selectedManifest.wholeTransportLoss.record.reason', null)
                    ->where('selectedManifest.wholeTransportLoss.record.proof_url', null));
            $this->actingAs($floorUser)->get('/hub/manifests/'.$manifest->id.'/whole-transport-loss/proof')->assertForbidden();
        }
        $this->actingAs($buyer)->get('/hub/manifests/'.$manifest->id.'/whole-transport-loss/proof')->assertForbidden();
        $this->actingAs($admin)->get('/hub/manifests/'.$manifest->id.'/whole-transport-loss/proof')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_unopened_mother_bag_loss_locks_physical_tag_prevents_opening_and_enforces_immutable_sql_triggers(): void
    {
        $admin = $this->createApprovedUser('admin');
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => '100.00']), 'quantity' => 1]]);
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $parcel = $this->flowDelivery($order, 'arrived_at_origin_hub');

        $hub = LogisticsHub::findOrFail($parcel->current_hub_id);
        $destination = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $company = LogisticsCompany::findOrFail($parcel->logistics_company_id);
        $handler = $this->flowHandler($hub);
        $manager = $company->user;
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create([
            'logistics_company_id' => $company->id,
            'hub_id' => $hub->id,
            'plate_number' => 'TAG-0001',
            'vehicle_type' => 'l300_van',
            'model' => 'Bagoo Van',
            'capacity_kg' => 5000,
            'assigned_driver_id' => $driver->id,
            'status' => 'active',
        ]);
        $driver->courierProfile->update(['logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id, 'vehicle_id' => $vehicle->id, 'is_available' => true]);

        $this->actingAs($manager)->get('/hub/manifests')->assertOk();
        $manifest = LogisticsManifest::findOrFail($this->postJson('/hub/manifests', [
            'source_hub_id' => $hub->id,
            'destination_hub_id' => $destination->id,
            'direction' => 'outbound',
            'vehicle_id' => $vehicle->id,
            'creation_token' => session()->get('manifest_creation_token'),
        ])->assertCreated()->json('manifest.id'));

        $bags = app(MotherBagService::class);
        $physicalTag = 'PERMANENT-SACK-77';
        $bag = $bags->create($handler, [
            'source_hub_id' => $hub->id,
            'destination_hub_id' => $destination->id,
            'creation_token' => (string) Str::uuid(),
            'physical_tag' => $physicalTag,
            'physical_checked' => true,
        ]);
        $bag = $bags->command($bag, $handler, 'add', [
            'version' => $bag->version,
            'barcode' => $parcel->tracking_number,
            'handling' => ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true],
        ]);
        $bag = $bags->command($bag, $handler, 'seal', ['version' => $bag->version, 'seal_number' => 'BAG-SEAL-77']);
        $bag = $bags->command($bag, $handler, 'load', ['version' => $bag->version, 'barcode' => $bag->reference, 'seal_number' => $bag->seal_number, 'manifest_id' => $manifest->id]);
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();

        $receiver = $this->flowHandler($destination);
        $member = $manifest->parcels()->sole();
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest, [
            'kind' => 'missing',
            'parcel_id' => $member->id,
            'notes' => 'Neither the transport vehicle nor its sealed reusable sack arrived.',
        ]))->assertOk();
        $observation = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $this->accountConfirmedLoss($observation, $admin, '80.00');
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();

        $activePhysicalKey = $bag->fresh()->active_physical_key;
        $this->assertNotNull($activePhysicalKey);
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());

        $this->actingAs($admin)->postJson('/hub/manifests/'.$manifest->id.'/whole-transport-loss', $this->validPayload($manifest))->assertOk();

        $lostBag = $bag->fresh();
        $this->assertSame('lost', $lostBag->status);
        $this->assertNull($lostBag->opened_at);
        $this->assertNull($lostBag->opened_by_id);
        $this->assertSame($activePhysicalKey, $lostBag->active_physical_key);
        $this->assertSame($physicalTag, $lostBag->physical_tag);
        $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());

        // Cannot open a lost bag or reuse its permanent physical sack tag.
        $this->actingAs($receiver)->postJson('/hub/mother-bags/'.$lostBag->id.'/open', [
            'version' => $lostBag->version,
            'barcode' => $lostBag->reference,
            'seal_number' => $lostBag->seal_number,
            'seal_intact' => true,
        ])->assertConflict();
        try {
            $bags->create($handler, [
                'source_hub_id' => $hub->id,
                'destination_hub_id' => $destination->id,
                'creation_token' => (string) Str::uuid(),
                'physical_tag' => $physicalTag,
                'physical_checked' => true,
            ]);
            $this->fail('Expected permanent physical sack tag lock to reject reuse.');
        } catch (\DomainException $error) {
            $this->assertStringContainsString('still in use', $error->getMessage());
        }

        // Database triggers protect lost mother bag columns & immutable confirmation history.
        foreach ([
            ['active_physical_key' => null],
            ['status' => 'received'],
            ['opened_at' => now()],
            ['seal_number' => 'TAMPERED'],
            ['physical_tag' => 'OTHER-TAG'],
        ] as $mutation) {
            try {
                DB::table('mother_bags')->where('id', $lostBag->id)->update($mutation);
                $this->fail('Expected DB trigger to reject mutation on lost bag.');
            } catch (QueryException) {
            }
        }
        $otherBag = $bags->create($handler, [
            'source_hub_id' => $hub->id,
            'destination_hub_id' => $destination->id,
            'creation_token' => (string) Str::uuid(),
        ]);
        try {
            DB::table('mother_bags')->where('id', $otherBag->id)->update(['status' => 'lost']);
            $this->fail('Expected DB trigger to reject setting status=lost without whole_transport_loss_bags row.');
        } catch (QueryException) {
        }
        try {
            DB::table('whole_transport_loss_confirmations')->update(['reason' => 'Tampered reason']);
            $this->fail('Expected immutable confirmation update trigger.');
        } catch (QueryException) {
        }
        try {
            DB::table('whole_transport_loss_bags')->delete();
            $this->fail('Expected immutable whole_transport_loss_bags delete trigger.');
        } catch (QueryException) {
        }
    }

    public function test_shared_trip_whole_transport_loss_marks_forward_bag_and_return_sack_lost_without_fake_arrival_or_receipts(): void
    {
        [$outbound, $forward, $forwardBag, $reverse, $case, $admin] = $this->prepareSharedTripFixture();

        $handler = $this->flowHandler($reverse->sourceHub);
        $trips = app(LogisticsTripService::class);
        $trip = $trips->create($handler, $this->grouping($reverse, $outbound));
        $returnSack = $this->sealTripReturn($trip, $handler, $case->parcel->barcode);
        $returnKey = $returnSack->active_physical_key;
        $forwardKey = $forwardBag->active_physical_key;
        $trips->command($trip, $handler, 'dispatch', ['version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid()]);

        $receiver = $this->flowHandler($reverse->destinationHub);
        $forwardMember = $outbound->parcels()->where('delivery_id', $forward->id)->sole();
        $returnMember = $reverse->buyerReturnParcels()->sole();

        $this->actingAs($receiver)->postJson('/hub/manifests/'.$outbound->id.'/report-discrepancy', $this->flowManifestPayload($outbound, [
            'kind' => 'missing', 'parcel_id' => $forwardMember->id, 'notes' => 'Shared trip forward bag never reached destination hub.',
        ]))->assertOk();
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$reverse->id.'/report-discrepancy', $this->flowManifestPayload($reverse, [
            'kind' => 'missing', 'parcel_id' => $returnMember->id, 'notes' => 'Shared trip return sack never reached destination hub.',
        ]))->assertOk();

        $forwardObs = $outbound->events()->where('event_type', 'report-discrepancy')->sole();
        $returnObs = $reverse->events()->where('event_type', 'report-discrepancy')->sole();
        $this->accountConfirmedLoss($forwardObs, $admin, '80.00');
        $this->accountConfirmedLoss($returnObs, $admin, '0.00');

        $manager = $outbound->company->user;
        $this->actingAs($manager)->postJson('/hub/manifests/'.$outbound->id.'/close', $this->flowManifestPayload($outbound))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$reverse->id.'/close', $this->flowManifestPayload($reverse))->assertOk();

        // Trip cannot close before independent whole-transport loss confirmation.
        $this->actingAs($receiver)->postJson('/hub/manifests/trips/'.$trip->id.'/close', [
            'version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid(),
        ])->assertConflict();

        // Confirm via hub subdomain endpoint as independent admin.
        $res = $this->actingAs($admin)->postJson('http://hub.localhost/manifests/'.$outbound->id.'/whole-transport-loss', $this->validPayload($outbound))->assertOk();
        $this->assertSame('whole_transport', $res->json('scope_kind'));

        $this->assertSame('lost', $forwardBag->fresh()->status);
        $this->assertSame($forwardKey, $forwardBag->fresh()->active_physical_key);
        $this->assertNull($forwardBag->fresh()->opened_at);
        $this->assertSame('lost', $returnSack->fresh()->status);
        $this->assertSame($returnKey, $returnSack->fresh()->active_physical_key);
        $this->assertNull($returnSack->fresh()->opened_at);
        $this->assertFalse($outbound->events()->where('event_type', 'transport-arrived')->exists());
        $this->assertFalse($reverse->events()->where('event_type', 'buyer-return-received')->exists());

        $this->assertTrue(DB::table('logistics_trip_events')->where('trip_id', $trip->id)->where('event_type', 'transport-loss')->exists());
        $this->actingAs($admin)->get('http://hub.localhost/manifests/'.$reverse->id.'/whole-transport-loss/proof')->assertOk();
        $this->actingAs($receiver)->get('http://hub.localhost/manifests/'.$reverse->id.'/whole-transport-loss/proof')->assertForbidden();

        $this->actingAs($receiver)->postJson('/hub/manifests/trips/'.$trip->id.'/close', [
            'version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame('closed', $trip->fresh()->status);
        $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereIn('id', [$outbound->id, $reverse->id])->exists());
    }

    public function test_partial_arrival_shared_trip_supports_unarrived_bags_loss_alongside_arrived_sack_and_recovered_forward_parcel(): void
    {
        [$outbound, $forward, $forwardBag, $reverse, $case, $admin] = $this->prepareSharedTripFixture();

        $handler = $this->flowHandler($reverse->sourceHub);
        $trips = app(LogisticsTripService::class);
        $trip = $trips->create($handler, $this->grouping($reverse, $outbound));
        $returnSack = $this->sealTripReturn($trip, $handler, $case->parcel->barcode);
        $forwardKey = $forwardBag->active_physical_key;
        $trips->command($trip, $handler, 'dispatch', ['version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid()]);

        // Return sack physically arrived & opened at destination hub; forward Mother Bag NEVER arrived,
        // and the forward parcel inside it was separately recovered at destination hub!
        $receiver = $this->flowHandler($reverse->destinationHub);
        $this->openTripReturn($trip, $returnSack, $receiver);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$reverse->id.'/receive', $this->flowManifestPayload($reverse, [
            'barcode' => $case->parcel->barcode,
        ]))->assertOk();
        $this->actingAs($reverse->company->user)->postJson('/hub/manifests/'.$reverse->id.'/close', $this->flowManifestPayload($reverse))->assertOk();

        // Before the forward parcel inside the unarrived Mother Bag is lost or recovered, unarrived_bags is blocked.
        $this->actingAs($admin)->postJson('/hub/manifests/'.$outbound->id.'/whole-transport-loss', $this->validPayload($outbound))->assertConflict();

        // Separately recover the forward parcel via another manifest observation -> sets manifest received,
        // while leaving the original unarrived Mother Bag still in_transit!
        $otherOrder = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $outbound->company->user ? $this->createE2EShop($this->createApprovedUser('seller'), ['city' => $outbound->sourceHub->city_municipality, 'province' => $outbound->sourceHub->province]) : $this->createE2EShop($this->createApprovedUser('seller')), [], 'ready_for_pickup', ['shipping_city' => $case->order->shop->city]);
        $other = $this->flowDelivery($otherOrder, 'in_transit_to_mother_hub');
        $observedManifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($other)['manifest_id']);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$observedManifest->id.'/report-discrepancy', $this->flowManifestPayload($observedManifest, [
            'kind' => 'extra', 'barcode' => $forward->tracking_number, 'notes' => 'Original forward parcel arrived loose on another transport.',
        ]))->assertOk();
        $observation = $observedManifest->events()->where('event_type', 'report-discrepancy')->sole();
        $manager = $reverse->company->user;
        $recoveries = app(ParcelRecoveryService::class);
        $proposal = $recoveries->proposal($manager, $observation);
        $recovery = $recoveries->authorize($manager, $observation, [
            'source_token' => $proposal['source_token'],
            'request_token' => (string) Str::uuid(),
            'notes' => 'Review actual unexpected loose parcel and expected destination hub.',
        ]);
        $recoveries->intake($receiver, $recovery, [
            'version' => $recovery->version,
            'request_token' => (string) Str::uuid(),
            'barcode' => $forward->tracking_number,
            'notes' => 'Destination handler physically received the loose recovered parcel.',
        ]);
        $this->actingAs($manager)->postJson('/hub/manifests/'.$outbound->id.'/close', $this->flowManifestPayload($outbound))->assertOk();
        $this->assertSame('in_transit', $forwardBag->fresh()->status);

        // Now confirm unarrived_bags loss on the partially arrived trip.
        $res = $this->actingAs($admin)->postJson('/hub/manifests/'.$outbound->id.'/whole-transport-loss', $this->validPayload($outbound))->assertOk();
        $this->assertSame('unarrived_bags', $res->json('scope_kind'));
        $this->assertSame('lost', $forwardBag->fresh()->status);
        $this->assertSame($forwardKey, $forwardBag->fresh()->active_physical_key);
        $this->assertNull($forwardBag->fresh()->opened_at);
        $this->assertSame('received', $returnSack->fresh()->status);

        $this->actingAs($receiver)->postJson('/hub/manifests/trips/'.$trip->id.'/close', [
            'version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame('closed', $trip->fresh()->status);
        $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($outbound->id)->exists());
    }

    private function prepareSharedTripFixture(): array
    {
        $admin = $this->createApprovedUser('admin');
        $case = $this->approvedReturn();
        $case->order->update(['shipping_quote' => [...$case->order->shipping_quote, 'refund_policy_version' => 2]]);
        $this->intake($case);
        $this->measureReturn($case);
        $reverse = $this->prepareLeg($case);
        $source = $reverse->sourceHub;
        $handler = $this->flowHandler($source);
        $service = app(LogisticsManifestService::class);
        $service->command($reverse, $handler, 'load', $this->flowManifestPayload($reverse, ['barcode' => $case->parcel->barcode]));
        $service->command($reverse->fresh(), $handler, 'seal', $this->flowManifestPayload($reverse));

        $shop = $this->createE2EShop($this->createApprovedUser('seller'), ['city' => $source->city_municipality, 'province' => $source->province]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $this->createE2EProduct($shop, ['price' => '100.00']), 'quantity' => 1]], 'ready_for_pickup', ['shipping_city' => $case->order->shop->city]);
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $forward = $this->flowDelivery($order, 'arrived_at_origin_hub');

        $token = (string) Str::uuid();
        $outbound = $service->create($handler, [
            'source_hub_id' => $source->id,
            'destination_hub_id' => $reverse->destination_hub_id,
            'vehicle_id' => $reverse->vehicle_id,
            'creation_token' => $token,
            'direction' => 'outbound',
        ], $token);

        $bags = app(MotherBagService::class);
        $forwardBag = $bags->create($handler, [
            'source_hub_id' => $source->id,
            'destination_hub_id' => $reverse->destination_hub_id,
            'creation_token' => (string) Str::uuid(),
            'physical_tag' => 'SHARED-FORWARD-'.$outbound->id,
            'physical_checked' => true,
        ]);
        $forwardBag = $bags->command($forwardBag, $handler, 'add', [
            'version' => $forwardBag->version,
            'barcode' => $forward->tracking_number,
            'handling' => ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true],
        ]);
        $forwardBag = $bags->command($forwardBag, $handler, 'seal', ['version' => $forwardBag->version, 'seal_number' => 'SHARED-SEAL-'.$outbound->id]);
        $forwardBag = $bags->command($forwardBag, $handler, 'load', ['version' => $forwardBag->version, 'barcode' => $forwardBag->reference, 'seal_number' => $forwardBag->seal_number, 'manifest_id' => $outbound->id]);
        $service->command($outbound->fresh(), $handler, 'seal', $this->flowManifestPayload($outbound));

        return [$outbound->fresh(), $forward->fresh(), $forwardBag->fresh(), $reverse->fresh(['buyerReturnParcels', 'company', 'sourceHub', 'destinationHub']), $case->fresh(['parcel']), $admin];
    }

    private function grouping(LogisticsManifest $a, LogisticsManifest $b): array
    {
        return ['manifest_ids' => [$a->id, $b->id], 'request_token' => (string) Str::uuid()];
    }
}
