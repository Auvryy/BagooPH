<?php

namespace Tests\Feature\Returns;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Services\AccountClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class BuyerReturnCustodyTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;
    use CreatesBuyerReturns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_a_real_return_uses_every_original_hub_hop_and_seller_receipt_without_rewriting_the_successful_delivery(): void
    {
        $case = $this->approvedReturn();
        $order = $case->order->fresh();
        $delivery = $order->delivery;
        $before = [$order->getAttributes(), $delivery->getAttributes(), $delivery->checkpoints()->get()->map->getAttributes()->all()];
        $this->assertStringStartsWith('BRT-', $case->parcel->barcode);
        $this->assertNotSame($delivery->tracking_number, $case->parcel->barcode);
        $this->intake($case);
        $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
            'condition' => 'accepted', 'photo' => UploadedFile::fake()->image('early.png'),
        ]))->assertConflict();
        foreach (range(0, count($case->parcel->hub_ids) - 2) as $index) {
            $manifest = $this->leg($case);
            $this->assertSame($index, $manifest->buyer_return_leg_index);
        }
        $origin = LogisticsHub::findOrFail($case->parcel->hub_ids[count($case->parcel->hub_ids) - 1]);
        $this->actingAs($this->flowHandler($origin))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $origin->id]))->assertSessionHasNoErrors();
        $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
            'condition' => 'accepted', 'photo' => UploadedFile::fake()->image('received.png'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('seller_received', $case->fresh()->status);
        $this->assertSame(['kind' => 'seller', 'user_id' => $case->seller_id], $case->parcel->fresh()->custody);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $delivery->fresh()->getAttributes(), $delivery->checkpoints()->get()->map->getAttributes()->all()]);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertDatabaseCount('buyer_return_evidence', 3);
    }

    public function test_scanning_the_return_identifies_its_case_without_recording_custody_or_reusing_the_delivery(): void
    {
        $case = $this->approvedReturn();
        $parcel = $case->parcel;
        $hub = LogisticsHub::findOrFail($parcel->hub_ids[0]);
        $before = [$parcel->getAttributes(), $case->events()->count(), $case->order->delivery->getAttributes()];
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->barcode, 'hub_id' => $hub->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('confirmed', false)->assertJsonPath('return_case.href', '/buyer-returns/'.$case->id)
            ->assertJsonMissingPath('delivery');
        $this->assertSame($before, [$parcel->fresh()->getAttributes(), $case->events()->count(), $case->order->delivery->fresh()->getAttributes()]);
        $this->get('/buyer-returns/'.$case->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('returnCase.custody.barcode', $parcel->barcode)->where('returnCase.custody.actions.0.action', 'intake'));
    }

    public function test_floor_authority_expected_hub_original_waybill_and_late_dropoff_are_rechecked(): void
    {
        $case = $this->approvedReturn();
        $destination = LogisticsHub::findOrFail($case->parcel->hub_ids[0]);
        $origin = LogisticsHub::findOrFail($case->parcel->hub_ids[count($case->parcel->hub_ids) - 1]);
        $payload = $this->custodyPayload($case, 'intake', ['hub_id' => $destination->id, 'weight_kg' => '2.500', 'condition' => 'intact', 'photo' => UploadedFile::fake()->image('intake.png')]);
        $manager = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $this->actingAs($manager)->post('/buyer-returns/'.$case->id.'/custody', $payload)->assertForbidden();
        $this->actingAs($this->flowHandler($origin))->post('/buyer-returns/'.$case->id.'/custody', [...$payload, 'hub_id' => $origin->id])->assertUnprocessable();
        $handler = $this->flowHandler($destination);
        $this->actingAs($handler)->post('/buyer-returns/'.$case->id.'/custody', [...$payload, 'barcode' => $case->order->delivery->tracking_number])->assertUnprocessable();
        $this->travelTo($case->dropoff_until->copy()->addSecond());
        $this->post('/buyer-returns/'.$case->id.'/custody', $payload)->assertConflict();
        $this->assertSame('awaiting_dropoff', $case->parcel->fresh()->status);
        $this->assertSame(2, $case->events()->count());
        $this->assertDatabaseCount('buyer_return_evidence', 1);
    }

    public function test_the_exact_dropoff_deadline_is_allowed_and_unscanned_route_shortcuts_are_rejected(): void
    {
        $case = $this->approvedReturn();
        $this->travelTo($case->dropoff_until->copy());
        $this->intake($case);
        $origin = LogisticsHub::findOrFail($case->parcel->hub_ids[count($case->parcel->hub_ids) - 1]);
        $this->actingAs($this->flowHandler($origin))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $origin->id]))->assertConflict();
        $manifest = $this->prepareLeg($case);
        $this->actingAs(LogisticsCompany::findOrFail($manifest->logistics_company_id)->user)->get('/hub/manifests')->assertOk();
        $this->postJson('/hub/manifests', ['source_hub_id' => $manifest->source_hub_id, 'destination_hub_id' => $origin->id,
            'vehicle_id' => $manifest->vehicle_id, 'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id,
            'creation_token' => session()->get('manifest_creation_token')])->assertConflict();
        $this->assertSame(0, $case->parcel->fresh()->route_position);
        $this->assertSame('at_hub', $case->parcel->fresh()->status);
        $this->actingAs($case->order->buyer)->post('/buyer-returns/'.$case->id.'/actions', ['action' => 'withdraw', 'expected_version' => $case->fresh()->version,
            'request_token' => (string) Str::uuid(), 'reason' => 'An accepted physical parcel cannot be withdrawn from recorded custody.'])->assertForbidden();
    }

    public function test_departure_needs_a_real_load_and_current_weight_capacity_driver_and_vehicle_eligibility(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $manifest = $this->prepareLeg($case, 2.5);
        $source = LogisticsHub::findOrFail($manifest->source_hub_id);
        $manager = LogisticsCompany::findOrFail($manifest->logistics_company_id)->user;
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertConflict();
        $handler = $this->flowHandler($source);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $case->order->delivery->tracking_number]))->assertUnprocessable();
        $this->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $manifest->vehicle->update(['capacity_kg' => 2.49]);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertConflict();
        $manifest->vehicle->update(['capacity_kg' => 2.5, 'status' => 'maintenance']);
        $this->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertConflict();
        $manifest->vehicle->update(['status' => 'active']);
        $manifest->driver->update(['status' => 'suspended']);
        $this->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertConflict();
        $manifest->driver->update(['status' => 'active']);
        $this->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertSame('in_transit', $case->parcel->fresh()->status);
        $this->assertContains('buyer_return_custody', array_column(app(AccountClosureService::class)->state($manifest->driver)['blockers'], 'code'));
    }

    public function test_a_missing_return_stays_in_actual_custody_until_the_destination_records_its_receipt(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $manifest = $this->prepareLeg($case);
        $source = $this->flowHandler(LogisticsHub::findOrFail($manifest->source_hub_id));
        $destination = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $manager = LogisticsCompany::findOrFail($manifest->logistics_company_id)->user;
        $this->actingAs($source)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->actingAs($source)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $member = $manifest->buyerReturnParcels()->sole();
        $this->actingAs($destination)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest, [
            'kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'The expected return is not present at the receiving unloading bay.',
        ]))->assertOk();
        $observation = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $this->assertSame('manifest', $case->parcel->fresh()->custody['kind']);
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $this->flowManifestPayload($manifest, [
            'source_event_id' => $observation->id, 'notes' => 'A reason alone cannot substitute for the missing physical receipt.',
        ]))->assertConflict();
        $this->actingAs($destination)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertConflict();
        $this->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $this->flowManifestPayload($manifest, [
            'source_event_id' => $observation->id, 'notes' => 'The receiving handler located and scanned the actual expected return.',
        ]))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
    }

    public function test_a_failed_return_receipt_event_rolls_back_both_manifest_and_custody_then_retries_once(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $manifest = $this->prepareLeg($case);
        $source = $this->flowHandler(LogisticsHub::findOrFail($manifest->source_hub_id));
        $manager = LogisticsCompany::findOrFail($manifest->logistics_company_id)->user;
        $this->actingAs($source)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->actingAs($source)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $payload = $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]);
        DB::unprepared("CREATE TRIGGER fail_buyer_return_receipt BEFORE INSERT ON buyer_return_events WHEN NEW.event_type = 'buyer-return-received' BEGIN SELECT RAISE(ABORT, 'test'); END");
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id)))->postJson('/hub/manifests/'.$manifest->id.'/receive', $payload)->assertServerError();
        $this->assertSame('dispatched', $manifest->fresh()->status);
        $this->assertSame('in_transit', $case->parcel->fresh()->status);
        $this->assertNull($manifest->buyerReturnParcels()->sole()->received_at);
        DB::unprepared('DROP TRIGGER fail_buyer_return_receipt');
        $this->postJson('/hub/manifests/'.$manifest->id.'/receive', $payload)->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/receive', $payload)->assertOk();
        $this->assertSame(1, $case->events()->where('event_type', 'buyer-return-received')->count());
    }

    public function test_two_mother_hubs_remain_distinct_in_the_original_reverse_sequence(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $company = LogisticsCompany::where('code', 'ACCEPTANCE')->sole();
        $mother = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Cavite Return Mother Hub',
            'code' => 'RET-CAV-MH', 'tier' => 'regional_mother_hub', 'province' => 'Cavite', 'city_municipality' => 'Dasmariñas', 'address' => '100 Mother Hub Road', 'is_active' => true]);
        $destination = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Dasmariñas Return Bayan Hub',
            'code' => 'RET-CAV-BH', 'tier' => 'local_bayan_hub', 'province' => 'Cavite', 'city_municipality' => 'Dasmariñas', 'address' => '100 Bayan Hub Road', 'is_active' => true]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'ready_for_pickup',
            ['shipping_province' => 'Cavite', 'shipping_city' => 'Dasmariñas', 'shipping_postal_code' => '4114', 'destination_barangay' => 'Paliparan I']);
        $rider = $this->createApprovedUser('courier');
        $rider->courierProfile->update(['logistics_company_id' => $company->id, 'assigned_hub_id' => $destination->id,
            'assigned_barangay' => 'Paliparan I', 'is_available' => true]);
        $case = $this->approvedReturn($order, $rider);
        $this->assertCount(4, $case->parcel->hub_ids);
        $this->assertSame($mother->id, $case->parcel->hub_ids[1]);
        $this->intake($case);
        foreach (range(0, 2) as $index) {
            $manifest = $this->leg($case);
            $this->assertSame($index === 1 ? 'line_haul' : 'feeder', $manifest->type);
        }
        $this->assertSame('at_origin_hub', $case->fresh()->status);
        $this->assertSame(3, $case->parcel->fresh()->route_position);
    }

    public function test_reusing_the_endpoint_bayan_id_does_not_remove_either_mother_hub_leg(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'), ['city' => 'Santa Cruz']);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'ready_for_pickup');
        $case = $this->approvedReturn($order);
        $hubs = $case->parcel->hub_ids;
        $this->assertCount(3, $hubs);
        $this->assertSame($hubs[0], $hubs[2]);
        $this->assertNotSame($hubs[0], $hubs[1]);
        $this->intake($case);
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($hubs[0])))->post('/buyer-returns/'.$case->id.'/custody',
            $this->custodyPayload($case, 'stage', ['hub_id' => $hubs[0]]))->assertConflict();
        $this->leg($case);
        $this->leg($case);
        $this->assertSame(2, $case->parcel->fresh()->route_position);
        $this->assertSame('at_origin_hub', $case->fresh()->status);
        $this->assertSame(2, LogisticsManifest::where('direction', 'buyer_return')->count());
    }

    public function test_a_failed_intake_photo_record_leaves_no_new_file_or_physical_intake_and_allows_retry(): void
    {
        $case = $this->approvedReturn();
        $hub = LogisticsHub::findOrFail($case->parcel->hub_ids[0]);
        $input = $this->custodyPayload($case, 'intake', ['hub_id' => $hub->id, 'weight_kg' => '2.500', 'condition' => 'intact', 'photo' => UploadedFile::fake()->image('intake.png')]);
        $files = Storage::disk('local')->allFiles('buyer-return-evidence');
        DB::unprepared("CREATE TRIGGER fail_return_intake_photo BEFORE INSERT ON buyer_return_evidence WHEN NEW.purpose = 'intake' BEGIN SELECT RAISE(ABORT, 'test'); END");
        $this->actingAs($this->flowHandler($hub))->post('/buyer-returns/'.$case->id.'/custody', $input)->assertSessionHasErrors('custody');
        $this->assertSame('approved', $case->fresh()->status);
        $this->assertSame('awaiting_dropoff', $case->parcel->fresh()->status);
        $this->assertNull($case->parcel->fresh()->weight_grams);
        $this->assertSame($files, Storage::disk('local')->allFiles('buyer-return-evidence'));
        DB::unprepared('DROP TRIGGER fail_return_intake_photo');
        $this->post('/buyer-returns/'.$case->id.'/custody', $input)->assertSessionHasNoErrors();
        $this->assertSame('at_destination_hub', $case->fresh()->status);
    }

    public function test_seller_condition_disagreement_retains_actual_seller_custody_for_later_review(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        foreach (range(0, count($case->parcel->hub_ids) - 2) as $index) {
            $this->leg($case);
        }
        $origin = LogisticsHub::findOrFail($case->parcel->fresh()->current_hub_id);
        $this->actingAs($this->flowHandler($origin))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $origin->id]))->assertSessionHasNoErrors();
        $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
            'condition' => 'disputed', 'photo' => UploadedFile::fake()->image('disputed.png'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('condition_review', $case->fresh()->status);
        $this->assertSame('seller', $case->parcel->fresh()->custody['kind']);
        $this->assertSame('seller_received', $case->parcel->fresh()->status);
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertSame('delivered', $case->order->fresh()->status);
    }

    public function test_receipt_recording_may_cross_a_clock_second_and_its_retained_time_governs_the_window(): void
    {
        DeliveryCheckpoint::creating(function ($receipt) {
            if ($receipt->checkpoint_type === 'delivered') {
                $this->travel(1)->seconds();
            }
        });
        $case = $this->approvedReturn();
        $delivery = $case->order->delivery;
        $receipt = $delivery->checkpoints()->where('checkpoint_type', 'delivered')->sole();
        $this->assertTrue($receipt->created_at->greaterThan($delivery->delivered_at));
        $this->assertTrue($case->delivered_at->equalTo($receipt->created_at));
        $this->assertTrue($case->request_until->equalTo($receipt->created_at->copy()->addDays(7)));
    }

    public function test_a_mistyped_observed_code_needs_an_actual_receipt_handler_correction_and_company_review(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $manifest = $this->leg($case, false);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $manager = LogisticsCompany::findOrFail($manifest->logistics_company_id)->user;
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest, [
            'kind' => 'wrong_hub', 'barcode' => 'OBSERVED-MISTYPED-RETURN', 'notes' => 'The initial typed observed barcode needs checking against the actual received label.',
        ]))->assertOk();
        $source = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $data = ['source_event_id' => $source->id, 'barcode' => $case->parcel->barcode, 'notes' => 'The first code was a typing mistake; the original return receipt identifies the real parcel.'];
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/correct-discrepancy', $this->flowManifestPayload($manifest, $data))->assertForbidden();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/correct-discrepancy', $this->flowManifestPayload($manifest, $data))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $this->flowManifestPayload($manifest, [
            'source_event_id' => $source->id, 'notes' => 'The actual receiving scan and handler correction support resolving this recording mistake.',
        ]))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertSame('OBSERVED-MISTYPED-RETURN', $source->fresh()->barcode_scanned);
        $this->assertSame('at_hub', $case->parcel->fresh()->status);
        $this->assertSame('delivered', $case->order->fresh()->status);
    }

    public function test_additive_custody_migration_preserves_existing_manifest_records_and_database_guards(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_mother_hub');
        $tables = ['logistics_manifests', 'logistics_manifest_parcels', 'logistics_manifest_events', 'delivery_checkpoints'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $migration = require database_path('migrations/2026_10_19_060000_create_buyer_return_custody.php');
        $migration->down();
        $migration->up();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        foreach (['manifest_identity_retained', 'manifest_direction_retained', 'manifest_leg_type_retained', 'buyer_return_manifest_routes_no_update'] as $guard) {
            $this->assertTrue(DB::table('sqlite_master')->where('type', 'trigger')->where('name', $guard)->exists(), $guard);
        }
        $this->assertSame('arrived_at_mother_hub', $delivery->fresh()->status);
    }
}
