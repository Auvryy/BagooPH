<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HandoverIssue;
use App\Models\HandoverManifestReceipt;
use App\Models\HandoverRecoveryGrant;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\ParcelRecoveryCase;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Logistics\HandoverIssueService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Logistics\PublicTrackingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class HubHandoverRecoveryTest extends TestCase
{
    // Missed historical manifest receipts keep their original individual-scan evidence.
    protected function flowContractVersion(): int
    {
        return 1;
    }

    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function reviewer(Delivery $parcel): User
    {
        return LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
    }

    private function held(Delivery $parcel, ?User $reporter = null, ?User $reviewer = null): HandoverIssue
    {
        $this->actingAs($reporter ?? $parcel->order->shop->user)->postJson('/handover-issues', ['barcode' => $parcel->tracking_number,
            'kind' => 'missed_scan', 'reported_holder' => 'The known parcel holder', 'reported_location' => 'The actual receiving facility',
            'notes' => 'The parcel was physically handed over without the expected hub or manifest scan.',
            'evidence' => UploadedFile::fake()->image('handover.png'), 'request_token' => (string) Str::uuid()])->assertOk();
        $issue = HandoverIssue::where('active_delivery_id', $parcel->id)->sole();
        $view = $this->actingAs($reviewer ?? $this->reviewer($parcel))->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/review', ['decision' => 'confirm', 'notes' => 'The original physical observation and private evidence were independently verified.',
            'evidence_reviewed' => true, 'expected_version' => $view['version'], 'source_token' => $view['source_token'],
            'request_token' => (string) Str::uuid()])->assertOk();

        return $issue->fresh()->load('delivery.order');
    }

    private function proposalInput(HandoverIssue $issue, string $mode, ?int $reference, User $holder, ?User $reviewer = null): array
    {
        $view = $this->actingAs($reviewer ?? $this->reviewer($issue->delivery))->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $option = collect($view['hub_recovery']['options'])->first(fn ($option) => $option['mode'] === $mode && $option['reference_id'] === $reference);
        $this->assertNotNull($option, 'Expected supported source option: '.$mode);

        return ['mode' => $mode, 'reference_id' => $reference, 'holder_id' => $holder->id, 'holder_verified' => true, 'evidence_reviewed' => true,
            'notes' => 'I independently checked the exact physical holder and original transport source.', 'expected_version' => $view['version'],
            'source_token' => $option['source_token'], 'request_token' => (string) Str::uuid()];
    }

    private function authorize(HandoverIssue $issue, string $mode, ?int $reference, User $holder, ?User $reviewer = null): HandoverRecoveryGrant
    {
        $input = $this->proposalInput($issue, $mode, $reference, $holder, $reviewer);
        $response = $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', $input)->assertOk();
        $grant = HandoverRecoveryGrant::findOrFail($response->json('id'));
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', $input)->assertOk()->assertJsonPath('id', $grant->id);

        return $grant;
    }

    private function snapshot(Delivery $parcel): array
    {
        $snapshot = ['parcel' => $parcel->fresh()->getRawOriginal(), 'order' => $parcel->order->fresh()->getRawOriginal()];
        foreach (['order_items', 'products', 'handover_issues', 'handover_issue_events', 'handover_recovery_grants', 'handover_manifest_receipts',
            'delivery_checkpoints', 'delivery_attempts', 'delivery_recovery_events', 'delivery_return_routes',
            'logistics_manifests', 'logistics_manifest_parcels', 'logistics_manifest_events', 'parcel_recovery_cases', 'parcel_recovery_events',
            'cod_accounts', 'cod_custody_entries'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function payload(HandoverIssue $issue, User $actor, array $extra = []): array
    {
        $view = $this->actingAs($actor)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $grant = $view['hub_recovery']['grant'];
        $this->assertNotEmpty($grant['source_token'], $grant['blocker'] ?? 'The current grant must have a usable source.');

        return array_replace(['notes' => 'I actually checked and received this exact physical parcel at the named facility.',
            'expected_version' => $view['version'], 'source_token' => $grant['source_token'], 'barcode' => $issue->delivery->tracking_number,
            'possession_verified' => true, 'request_token' => (string) Str::uuid()], $extra);
    }

    private function prepare(Delivery $parcel, bool $load = false, bool $seal = false): LogisticsManifest
    {
        $source = $parcel->currentHub;
        $target = app(LogisticsManifestService::class)->nextHub($parcel);
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $parcel->logistics_company_id, 'hub_id' => $source->id,
            'plate_number' => 'HUB-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT),
            'vehicle_type' => $source->isMotherHub() && LogisticsHub::findOrFail($target)->isMotherHub() ? 'wing_truck' : 'l300_van',
            'model' => 'Bagoo Recovery Transport', 'capacity_kg' => 1000, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $parcel->logistics_company_id, 'assigned_hub_id' => $source->id,
            'vehicle_id' => $vehicle->id, 'is_available' => true]);
        $this->actingAs($this->reviewer($parcel))->get('/hub/manifests')->assertOk();
        $response = $this->postJson('/hub/manifests', ['source_hub_id' => $source->id, 'destination_hub_id' => $target,
            'vehicle_id' => $vehicle->id, 'direction' => app(LogisticsManifestService::class)->direction($parcel),
            'creation_token' => session()->get('manifest_creation_token')])->assertCreated();
        $manifest = LogisticsManifest::findOrFail($response->json('manifest.id'));
        if ($load) {
            $this->actingAs($this->flowHandler($source))->postJson('/hub/manifests/'.$manifest->id.'/load',
                $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        }
        if ($seal) {
            $this->actingAs($this->reviewer($parcel))->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        }

        return $manifest->fresh();
    }

    private function observe(Delivery $parcel, string $stage): LogisticsManifestEvent
    {
        $other = $this->flowDelivery($this->newFlowOrder(), $stage);
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($other)['manifest_id']);
        $this->actingAs($this->flowHandler($manifest->destinationHub))->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy',
            $this->flowManifestPayload($manifest, ['kind' => $stage === 'in_transit_to_mother_hub' ? 'extra' : 'wrong_hub',
                'barcode' => $parcel->tracking_number, 'notes' => 'The known physical parcel was observed during this actual receiving process.']))->assertOk();

        return $manifest->events()->where('event_type', 'report-discrepancy')->sole();
    }

    private function prepareRecoveryTransfer(ParcelRecoveryCase $case): LogisticsManifest
    {
        $parcel = $case->delivery->fresh(['order']);
        $source = $parcel->currentHub;
        $target = app(ParcelRecoveryService::class)->nextHub($case->fresh(), $parcel);
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $parcel->logistics_company_id, 'hub_id' => $source->id,
            'plate_number' => 'HRT-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT),
            'vehicle_type' => $source->isMotherHub() && LogisticsHub::findOrFail($target)->isMotherHub() ? 'wing_truck' : 'l300_van',
            'model' => 'Bagoo Actual Recovery Transfer', 'capacity_kg' => 1000, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $parcel->logistics_company_id, 'assigned_hub_id' => $source->id,
            'vehicle_id' => $vehicle->id, 'is_available' => true]);
        $this->actingAs($this->reviewer($parcel))->get('/hub/parcel-recovery/'.$case->observation_event_id)->assertOk();
        $response = $this->postJson('/hub/manifests', ['source_hub_id' => $source->id, 'destination_hub_id' => $target,
            'direction' => 'recovery', 'parcel_recovery_case_id' => $case->id, 'vehicle_id' => $vehicle->id,
            'creation_token' => session()->get('manifest_creation_token')])->assertCreated();

        return LogisticsManifest::findOrFail($response->json('manifest.id'));
    }

    private function receiveManifestCorrection(Delivery $parcel, LogisticsManifest $manifest): array
    {
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->payload($issue, $this->flowHandler($manifest->destinationHub)))->assertOk();
        $this->actingAs($this->reviewer($parcel))->postJson('/hub/manifests/'.$manifest->id.'/close-recovery',
            $this->flowManifestPayload($manifest, ['notes' => 'The actual corrective receipts support every original receiving obligation.']))->assertOk();
        $parcel->refresh()->load('order');

        return [$issue, $grant];
    }

    private function finishToFinalMile(Delivery $parcel): User
    {
        $mother = $parcel->fresh()->currentHub;
        $this->confirmFlowScan($parcel, $mother, 'SORT_TO_LINE_HAUL');
        $this->confirmFlowManifest($parcel, $mother, true);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->confirmFlowManifest($parcel, $destination, false);
        $this->actingAs($this->flowHandler($destination))->postJson(route('hub.sort'),
            ['delivery_id' => $parcel->id, 'barangay' => $parcel->order->destination_barangay])->assertOk();
        $rider = $this->flowRider($destination);
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $rider->id])->assertOk();
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel),
            ['status' => 'out_for_delivery', 'barcode' => $parcel->tracking_number])->assertSessionHas('success');
        $parcel->refresh()->load('order');

        return $rider;
    }

    public static function manifestSources(): array
    {
        return ['missed load' => ['unlisted'], 'missed seal/departure' => ['draft'], 'missed departure' => ['sealed'], 'missed receipt' => ['dispatched']];
    }

    #[DataProvider('manifestSources')]
    public function test_actual_corrective_receipt_retains_original_manifest_history_and_can_resume_through_the_mother_hub(string $stage): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, $stage !== 'unlisted', in_array($stage, ['sealed', 'dispatched'], true));
        if ($stage === 'dispatched') {
            $this->actingAs($this->flowHandler($manifest->sourceHub))->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
            $parcel->refresh()->load('order');
        }
        $retainedFields = array_fill_keys(['status', 'sealed_at', 'dispatched_at', 'received_at'], true);
        $beforeManifest = array_intersect_key($manifest->fresh()->getRawOriginal(), $retainedFields);
        $beforeMembers = $manifest->parcels()->get()->map(fn ($member) => $member->only(['id', 'included', 'received_at', 'received_by_id', 'source_state', 'route_snapshot']))->all();
        $beforeStock = $parcel->order->items->first()->product->stock;
        $originalCheckpoints = $parcel->checkpoints()->get()->map(fn ($checkpoint) => $checkpoint->getAttributes())->all();
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $ack = $this->payload($issue, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $ack)->assertOk();
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame($originalCheckpoints, $parcel->checkpoints()->get()->map(fn ($checkpoint) => $checkpoint->getAttributes())->all());
        $receiver = $this->flowHandler($manifest->destinationHub);
        $input = $this->payload($issue, $receiver);
        $response = $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertOk();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertSame('recovered', $issue->fresh()->state);
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame($beforeManifest, array_intersect_key($manifest->fresh()->getRawOriginal(), $retainedFields));
        $this->assertSame($beforeMembers, $manifest->parcels()->get()->map(fn ($member) => $member->only(['id', 'included', 'received_at', 'received_by_id', 'source_state', 'route_snapshot']))->all());
        $receipt = HandoverManifestReceipt::sole();
        $this->assertTrue($receipt->supports());
        $this->assertSame($stage === 'unlisted' ? 0 : 1, $receipt->original_expected_count);
        $this->assertSame(0, $receipt->original_received_count);
        $this->assertSame($beforeStock, $parcel->order->items->first()->product->fresh()->stock);
        $counts = app(LogisticsManifestService::class)->receiptCounts($manifest);
        $this->assertSame(['expected' => $stage === 'unlisted' ? 0 : 1, 'normal_received' => 0,
            'corrective_expected_received' => $stage === 'unlisted' ? 0 : 1, 'unlisted_received' => $stage === 'unlisted' ? 1 : 0, 'outstanding' => 0], $counts);
        $this->actingAs($this->reviewer($parcel))->postJson('/hub/manifests/'.$manifest->id.'/close-recovery',
            $this->flowManifestPayload($manifest, ['notes' => 'Every actual receiving obligation and corrective receipt has been checked.']))->assertOk();
        $this->assertSame('closed', $manifest->fresh()->status);
        $this->assertSame($beforeManifest['dispatched_at'], $manifest->fresh()->getRawOriginal('dispatched_at'));
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
        $this->confirmFlowScan($parcel, $manifest->destinationHub, 'SORT_TO_LINE_HAUL');
        $this->assertSame('sorted_to_line_haul', $parcel->fresh()->status);
        $this->assertSame($parcel->destination_bayan_hub_id, app(LogisticsManifestService::class)->nextHub($parcel->fresh()->load('order')));
    }

    public static function actualHubSources(): array
    {
        return ['missed origin intake' => ['picked_up', 'hub_intake'], 'missed final-mile handover' => ['assigned_to_rider', 'hub_intake'],
            'failed-attempt return' => ['delivery_failed', 'hub_failed_return']];
    }

    #[DataProvider('actualHubSources')]
    public function test_an_eligible_handler_can_acknowledge_actual_hub_possession_but_another_handler_must_receive_it(string $stage, string $mode): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $stage === 'delivery_failed' ? 'out_for_delivery' : $stage);
        if ($stage === 'delivery_failed') {
            $this->reportFlowFailure($parcel);
            $parcel->refresh()->load('order');
        }
        $hub = LogisticsHub::findOrFail($stage === 'picked_up' ? $parcel->origin_bayan_hub_id : $parcel->destination_bayan_hub_id);
        $holder = $this->flowHandler($hub);
        $receiver = $this->createApprovedUser('logistics');
        HubHandler::create(['user_id' => $receiver->id, 'logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id, 'role_title' => 'Receiving Handler', 'is_active' => true]);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $mode, null, $holder);
        $ack = $this->payload($issue, $holder);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $ack)->assertForbidden();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $ack)->assertOk();
        $input = $this->payload($issue, $receiver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertOk();
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame($hub->id, $parcel->fresh()->current_hub_id);
        $this->assertNull($parcel->fresh()->assigned_rider_id);
        $this->assertNotContains($parcel->order->fresh()->status, ['delivered', 'completed', 'returned']);
        if ($mode === 'hub_failed_return') {
            $this->assertDatabaseHas('delivery_recovery_events', ['delivery_id' => $parcel->id, 'event_type' => 'hub_return', 'actor_id' => $receiver->id]);
            $this->assertSame('delivery_failed', $parcel->order->fresh()->status);
        }
    }

    public static function invalidAuthorizations(): array
    {
        return ['blank notes' => [['notes' => '   '], 422], 'short notes' => [['notes' => 'Hi'], 422],
            'long notes' => [['notes' => str_repeat('A', 1001)], 422], 'markup' => [['notes' => '<b>Parcel received</b>'], 422],
            'control notes' => [['notes' => "The parcel\0was received."], 422], 'unreviewed evidence' => [['evidence_reviewed' => false], 422],
            'unknown holder' => [['holder_verified' => false], 422], 'unsupported mode' => [['mode' => 'guess_transport'], 422],
            'missing source' => [['reference_id' => null], 422], 'array source' => [['reference_id' => ['1']], 422],
            'lookalike source' => [['reference_id' => '１'], 422], 'overlarge source' => [['reference_id' => '2147483648'], 422],
            'invalid request' => [['request_token' => 'request'], 422], 'stale version' => [['expected_version' => 1], 409],
            'fractional version' => [['expected_version' => '2.0'], 422], 'forged source' => [['source_token' => str_repeat('0', 64)], 409]];
    }

    #[DataProvider('invalidAuthorizations')]
    public function test_invalid_or_stale_authorization_preserves_the_entire_original_parcel_and_manifest(array $change, int $status): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $input = array_replace($this->proposalInput($issue, 'hub_manifest', $manifest->id, $manifest->driver), $change);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', $input)->assertStatus($status);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_receiving_before_acknowledgement_and_wrong_waybills_cannot_release_the_hold(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $receiver = $this->flowHandler($manifest->destinationHub);
        $input = $this->payload($issue, $receiver);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver, ['barcode' => 'BGO-WRONG']))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $input = $this->payload($issue, $receiver, ['barcode' => 'BGO-WRONG']);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_integer_and_form_boolean_normalization_preserves_the_same_authorization_retry(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel);
        $issue = $this->held($parcel);
        $input = $this->proposalInput($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $response = $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', $input)->assertOk();
        $retry = $input;
        foreach (['holder_id', 'reference_id', 'expected_version'] as $field) {
            $retry[$field] = (string) $retry[$field];
        }
        $retry['holder_verified'] = 'on';
        $retry['evidence_reviewed'] = 'yes';
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', $retry)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_failed_receipt_rolls_back_custody_corrective_counts_and_temporary_recovery_permission(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $receiver = $this->flowHandler($manifest->destinationHub);
        $input = $this->payload($issue, $receiver);
        $before = $this->snapshot($parcel);
        Event::listen('eloquent.created: '.HandoverManifestReceipt::class, fn () => throw new RuntimeException('Receipt retention unavailable.'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input);
            $this->fail('The unavailable receipt store must roll back the whole transfer.');
        } catch (RuntimeException $error) {
            $this->assertSame('Receipt retention unavailable.', $error->getMessage());
        }
        Event::forget('eloquent.created: '.HandoverManifestReceipt::class);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(app(HandoverHoldService::class)->requiresReviewedRecovery($parcel));
        $this->withExceptionHandling();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertOk();
    }

    public static function linkedSources(): array
    {
        return ['found at original Mother Hub' => ['in_transit_to_mother_hub'], 'found at wrong Bayan Hub' => ['in_transit_to_destination_hub']];
    }

    #[DataProvider('linkedSources')]
    public function test_linked_physical_recovery_requires_holder_acknowledgement_and_clears_only_at_its_original_correct_hub(string $observedStage): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $original = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $observation = $this->observe($parcel, $observedStage);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_existing', $observation->id, $original->driver);
        $case = ParcelRecoveryCase::where('observation_event_id', $observation->id)->sole();
        $intake = ['version' => $case->version, 'request_token' => (string) Str::uuid(), 'barcode' => $parcel->tracking_number,
            'notes' => 'I actually received this exact parcel at the original observed facility.',
            'authorization_only' => true, 'bypass_handover_hold' => true];
        $handler = $this->flowHandler($observation->manifest->destinationHub);
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $intake)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $original->driver))->assertOk();
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake', $intake)->assertOk();
        while ($case->fresh()->state !== 'completed') {
            $this->assertTrue(app(HandoverHoldService::class)->requiresReviewedRecovery($parcel));
            $manifest = $this->prepareRecoveryTransfer($case->fresh());
            $this->actingAs($this->flowHandler($manifest->sourceHub))->postJson('/hub/manifests/'.$manifest->id.'/load',
                $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
            $this->actingAs($this->reviewer($parcel))->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
            $this->actingAs($this->flowHandler($manifest->sourceHub))->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
            $this->assertTrue(app(HandoverHoldService::class)->requiresReviewedRecovery($parcel));
            $this->actingAs($this->flowHandler($manifest->destinationHub))->postJson('/hub/manifests/'.$manifest->id.'/receive',
                $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
            $this->actingAs($this->reviewer($parcel))->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        }
        $this->assertSame('recovered', $issue->fresh()->state);
        $this->assertFalse(app(HandoverHoldService::class)->requiresReviewedRecovery($parcel));
        $this->assertSame($original->destination_hub_id, $parcel->fresh()->current_hub_id);
        $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
        $this->assertSame(1, $original->parcels()->count());
        $this->assertSame('dispatched', $observation->manifest->fresh()->status);
        $this->assertTrue(app(LogisticsManifestService::class)->openDiscrepancies($observation->manifest)->contains('id', $observation->id));
        $this->assertSame(0, HandoverManifestReceipt::count());
    }

    public function test_original_transport_driver_and_receiving_handler_can_report_but_an_unrelated_facility_cannot(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $this->assertTrue(app(HandoverIssueService::class)->parcels($manifest->driver)->whereKey($parcel->id)->exists());
        $this->assertTrue(app(HandoverIssueService::class)->parcels($this->flowHandler($manifest->destinationHub))->whereKey($parcel->id)->exists());
        $unrelated = $this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id));
        $this->assertFalse(app(HandoverIssueService::class)->parcels($unrelated)->whereKey($parcel->id)->exists());
    }

    public function test_platform_oversight_can_authorize_but_cannot_impersonate_a_receiving_handler(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $admin = $this->createApprovedUser('admin');
        $view = $this->actingAs($admin)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $option = collect($view['hub_recovery']['options'])->firstWhere('mode', 'hub_manifest');
        $input = ['mode' => 'hub_manifest', 'reference_id' => $manifest->id, 'holder_id' => $manifest->driver_id,
            'holder_verified' => true, 'evidence_reviewed' => true, 'notes' => 'I independently checked the original source and the exact known transport holder.',
            'expected_version' => $view['version'], 'source_token' => $option['source_token'], 'request_token' => (string) Str::uuid()];
        $response = $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', $input)->assertOk();
        $grant = HandoverRecoveryGrant::findOrFail($response->json('id'));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $input = $this->payload($issue, $admin);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_a_company_reporter_needs_independent_oversight_and_keeps_its_own_original_case_authority(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $original = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $observation = $this->observe($parcel, 'in_transit_to_mother_hub');
        $manager = $this->reviewer($parcel);
        $admin = $this->createApprovedUser('admin');
        $issue = $this->held($parcel, $manager, $admin);
        $grant = $this->authorize($issue, 'hub_existing', $observation->id, $original->driver, $admin);
        $this->assertDatabaseMissing('parcel_recovery_cases', ['observation_event_id' => $observation->id]);
        $proposal = app(ParcelRecoveryService::class)->proposal($manager, $observation);
        $input = ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(),
            'notes' => 'I authorize the original own-company physical route after independent oversight.'];
        $this->actingAs($admin)->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', $input)->assertForbidden();
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize', $input)->assertOk();
        $case = ParcelRecoveryCase::where('observation_event_id', $observation->id)->sole();
        $this->assertSame($manager->id, $case->authorized_by_id);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $original->driver))->assertOk();
        $handler = $this->flowHandler($observation->manifest->destinationHub);
        $this->actingAs($handler)->postJson('/hub/parcel-recovery/'.$observation->id.'/intake',
            ['version' => $case->version, 'request_token' => (string) Str::uuid(), 'barcode' => $parcel->tracking_number,
                'notes' => 'I received the exact parcel at the original proper receiving facility.'])->assertOk();
        $this->assertSame('recovered', $issue->fresh()->state);
    }

    public function test_acknowledged_unreceived_possession_blocks_new_rider_work_and_closure_even_after_withdrawal(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->assertFalse(Delivery::riderHasActiveWork($manifest->driver_id));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $this->assertTrue(Delivery::riderHasActiveWork($manifest->driver_id));
        $this->assertContains('acknowledged_handover', array_column(app(AccountClosureService::class)->state($manifest->driver)['blockers'], 'code'));
        $view = $this->actingAs($this->reviewer($parcel))->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/withdraw', ['notes' => 'A fresh supervised receiving authorization is required for this physical parcel.',
            'expected_version' => $view['version'], 'source_token' => $view['hub_recovery']['grant']['withdraw_source_token'],
            'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertTrue(Delivery::riderHasActiveWork($manifest->driver_id));
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $receiver = $this->flowHandler($manifest->destinationHub);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->payload($issue, $receiver))->assertOk();
        $this->assertFalse(Delivery::riderHasActiveWork($manifest->driver_id));
        $this->assertNotContains('acknowledged_handover', array_column(app(AccountClosureService::class)->state($manifest->driver)['blockers'], 'code'));
    }

    public static function changedSources(): array
    {
        return ['driver approval' => ['driver_approval'], 'driver profile' => ['driver_profile'], 'vehicle owner' => ['vehicle_driver'],
            'receiving facility' => ['facility_address'], 'reviewer activity' => ['reviewer_status'],
            'purchased quantity' => ['quantity'], 'purchased price' => ['price'], 'delivery address' => ['address']];
    }

    #[DataProvider('changedSources')]
    public function test_changed_source_or_authority_cannot_record_a_receipt(string $change): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $receiver = $this->flowHandler($manifest->destinationHub);
        $input = $this->payload($issue, $receiver);
        match ($change) {
            'driver_approval' => $manifest->driver->update(['kyc_status' => 'rejected']),
            'driver_profile' => $manifest->driver->courierProfile->update(['assigned_hub_id' => $manifest->destination_hub_id]),
            'vehicle_driver' => $manifest->vehicle->update(['assigned_driver_id' => $this->createApprovedUser('courier')->id]),
            'facility_address' => $manifest->destinationHub->update(['address' => 'Different actual receiving facility address']),
            'reviewer_status' => $this->reviewer($parcel)->update(['status' => 'inactive']),
            'quantity' => $parcel->order->items()->first()->increment('quantity'),
            'price' => $parcel->order->items()->first()->increment('unit_price', 10),
            'address' => $parcel->update(['delivery_address' => 'A changed saved delivery address']),
        };
        $before = $this->snapshot($parcel);
        $response = $this->actingAs($receiver)->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input);
        $this->assertContains($response->status(), [403, 409]);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public function test_expiry_preserves_acknowledged_possession_and_allows_fresh_current_authorization(): void
    {
        config(['session.lifetime' => 60 * 48]);
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $receiver = $this->flowHandler($manifest->destinationHub);
        $input = $this->payload($issue, $receiver);
        $this->travel(25)->hours();
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(Delivery::riderHasActiveWork($manifest->driver_id));
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->payload($issue, $receiver))->assertOk();
        $this->assertFalse(Delivery::riderHasActiveWork($manifest->driver_id));
    }

    public function test_a_changed_network_grant_can_be_withdrawn_without_releasing_the_physical_hold(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $manifest->destinationHub->update(['address' => 'A changed receiving address requiring review']);
        $view = $this->actingAs($this->reviewer($parcel))->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->assertFalse($view['hub_recovery']['grant']['can_receive']);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/withdraw', ['notes' => 'The receiving network changed and this authorization must no longer be used.',
            'source_token' => $view['hub_recovery']['grant']['withdraw_source_token'], 'expected_version' => $view['version'],
            'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public function test_partial_normal_and_corrective_receipts_preserve_the_original_count_and_unresolved_discrepancy(): void
    {
        $first = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $second = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($first, true);
        $sender = $this->flowHandler($manifest->sourceHub);
        $this->actingAs($sender)->postJson('/hub/manifests/'.$manifest->id.'/load',
            $this->flowManifestPayload($manifest, ['barcode' => $second->tracking_number]))->assertOk();
        $this->actingAs($this->reviewer($first))->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->actingAs($sender)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $first->refresh()->load('order');
        $second->refresh()->load('order');
        $issue = $this->held($second);
        $receiver = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive',
            $this->flowManifestPayload($manifest, ['barcode' => $first->tracking_number]))->assertOk();
        $member = $manifest->parcels()->where('delivery_id', $second->id)->sole();
        $this->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy',
            $this->flowManifestPayload($manifest, ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'This expected parcel still has no actual receiving record.']))->assertOk();
        $discrepancy = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $this->assertSame(1, app(LogisticsManifestService::class)->receiptCounts($manifest)['outstanding']);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->payload($issue, $receiver))->assertOk();
        $this->assertSame(['expected' => 2, 'normal_received' => 1, 'corrective_expected_received' => 1, 'unlisted_received' => 0, 'outstanding' => 0],
            app(LogisticsManifestService::class)->receiptCounts($manifest));
        $this->actingAs($this->reviewer($first))->postJson('/hub/manifests/'.$manifest->id.'/close-recovery',
            $this->flowManifestPayload($manifest, ['notes' => 'The unresolved discrepancy must still prevent administrative closure.']))->assertConflict();
        $this->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy',
            $this->flowManifestPayload($manifest, ['source_event_id' => $discrepancy->id, 'notes' => 'The actual corrective receipt now supports this original missing observation.']))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/close-recovery',
            $this->flowManifestPayload($manifest, ['notes' => 'Every original expected parcel has its actual receipt and supported resolution.']))->assertOk();
        $this->assertNull($member->fresh()->received_at);
        $this->assertSame(2, $manifest->parcels()->where('included', true)->count());
    }

    public static function restrictedHolders(): array
    {
        return [['inactive'], ['suspended']];
    }

    #[DataProvider('restrictedHolders')]
    public function test_restricted_transport_holder_has_only_owned_handover_access(string $status): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $holder = $manifest->driver;
        $holder->update(['status' => $status]);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $holder);
        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        $this->post('/custody-recovery/sign-in', ['email' => $holder->email, 'password' => 'password'])->assertRedirect(route('custody-recovery.own'));
        $view = $this->get(route('custody-recovery.own'))->assertOk()->inertiaProps('handoverGrants');
        $this->assertCount(1, $view);
        $this->get('/handover-issues/'.$issue->id)->assertForbidden();
        $this->postJson('/custody-recovery/handover/'.$grant->id.'/acknowledge',
            ['notes' => 'I physically hold this exact parcel and will hand it to the named receiving facility.',
                'source_token' => $view[0]['source_token'], 'expected_version' => $view[0]['expected_version'],
                'request_token' => (string) Str::uuid(), 'barcode' => $parcel->tracking_number, 'possession_verified' => true])->assertOk();
        $this->postJson('/api/v1/auth/tokens', ['email' => $holder->email, 'password' => 'password', 'device_name' => 'Recovery device'])->assertForbidden();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->payload($issue, $this->flowHandler($manifest->destinationHub)))->assertOk();
        $this->assertSame($status, $holder->fresh()->status);
        $this->actingAs($holder)->get(route('custody-recovery.own'))->assertOk()->assertInertia(fn ($page) => $page->has('handoverGrants', 0));
    }

    public function test_corrective_outbound_and_return_receipts_keep_the_real_reverse_route_and_seller_receipt(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $outbound = $this->prepare($parcel, true, true);
        $this->receiveManifestCorrection($parcel, $outbound);
        $this->finishToFinalMile($parcel);
        $this->reportFlowFailure($parcel, 'Customer refused');
        $parcel->refresh()->load('order');
        $issue = $this->held($parcel);
        $holder = User::findOrFail($parcel->assigned_rider_id);
        $grant = $this->authorize($issue, 'hub_failed_return', null, $holder);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $holder))->assertOk();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->payload($issue, $this->flowHandler($grant->hub)))->assertOk();
        $parcel->refresh()->load('order');
        $this->assertSame('return_to_sender', $parcel->status);
        $reverse = $this->prepare($parcel, true, true);
        $this->receiveManifestCorrection($parcel, $reverse);
        $returns = app(DeliveryReturnService::class);
        $route = $returns->route($parcel);
        $position = $returns->position($parcel, $route);
        $this->assertSame(1, $position);
        foreach (array_slice($route->hub_ids, $position + 1) as $hubId) {
            $this->confirmFlowManifest($parcel, $parcel->fresh()->currentHub, true, direction: 'return');
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($hubId), false, direction: 'return');
        }
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'),
            ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id, 'mode' => 'confirm',
                'action' => 'STAGE_SELLER_RETURN', 'expected_status' => 'return_to_sender', 'route_reference' => $route->reference])->assertOk();
        $this->actingAs($parcel->order->shop->user)->postJson(route('seller.orders.return-receipt', $parcel->order),
            ['barcode' => $parcel->tracking_number, 'route_reference' => $route->reference, 'notes' => 'I actually received the original returned parcel.',
                'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertSame('returned', $parcel->order->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
    }

    public function test_missed_recovery_manifest_departure_uses_a_corrective_receipt_without_inventing_transport(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $observation = $this->observe($parcel, 'in_transit_to_destination_hub');
        $manager = $this->reviewer($parcel);
        $proposal = app(ParcelRecoveryService::class)->proposal($manager, $observation);
        $this->actingAs($manager)->postJson('/hub/parcel-recovery/'.$observation->id.'/authorize',
            ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'notes' => 'Recover the actual parcel through its original receiving route.'])->assertOk();
        $case = ParcelRecoveryCase::where('observation_event_id', $observation->id)->sole();
        $this->actingAs($this->flowHandler($observation->manifest->destinationHub))->postJson('/hub/parcel-recovery/'.$observation->id.'/intake',
            ['version' => $case->version, 'request_token' => (string) Str::uuid(), 'barcode' => $parcel->tracking_number,
                'notes' => 'I received this exact physical parcel at the observed recovery facility.'])->assertOk();
        $manifest = $this->prepareRecoveryTransfer($case->fresh());
        $this->actingAs($this->flowHandler($manifest->sourceHub))->postJson('/hub/manifests/'.$manifest->id.'/load',
            $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $parcel->refresh()->load('order');
        $this->receiveManifestCorrection($parcel, $manifest);
        $this->assertSame('completed', $case->fresh()->state);
        $this->assertNull($manifest->fresh()->dispatched_at);
        $this->assertNull($manifest->parcels()->sole()->received_at);
        $this->assertTrue(HandoverManifestReceipt::sole()->supports());
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
    }

    public function test_corrective_delivery_preserves_cash_separation_public_privacy_and_buyer_only_completion(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        [$issue, $grant] = $this->receiveManifestCorrection($parcel, $manifest);
        $public = json_encode(app(PublicTrackingService::class)->find($parcel->tracking_number), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($grant->reference, $public);
        $this->assertStringNotContainsString($issue->reference, $public);
        $this->assertStringNotContainsString('authority_snapshot', $public);
        $rider = $this->finishToFinalMile($parcel);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel),
            ['status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('proof.jpg'), ...$this->codCollectionInput($parcel)])->assertSessionHas('success');
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $this->actingAs($this->reviewer($parcel))->post(route('buyer.orders.confirm', $parcel->order))->assertForbidden();
        $this->completeFlowOrder($parcel->order->fresh());
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
    }

    public function test_corrective_history_refuses_direct_sql_edits_and_used_migration_rollback(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $this->receiveManifestCorrection($parcel, $manifest);
        $receipt = HandoverManifestReceipt::sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? DB::table('handover_manifest_receipts')->where('id', $receipt->id)->update(['original_expected_count' => 0])
                    : DB::table('handover_manifest_receipts')->where('id', $receipt->id)->delete();
                $this->fail('Corrective receipt history must be retained.');
            } catch (QueryException) {
                $this->assertTrue($receipt->fresh()->supports());
            }
        }
        $migration = require database_path('migrations/2026_10_14_004000_create_handover_manifest_receipts.php');
        try {
            $migration->down();
            $this->fail('A used corrective receipt table must not be removed.');
        } catch (LogicException $error) {
            $this->assertSame('Corrective manifest receipts must be retained.', $error->getMessage());
        }
        $this->assertTrue($receipt->fresh()->supports());
    }

    public function test_wrong_facility_company_authority_and_unrelated_handlers_cannot_receive_or_authorize(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $proposal = $this->proposalInput($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $foreignOwner = $this->createApprovedUser('logistics');
        LogisticsCompany::create(['user_id' => $foreignOwner->id, 'name' => 'Bagoo Separate Network', 'slug' => 'bagoo-separate',
            'code' => 'SEPARATE', 'status' => 'active', 'is_active' => true]);
        $before = $this->snapshot($parcel);
        $this->actingAs($foreignOwner)->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', $proposal)->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $input = $this->payload($issue, $this->flowHandler($manifest->destinationHub));
        foreach ([$foreignOwner, $this->flowHandler($manifest->sourceHub), $this->reviewer($parcel)] as $actor) {
            $before = $this->snapshot($parcel);
            $this->actingAs($actor)->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertForbidden();
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_changed_retries_and_competing_receipts_cannot_add_another_actual_handover(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($parcel, true, true);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $ack = $this->payload($issue, $manifest->driver);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $ack)->assertOk();
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', array_replace($ack, ['notes' => 'Different evidence cannot reuse the original acknowledgement request.']))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $input = $this->payload($issue, $this->flowHandler($manifest->destinationHub));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertOk();
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', array_replace($input, ['notes' => 'Changed receipt evidence must retain its original committed result.']))->assertConflict();
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', array_replace($input, ['request_token' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame(1, HandoverManifestReceipt::count());
    }

    public function test_unknown_original_hub_custody_cannot_guess_a_receipt_or_transport_assignment(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'));
        $issue = $this->held($parcel);
        $holder = $this->flowRider(LogisticsHub::findOrFail($parcel->origin_bayan_hub_id));
        $view = $this->actingAs($this->reviewer($parcel))->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->assertSame([], $view['hub_recovery']['options']);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery',
            ['mode' => 'hub_intake', 'holder_id' => $holder->id, 'holder_verified' => true, 'evidence_reviewed' => true,
                'notes' => 'Unknown physical custody cannot be converted into a guessed hub receipt.',
                'expected_version' => $view['version'], 'source_token' => $view['recovery']['source_token'], 'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public function test_unused_receipt_migration_can_reinstall_without_losing_existing_history_guards(): void
    {
        $migration = require database_path('migrations/2026_10_14_004000_create_handover_manifest_receipts.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('handover_manifest_receipts', 0);
        $triggers = DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name IN ('handover_events_no_update', 'handover_grants_no_update', 'handover_manifest_receipts_no_update')");
        $this->assertCount(3, $triggers);
    }

    public function test_unlisted_authorized_handover_also_holds_its_selected_manifest_batch(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $other = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $manifest = $this->prepare($other, true);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, 'hub_manifest', $manifest->id, $manifest->driver);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->reviewer($parcel))->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $this->payload($issue, $manifest->driver))->assertOk();
        $view = $this->actingAs($this->reviewer($parcel))->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/withdraw', ['notes' => 'Current acknowledged physical possession still needs another receiving authorization.',
            'expected_version' => $view['version'], 'source_token' => $view['hub_recovery']['grant']['withdraw_source_token'], 'request_token' => (string) Str::uuid()])->assertOk();
        $before = $this->snapshot($parcel);
        $this->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame([$parcel->id], app(HandoverHoldService::class)->manifestParcelIds($manifest));
    }
}
