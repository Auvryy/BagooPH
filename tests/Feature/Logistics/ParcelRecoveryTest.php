<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\MotherBagParcel;
use App\Models\ParcelRecoveryCase;
use App\Models\ParcelRecoveryEvent;
use App\Models\User;
use App\Services\ExceptionOversightService;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\MisrouteTrapService;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Logistics\PublicTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use Throwable;

class ParcelRecoveryTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_scanning_at_a_wrong_hub_records_a_hold_and_recovers_through_the_mother_on_the_original_manifest(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'in_transit_to_mother_hub');
        $original = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $wrong = LogisticsHub::create(['logistics_company_id' => $parcel->logistics_company_id, 'code' => 'QA-WRONG-CHILD',
            'name' => 'Pagsanjan Child Hub', 'tier' => 'local_bayan_hub', 'parent_hub_id' => $parcel->origin_mother_hub_id,
            'province' => 'Laguna', 'city_municipality' => 'Pagsanjan', 'address' => '100 Test Warehouse Road', 'is_active' => true]);
        $handler = $this->flowHandler($wrong);
        $before = $parcel->getAttributes();
        $scan = ['barcode' => $parcel->tracking_number, 'hub_id' => $wrong->id, 'mode' => 'inspect'];
        $this->actingAs($handler)->postJson('/hub/scan', $scan)->assertConflict()->assertJsonPath('success', false);
        $this->postJson('/hub/scan', $scan)->assertConflict();
        $this->assertSame($before, $parcel->fresh()->getAttributes());
        $observation = $original->events()->where('payload->scanner_misroute', true)->sole();
        $this->assertSame($wrong->id, $observation->hub_id);
        $expectedHandler = $this->flowHandler($original->destinationHub);
        $this->openFlowMotherBags($original, $expectedHandler);
        $this->actingAs($expectedHandler)->postJson('/hub/manifests/'.$original->id.'/receive', $this->flowManifestPayload($original, ['barcode' => $parcel->tracking_number]))->assertConflict();
        $manager = $wrong->company->user;
        $case = $this->authorizeCase($observation, $manager);
        $this->assertSame([$wrong->id, $parcel->origin_mother_hub_id], $case->hub_ids);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->transfer($case->fresh(), $manager);
        $this->assertSame('completed', $case->fresh()->state);
        $this->assertSame($parcel->origin_mother_hub_id, $parcel->fresh()->current_hub_id);
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
        $this->assertNull(MotherBagParcel::where('delivery_id', $parcel->id)->sole()->active_delivery_id);
        $this->assertSame('received', $original->fresh()->status);
        app(MisrouteTrapService::class)->assertMovable($parcel->fresh());
    }

    public static function incidents(): array
    {
        return ['unexpected at expected hub' => ['in_transit_to_mother_hub', 'in_transit_to_mother_hub', 'Santa Cruz', '/hub'],
            'wrong Bayan to expected Mother' => ['in_transit_to_mother_hub', 'in_transit_to_destination_hub', 'Santa Cruz', '/hub'],
            'two Bayan hubs require Mother' => ['in_transit_to_destination_hub', 'in_transit_to_destination_hub', 'Los Banos', '/hub'],
            'subdomain recovery' => ['in_transit_to_mother_hub', 'in_transit_to_destination_hub', 'Santa Cruz', 'http://hub.localhost']];
    }

    #[DataProvider('incidents')]
    public function test_actual_recovery_restores_only_the_original_receipt_and_retains_both_manifest_histories(string $originalStage, string $observedStage, string $city, string $base): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident($originalStage, $observedStage, $city, $base);
        $original = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $originalRoute = $original->parcels()->sole()->route_snapshot;
        $originalState = $parcel->getRawOriginal();
        $case = $this->authorizeCase($observation, $manager, $base);
        $this->assertSame($originalState, $parcel->fresh()->getRawOriginal(), 'Authorization cannot invent a physical handover.');
        $intake = $this->casePayload($case, ['barcode' => $parcel->tracking_number]);
        $this->actingAs($handler)->postJson($base.'/parcel-recovery/'.$observation->id.'/intake', $intake)->assertOk();
        $this->get($base.'/deliveries?hub_id='.$observation->hub_id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.data.0.id', $parcel->id)->where('deliveries.data.0.guidance.action', $case->fresh()->state === 'completed' ? 'manifest' : 'recovery')
            ->where('deliveries.data.0.guidance.can_act', true));
        $after = $this->snapshot($parcel);
        $this->postJson($base.'/parcel-recovery/'.$observation->id.'/intake', $intake)->assertOk();
        $this->assertSame($after, $this->snapshot($parcel));
        $case->refresh();
        while ($case->state !== 'completed') {
            $this->transfer($case, $manager, $base);
            $case->refresh();
        }
        $parcel->refresh();
        $this->assertSame($original->destination_hub_id, $parcel->current_hub_id);
        $this->assertSame($original->destinationHub->isMotherHub() ? 'arrived_at_mother_hub' : 'arrived_at_destination_hub', $parcel->status);
        $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
        $this->assertSame($originalRoute, $original->parcels()->sole()->route_snapshot);
        $this->assertNull($case->active_delivery_id);
        $member = $original->parcels()->sole();
        $this->assertNotNull($member->received_at);
        $this->assertSame($this->flowHandler($original->destinationHub)->id, $member->received_by_id);
        $receipt = $original->events()->where('event_type', 'parcel_received')->sole();
        $this->assertSame($case->reference, $receipt->payload['parcel_recovery_reference']);
        $this->assertTrue($parcel->checkpoints()->where('manifest_number', $original->reference)->where('custody_after->manifest_scan_reference', $receipt->reference)->exists());
        $this->actingAs($manager)->postJson($base.'/manifests/'.$original->id.'/close', $this->flowManifestPayload($original))->assertOk();
        $completion = $observation->manifest->events()->where('event_type', 'recovery-completed')->sole();
        $this->assertSame($receipt->id, app(ParcelRecoveryService::class)->completionSupport($completion)?->id);
        $this->actingAs($manager)->postJson($base.'/manifests/'.$observation->manifest_id.'/resolve-discrepancy',
            $this->flowManifestPayload($observation->manifest, ['source_event_id' => $observation->id, 'notes' => 'Checked the actual original-hub receiving evidence.']))->assertOk();
        $this->assertSame($completion->id, $observation->manifest->events()->where('event_type', 'resolve-discrepancy')->sole()->payload['support_event_id']);
        $admin = $this->createApprovedUser('admin');
        $oversight = app(ExceptionOversightService::class)->detail($admin, 'manifest', $observation->id);
        $this->assertTrue($oversight['canResolve']);
        $this->assertSame(0, DB::table('commission_ledgers')->count());
        if ($city === 'Los Banos') {
            $this->assertCount(3, $case->hub_ids);
            $this->assertTrue(LogisticsHub::findOrFail($case->hub_ids[1])->isMotherHub());
            $this->assertSame(['feeder', 'feeder'], $case->manifests()->orderBy('id')->pluck('type')->all());
        }
    }

    public function test_unknown_observation_cannot_create_a_parcel_or_recovery_case(): void
    {
        [$parcel, $observation, $manager] = $this->incident();
        $manifest = $observation->manifest;
        $handler = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'extra', 'barcode' => 'UNKNOWN-PARCEL', 'notes' => 'A separate unknown label was found on arrival.']))->assertOk();
        $unknown = $manifest->events()->where('barcode_scanned', 'UNKNOWN-PARCEL')->sole();
        $before = $this->snapshot($parcel);
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$unknown->id.'/authorize',
            ['source_token' => str_repeat('a', 64), 'request_token' => (string) Str::uuid(), 'notes' => 'Investigate the original parcel before proceeding.'])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame(0, ParcelRecoveryCase::count());
    }

    public function test_company_authorization_cannot_replace_the_assigned_handler_intake_scan(): void
    {
        [$parcel, $observation, $manager] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $before = $this->snapshot($parcel);
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public static function invalidScans(): array
    {
        return ['missing barcode' => [['barcode' => '']], 'array barcode' => [['barcode' => ['BGO-OTHER']]],
            'control barcode' => [['barcode' => "\tBGO-OTHER\n"]], 'hidden barcode' => [['barcode' => "BGO-\u{200B}OTHER"]],
            'lookalike barcode' => [['barcode' => 'ＢＧＯ-OTHER']], 'wrong barcode' => [['barcode' => 'BGO-OTHER']],
            'long barcode' => [['barcode' => str_repeat('A', 256)]], 'empty notes' => [['notes' => '']],
            'markup notes' => [['notes' => '<b>Actual parcel</b>']], 'control notes' => [['notes' => "Received\0parcel"]],
            'long notes' => [['notes' => str_repeat('A', 1001)]], 'zero version' => [['version' => 0]],
            'float version' => [['version' => 1.0]], 'lookalike version' => [['version' => '１']],
            'invalid token' => [['request_token' => 'other']], 'array token' => [['request_token' => ['other']]]];
    }

    #[DataProvider('invalidScans')]
    public function test_invalid_intake_preserves_parcel_custody_and_all_original_history(array $invalid): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $before = $this->snapshot($parcel);
        $payload = array_replace($this->casePayload($case, ['barcode' => $parcel->tracking_number]), $invalid);
        $this->actingAs($handler)->call('POST', '/hub/parcel-recovery/'.$observation->id.'/intake', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public static function forbiddenActors(): array
    {
        return ['buyer' => ['buyer', 403], 'seller' => ['seller', 403], 'courier' => ['courier', 403],
            'admin floor bypass' => ['admin', 403], 'manager floor bypass' => ['manager', 403],
            'wrong facility' => ['wrong_handler', 404], 'foreign company' => ['foreign_manager', 404]];
    }

    #[DataProvider('forbiddenActors')]
    public function test_wrong_role_or_facility_cannot_receive_the_unexpected_parcel(string $role, int $status): void
    {
        [$parcel, $observation, $manager] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $actor = match ($role) {
            'manager' => $manager, 'wrong_handler' => $this->flowHandler(LogisticsHub::findOrFail($parcel->origin_bayan_hub_id)),
            'foreign_manager' => $this->createApprovedUser('logistics'), default => $this->createApprovedUser($role),
        };
        if ($role === 'foreign_manager') {
            LogisticsCompany::create(['user_id' => $actor->id, 'name' => 'Bagoo Other Network', 'slug' => 'other-recovery-network', 'code' => 'OTHER-REC', 'status' => 'active', 'is_active' => true]);
        }
        $before = $this->snapshot($parcel);
        $this->actingAs($actor)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertStatus($status);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_authorization_retries_conflicts_and_a_second_active_case_preserve_the_original_result(): void
    {
        [$parcel, $observation, $manager] = $this->incident();
        $proposal = app(ParcelRecoveryService::class)->proposal($manager, $observation);
        $payload = ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'notes' => 'Recover this actual parcel through its original route.'];
        $response = $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', $payload)->assertOk();
        $id = $response->json('case_id');
        $before = $this->snapshot($parcel);
        $this->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', $payload)->assertOk()->assertJsonPath('case_id', $id);
        $this->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', [...$payload, 'notes' => 'A different review reason is now submitted.'])->assertConflict();
        $this->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', [...$payload, 'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_changed_intake_retry_and_stale_competing_scan_cannot_transfer_custody_twice(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $payload = $this->casePayload($case, ['barcode' => $parcel->tracking_number]);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $payload)->assertOk();
        $before = $this->snapshot($parcel);
        $this->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', [...$payload, 'notes' => 'Change the original recorded receiving statement.'])->assertConflict();
        $this->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', [...$payload, 'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_actual_intake_blocks_ordinary_route_and_manual_discrepancy_shortcuts(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $original = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $case = $this->authorizeCase($observation, $manager);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->assertOwnedRecoveryTracking($parcel, 'Held for route recovery', LogisticsHub::findOrFail($observation->hub_id)->name);
        $before = $this->snapshot($parcel);
        $this->openFlowMotherBags($original, $this->flowHandler($original->destinationHub));
        $this->actingAs($this->flowHandler($original->destinationHub))->postJson('/hub/manifests/'.$original->id.'/receive', $this->flowManifestPayload($original, ['barcode' => $parcel->tracking_number]))->assertConflict();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$observation->manifest_id.'/resolve-discrepancy', $this->flowManifestPayload($observation->manifest, ['source_event_id' => $observation->id, 'notes' => 'A manager reason cannot replace receiving evidence.']))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_recovery_evidence_failure_rolls_back_actual_custody_and_original_membership(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident('in_transit_to_mother_hub', 'in_transit_to_mother_hub');
        $case = $this->authorizeCase($observation, $manager);
        $before = $this->snapshot($parcel);
        Event::listen('eloquent.creating: '.LogisticsManifestEvent::class, function ($event) use ($case) {
            if ($event->event_type === 'parcel_received' && ($event->payload['parcel_recovery_reference'] ?? null) === $case->reference) {
                throw new RuntimeException('Original receiving evidence unavailable.');
            }
        });
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertStatus(500);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_unused_authorization_can_be_withdrawn_without_changing_any_parcel_checkpoint(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $before = [$parcel->fresh()->getRawOriginal(), $parcel->checkpoints()->get()->toArray()];
        $payload = $this->casePayload($case);
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$observation->id.'/withdraw', $payload)->assertOk();
        $this->postJson('/hub/parcel-recovery/'.$observation->id.'/withdraw', $payload)->assertOk();
        $this->assertSame($before, [$parcel->fresh()->getRawOriginal(), $parcel->checkpoints()->get()->toArray()]);
        $this->assertSame('withdrawn', $case->fresh()->state);
        $this->assertNull($case->fresh()->active_delivery_id);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertConflict();
    }

    public function test_retained_case_and_event_identity_reject_model_and_direct_database_mutation(): void
    {
        [$parcel, $observation, $manager] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $event = $case->events()->sole();
        foreach ([fn () => $case->update(['reason' => 'Rewrite the original authorization.']), fn () => $case->delete(),
            fn () => $event->update(['reason' => 'Replace original evidence.']), fn () => $event->delete(),
            fn () => DB::table('parcel_recovery_cases')->where('id', $case->id)->update(['hub_ids' => '[999]']),
            fn () => DB::table('parcel_recovery_cases')->where('id', $case->id)->delete(),
            fn () => DB::table('parcel_recovery_events')->where('id', $event->id)->update(['reason' => 'Replace original evidence.']),
            fn () => DB::table('parcel_recovery_events')->where('id', $event->id)->delete()] as $mutation) {
            $caught = null;
            try {
                $mutation();
            } catch (Throwable $error) {
                $caught = $error;
            }
            $this->assertNotNull($caught);
        }
        $this->assertSame('Recover the actual parcel through its original expected hub.', $case->fresh()->reason);
        $this->assertSame(1, $case->events()->count());
    }

    public function test_worklist_is_scoped_and_public_tracking_exposes_only_safe_real_recovery_milestones(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->actingAs($manager)->get('/hub/parcel-recovery/'.$observation->id)->assertInertia(fn (Assert $page) => $page
            ->component('Hub/ParcelRecovery')->where('selectedObservation.case.state', 'held')->where('selectedObservation.canIntake', false)
            ->where('selectedObservation.case.canPrepareTransfer', true)->where('selectedObservation.expectedHub', $case->originalMember->manifest->destinationHub->name));
        $this->actingAs($handler)->get('/hub/parcel-recovery/'.$observation->id)->assertInertia(fn (Assert $page) => $page
            ->component('Hub/ParcelRecovery')->where('selectedObservation.case.originalManifestUrl', null)
            ->where('selectedObservation.case.canPrepareTransfer', true));
        $expectedHandler = $this->flowHandler($case->originalMember->manifest->destinationHub);
        $this->actingAs($expectedHandler)->get('/hub/parcel-recovery/'.$observation->id)->assertOk();
        $this->actingAs($this->createApprovedUser('logistics'))->get('/hub/parcel-recovery/'.$observation->id)->assertNotFound();
        $tracking = app(PublicTrackingService::class)->find($parcel->tracking_number);
        $this->assertSame('at_sorting_center', $tracking['status']);
        $milestone = collect($tracking['checkpoints'])->firstWhere('checkpoint_type', 'parcel_recovery_intake');
        $this->assertSame('Parcel received for route recovery', $milestone['location_name']);
        $this->assertSame(['checkpoint_type', 'location_name', 'created_at'], array_keys($milestone));
        $admin = $this->createApprovedUser('admin');
        $detail = app(ExceptionOversightService::class)->detail($admin, 'manifest', $observation->id);
        $this->assertFalse($detail['canResolve']);
        $this->assertSame('/hub/parcel-recovery/'.$observation->id, $detail['recoveryUrl']);
    }

    public function test_manifest_discrepancy_links_require_the_viewers_recovery_scope(): void
    {
        [$parcel, $observation, $manager, $receiver] = $this->incident();
        $manifest = $observation->manifest;
        $sender = $this->flowHandler($manifest->sourceHub);
        $this->actingAs($sender)->get('/hub/manifests/'.$manifest->id)->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Manifests')->where('selectedManifest.discrepancies.0.recoveryUrl', '/hub/parcel-recovery/'.$observation->id));
        $this->assertTrue($manifest->sourceHub->isMotherHub());
        $this->get('/hub/parcel-recovery/'.$observation->id)->assertOk();
        $unrelatedBayan = $this->flowHandler(LogisticsHub::findOrFail($parcel->origin_bayan_hub_id));
        $this->actingAs($unrelatedBayan)->get('/hub/parcel-recovery/'.$observation->id)->assertNotFound();
        $this->actingAs($receiver)->get('/hub/manifests/'.$manifest->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedManifest.discrepancies.0.recoveryUrl', '/hub/parcel-recovery/'.$observation->id));

        $case = $this->authorizeCase($observation, $manager);
        $this->assertContains($manifest->source_hub_id, $case->hub_ids);
        $this->actingAs($sender)->get('/hub/manifests/'.$manifest->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedManifest.discrepancies.0.recoveryUrl', '/hub/parcel-recovery/'.$observation->id));
        $this->get('/hub/parcel-recovery/'.$observation->id)->assertOk();
    }

    public function test_recovery_attention_follows_the_next_receiver_and_intermediate_custody(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident('in_transit_to_destination_hub', 'in_transit_to_destination_hub', 'Los Banos');
        $case = $this->authorizeCase($observation, $manager);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $manifest = $this->dispatchTransfer($case->fresh(), $manager);
        $motherHandler = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($motherHandler)->withSession(['active_hub_id' => $manifest->destination_hub_id])
            ->get(route('hub.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('attentionQueue.4.key', 'parcel_recovery')->where('attentionQueue.4.count', 1));
        $this->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->get(route('hub.dashboard'))->assertInertia(fn (Assert $page) => $page->where('attentionQueue.4.count', 1));

        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->transfer($case->fresh(), $manager);
        $this->actingAs($motherHandler)->withSession(['active_hub_id' => $manifest->destination_hub_id])
            ->get(route('hub.dashboard'))->assertInertia(fn (Assert $page) => $page->where('attentionQueue.4.count', 1));
        $this->actingAs($handler)->withSession(['active_hub_id' => $case->observed_hub_id])
            ->get(route('hub.dashboard'))->assertInertia(fn (Assert $page) => $page->where('attentionQueue.4.count', 1));
    }

    public function test_recovered_mother_hub_receipt_allows_normal_delivery_and_only_the_buyer_completes_it(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $original = $case->originalMember->manifest;
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->transfer($case->fresh(), $manager);
        $this->actingAs($manager)->postJson('/hub/manifests/'.$original->id.'/close', $this->flowManifestPayload($original))->assertOk();
        $mother = LogisticsHub::findOrFail($parcel->fresh()->current_hub_id);
        $this->confirmFlowScan($parcel, $mother, 'SORT_TO_LINE_HAUL');
        $this->confirmFlowManifest($parcel, $mother, true);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->confirmFlowManifest($parcel, $destination, false);
        $this->actingAs($this->flowHandler($destination))->postJson(route('hub.sort'), ['delivery_id' => $parcel->id, 'barangay' => $parcel->order->destination_barangay])->assertOk();
        $rider = $this->flowRider($destination);
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $rider->id])->assertOk();
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), ['status' => 'out_for_delivery', 'barcode' => $parcel->tracking_number])->assertSessionHas('success');
        Storage::fake('local');
        Storage::fake('public');
        $this->patch(route('courier.updateStatus', $parcel), ['status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('proof.jpg'), ...$this->codCollectionInput($parcel)])->assertSessionHas('success');
        $this->assertSame('completed', $parcel->order->fresh()->status);
        $this->completeFlowOrder($parcel->order->fresh());
        $this->assertSame('paid', $parcel->order->fresh()->payment_status);
    }

    public function test_mother_to_mother_recovery_uses_actual_line_haul_without_replacing_the_original_route(): void
    {
        $this->newFlowOrder();
        $mother = LogisticsHub::create(['logistics_company_id' => $this->flowCompany->id, 'name' => 'Cavite Mother Hub',
            'code' => 'REC-CAV-MH', 'tier' => 'regional_mother_hub', 'province' => 'Cavite',
            'city_municipality' => 'Dasmariñas', 'address' => '100 Cavite Hub Road', 'is_active' => true]);
        LogisticsHub::create(['logistics_company_id' => $this->flowCompany->id, 'name' => 'Dasmariñas Bayan Hub',
            'code' => 'REC-CAV-BH', 'tier' => 'local_bayan_hub', 'province' => 'Cavite',
            'city_municipality' => 'Dasmariñas', 'address' => '100 Bayan Hub Road', 'is_active' => true]);
        $order = $this->newFlowOrder('placed', ['shipping_province' => 'Cavite', 'shipping_city' => 'Dasmariñas',
            'shipping_postal_code' => '4114', 'destination_barangay' => 'Paliparan I']);
        $parcel = $this->flowDelivery($order, 'sorted_to_line_haul');
        $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->origin_mother_hub_id), true);
        [$observation, $manager, $handler] = $this->observe($parcel, 'in_transit_to_mother_hub');
        $case = $this->authorizeCase($observation, $manager);
        $originalRoute = $case->route_snapshot;
        $this->assertSame([$parcel->origin_mother_hub_id, $mother->id], $case->hub_ids);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $manifest = $this->transfer($case->fresh(), $manager);
        $this->assertSame('line_haul', $manifest->type);
        $this->assertSame('wing_truck', $manifest->vehicle->vehicle_type);
        $this->assertSame('completed', $case->fresh()->state);
        $this->assertSame($mother->id, $parcel->fresh()->current_hub_id);
        $this->assertSame($originalRoute, $case->originalMember->fresh()->route_snapshot);
        $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
    }

    public function test_only_the_owning_company_can_authorize_and_another_observation_cannot_duplicate_active_recovery(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $proposal = app(ParcelRecoveryService::class)->proposal($manager, $observation);
        $payload = ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'notes' => 'Review the actual parcel and its original receiving hub.'];
        $before = $this->snapshot($parcel);
        foreach ([$handler, $this->createApprovedUser('admin')] as $actor) {
            $this->actingAs($actor)->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', $payload)->assertForbidden();
            $this->assertSame($before, $this->snapshot($parcel));
        }
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', $payload)->assertOk();
        [$otherObservation] = $this->observe($parcel, 'in_transit_to_destination_hub', 'Los Banos');
        $before = $this->snapshot($parcel);
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$otherObservation->id.'/authorize', [...$payload, 'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame(1, ParcelRecoveryCase::count());
    }

    public function test_final_recovery_receipt_failure_rolls_back_the_transfer_and_can_be_safely_retried(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $manifest = $this->dispatchTransfer($case->fresh(), $manager);
        $before = $this->snapshot($parcel);
        $failed = false;
        Event::listen('eloquent.creating: '.ParcelRecoveryEvent::class, function ($event) use (&$failed) {
            if ($event->event_type === 'completed' && ! $failed) {
                $failed = true;
                throw new RuntimeException('Final recovery evidence unavailable.');
            }
        });
        $payload = $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]);
        $this->actingAs($this->flowHandler($manifest->destinationHub))->postJson('/hub/manifests/'.$manifest->id.'/receive', $payload)->assertStatus(500);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/hub/manifests/'.$manifest->id.'/receive', $payload)->assertOk();
        $after = $this->snapshot($parcel);
        $this->postJson('/hub/manifests/'.$manifest->id.'/receive', $payload)->assertOk();
        $this->assertSame($after, $this->snapshot($parcel));
        $this->assertSame('completed', $case->fresh()->state);
    }

    public function test_recovery_manifest_cannot_skip_a_mother_hub_or_duplicate_the_recorded_leg(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident('in_transit_to_destination_hub', 'in_transit_to_destination_hub', 'Los Banos');
        $case = $this->authorizeCase($observation, $manager);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        $manifest = $this->prepareTransfer($case->fresh(), $manager);
        $this->actingAs($manager)->get('/hub/manifests')->assertOk();
        $input = ['source_hub_id' => $manifest->source_hub_id, 'destination_hub_id' => $case->expected_hub_id,
            'vehicle_id' => $manifest->vehicle_id, 'direction' => 'recovery', 'parcel_recovery_case_id' => $case->id,
            'creation_token' => session()->get('manifest_creation_token')];
        $before = $this->snapshot($parcel);
        $this->postJson('/hub/manifests', $input)->assertConflict();
        $this->postJson('/hub/manifests', [...$input, 'destination_hub_id' => $manifest->destination_hub_id])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame(1, $case->manifests()->count());
    }

    public function test_unused_migration_rollback_and_reinstall_preserve_existing_manifest_history_guards(): void
    {
        $migration = require base_path('database/migrations/2026_10_10_200000_create_parcel_recovery_cases.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('parcel_recovery_cases'));
        $this->assertFalse(Schema::hasColumn('logistics_manifests', 'parcel_recovery_case_id'));
        $this->assertNotNull(DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = 'manifest_identity_retained'"));
        $migration->up();
        $this->assertTrue(Schema::hasTable('parcel_recovery_cases'));
        $this->assertNotNull(DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = 'manifest_leg_type_retained'"));
    }

    public function test_stale_review_and_changed_route_cannot_authorize_or_receive_recovery(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $proposal = app(ParcelRecoveryService::class)->proposal($manager, $observation);
        $original = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $this->openFlowMotherBags($original, $this->flowHandler($original->destinationHub));
        $this->actingAs($this->flowHandler($original->destinationHub))->postJson('/hub/manifests/'.$original->id.'/receive', $this->flowManifestPayload($original, ['barcode' => $parcel->tracking_number]))->assertOk();
        $before = $this->snapshot($parcel);
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'notes' => 'This review was prepared before the actual receiving scan.'])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_current_restriction_blocks_a_previously_authorized_handler_without_custody_change(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $this->restrictFlowAccount($handler, 'suspend');
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_reported_route_cannot_change_between_authorization_and_physical_intake(): void
    {
        [$parcel, $observation, $manager, $handler] = $this->incident();
        $case = $this->authorizeCase($observation, $manager);
        $parcel->update(['destination_bayan_hub_id' => $parcel->origin_bayan_hub_id]);
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_migration_cannot_roll_back_used_recovery_and_preserves_existing_manifest_guards(): void
    {
        [$parcel, $observation, $manager] = $this->incident();
        $this->authorizeCase($observation, $manager);
        $migration = require base_path('database/migrations/2026_10_10_200000_create_parcel_recovery_cases.php');
        $caught = null;
        try {
            $migration->down();
        } catch (Throwable $error) {
            $caught = $error;
        }
        $this->assertInstanceOf(\LogicException::class, $caught);
        $this->assertNotNull(DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = 'manifest_identity_retained'"));
        $this->assertNotNull(DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = 'manifest_leg_type_retained'"));
    }

    public function test_reverse_recovery_preserves_the_original_return_route_and_can_continue_to_seller_receipt(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->destination_bayan_hub_id), true, direction: 'return');
        [$observation, $manager, $handler] = $this->observe($parcel, 'in_transit_to_destination_hub', 'Los Banos');
        $original = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel->fresh())['manifest_id']);
        $route = app(DeliveryReturnService::class)->route($parcel);
        $case = $this->authorizeCase($observation, $manager);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $this->casePayload($case, ['barcode' => $parcel->tracking_number]))->assertOk();
        while ($case->fresh()->state !== 'completed') {
            $this->transfer($case->fresh(), $manager);
        }
        $this->actingAs($manager)->postJson('/hub/manifests/'.$original->id.'/close', $this->flowManifestPayload($original))->assertOk();
        $this->assertSame(1, app(DeliveryReturnService::class)->position($parcel->fresh(), $route));
        $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->fresh()->current_hub_id), true, direction: 'return');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->confirmFlowManifest($parcel, $origin, false, direction: 'return');
        $this->assertTrue(app(DeliveryReturnService::class)->readyForSeller($parcel->fresh()));
        $this->actingAs($this->flowHandler($origin))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $origin->id, 'mode' => 'confirm', 'action' => 'STAGE_SELLER_RETURN', 'expected_status' => 'return_to_sender', 'route_reference' => $route->reference])->assertOk();
        $this->actingAs($parcel->order->shop->user)->postJson('/seller/orders/'.$parcel->order_id.'/return-receipt',
            ['barcode' => $parcel->tracking_number, 'route_reference' => $route->reference, 'notes' => 'Received the original parcel after its actual reverse journey.', 'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertSame('returned', $parcel->order->fresh()->status);
        $this->assertSame($route->hub_ids, app(DeliveryReturnService::class)->route($parcel->fresh())->hub_ids);
    }

    private function incident(string $originalStage = 'in_transit_to_mother_hub', string $observedStage = 'in_transit_to_destination_hub', string $city = 'Santa Cruz', string $base = '/hub'): array
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $originalStage);
        [$observation, $manager, $handler] = $this->observe($parcel, $observedStage, $city, $base);

        return [$parcel->fresh(), $observation, $manager, $handler];
    }

    private function observe(Delivery $parcel, string $observedStage, string $city = 'Santa Cruz', string $base = '/hub'): array
    {
        if ($city === 'Los Banos') {
            // A different origin keeps this a regional transfer under the local-bypass contract.
            $shop = $this->createE2EShop($this->createApprovedUser('seller'), ['city' => 'Santa Cruz']);
            $otherOrder = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, shipping: ['shipping_city' => $city, 'destination_barangay' => 'Bambang']);
        } else {
            $otherOrder = $this->newFlowOrder('placed', ['shipping_city' => $city, 'destination_barangay' => 'Bambang']);
        }
        $other = $this->flowDelivery($otherOrder, $observedStage);
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($other)['manifest_id']);
        $manager = User::findOrFail($manifest->company->user_id);
        $handler = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($handler)->postJson($base.'/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => $parcel->status === $observedStage && $observedStage === 'in_transit_to_mother_hub' ? 'extra' : 'wrong_hub',
                'barcode' => $parcel->tracking_number, 'notes' => 'The actual unexpected parcel was found during this arrival.']))->assertOk();
        $observation = $manifest->events()->where('event_type', 'report-discrepancy')->sole();

        return [$observation, $manager, $handler];
    }

    private function authorizeCase(LogisticsManifestEvent $observation, User $manager, string $base = '/hub'): ParcelRecoveryCase
    {
        $proposal = app(ParcelRecoveryService::class)->proposal($manager, $observation);
        $response = $this->actingAs($manager)->postJson($base.'/parcel-recovery/'.$observation->id.'/authorize',
            ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'notes' => 'Recover the actual parcel through its original expected hub.'])->assertOk();

        return ParcelRecoveryCase::findOrFail($response->json('case_id'));
    }

    private function transfer(ParcelRecoveryCase $case, User $manager, string $base = '/hub'): LogisticsManifest
    {
        $manifest = $this->dispatchTransfer($case, $manager, $base);
        $receiver = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($receiver)->postJson($base.'/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $case->delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson($base.'/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();

        return $manifest->fresh();
    }

    private function dispatchTransfer(ParcelRecoveryCase $case, User $manager, string $base = '/hub'): LogisticsManifest
    {
        $manifest = $this->prepareTransfer($case, $manager, $base);
        $sender = $this->flowHandler($manifest->sourceHub);
        $this->actingAs($sender)->postJson($base.'/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $case->delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson($base.'/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->actingAs($sender)->postJson($base.'/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertOwnedRecoveryTracking($case->delivery, 'Travelling on a recovery route', 'Hub transport (handover recorded)');

        return $manifest->fresh();
    }

    private function assertOwnedRecoveryTracking(Delivery $parcel, string $stage, string $holder): void
    {
        $previousActor = auth()->user();
        $parcel = $parcel->fresh(['order.buyer', 'order.shop.user']);
        $before = $this->snapshot($parcel);
        $label = $parcel->order->status === 'delivery_failed' ? 'Delivery issue' : 'Moving through the hub network';
        $buyerStarted = now()->timestamp;
        $response = $this->actingAs($parcel->order->buyer)->get('http://localhost/buyer/orders/'.$parcel->order_id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.tracking.status_label', $label)
                ->where('order.tracking.stage_label', $stage)->where('order.tracking.custodian', $holder)
                ->where('order.tracking.notice', null)->where('order.tracking.responsible', 'Logistics company and assigned handlers')
                ->missing('order.delivery.latest_custody_checkpoint'));
        $tracking = $response->inertiaProps('order.tracking');
        $this->assertGreaterThanOrEqual($buyerStarted, strtotime($tracking['observed_at']));
        $this->assertLessThanOrEqual(now()->timestamp, strtotime($tracking['observed_at']));
        $sellerStarted = now()->timestamp;
        $sellerResponse = $this->actingAs($parcel->order->shop->user)->withSession(['active_seller_shop_id' => $parcel->order->shop->id])
            ->get('http://localhost/seller/orders')->assertOk()->assertInertia(function (Assert $page) use ($tracking) {
                foreach ($tracking as $field => $value) {
                    if ($field !== 'observed_at') {
                        $page->where('orders.data.0.tracking.'.$field, $value);
                    }
                }
            });
        $sellerObserved = strtotime($sellerResponse->inertiaProps('orders.data.0.tracking.observed_at'));
        $this->assertGreaterThanOrEqual($sellerStarted, $sellerObserved);
        $this->assertLessThanOrEqual(now()->timestamp, $sellerObserved);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->actingAs($previousActor);
    }

    private function prepareTransfer(ParcelRecoveryCase $case, User $manager, string $base = '/hub'): LogisticsManifest
    {
        $source = LogisticsHub::findOrFail($case->hub_ids[$case->leg_index]);
        $destination = LogisticsHub::findOrFail($case->hub_ids[$case->leg_index + 1]);
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $case->logistics_company_id, 'hub_id' => $source->id,
            'plate_number' => 'REC-'.strtoupper(Str::random(6)), 'vehicle_type' => $source->isMotherHub() && $destination->isMotherHub() ? 'wing_truck' : 'l300_van',
            'model' => 'Bagoo Recovery Vehicle', 'capacity_kg' => 10000, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $case->logistics_company_id, 'assigned_hub_id' => $source->id,
            'vehicle_id' => $vehicle->id, 'is_available' => true]);
        $this->actingAs($manager)->get($base.'/parcel-recovery/'.$case->observation_event_id)->assertOk();
        $response = $this->postJson($base.'/manifests', ['source_hub_id' => $source->id, 'destination_hub_id' => $destination->id,
            'vehicle_id' => $vehicle->id, 'direction' => 'recovery', 'parcel_recovery_case_id' => $case->id, 'creation_token' => session()->get('manifest_creation_token')])->assertCreated();

        return LogisticsManifest::findOrFail($response->json('manifest.id'));
    }

    private function casePayload(ParcelRecoveryCase $case, array $fields = []): array
    {
        return ['version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(), 'notes' => 'Scanned the actual parcel at this recorded facility.', ...$fields];
    }

    private function snapshot(Delivery $parcel): array
    {
        return [$parcel->fresh()->getRawOriginal(), $parcel->order->fresh()->getRawOriginal(), $parcel->checkpoints()->get()->toArray(),
            ParcelRecoveryCase::get()->toArray(), ParcelRecoveryEvent::get()->toArray(), LogisticsManifestEvent::get()->toArray(),
            LogisticsManifest::get()->toArray(), LogisticsManifestParcel::get()->toArray()];
    }
}
