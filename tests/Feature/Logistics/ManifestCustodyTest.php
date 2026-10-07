<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\AccountRestrictionService;
use App\Services\ResourceRestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use Throwable;

class ManifestCustodyTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return ['root' => ['/hub'], 'subdomain' => ['http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_origin_departure_requires_a_sealed_actual_manifest(string $prefix): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs($this->flowHandler($hub))->postJson($prefix.'/scan', ['barcode' => $delivery->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'DISPATCH_TO_FEEDER', 'expected_status' => 'arrived_at_origin_hub'])
            ->assertConflict();
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    #[DataProvider('portals')]
    public function test_real_manifest_preparation_scan_seal_and_dispatch_retain_actual_custody(string $prefix): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler, $driver, $vehicle] = $this->prepare($delivery, $prefix);
        $before = [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs($handler)->postJson($prefix.'/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => '  '.strtolower($delivery->tracking_number).'  ']))
            ->assertOk()->assertJsonPath('manifest.status', 'draft');
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
        $load = $manifest->events()->where('event_type', 'load')->sole();
        $this->assertSame($delivery->tracking_number, $load->barcode_scanned);
        $this->assertSame($handler->id, $load->actor_id);
        $this->assertSame('logistics', $load->actor_role);
        $this->actingAs($manager)->postJson($prefix.'/manifests/'.$manifest->id.'/seal', $this->payload($manifest))
            ->assertOk()->assertJsonPath('manifest.status', 'sealed');
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
        $payload = $this->payload($manifest);
        $this->actingAs($handler)->postJson($prefix.'/manifests/'.$manifest->id.'/dispatch', $payload)
            ->assertOk()->assertJsonPath('manifest.status', 'dispatched');
        $record = $delivery->checkpoints()->where('checkpoint_type', 'in_transit_to_mother_hub')->sole();
        $this->assertSame($manifest->reference, $record->manifest_number);
        $this->assertSame($load->barcode_scanned, $record->barcode_scanned);
        $this->assertSame('manifest_load_event', $record->scan_provenance);
        $this->assertSame(['kind' => 'hub', 'hub_id' => $delivery->origin_bayan_hub_id], $record->custody_before);
        $this->assertSame('manifest', $record->custody_after['kind']);
        $this->assertSame($manifest->id, $record->custody_after['manifest_id']);
        $this->assertSame($driver->id, $record->custody_after['driver_id']);
        $this->assertSame($vehicle->id, $record->custody_after['vehicle_id']);
        $this->assertSame($load->reference, $record->custody_after['source_scan_reference']);
        $this->assertSame('at_sorting_center', $delivery->order->fresh()->status);
        $after = $this->snapshot($manifest, $delivery);
        $this->postJson($prefix.'/manifests/'.$manifest->id.'/dispatch', $payload)->assertOk();
        $this->assertSame($after, $this->snapshot($manifest, $delivery));
    }

    public static function invalidInputs(): array
    {
        return ['empty scan' => [['barcode' => '']], 'control scan' => [['barcode' => "\tBGO-123\n"]],
            'hidden scan' => [['barcode' => "BGO-12\u{200B}3"]], 'lookalike scan' => [['barcode' => 'ＢＧＯ-123']],
            'array scan' => [['barcode' => ['BGO-123']]], 'overlong scan' => [['barcode' => str_repeat('A', 256)]],
            'foreign scan' => [['barcode' => 'BGO-WRONG']], 'markup notes' => [['notes' => '<b>Parcel</b>']],
            'control notes' => [['notes' => "Parcel\0box"]], 'long notes' => [['notes' => str_repeat('A', 1001)]],
            'zero version' => [['version' => 0]], 'float version' => [['version' => 1.0]], 'lookalike version' => [['version' => '１']],
            'control version' => [['version' => "\t1\n"]], 'invalid token' => [['request_token' => 'other']],
            'array token' => [['request_token' => ['other']]]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_load_input_preserves_all_manifest_and_parcel_evidence(array $invalid): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, , $handler] = $this->prepare($delivery);
        $before = $this->snapshot($manifest, $delivery);
        $input = array_replace($this->payload($manifest, ['barcode' => $delivery->tracking_number]), $invalid);
        $this->actingAs($handler)->call('POST', '/hub/manifests/'.$manifest->id.'/load', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($input, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))
            ->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public static function deniedActors(): array
    {
        return ['buyer' => ['buyer', 'load'], 'seller' => ['seller', 'load'], 'rider' => ['courier', 'load'], 'platform' => ['admin', 'load'],
            'company floor bypass' => ['manager', 'load'], 'handler manager bypass' => ['handler', 'seal'],
            'wrong facility' => ['wrong_handler', 'load'], 'foreign company' => ['foreign_manager', 'load']];
    }

    #[DataProvider('deniedActors')]
    public function test_wrong_actor_or_scope_cannot_change_manifest(string $role, string $action): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery);
        $actor = match ($role) {
            'manager' => $manager, 'handler' => $handler,
            'wrong_handler' => $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id)),
            'foreign_manager' => $this->createApprovedUser('logistics'),
            default => $this->createApprovedUser($role),
        };
        if ($role === 'foreign_manager') {
            LogisticsCompany::create(['user_id' => $actor->id, 'name' => 'Bagoo Other Network', 'slug' => 'other-network', 'code' => 'OTHER', 'status' => 'active', 'is_active' => true]);
            $this->actingAs($actor)->get('/hub/manifests/'.$manifest->id)->assertForbidden();
        }
        $before = $this->snapshot($manifest, $delivery);
        $this->actingAs($actor)->postJson('/hub/manifests/'.$manifest->id.'/'.$action, $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertForbidden();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public function test_platform_oversight_is_read_only_and_contains_no_creation_credentials(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest] = $this->prepare($delivery);
        $this->actingAs($this->createApprovedUser('admin'))->get('/hub/manifests/'.$manifest->id)
            ->assertOk()->assertInertia(fn ($page) => $page->component('Hub/Manifests')->where('creationToken', null)
            ->where('selectedManifest.id', $manifest->id)->where('selectedManifest.canManage', false)
            ->where('selectedManifest.canLoad', false)->where('selectedManifest.canReceive', false)
            ->missing('selectedManifest.creation_token')->missing('selectedManifest.creation_fingerprint'));
    }

    public function test_one_parcel_cannot_load_onto_two_active_manifests(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$first, , $handler] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$first->id.'/load', $this->payload($first, ['barcode' => $delivery->tracking_number]))->assertOk();
        [$second] = $this->prepare($delivery);
        $beforeFirst = $this->snapshot($first, $delivery);
        $beforeSecond = $this->snapshot($second, $delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$second->id.'/load', $this->payload($second, ['barcode' => $delivery->tracking_number]))->assertConflict();
        $this->assertSame($beforeFirst, $this->snapshot($first, $delivery));
        $this->assertSame($beforeSecond, $this->snapshot($second, $delivery));
    }

    public function test_sealed_list_cannot_change_and_reopening_keeps_old_scan_and_reason(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $before = $this->snapshot($manifest, $delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertConflict();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/remove', $this->payload($manifest, ['parcel_id' => $manifest->parcels()->sole()->id, 'notes' => 'The parcel needs another load.']))->assertConflict();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
        $this->postJson('/hub/manifests/'.$manifest->id.'/reopen', $this->payload($manifest, ['notes' => 'Review the parcel list before departure.']))->assertOk()->assertJsonPath('manifest.status', 'draft');
        $this->assertSame('Review the parcel list before departure.', $manifest->events()->where('event_type', 'reopen')->sole()->reason);
        $this->assertSame(1, $manifest->events()->where('event_type', 'load')->count());
    }

    public function test_conflicting_or_stale_commands_do_not_replace_the_original_record(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, , $handler] = $this->prepare($delivery);
        $input = $this->payload($manifest, ['barcode' => $delivery->tracking_number, 'notes' => 'Loaded at the source hub.']);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $input)->assertOk();
        $before = $this->snapshot($manifest, $delivery);
        $this->postJson('/hub/manifests/'.$manifest->id.'/load', $input)->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/load', array_replace($input, ['notes' => 'Replacement note.']))->assertConflict();
        $this->postJson('/hub/manifests/'.$manifest->id.'/load', array_replace($input, ['request_token' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public function test_a_failed_departure_audit_rolls_back_every_parcel_and_custody_event(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $before = $this->snapshot($manifest, $delivery);
        Event::listen('eloquent.creating: '.LogisticsManifestEvent::class, function ($event) {
            if ($event->event_type === 'dispatch') {
                throw new RuntimeException('Manifest audit storage failed');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest));
            $this->fail('Departure evidence must commit atomically.');
        } catch (RuntimeException $error) {
            $this->assertSame('Manifest audit storage failed', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public static function invalidDispatchResources(): array
    {
        return ['vehicle' => ['vehicle'], 'driver off duty' => ['off_duty'], 'driver restricted' => ['restricted'],
            'source unavailable' => ['source'], 'driver busy' => ['busy'],
            'motorcycle feeder' => ['motorcycle'], 'truck feeder' => ['wing_truck']];
    }

    #[DataProvider('invalidDispatchResources')]
    public function test_dispatch_rechecks_current_vehicle_driver_and_facility(string $resource): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler, $driver, $vehicle] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        match ($resource) {
            'vehicle' => $vehicle->update(['status' => 'maintenance']),
            'motorcycle', 'wing_truck' => $vehicle->update(['vehicle_type' => $resource]),
            'off_duty' => $this->actingAs($driver)->post('/courier/profile/toggle-duty', ['is_available' => false])->assertSessionHas('success'),
            'restricted' => $this->restrictFlowAccount($driver, 'suspend'),
            'source' => LogisticsHub::findOrFail($manifest->source_hub_id)->update(['is_active' => false]),
            'busy' => $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup', $driver),
        };
        $before = $this->snapshot($manifest, $delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertStatus($resource === 'source' ? 403 : 409);
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public static function forbiddenHistoryWrites(): array
    {
        return ['event model update' => ['event_update'], 'event model delete' => ['event_delete'],
            'event raw update' => ['raw_update'], 'event raw delete' => ['raw_delete'],
            'member identity' => ['member'], 'manifest identity' => ['manifest'], 'manifest leg type' => ['type'], 'member delete' => ['member_delete']];
    }

    #[DataProvider('forbiddenHistoryWrites')]
    public function test_manifest_source_history_and_membership_identity_are_retained(string $operation): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, , $handler] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $event = $manifest->events()->where('event_type', 'load')->sole();
        $member = $manifest->parcels()->sole();
        $before = $this->snapshot($manifest, $delivery);
        $error = null;
        try {
            match ($operation) {
                'event_update' => $event->update(['reason' => 'Replacement']),
                'event_delete' => $event->delete(),
                'raw_update' => DB::table('logistics_manifest_events')->where('id', $event->id)->update(['created_at' => '2020-01-01 00:00:00']),
                'raw_delete' => DB::table('logistics_manifest_events')->where('id', $event->id)->delete(),
                'member' => DB::table('logistics_manifest_parcels')->where('id', $member->id)->update(['tracking_number_snapshot' => 'REPLACEMENT']),
                'manifest' => DB::table('logistics_manifests')->where('id', $manifest->id)->update(['reference' => 'REPLACEMENT']),
                'type' => DB::table('logistics_manifests')->where('id', $manifest->id)->update(['type' => 'line_haul']),
                'member_delete' => DB::table('logistics_manifest_parcels')->where('id', $member->id)->delete(),
            };
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $this->assertNotNull($error);
        $this->assertStringContainsString('Manifest', $error->getMessage());
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public function test_a_manifest_driver_in_transit_cannot_claim_new_pickup_work(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler, $driver] = $this->prepare($delivery);
        $otherOrder = $this->newFlowOrder('ready_for_pickup');
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        $before = $otherOrder->delivery->getRawOriginal();
        $this->actingAs($driver)->post('/courier/deliveries/'.$otherOrder->delivery->id.'/claim')->assertSessionHas('error');
        $this->assertSame($before, $otherOrder->delivery->fresh()->getRawOriginal());
    }

    #[DataProvider('portals')]
    public function test_expected_destination_records_one_actual_receipt_then_company_closes(string $prefix): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery, $prefix);
        $this->actingAs($handler)->postJson($prefix.'/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson($prefix.'/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson($prefix.'/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $input = $this->payload($manifest, ['barcode' => $delivery->tracking_number, 'condition' => 'intact']);
        $this->actingAs($receiver)->postJson($prefix.'/manifests/'.$manifest->id.'/receive', $input)->assertOk()->assertJsonPath('manifest.status', 'received');
        $record = $delivery->checkpoints()->where('checkpoint_type', 'arrived_at_mother_hub')->sole();
        $this->assertSame($receiver->id, $record->scanned_by_id);
        $this->assertSame($delivery->tracking_number, $record->barcode_scanned);
        $this->assertSame($manifest->reference, $record->manifest_number);
        $this->assertSame('manifest', $record->custody_before['kind']);
        $this->assertSame('hub', $record->custody_after['kind']);
        $this->assertSame($manifest->destination_hub_id, $record->custody_after['hub_id']);
        $before = $this->snapshot($manifest, $delivery);
        $this->postJson($prefix.'/manifests/'.$manifest->id.'/receive', $input)->assertOk();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
        $this->actingAs($manager)->postJson($prefix.'/manifests/'.$manifest->id.'/close', $this->payload($manifest))->assertOk()->assertJsonPath('manifest.status', 'closed');
        $this->assertNull($manifest->parcels()->sole()->active_delivery_id);
        $this->assertSame('arrived_at_mother_hub', $delivery->fresh()->status);
        $this->assertSame('at_sorting_center', $delivery->order->fresh()->status);
    }

    public function test_partial_receipt_and_missing_report_keep_original_custody_until_actual_scan(): void
    {
        $first = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $second = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($first);
        foreach ([$first, $second] as $delivery) {
            $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        }
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->payload($manifest, ['barcode' => $first->tracking_number]))->assertOk()->assertJsonPath('manifest.status', 'dispatched');
        $before = [$second->fresh()->getRawOriginal(), $second->checkpoints()->get()->toArray()];
        $member = $manifest->parcels()->where('delivery_id', $second->id)->sole();
        $report = $this->payload($manifest, ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'The expected parcel was not found during unloading.']);
        $this->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $report)->assertOk();
        $source = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $this->assertNull($source->barcode_scanned);
        $this->assertSame($before, [$second->fresh()->getRawOriginal(), $second->checkpoints()->get()->toArray()]);
        $after = $this->snapshot($manifest, $second);
        $this->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $report)->assertOk();
        $this->assertSame($after, $this->snapshot($manifest, $second));
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $this->payload($manifest, ['source_event_id' => $source->id, 'notes' => 'It should have been received.']))->assertConflict();
        $this->postJson('/hub/manifests/'.$manifest->id.'/close', $this->payload($manifest))->assertConflict();
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->payload($manifest, ['barcode' => $second->tracking_number, 'notes' => 'The parcel was found and its actual waybill scanned.']))->assertOk()->assertJsonPath('manifest.status', 'received');
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->payload($manifest))->assertConflict();
        $resolution = $this->payload($manifest, ['source_event_id' => $source->id, 'notes' => 'The later receiving scan accounts for this parcel.']);
        $this->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $resolution)->assertOk();
        $record = $manifest->events()->where('event_type', 'resolve-discrepancy')->sole();
        $this->assertSame($source->id, $record->payload['source_event_id']);
        $this->assertSame('parcel_received', LogisticsManifestEvent::findOrFail($record->payload['support_event_id'])->event_type);
        $this->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $resolution)->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/close', $this->payload($manifest))->assertOk();
    }

    public static function observedDiscrepancies(): array
    {
        return ['damage' => ['damaged'], 'duplicate' => ['duplicate'], 'extra' => ['extra'], 'wrong hub' => ['wrong_hub']];
    }

    #[DataProvider('observedDiscrepancies')]
    public function test_arrival_observations_require_handler_receipt_evidence_before_resolution(string $kind): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        if ($kind === 'duplicate') {
            $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        }
        $before = [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $barcode = in_array($kind, ['extra', 'wrong_hub'], true) ? 'OBSERVED-UNLISTED-001' : $delivery->tracking_number;
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->payload($manifest, ['kind' => $kind, 'barcode' => $barcode, 'notes' => 'Observed this issue while checking the arriving parcels.']))->assertOk();
        $source = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $this->assertSame($barcode, $source->barcode_scanned);
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
        if ($kind !== 'duplicate') {
            $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $this->payload($manifest, ['source_event_id' => $source->id, 'notes' => 'The issue looks resolved.']))->assertConflict();
            $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->payload($manifest, ['barcode' => $delivery->tracking_number, 'notes' => 'Rechecked the intact parcel and scanned its actual waybill.']))->assertOk();
        }
        if (in_array($kind, ['extra', 'wrong_hub'], true)) {
            $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/correct-discrepancy', $this->payload($manifest, ['source_event_id' => $source->id, 'barcode' => $delivery->tracking_number, 'notes' => 'Correct the observed code.']))->assertForbidden();
            $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/correct-discrepancy', $this->payload($manifest, ['source_event_id' => $source->id, 'barcode' => $delivery->tracking_number, 'notes' => 'The first entry was a transcription mistake; this is the actual received waybill.']))->assertOk();
        }
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $this->payload($manifest, ['source_event_id' => $source->id, 'notes' => 'Reviewed the original observation and supporting handler record.']))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/close', $this->payload($manifest))->assertOk();
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'arrived_at_mother_hub')->count());
        $this->assertSame('at_sorting_center', $delivery->order->fresh()->status);
    }

    public function test_real_manifest_transport_includes_both_distinct_mother_hubs_before_destination(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $companyId = LogisticsCompany::where('code', 'ACCEPTANCE')->sole()->id;
        $destinationMother = LogisticsHub::create(['logistics_company_id' => $companyId, 'name' => 'Cavite Mother Hub',
            'code' => 'FLOW-CAV-MH', 'tier' => 'regional_mother_hub', 'province' => 'Cavite',
            'city_municipality' => 'Dasmariñas', 'address' => '100 Cavite Hub Road', 'is_active' => true]);
        $destination = LogisticsHub::create(['logistics_company_id' => $companyId, 'name' => 'Dasmariñas Bayan Hub',
            'code' => 'FLOW-CAV-BH', 'tier' => 'local_bayan_hub', 'province' => 'Cavite',
            'city_municipality' => 'Dasmariñas', 'address' => '100 Bayan Hub Road', 'is_active' => true]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'ready_for_pickup',
            ['shipping_province' => 'Cavite', 'shipping_city' => 'Dasmariñas', 'shipping_postal_code' => '4114', 'destination_barangay' => 'Paliparan I']);
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $this->assertSame($destinationMother->id, $delivery->destination_mother_hub_id);
        $this->assertNotSame($delivery->origin_mother_hub_id, $delivery->destination_mother_hub_id);
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $mother = LogisticsHub::findOrFail($delivery->origin_mother_hub_id);
        $this->confirmFlowManifest($delivery, $origin, true);
        $this->confirmFlowManifest($delivery, $mother, false);
        $this->confirmFlowScan($delivery, $mother, 'SORT_TO_LINE_HAUL');
        $this->confirmFlowManifest($delivery, $mother, true);
        $this->assertSame('in_transit_to_mother_hub', $delivery->fresh()->status);
        $this->confirmFlowManifest($delivery, $destinationMother, false);
        $this->confirmFlowScan($delivery, $destinationMother, 'SORT_TO_LINE_HAUL');
        $this->confirmFlowManifest($delivery, $destinationMother, true);
        $this->confirmFlowManifest($delivery, $destination, false);
        $this->assertSame('arrived_at_destination_hub', $delivery->fresh()->status);
        $this->assertSame(3, LogisticsManifest::count());
        $this->assertSame(['feeder', 'line_haul', 'feeder'], LogisticsManifest::orderBy('id')->pluck('type')->all());
        $this->assertSame(['l300_van', 'wing_truck', 'l300_van'], LogisticsManifest::with('vehicle')->orderBy('id')->get()->pluck('vehicle.vehicle_type')->all());
        $this->assertSame(LogisticsManifest::orderBy('id')->get()[1]->reference, $delivery->fresh()->truck_manifest_number);
        $this->assertSame(LogisticsManifest::orderBy('id')->get()[2]->reference, $delivery->fresh()->shuttle_manifest_number);
        $this->assertSame(2, $delivery->checkpoints()->where('checkpoint_type', 'arrived_at_mother_hub')->count());
        $this->assertSame([$mother->id, $destinationMother->id, $destination->id], LogisticsManifestEvent::where('event_type', 'parcel_received')->orderBy('id')->pluck('hub_id')->all());
        $this->assertSame(0, LogisticsManifest::where('status', '!=', 'closed')->count());
    }

    public function test_actual_carrier_work_is_in_restriction_and_closure_reviews_without_custody_change(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler, $driver, $vehicle] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        $restriction = app(AccountRestrictionService::class);
        $this->assertContains($delivery->order_id, $restriction->ordersFor($driver)->pluck('orders.id')->all());
        $work = collect($restriction->work($restriction->ordersFor($driver)))->firstWhere('order_id', $delivery->order_id);
        $this->assertSame($manifest->id, $work['manifests'][0]['id']);
        $this->assertSame($driver->id, $work['custody']['driver_id']);
        $this->assertContains($delivery->order_id, app(AccountClosureService::class)->ordersFor($driver)->pluck('orders.id')->all());
        $admin = $this->createApprovedUser('admin');
        $this->assertContains($delivery->order_id, app(ResourceRestrictionService::class)->ordersFor($admin, 'fleet', $vehicle)->pluck('orders.id')->all());
        $before = [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->restrictFlowAccount($driver, 'suspend');
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_changed_mother_hub_tier_cannot_turn_the_recorded_receipt_into_a_bayan_shortcut(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery);
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        LogisticsHub::findOrFail($manifest->destination_hub_id)->update(['tier' => 'local_bayan_hub']);
        $before = $this->snapshot($manifest, $delivery);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertConflict();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public function test_failed_receipt_audit_rolls_back_arrival_and_all_custody_evidence(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $before = $this->snapshot($manifest, $delivery);
        Event::listen('eloquent.creating: '.LogisticsManifestEvent::class, function ($event) {
            if ($event->event_type === 'receive') {
                throw new RuntimeException('Receiving audit storage failed');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->payload($manifest, ['barcode' => $delivery->tracking_number]));
            $this->fail('Receiving evidence must commit atomically.');
        } catch (RuntimeException $error) {
            $this->assertSame('Receiving audit storage failed', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    public function test_receipt_labels_without_the_original_scan_cannot_close_a_manifest(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        // Adversarial projection corruption is not physical receiving evidence.
        DB::table('logistics_manifest_parcels')->where('manifest_id', $manifest->id)->update(['received_at' => now(), 'received_by_id' => $handler->id, 'receipt_condition' => 'intact']);
        DB::table('logistics_manifests')->where('id', $manifest->id)->update(['status' => 'received']);
        $before = $this->snapshot($manifest, $delivery);
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->payload($manifest))->assertConflict();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
    }

    #[DataProvider('portals')]
    public function test_source_handler_and_company_manager_cannot_receive_at_the_destination(string $prefix): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, $handler] = $this->prepare($delivery, $prefix);
        $this->actingAs($handler)->postJson($prefix.'/manifests/'.$manifest->id.'/load', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson($prefix.'/manifests/'.$manifest->id.'/seal', $this->payload($manifest))->assertOk();
        $this->actingAs($handler)->postJson($prefix.'/manifests/'.$manifest->id.'/dispatch', $this->payload($manifest))->assertOk();
        $before = $this->snapshot($manifest, $delivery);
        foreach ([$manager, $handler, $this->createApprovedUser('admin')] as $actor) {
            $this->actingAs($actor)->postJson($prefix.'/manifests/'.$manifest->id.'/receive', $this->payload($manifest, ['barcode' => $delivery->tracking_number]))->assertForbidden();
            $this->assertSame($before, $this->snapshot($manifest, $delivery));
        }
    }

    public static function incompatibleVehicles(): array
    {
        return ['motorcycle feeder' => ['feeder', 'motorcycle'], 'tricycle feeder' => ['feeder', 'tricycle'],
            'truck feeder' => ['feeder', 'wing_truck'], 'motorcycle line-haul' => ['line_haul', 'motorcycle'],
            'tricycle line-haul' => ['line_haul', 'tricycle'], 'van line-haul' => ['line_haul', 'l300_van']];
    }

    #[DataProvider('incompatibleVehicles')]
    public function test_preparation_enforces_actual_vehicle_type_for_the_recorded_leg(string $type, string $vehicleType): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        [$manifest, $manager, , $driver, $vehicle] = $this->prepare($delivery);
        $source = LogisticsHub::findOrFail($manifest->source_hub_id);
        $destination = LogisticsHub::findOrFail($manifest->destination_hub_id);
        if ($type === 'line_haul') {
            $source = $destination;
            $destination = LogisticsHub::create(['logistics_company_id' => $manifest->logistics_company_id,
                'name' => 'Cavite Mother Hub', 'code' => 'CAP-CAV-MH', 'tier' => 'regional_mother_hub', 'province' => 'Cavite',
                'city_municipality' => 'Dasmariñas', 'address' => '100 Mother Hub Road', 'is_active' => true]);
        }
        // These are initial vehicle eligibility conditions; no physical custody is fabricated.
        $vehicle->update(['hub_id' => $source->id, 'vehicle_type' => $vehicleType]);
        $driver->courierProfile->update(['assigned_hub_id' => $source->id]);
        $this->actingAs($manager)->get('/hub/manifests')->assertOk();
        $input = ['source_hub_id' => $source->id, 'destination_hub_id' => $destination->id, 'vehicle_id' => $vehicle->id,
            'creation_token' => session()->get('manifest_creation_token'), 'type' => 'client-choice', 'vehicle_type' => 'client-choice'];
        $before = $this->snapshot($manifest, $delivery);
        $this->postJson('/hub/manifests', $input)->assertConflict();
        $this->assertSame($before, $this->snapshot($manifest, $delivery));
        $this->assertSame(1, LogisticsManifest::count());
        $expected = $type === 'line_haul' ? 'wing_truck' : 'l300_van';
        $vehicle->update(['vehicle_type' => $expected]);
        $response = $this->postJson('/hub/manifests', $input)->assertCreated();
        $created = LogisticsManifest::findOrFail($response->json('manifest.id'));
        $this->assertSame($type, $created->type);
        $this->assertSame($expected, $created->events()->where('event_type', 'created')->sole()->payload['vehicle_type']);
    }

    private function prepare(Delivery $delivery, string $prefix = '/hub'): array
    {
        $source = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $company = LogisticsCompany::findOrFail($delivery->logistics_company_id);
        $manager = User::findOrFail($company->user_id);
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $company->id, 'hub_id' => $source->id,
            'plate_number' => 'MNF-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT), 'vehicle_type' => 'l300_van', 'model' => 'Bagoo Feeder Van',
            'capacity_kg' => 1000, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        // Initial resource eligibility is a fixture; manifest and custody facts come from actual requests.
        $driver->courierProfile->update(['logistics_company_id' => $company->id, 'assigned_hub_id' => $source->id,
            'vehicle_id' => $vehicle->id, 'is_available' => true]);
        $this->actingAs($manager)->get($prefix.'/manifests')->assertOk();
        $token = session()->get('manifest_creation_token');
        $this->assertIsString($token);
        $input = ['source_hub_id' => $source->id, 'destination_hub_id' => $delivery->origin_mother_hub_id,
            'vehicle_id' => $vehicle->id, 'creation_token' => $token];
        $response = $this->postJson($prefix.'/manifests', $input)->assertCreated()->assertJsonPath('manifest.status', 'draft');
        $manifest = LogisticsManifest::findOrFail($response->json('manifest.id'));
        $this->assertStringStartsWith('MFT-', $manifest->reference);
        $state = $this->snapshot($manifest, $delivery);
        $this->postJson($prefix.'/manifests', $input)->assertOk()->assertJsonPath('manifest.id', $manifest->id);
        $this->assertSame($state, $this->snapshot($manifest, $delivery));

        return [$manifest, $manager, $this->flowHandler($source), $driver, $vehicle];
    }

    private function payload(LogisticsManifest $manifest, array $fields = []): array
    {
        return ['version' => $manifest->fresh()->version, 'request_token' => (string) Str::uuid(), ...$fields];
    }

    private function snapshot(LogisticsManifest $manifest, Delivery $delivery): array
    {
        return [$manifest->fresh()->getRawOriginal(), $manifest->parcels()->orderBy('id')->get()->toArray(),
            LogisticsManifestEvent::where('manifest_id', $manifest->id)->orderBy('id')->get()->toArray(),
            $delivery->fresh()->getRawOriginal(), $delivery->order->fresh()->getRawOriginal(),
            $delivery->checkpoints()->orderBy('id')->get()->toArray()];
    }
}
