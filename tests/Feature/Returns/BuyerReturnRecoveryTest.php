<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnEvidence;
use App\Models\BuyerReturnRecovery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsTripService;
use App\Services\Returns\BuyerReturnRecoveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class BuyerReturnRecoveryTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function traveling(bool $bagged): array
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $this->measureReturn($case, $bagged ? [] : ['length_cm' => '70']);
        $manifest = $this->prepareLeg($case);
        $handler = $this->flowHandler($manifest->sourceHub);
        $service = app(LogisticsManifestService::class);
        foreach (['load', 'seal'] as $action) {
            $service->command($manifest->fresh(), $handler, $action, $this->flowManifestPayload($manifest, $action === 'load' ? ['barcode' => $case->parcel->barcode] : []));
        }
        $trip = app(LogisticsTripService::class)->create($handler, ['manifest_ids' => [$manifest->id], 'request_token' => (string) Str::uuid()]);
        $bag = $bagged ? $this->sealTripReturn($trip, $handler, $case->parcel->barcode) : null;
        app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        $company = $manifest->logistics_company_id;
        $mother = LogisticsHub::create(['logistics_company_id' => $company, 'name' => 'Quezon recovery Mother Hub', 'code' => 'RECOVERY-Q',
            'tier' => 'regional_mother_hub', 'province' => 'Quezon', 'city_municipality' => 'Lucena City', 'address' => '100 Recovery Road', 'is_active' => true]);
        $wrong = LogisticsHub::create(['logistics_company_id' => $company, 'name' => 'Lucban recovery Bayan Hub', 'code' => 'RECOVERY-L',
            'tier' => 'local_bayan_hub', 'province' => 'Quezon', 'city_municipality' => 'Lucban', 'parent_hub_id' => $mother->id, 'address' => '100 Recovery Lane', 'is_active' => true]);

        return [$case->fresh(['parcel', 'order', 'delivery']), $manifest->fresh(), $trip->fresh(), $bag, $wrong];
    }

    private function payload(BuyerReturnRecovery $recovery, array $extra = []): array
    {
        return ['version' => $recovery->fresh()->version, 'request_token' => (string) Str::uuid(),
            'notes' => 'The named actor checked this actual return and its original custody evidence.', ...$extra];
    }

    private function recoveryManifest($case, BuyerReturnRecovery $recovery): LogisticsManifest
    {
        $recovery->refresh();
        $source = LogisticsHub::findOrFail($recovery->hub_ids[$recovery->leg_index]);
        $destination = LogisticsHub::findOrFail($recovery->hub_ids[$recovery->leg_index + 1]);
        $driver = $this->createApprovedUser('courier');
        $fleet = LogisticsFleet::create(['logistics_company_id' => $recovery->logistics_company_id, 'hub_id' => $source->id,
            'plate_number' => 'REC-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT),
            'vehicle_type' => $source->isMotherHub() && $destination->isMotherHub() ? 'wing_truck' : 'l300_van',
            'model' => 'Bagoo Recovery Transport', 'capacity_kg' => 1000, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $recovery->logistics_company_id, 'assigned_hub_id' => $source->id, 'vehicle_id' => $fleet->id, 'is_available' => true]);
        $token = (string) Str::uuid();

        return app(LogisticsManifestService::class)->create($this->flowHandler($source), ['source_hub_id' => $source->id, 'destination_hub_id' => $destination->id,
            'vehicle_id' => $fleet->id, 'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id,
            'buyer_return_recovery_id' => $recovery->id, 'creation_token' => $token], $token);
    }

    public static function cargo(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('cargo')]
    public function test_actual_wrong_hub_recovery_keeps_original_delivery_and_return_route_until_the_expected_receipt(bool $bagged): void
    {
        [$case, $original, $originalTrip, $originalBag, $wrong] = $this->traveling($bagged);
        $successful = [$case->order->getAttributes(), $case->delivery->getAttributes()];
        $parcelBefore = $case->parcel->getAttributes();
        $service = app(BuyerReturnRecoveryService::class);
        $observer = $this->flowHandler($wrong);
        $this->actingAs($observer)->postJson('/hub/scan', ['barcode' => $case->parcel->barcode, 'hub_id' => $wrong->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('confirmed', false);
        $recovery = BuyerReturnRecovery::sole();
        $this->assertCount(3, $recovery->hub_ids);
        $this->get('/buyer-return-recoveries/'.$recovery->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Returns/Recovery')
            ->where('recovery.state', 'observed')->where('recovery.currentHubId', null)->where('recovery.actions', []));
        $this->assertSame($recovery->id, $service->observe($observer, $case->parcel->fresh(), $wrong)->id);
        $this->assertSame($parcelBefore, $case->parcel->fresh()->getAttributes());
        $this->assertNull($original->buyerReturnParcels()->sole()->received_at);
        $recovery = $service->command($original->sourceHub->company->user, $recovery, 'authorize', $this->payload($recovery, ['source_token' => $service->sourceToken($recovery)]));
        $this->assertSame($parcelBefore, $case->parcel->fresh()->getAttributes());
        $input = $this->payload($recovery, ['barcode' => $case->parcel->barcode]);
        $photo = UploadedFile::fake()->image('recovery-intake.png');
        $recovery = $service->command($observer, $recovery, 'intake', $input, $photo);
        $this->assertSame($recovery->id, $service->command($observer, $recovery, 'intake', $input, $photo)->id);
        $this->assertSame($wrong->id, $case->parcel->fresh()->current_hub_id);
        $this->assertSame(0, $case->parcel->fresh()->route_position);
        $this->actingAs($observer)->get('/buyer-returns/'.$case->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('returnCase.custody.hubs.0.id', $wrong->id)->where('returnCase.custody.position', 0)
            ->where('returnCase.custody.recovery.active_route', true));
        $this->get('/hub/manifests')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('buyerReturns.0.recovery_id', $recovery->id)->where('buyerReturns.0.destination_hub_id', $recovery->hub_ids[1]));
        $this->assertNull($original->buyerReturnParcels()->sole()->active_return_parcel_id);
        $this->assertNull($original->buyerReturnParcels()->sole()->received_at);
        if ($originalBag) {
            $this->assertNull($originalBag->returnParcels()->sole()->active_return_parcel_id);
            $this->assertNull($originalBag->returnParcels()->sole()->received_at);
            $this->assertNotNull($originalBag->fresh()->active_physical_key);
        }
        while ($recovery->fresh()->state !== 'completed') {
            $this->measureReturn($case, $bagged ? [] : ['length_cm' => '70']);
            $manifest = $this->recoveryManifest($case, $recovery);
            $handler = $this->flowHandler($manifest->sourceHub);
            foreach (['load', 'seal'] as $action) {
                app(LogisticsManifestService::class)->command($manifest->fresh(), $handler, $action,
                    $this->flowManifestPayload($manifest, $action === 'load' ? ['barcode' => $case->parcel->barcode] : []));
            }
            $trip = app(LogisticsTripService::class)->create($handler, ['manifest_ids' => [$manifest->id], 'request_token' => (string) Str::uuid()]);
            $bag = $bagged ? $this->sealTripReturn($trip, $handler, $case->parcel->barcode) : null;
            app(LogisticsTripService::class)->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
            $receiver = $this->flowHandler($manifest->destinationHub);
            if ($bag) {
                $this->openTripReturn($trip, $bag, $receiver);
            }
            $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]))->assertOk();
            $this->assertSame($recovery->fresh()->state === 'completed' ? 1 : 0, $case->parcel->fresh()->route_position);
            $this->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        }
        $this->assertSame($original->destination_hub_id, $case->parcel->fresh()->current_hub_id);
        $this->assertSame('received', $original->fresh()->status);
        $receipt = $original->events()->where('event_type', 'buyer-return-received')->sole();
        $this->assertTrue($service->supportsCompletion($receipt));
        if ($originalBag) {
            $this->assertSame('in_transit', $originalBag->fresh()->status);
            $this->assertNotNull($originalBag->active_physical_key);
            $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($original->id)->exists());
            $this->assertFalse(app(LogisticsEligibilityService::class)->canReceivePickups($original->driver->courierProfile));
            try {
                app(LogisticsManifestService::class)->vehicle($original->logistics_company_id, $original->source_hub_id, $original->vehicle_id, $original->driver_id, true, $original->type);
                $this->fail('A separately recovered parcel cannot release the original driver while its physical sack remains unreceived.');
            } catch (\DomainException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
            $this->openTripReturn($originalTrip, $originalBag, $receiver);
            $this->assertSame('received', $originalBag->fresh()->status);
            $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($original->id)->exists());
        }
        while ($case->parcel->fresh()->route_position < count($case->parcel->hub_ids) - 1) {
            $this->measureReturn($case, $bagged ? [] : ['length_cm' => '70']);
            $this->leg($case);
        }
        $origin = LogisticsHub::findOrFail($case->parcel->fresh()->current_hub_id);
        $this->actingAs($this->flowHandler($origin))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $origin->id]))
            ->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();
        $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
            'condition' => 'accepted', 'photo' => UploadedFile::fake()->image('recovered-seller-receipt.png'),
        ]))->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();
        $this->assertSame('seller_received', $case->fresh()->status);
        $this->assertSame(['kind' => 'seller', 'user_id' => $case->seller_id], $case->parcel->fresh()->custody);
        $this->assertNull($service->present($original->sourceHub->company->user, $recovery)['currentHubId']);
        $this->assertSame($successful, [$case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes()]);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public function test_scanning_only_observes_and_an_unreceived_observation_can_be_withdrawn_without_moving_custody(): void
    {
        [$case, $original, , , $wrong] = $this->traveling(false);
        $before = $case->parcel->getAttributes();
        $handler = $this->flowHandler($wrong);
        $this->actingAs($handler)->postJson('/hub/scan', ['barcode' => $case->parcel->barcode, 'hub_id' => $wrong->id,
            'mode' => 'confirm', 'action' => 'INTAKE', 'expected_status' => 'in_transit'])->assertConflict();
        $this->assertDatabaseCount('buyer_return_recoveries', 0);
        $this->postJson('/hub/scan', ['barcode' => $case->parcel->barcode, 'hub_id' => $wrong->id, 'mode' => 'inspect'])->assertOk()->assertJsonPath('confirmed', false);
        $recovery = BuyerReturnRecovery::sole();
        $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', $this->payload($recovery, ['source_token' => app(BuyerReturnRecoveryService::class)->sourceToken($recovery)]))->assertForbidden();
        $receiver = $this->flowHandler($original->destinationHub);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$original->id.'/receive', $this->flowManifestPayload($original, ['barcode' => $case->parcel->barcode]))->assertConflict();
        $owner = $original->sourceHub->company->user;
        $this->actingAs($owner)->postJson('/buyer-return-recoveries/'.$recovery->id.'/withdraw', $this->payload($recovery))->assertOk()->assertJsonPath('recovery.state', 'withdrawn');
        $this->assertSame($before, $case->parcel->fresh()->getAttributes());
        $this->assertNull($recovery->fresh()->active_return_parcel_id);
        $this->assertNull($original->buyerReturnParcels()->sole()->received_at);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$original->id.'/receive', $this->flowManifestPayload($original, ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->assertSame(1, $case->parcel->fresh()->route_position);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public function test_private_recovery_and_parent_case_scope_exclude_other_accounts_and_unrelated_hubs(): void
    {
        [$case, $original, , , $wrong] = $this->traveling(false);
        $service = app(BuyerReturnRecoveryService::class);
        $recovery = $service->observe($this->flowHandler($wrong), $case->parcel, $wrong);
        foreach ([$case->order->buyer, $case->order->shop->user, $this->createApprovedUser('courier')] as $actor) {
            $this->actingAs($actor)->get('/buyer-return-recoveries/'.$recovery->id)->assertForbidden();
            $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', $this->payload($recovery, ['source_token' => $service->sourceToken($recovery)]))->assertForbidden();
        }
        $owner = $this->createApprovedUser('logistics');
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Unrelated Recovery Company', 'slug' => 'unrelated-recovery', 'code' => 'UREC', 'status' => 'active', 'is_active' => true]);
        $foreign = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Unrelated Mother Hub', 'code' => 'UREC-M',
            'tier' => 'regional_mother_hub', 'province' => 'Laguna', 'city_municipality' => 'Calamba City', 'address' => '100 Other Road', 'is_active' => true]);
        foreach ([$owner, $this->flowHandler($foreign)] as $actor) {
            $this->actingAs($actor)->get('/buyer-return-recoveries/'.$recovery->id)->assertNotFound();
            $this->get('/buyer-returns/'.$case->id)->assertNotFound();
            $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/withdraw', $this->payload($recovery))->assertNotFound();
        }
        $this->postJson('/hub/scan', ['barcode' => $case->parcel->barcode, 'hub_id' => $foreign->id, 'mode' => 'inspect'])->assertNotFound();
        $unrelated = $wrong->replicate();
        $unrelated->fill(['name' => 'Unrelated same-company child', 'code' => 'REC-UNRELATED'])->save();
        $this->actingAs($this->flowHandler($unrelated))->get('/buyer-returns/'.$case->id)->assertNotFound();
        $this->get('/buyer-return-recoveries/'.$recovery->id)->assertNotFound();
        $this->actingAs($this->flowHandler($wrong))->get('/buyer-returns/'.$case->id)->assertOk();
        $this->actingAs($original->sourceHub->company->user)->get('/buyer-return-recoveries/'.$recovery->id)->assertOk();
        $this->assertSame('observed', $recovery->fresh()->state);
    }

    public function test_current_review_version_bounded_inputs_and_exact_retries_protect_the_recovery_intake(): void
    {
        [$case, $original, , , $wrong] = $this->traveling(false);
        $service = app(BuyerReturnRecoveryService::class);
        $handler = $this->flowHandler($wrong);
        $recovery = $service->observe($handler, $case->parcel, $wrong);
        $this->actingAs($original->sourceHub->company->user);
        $authorization = $this->payload($recovery, ['source_token' => $service->sourceToken($recovery)]);
        foreach (['short', str_repeat('a', 1001)] as $notes) {
            $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', [...$authorization, 'notes' => $notes])->assertUnprocessable()->assertJsonValidationErrors('notes');
        }
        $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', [...$authorization, 'version' => 99])->assertConflict();
        $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', [...$authorization, 'source_token' => str_repeat('0', 64)])->assertConflict();
        $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', $authorization)->assertOk();
        $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', $authorization)->assertOk();
        $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize', [...$authorization, 'notes' => 'A changed reason must not be hidden by the original retry token.'])->assertConflict();
        $this->assertSame(2, $recovery->fresh()->version);
        $this->actingAs($handler);
        $intake = $this->payload($recovery, ['barcode' => $case->parcel->barcode]);
        $this->postJson('/buyer-return-recoveries/'.$recovery->id.'/intake', $intake)->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post('/buyer-return-recoveries/'.$recovery->id.'/intake', [...$intake, 'photo' => UploadedFile::fake()->create('too-big.png', 5121, 'image/png')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post('/buyer-return-recoveries/'.$recovery->id.'/intake', [...$intake, 'photo' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $photo = UploadedFile::fake()->image('actual.png');
        $this->post('/buyer-return-recoveries/'.$recovery->id.'/intake', [...$intake, 'barcode' => $case->delivery->tracking_number, 'photo' => $photo], ['Accept' => 'application/json'])->assertConflict();
        $this->assertSame('in_transit', $case->parcel->fresh()->status);
        $this->post('/buyer-return-recoveries/'.$recovery->id.'/intake', [...$intake, 'photo' => $photo], ['Accept' => 'application/json'])->assertOk();
        $this->post('/buyer-return-recoveries/'.$recovery->id.'/intake', [...$intake, 'photo' => $photo], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $recovery->events()->where('event_type', 'intake')->count());
        $this->actingAs($original->sourceHub->company->user)->postJson('/buyer-return-recoveries/'.$recovery->id.'/withdraw', $this->payload($recovery))->assertConflict();
        $this->assertSame('held', $recovery->fresh()->state);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public function test_changed_hub_corridor_requires_a_new_unstarted_review_and_raw_history_cannot_be_rewritten(): void
    {
        [$case, $original, , , $wrong] = $this->traveling(false);
        $service = app(BuyerReturnRecoveryService::class);
        $recovery = $service->observe($this->flowHandler($wrong), $case->parcel, $wrong);
        $wrong->update(['parent_hub_id' => $original->destination_hub_id]);
        $this->actingAs($original->sourceHub->company->user)->postJson('/buyer-return-recoveries/'.$recovery->id.'/authorize',
            $this->payload($recovery, ['source_token' => $service->sourceToken($recovery)]))->assertConflict();
        foreach (['buyer_return_recoveries' => ['hub_ids' => json_encode([$wrong->id, $original->destination_hub_id])],
            'buyer_return_recovery_events' => ['reason' => 'Changed retained observation.']] as $table => $fields) {
            try {
                DB::table($table)->update($fields);
                $this->fail('Raw SQL must preserve original recovery authority and evidence.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Retain', $error->getMessage());
            }
        }
        $this->assertSame('observed', $recovery->fresh()->state);
        $this->assertSame('in_transit', $case->parcel->fresh()->status);
    }

    public function test_failed_private_proof_rolls_back_extraction_and_an_extracted_sack_member_cannot_claim_an_early_receipt(): void
    {
        [$case, $original, , $bag, $wrong] = $this->traveling(true);
        $service = app(BuyerReturnRecoveryService::class);
        $handler = $this->flowHandler($wrong);
        $recovery = $service->observe($handler, $case->parcel, $wrong);
        $service->command($original->sourceHub->company->user, $recovery, 'authorize', $this->payload($recovery, ['source_token' => $service->sourceToken($recovery)]));
        $before = [$case->parcel->fresh()->getAttributes(), $original->buyerReturnParcels()->sole()->getAttributes(), $bag->returnParcels()->sole()->getAttributes(),
            Storage::disk('local')->allFiles(), $recovery->events()->count(), $case->events()->count()];
        $reject = true;
        BuyerReturnEvidence::creating(function ($evidence) use (&$reject) {
            if ($reject && $evidence->purpose === 'recovery-intake') {
                throw new \RuntimeException('Simulated private-proof persistence failure.');
            }
        });
        try {
            $service->command($handler, $recovery, 'intake', $this->payload($recovery, ['barcode' => $case->parcel->barcode]), UploadedFile::fake()->image('failed.png'));
            $this->fail('The entire evidenced intake must roll back.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated private-proof persistence failure.', $error->getMessage());
        }
        $reject = false;
        $this->assertSame($before, [$case->parcel->fresh()->getAttributes(), $original->buyerReturnParcels()->sole()->getAttributes(), $bag->returnParcels()->sole()->getAttributes(),
            Storage::disk('local')->allFiles(), $recovery->events()->count(), $case->events()->count()]);
        $this->assertDatabaseCount('buyer_return_bag_extractions', 0);
        $service->command($handler, $recovery, 'intake', $this->payload($recovery, ['barcode' => $case->parcel->barcode]), UploadedFile::fake()->image('actual.png'));
        try {
            DB::table('mother_bag_return_parcels')->where('mother_bag_id', $bag->id)->update(['received_at' => now(), 'received_by_id' => $handler->id]);
            $this->fail('An intake is not an original expected-hub receipt.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        try {
            DB::table('buyer_return_bag_extractions')->delete();
            $this->fail('Retain the actual original sack extraction.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        $this->assertNotNull($bag->fresh()->active_physical_key);
        $this->assertNull($bag->returnParcels()->sole()->received_at);
    }

    public function test_hub_subdomain_uses_the_same_observation_review_and_actual_preparation_scope(): void
    {
        [$case, $original, , , $wrong] = $this->traveling(false);
        $handler = $this->flowHandler($wrong);
        $this->actingAs($handler)->postJson('http://hub.localhost/scan', ['barcode' => $case->parcel->barcode, 'hub_id' => $wrong->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('return_case.href', '/buyer-return-recoveries/1')->assertJsonPath('confirmed', false);
        $recovery = BuyerReturnRecovery::sole();
        $this->get('http://hub.localhost/buyer-return-recoveries/'.$recovery->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('recovery.originalManifestUrl', null)->where('recovery.manifestUrl', null));
        $this->actingAs($original->sourceHub->company->user)->get('http://hub.localhost/buyer-return-recoveries/'.$recovery->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('recovery.originalManifestUrl', '/manifests/'.$original->id));
        $this->postJson('http://hub.localhost/buyer-return-recoveries/'.$recovery->id.'/authorize',
            $this->payload($recovery, ['source_token' => app(BuyerReturnRecoveryService::class)->sourceToken($recovery)]))->assertOk();
        $this->actingAs($handler)->post('http://hub.localhost/buyer-return-recoveries/'.$recovery->id.'/intake',
            [...$this->payload($recovery, ['barcode' => $case->parcel->barcode]), 'photo' => UploadedFile::fake()->image('subdomain.png')], ['Accept' => 'application/json'])->assertOk();
        $this->get('http://hub.localhost/manifests')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('basePath', '/manifests')->where('buyerReturns.0.recovery_id', $recovery->id)
            ->where('buyerReturns.0.destination_hub_id', $recovery->hub_ids[1]));
    }
}
