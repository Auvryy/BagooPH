<?php

namespace Tests\Feature\Logistics;

use App\Exceptions\HandoverHoldException;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HandoverIssue;
use App\Models\HandoverIssueEvent;
use App\Models\HandoverRecoveryGrant;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\RestrictionAffectedWork;
use App\Models\User;
use App\Services\Courier\RiderTaskService;
use App\Services\Logistics\DeliveryRecoveryService;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Logistics\PublicTrackingService;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\RiderAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class HandoverRecoveryTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function report(Delivery $parcel): HandoverIssue
    {
        $seller = $parcel->order->shop->user;
        $this->actingAs($seller)->postJson('/handover-issues', ['barcode' => $parcel->tracking_number,
            'kind' => in_array($parcel->order->status, ['placed', 'confirmed', 'preparing'], true) ? 'missed_ready' : 'missed_scan',
            'reported_holder' => 'The rider carrying this parcel', 'reported_location' => 'At the assigned hub receiving desk',
            'notes' => 'The actual parcel was handed over without its expected readiness or scan step.',
            'evidence' => UploadedFile::fake()->image('handover.png'), 'request_token' => (string) Str::uuid()])->assertOk();

        return HandoverIssue::where('delivery_id', $parcel->id)->sole();
    }

    private function reviewer(Delivery $parcel): User
    {
        return LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
    }

    private function held(Delivery $parcel): HandoverIssue
    {
        $issue = $this->report($parcel);
        $reviewer = $this->reviewer($parcel);
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/review', ['decision' => 'confirm', 'notes' => 'The original evidence and known physical holder were independently checked.',
            'evidence_reviewed' => true, 'source_token' => $view['source_token'], 'expected_version' => $view['version'], 'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));

        return $issue->fresh();
    }

    private function input(HandoverIssue $issue, User $actor, array $extra = []): array
    {
        $view = $this->actingAs($actor)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');

        return array_replace(['notes' => 'I checked this exact parcel and its current recovery responsibility.',
            'source_token' => $view['recovery']['source_token'], 'expected_version' => $view['version'], 'request_token' => (string) Str::uuid()], $extra);
    }

    private function authorize(HandoverIssue $issue, User $holder): HandoverRecoveryGrant
    {
        $reviewer = $this->reviewer($issue->delivery);
        $input = $this->input($issue, $reviewer, ['holder_id' => $holder->id, 'holder_verified' => true, 'evidence_reviewed' => true]);
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-recovery', $input)->assertOk();
        $grant = HandoverRecoveryGrant::where('handover_issue_id', $issue->id)->latest('id')->firstOrFail();
        $before = $this->snapshot($issue->delivery);
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-recovery', $input)->assertOk()->assertJsonPath('id', $grant->id);
        $this->assertSame($before, $this->snapshot($issue->delivery));

        return $grant;
    }

    private function snapshot(Delivery $parcel): array
    {
        $result = ['parcel' => $parcel->fresh()->getAttributes(), 'order' => $parcel->order->fresh()->getAttributes()];
        foreach (['products', 'order_items', 'handover_issues', 'handover_issue_events', 'handover_recovery_grants', 'delivery_checkpoints',
            'delivery_attempts', 'cod_accounts', 'cod_custody_entries', 'logistics_manifest_events', 'logistics_manifest_parcels',
            'custody_recovery_grants', 'custody_recovery_receipts', 'parcel_recovery_cases', 'parcel_recovery_events', 'pickup_claims', 'pickup_claim_events'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public static function phases(): array
    {
        return ['ready without a claim' => ['unassigned', 'http://localhost'], 'assigned pickup' => ['assigned_pickup', 'http://courier.localhost'],
            'recorded pickup' => ['picked_up', 'http://localhost'], 'missed departure' => ['assigned_to_rider', 'http://hub.localhost']];
    }

    #[DataProvider('phases')]
    public function test_named_holder_and_actual_receiving_handler_restore_custody_once(string $stage, string $host): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), $stage);
        $hub = LogisticsHub::findOrFail($stage === 'assigned_to_rider' ? $parcel->destination_bayan_hub_id : $parcel->origin_bayan_hub_id);
        $holder = $stage === 'unassigned' ? $this->flowRider($hub) : User::findOrFail($stage === 'assigned_to_rider' ? $parcel->assigned_rider_id : $parcel->courier_id);
        $originals = $parcel->checkpoints()->orderBy('id')->get()->map->getAttributes()->all();
        $stock = $parcel->order->items->first()->product->fresh()->stock;
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $handler = $this->flowHandler($hub);
        $premature = $this->input($issue, $handler, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $premature)->assertConflict();
        $ack = $this->input($issue, $holder, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $this->postJson($host.'/handover-issues/recovery/'.$grant->id.'/acknowledge', $ack)->assertOk();
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame('courier', DeliveryCheckpoint::lastCustody($parcel->fresh())['kind']);
        $before = $this->snapshot($parcel);
        $this->postJson($host.'/handover-issues/recovery/'.$grant->id.'/acknowledge', $ack)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $receipt = $this->input($issue, $handler, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $receipt)->assertOk();
        $parcel->refresh();
        $this->assertSame($stage === 'assigned_to_rider' ? 'arrived_at_destination_hub' : 'arrived_at_origin_hub', $parcel->status);
        $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
        $this->assertSame($hub->id, $parcel->current_hub_id);
        $this->assertSame('recovered', $issue->fresh()->state);
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame('hub', DeliveryCheckpoint::lastCustody($parcel)['kind']);
        $this->assertSame($stock, $parcel->order->items->first()->product->fresh()->stock);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->assertSame($originals, $parcel->checkpoints()->whereIn('id', array_column($originals, 'id'))->orderBy('id')->get()->map->getAttributes()->all());
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $receipt)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', array_replace($receipt, ['notes' => 'Different claimed receipt notes.']))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public static function missingFulfillment(): array
    {
        return ['placed' => ['placed', 3], 'confirmed' => ['confirmed', 2], 'preparing' => ['preparing', 1]];
    }

    #[DataProvider('missingFulfillment')]
    public function test_missing_fulfillment_is_confirmed_now_in_order_without_fake_past_scans(string $stage, int $steps): void
    {
        $parcel = $this->newFlowOrder($stage)->delivery;
        $issue = $this->held($parcel);
        $seller = $parcel->order->shop->user;
        $stock = $parcel->order->items->first()->product->fresh()->stock;
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $ordinaryBefore = $parcel->checkpoints()->whereIn('checkpoint_type', ['seller_pack', 'ready_for_pickup', 'picked_up', 'assigned_pickup'])->count();
        $input = $this->input($issue, $seller, ['barcode' => $parcel->tracking_number, 'fulfillment_verified' => true]);
        $this->postJson('/handover-issues/'.$issue->id.'/confirm-fulfillment', $input)->assertOk();
        $this->assertSame('ready_for_pickup', $parcel->order->fresh()->status);
        $this->assertSame('unassigned', $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->courier_id);
        $this->assertSame($custody, DeliveryCheckpoint::lastCustody($parcel->fresh()));
        $this->assertSame($ordinaryBefore, $parcel->checkpoints()->whereIn('checkpoint_type', ['seller_pack', 'ready_for_pickup', 'picked_up', 'assigned_pickup'])->count());
        $this->assertSame($steps, $parcel->checkpoints()->where('checkpoint_type', 'handover_fulfillment_confirmed')->count());
        $this->assertSame($stock, $parcel->order->items->first()->product->fresh()->stock);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/'.$issue->id.'/confirm-fulfillment', $input)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
        $holder = $this->flowRider($parcel->originBayanHub);
        $this->authorize($issue, $holder);
    }

    public function test_pending_report_allows_normal_work_and_confirmed_hold_rejects_seller_cancellation(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $seller = $parcel->order->shop->user;
        $this->actingAs($seller)->post(route('seller.orders.accept', $parcel->order))->assertSessionHasNoErrors();
        $view = $this->actingAs($this->reviewer($parcel))->get('/handover-issues/'.$issue->id)->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/review', ['decision' => 'confirm', 'notes' => 'The handover concern remains after checking the current records.',
            'evidence_reviewed' => true, 'source_token' => $view['source_token'], 'expected_version' => $view['version'], 'request_token' => (string) Str::uuid()])->assertOk();
        $before = $this->snapshot($parcel);
        $this->actingAs($seller)->postJson(route('seller.orders.cancel', $parcel->order), ['reason' => 'Out of stock / Inventory shortage'])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    private function recovery(string $stage = 'assigned_pickup'): array
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), $stage);
        $holder = User::findOrFail($stage === 'assigned_to_rider' ? $parcel->assigned_rider_id : $parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);

        return [$parcel, $holder, $issue, $grant];
    }

    private function acknowledge(HandoverIssue $issue, HandoverRecoveryGrant $grant, User $holder): void
    {
        $this->actingAs($holder)->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge',
            $this->input($issue, $holder, ['barcode' => $issue->delivery->tracking_number, 'possession_verified' => true]))->assertOk();
    }

    public static function expiryCases(): array
    {
        return ['pickup before acknowledgement' => ['assigned_pickup', false, false], 'pickup after acknowledgement' => ['assigned_pickup', true, false],
            'departure before acknowledgement' => ['assigned_to_rider', false, false], 'departure after acknowledgement' => ['assigned_to_rider', true, false],
            'pickup withdrawn' => ['assigned_pickup', true, true], 'departure withdrawn' => ['assigned_to_rider', true, true]];
    }

    #[DataProvider('expiryCases')]
    public function test_expiry_or_withdrawal_keeps_the_hold_and_fresh_authorization_can_finish(string $stage, bool $ack, bool $withdraw): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery($stage);
        if ($ack) {
            $this->acknowledge($issue, $grant, $holder);
        }
        if ($withdraw) {
            $input = $this->input($issue, $this->reviewer($parcel));
            $this->postJson('/handover-issues/recovery/'.$grant->id.'/withdraw', $input)->assertOk();
            $before = $this->snapshot($parcel);
            $this->postJson('/handover-issues/recovery/'.$grant->id.'/withdraw', $input)->assertOk();
            $this->assertSame($before, $this->snapshot($parcel));
        } else {
            $this->travelTo($grant->expires_at);
        }
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
        $handler = $this->flowHandler($grant->hub);
        $input = $this->input($issue, $ack ? $handler : $holder, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/'.($ack ? 'receive' : 'acknowledge'), $input)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $fresh = $this->authorize($issue, $holder);
        $this->assertNotSame($grant->id, $fresh->id);
        $this->acknowledge($issue, $fresh, $holder);
        $this->postJson('/handover-issues/recovery/'.$fresh->id.'/receive', $this->input($issue, $handler, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]))->assertOk();
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
    }

    public static function sourceChanges(): array
    {
        return array_map(fn ($field) => [$field], ['tracking_number', 'destination_bayan_hub_id', 'current_hub_id', 'courier_id', 'item_quantity', 'item_price', 'shop_owner', 'delivery_address', 'payment_status', 'new_custody']);
    }

    #[DataProvider('sourceChanges')]
    public function test_changed_source_blocks_acknowledgement_even_with_fresh_page_metadata(string $field): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        match ($field) {
            'item_quantity' => $parcel->order->items()->first()->update(['quantity' => $parcel->order->items()->first()->quantity + 1]),
            'item_price' => $parcel->order->items()->first()->update(['unit_price' => '1.00']),
            'shop_owner' => $parcel->order->shop->update(['user_id' => $this->createApprovedUser('buyer')->id]),
            'payment_status' => $parcel->order->update(['payment_status' => 'paid']),
            'new_custody' => DeliveryCheckpoint::record($parcel, 'changed_fixture_source', actor: $holder, evidence: ['custody_after' => ['kind' => 'courier', 'user_id' => $holder->id]]),
            'tracking_number' => $parcel->update(['tracking_number' => 'BGO-CHANGED']),
            'destination_bayan_hub_id', 'current_hub_id' => $parcel->update([$field => $parcel->origin_bayan_hub_id]),
            'courier_id' => $parcel->update(['courier_id' => $this->flowRider($parcel->originBayanHub, $this->createApprovedUser('courier'))->id]),
            default => $parcel->update([$field => 'A changed recipient destination']),
        };
        $input = $this->input($issue, $holder, ['barcode' => $parcel->fresh()->tracking_number, 'possession_verified' => true]);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $input)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public static function lostAuthority(): array
    {
        return array_map(fn ($change) => [$change], ['holder_restriction', 'holder_identity', 'holder_closed', 'holder_placement', 'authorizer_restriction', 'company', 'receiving_hub', 'mother_hub']);
    }

    #[DataProvider('lostAuthority')]
    public function test_authority_changes_invalidate_the_grant_and_do_not_change_custody(string $change): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $input = $this->input($issue, $holder, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        match ($change) {
            'holder_restriction' => $holder->update(['status' => 'inactive']),
            'holder_identity' => $holder->forceFill(['identity_version' => $holder->identity_version + 1])->save(),
            'holder_closed' => $holder->forceFill(['closed_at' => now()])->save(),
            'holder_placement' => $holder->courierProfile->update(['assigned_hub_id' => $parcel->destination_bayan_hub_id]),
            'authorizer_restriction' => $this->reviewer($parcel)->update(['status' => 'inactive']),
            'company' => LogisticsCompany::find($parcel->logistics_company_id)->update(['is_active' => false]),
            'receiving_hub' => $grant->hub->update(['is_active' => false]),
            'mother_hub' => LogisticsHub::find($parcel->origin_mother_hub_id)->forceFill(['restriction_version' => 1])->save(),
        };
        $before = $this->snapshot($parcel);
        $response = $this->actingAs($holder->fresh())->postJson('/custody-recovery/handover/'.$grant->id.'/acknowledge', $input);
        $this->assertContains($response->status(), $change === 'holder_closed' ? [422] : [403, 409]);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public static function restrictedStates(): array
    {
        return [['inactive'], ['suspended']];
    }

    #[DataProvider('restrictedStates')]
    public function test_restricted_holder_can_sign_in_and_acknowledge_only_owned_recovery(string $status): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $holder->update(['status' => $status]);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        $this->post('/custody-recovery/sign-in', ['email' => $holder->email, 'password' => 'password'])->assertRedirect(route('custody-recovery.own'));
        $this->assertAuthenticatedAs($holder);
        $view = $this->get(route('custody-recovery.own'))->assertOk()->inertiaProps('handoverGrants');
        $this->assertCount(1, $view);
        $this->assertArrayNotHasKey('authority_snapshot', $view[0]);
        $this->assertArrayNotHasKey('evidence_path', $view[0]);
        $this->get('/handover-issues/'.$issue->id)->assertForbidden();
        $input = ['notes' => 'I currently hold this exact parcel and will return it to the named hub.',
            'source_token' => $view[0]['source_token'], 'expected_version' => $view[0]['expected_version'], 'request_token' => (string) Str::uuid(),
            'barcode' => $parcel->tracking_number, 'possession_verified' => true];
        $this->postJson('/custody-recovery/handover/'.$grant->id.'/acknowledge', $input)->assertOk();
        $waiting = $this->get(route('custody-recovery.own'))->assertOk()->inertiaProps('handoverGrants');
        $this->assertTrue($waiting[0]['acknowledged']);
        $this->assertFalse($waiting[0]['can_acknowledge']);
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
        $this->postJson('/api/v1/auth/tokens', ['email' => $holder->email, 'password' => 'password', 'device_name' => 'Recovery device'])->assertForbidden();
        $handler = $this->flowHandler($grant->hub);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->input($issue, $handler, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]))->assertOk();
        $this->assertSame($status, $holder->fresh()->status);
        $this->actingAs($holder)->get(route('custody-recovery.own'))->assertOk()->assertInertia(fn ($page) => $page->has('handoverGrants', 0));
    }

    public function test_wrong_waybill_wrong_holder_and_wrong_receiving_authority_do_not_change_records(): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $input = $this->input($issue, $holder, ['barcode' => 'BGO-WRONG', 'possession_verified' => true]);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $input)->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $other = $this->flowRider($grant->hub, $this->createApprovedUser('courier'));
        $this->actingAs($other)->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', array_replace($input, ['barcode' => $parcel->tracking_number]))->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->acknowledge($issue, $grant, $holder);
        $input = $this->input($issue, $holder, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $before = $this->snapshot($parcel);
        foreach ([$holder, $this->reviewer($parcel), $this->createApprovedUser('admin'), $this->flowHandler($parcel->destinationBayanHub)] as $actor) {
            $this->actingAs($actor)->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input)->assertForbidden();
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_competing_intents_and_stale_pages_do_not_create_second_authorization_or_receipt(): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $reviewer = $this->reviewer($parcel);
        $input = $this->input($issue, $reviewer, ['holder_id' => $holder->id, 'holder_verified' => true, 'evidence_reviewed' => true]);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-recovery', $input)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $stale = $this->input($issue, $this->flowHandler($grant->hub), ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $this->acknowledge($issue, $grant, $holder);
        $handler = $this->flowHandler($grant->hub);
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $stale)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->input($issue, $handler, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]))->assertOk();
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->postJson('/handover-issues/recovery/'.$grant->id.'/receive', array_replace($stale, ['expected_version' => $issue->fresh()->version]))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_failed_receipt_rolls_back_custody_checkpoint_order_and_hold_release(): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $this->acknowledge($issue, $grant, $holder);
        $input = $this->input($issue, $this->flowHandler($grant->hub), ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $before = $this->snapshot($parcel);
        Event::listen('eloquent.creating: '.HandoverIssueEvent::class, function ($event) {
            if ($event->event_type === 'recovery_received') {
                throw new RuntimeException('Recovery receipt storage failed.');
            }
        });
        try {
            $this->withoutExceptionHandling()->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $input);
            $this->fail('Receipt failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Recovery receipt storage failed.', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.HandoverIssueEvent::class);
        }
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public static function invalidCommands(): array
    {
        return [['notes', 'short'], ['notes', str_repeat('x', 1001)], ['notes', '          '], ['notes', "Invalid\0notes text"],
            ['expected_version', 0], ['expected_version', '１２'], ['source_token', str_repeat('f', 63)], ['request_token', 'not-a-uuid'],
            ['barcode', 'BGO CODE'], ['barcode', "BGO\nCONTROL"], ['barcode', str_repeat('A', 256)], ['possession_verified', false]];
    }

    #[DataProvider('invalidCommands')]
    public function test_invalid_recovery_fields_are_rejected_before_any_business_write(string $field, mixed $value): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $input = $this->input($issue, $holder, ['barcode' => $parcel->tracking_number, 'possession_verified' => true, $field => $value]);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public static function unavailableEvidence(): array
    {
        return [['missing'], ['altered']];
    }

    #[DataProvider('unavailableEvidence')]
    public function test_missing_or_changed_original_evidence_blocks_the_physical_recovery(string $mode): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $input = $this->input($issue, $holder, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]);
        $mode === 'missing' ? Storage::disk('local')->delete($issue->evidence_path) : Storage::disk('local')->put($issue->evidence_path, 'Changed evidence');
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/acknowledge', $input)->assertStatus($mode === 'missing' ? 404 : 409);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_authorizations_and_recovery_events_are_immutable_in_models_and_direct_sql(): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $before = $this->snapshot($parcel);
        foreach (['handover_recovery_grants' => $grant->id, 'handover_issue_events' => $grant->events()->first()->id] as $table => $id) {
            foreach (['update', 'delete'] as $action) {
                try {
                    $row = DB::table($table)->where('id', $id);
                    $action === 'update' ? $row->update(['notes' => 'Replace the retained authorization.']) : $row->delete();
                    $this->fail('Retained records must reject direct '.$action.'.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString('SQLSTATE', $error->getMessage());
                }
            }
        }
        try {
            $grant->update(['holder_id' => $this->createApprovedUser('courier')->id]);
            $this->fail('The immutable authorization must reject edits.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('immutable', strtolower($error->getMessage()));
        }
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_reviewed_hold_disables_ordinary_seller_and_web_rider_guidance(): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery('assigned_to_rider');
        $this->assertSame(2, $parcel->order->logistics_flow_version);
        $page = $this->actingAs($holder)->get(route('courier.deliveries'))->assertOk()->inertiaProps('queues');
        $this->assertTrue($page['finalMileTasks'][0]['handoverHold']);
        $this->assertNull($page['finalMileTasks'][0]['nextAction']);
        $this->assertStringContainsString('handover hold', $page['finalMileTasks'][0]['tracking']['notice']);
        $this->actingAs($holder)->patch(route('courier.updateStatus', $parcel), ['status' => 'out_for_delivery', 'barcode' => $parcel->tracking_number])->assertSessionHas('error');
        $seller = $parcel->order->shop->user;
        $flags = app(OrderLifecycleService::class)->sellerActionFlags($parcel->order->fresh(), true, true);
        $this->assertTrue($flags['handover_hold']);
        foreach (['can_accept_and_pack', 'can_pack', 'can_mark_ready', 'can_cancel'] as $flag) {
            $this->assertFalse($flags[$flag]);
        }
        $before = $this->snapshot($parcel);
        $this->actingAs($seller)->postJson(route('seller.orders.pack', $parcel->order))->assertConflict();
        $this->actingAs($parcel->order->buyer)->postJson(route('buyer.orders.confirm', $parcel->order))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_recovery_receipt_resumes_the_real_mother_hub_route_without_completing_for_the_buyer(): void
    {
        [$parcel, $holder, $issue, $grant] = $this->recovery();
        $this->acknowledge($issue, $grant, $holder);
        $handler = $this->flowHandler($grant->hub);
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', $this->input($issue, $handler, ['barcode' => $parcel->tracking_number, 'possession_verified' => true]))->assertOk();
        $this->confirmFlowManifest($parcel, $parcel->originBayanHub, true);
        $this->confirmFlowManifest($parcel, $parcel->originMotherHub, false);
        $this->confirmFlowScan($parcel, $parcel->originMotherHub, 'SORT_TO_LINE_HAUL');
        $this->confirmFlowManifest($parcel, $parcel->originMotherHub, true);
        $this->confirmFlowManifest($parcel, $parcel->destinationBayanHub, false);
        $parcel->refresh();
        $this->assertSame('arrived_at_destination_hub', $parcel->status);
        $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
        $this->assertTrue($parcel->checkpoints()->where('hub_id', $parcel->origin_mother_hub_id)->where('checkpoint_type', 'arrived_at_mother_hub')->exists());
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->assertNull($parcel->order->fresh()->completed_at);
        $public = app(PublicTrackingService::class)->find($parcel->tracking_number);
        $json = json_encode($public);
        $this->assertStringNotContainsString($issue->reference, $json);
        $this->assertStringNotContainsString($grant->reference, $json);
        $this->assertStringNotContainsString($issue->evidence_path, $json);
        $this->assertStringContainsString('Actual recovery receipt', $json);
    }

    public function test_native_pickup_cannot_bypass_the_shared_hold_or_leave_a_committed_command(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $this->held($parcel);
        $this->app['auth']->forgetGuards();
        $this->withToken(app(RiderAccountService::class)->session($holder, 'Recovery fixture')['token']);
        $this->getJson('/api/v1/rider/tasks/pickup-'.$parcel->id)->assertOk()->assertJsonPath('data.handover_hold', true)->assertJsonPath('data.actions.pickup', false);
        $before = $this->snapshot($parcel);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/rider/tasks/pickup-'.$parcel->id.'/pickup',
            ['expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])), 'barcode' => $parcel->tracking_number])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertDatabaseCount('rider_commands', 0);
    }

    public static function nativeOutcomes(): array
    {
        return [['assigned_to_rider', 'depart'], ['out_for_delivery', 'deliver'], ['out_for_delivery', 'fail']];
    }

    #[DataProvider('nativeOutcomes')]
    public function test_native_departure_and_outcomes_cannot_bypass_holds_or_leave_proof_or_cash(string $stage, string $action): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $stage);
        $holder = User::findOrFail($parcel->assigned_rider_id);
        $this->held($parcel);
        $this->app['auth']->forgetGuards();
        $this->withToken(app(RiderAccountService::class)->session($holder, 'Recovery fixture')['token']);
        $base = ['barcode' => $parcel->tracking_number, 'expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order']))];
        $input = $base + match ($action) {
            'deliver' => ['recipient_name' => $parcel->order->recipient_name, 'recipient_relationship' => 'buyer', 'cash_received' => (string) $parcel->order->total_amount,
                'change_given' => '0.00', 'cash_confirmed' => true, 'proof_image_file' => UploadedFile::fake()->image('handoff.png')],
            'fail' => ['reason' => 'customer_unreachable', 'notes' => 'Waited and called at the buyer saved address.', 'location_name' => 'Saved address', 'proof_image_file' => UploadedFile::fake()->image('attempt.png')],
            default => [],
        };
        $this->getJson('/api/v1/rider/tasks/final_mile-'.$parcel->id)->assertOk()->assertJsonPath('data.actions.'.$action, false);
        $before = $this->snapshot($parcel);
        $files = Storage::disk('local')->allFiles();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/'.$action, $input)->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('rider_commands', 0);
    }

    public function test_ordinary_pickup_claim_and_hub_scan_remain_blocked_with_clear_guidance(): void
    {
        $parcel = $this->newFlowOrder('ready_for_pickup')->delivery;
        $this->held($parcel);
        $holder = $this->flowRider($parcel->originBayanHub);
        $before = $this->snapshot($parcel);
        $this->actingAs($holder)->post(route('courier.claim', $parcel))->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($parcel));
        $page = $this->get(route('courier.deliveries'))->assertOk()->inertiaProps('queues');
        $this->assertSame([], $page['availablePickups']);
        $grant = $this->authorize(HandoverIssue::sole(), $holder);
        $this->acknowledge($grant->issue, $grant, $holder);
        $handler = $this->flowHandler($grant->hub);
        $this->actingAs($handler)->postJson(route('hub.scan'), ['hub_id' => $grant->hub_id, 'barcode' => $parcel->tracking_number, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('prompt.requires_confirmation', false)->assertJsonPath('prompt.action', 'AWAIT_HANDOVER_RECOVERY')
            ->assertJsonPath('delivery.guidance.href', '/handover-issues/'.$grant->handover_issue_id);
        $before = $this->snapshot($parcel);
        $this->postJson(route('hub.scan'), ['hub_id' => $grant->hub_id, 'barcode' => $parcel->tracking_number, 'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up'])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_manifest_receipt_and_batch_work_roll_back_while_discrepancy_observations_remain_available(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $this->held($parcel);
        $handler = $this->flowHandler($manifest->destinationHub);
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest, ['kind' => 'damaged', 'barcode' => $parcel->tracking_number, 'notes' => 'Observed the original parcel while checking its current hold.']))->assertOk();
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame($before['parcel'], $parcel->fresh()->getAttributes());
    }

    private function assertHeldWrite(Delivery $parcel, callable $write): void
    {
        $before = $this->snapshot($parcel);
        try {
            $write();
            $this->fail('The reviewed hold must reject new custody work.');
        } catch (HandoverHoldException $error) {
            $this->assertStringContainsString('reviewed handover hold', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_counter_staging_release_and_expiry_return_respect_hold_without_extending_seven_days(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $hub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', 'Santa Cruz')->sole();
        $hub->update(['allows_self_pickup' => true, 'operating_hours' => 'Monday to Saturday, 08:00 to 17:00']);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, shipping: ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id]);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $handler = $this->flowHandler($hub);
        $claims = app(PickupClaimService::class);
        $claims->stage($parcel, $handler, $hub, $parcel->tracking_number, $parcel->status);
        $code = $claims->issue($parcel->fresh(), $order->buyer);
        $claim = $parcel->fresh()->pickupClaim;
        $expiry = $claim->expires_at;
        $this->held($parcel->fresh());
        $this->assertHeldWrite($parcel, fn () => $claims->stage($parcel, $handler, $hub, $parcel->tracking_number, 'ready_for_hub_pickup'));
        $this->assertHeldWrite($parcel, fn () => $claims->issue($parcel, $order->buyer));
        $this->assertHeldWrite($parcel, fn () => $claims->release($parcel, $handler, $hub, ['barcode' => $parcel->tracking_number, 'claim_code' => $code,
            'buyer_id' => $order->buyer_id, 'recipient_name' => $order->buyer->name, 'identity_confirmed' => true, 'request_token' => (string) Str::uuid()]));
        $this->assertNotNull($claims->collectionBlocker($parcel->fresh(), $hub));
        $this->travelTo($expiry);
        $claims->processDue();
        $this->assertSame('expired', $claim->fresh()->status);
        $this->assertTrue($expiry->equalTo($claim->fresh()->expires_at));
        $this->assertHeldWrite($parcel, fn () => $claims->beginReturn($parcel, $handler, $hub, $parcel->tracking_number, 'ready_for_hub_pickup', $claim->reference));
    }

    public function test_seller_batch_readiness_and_route_planning_reject_a_held_member_atomically(): void
    {
        $first = $this->newFlowOrder('preparing');
        $second = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $first->shop, [], 'preparing');
        $this->held($second->delivery);
        $before = $first->fresh()->getAttributes();
        $service = app(OrderLifecycleService::class);
        $this->assertHeldWrite($second->delivery, fn () => $service->sellerBatchReady(['order_ids' => [$first->id, $second->id]], $first->shop, $first->shop->user));
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertHeldWrite($second->delivery, fn () => app(LogisticsRoutingEngine::class)->planDeliveryRoute($second->delivery, $second, $first->shop));
    }

    public function test_failed_attempt_return_retry_and_seller_reverse_receipt_cannot_bypass_a_hold(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($parcel);
        $this->receiveFailureFlow($parcel);
        $hub = $parcel->destinationBayanHub;
        $handler = $this->flowHandler($hub);
        $recovery = app(DeliveryRecoveryService::class);
        $attempt = $recovery->latestAttempt($parcel->refresh());
        $retry = ['notes' => 'The actual buyer confirmed a later delivery date.', 'request_token' => (string) Str::uuid(),
            'retry_at' => now('Asia/Manila')->addMinutes(10)->format('Y-m-d\TH:i'), 'attempt_reference' => $attempt->reference];
        $recovery->approveRetry($parcel, $this->reviewer($parcel), $retry);
        $this->held($parcel->fresh());
        $this->travel(11)->minutes();
        $this->assertHeldWrite($parcel, fn () => $recovery->receive($parcel, $handler, $hub, $parcel->tracking_number, 'delivery_failed', $attempt->reference));
        $this->assertHeldWrite($parcel, fn () => $recovery->approveRetry($parcel, $this->reviewer($parcel), array_replace($retry, ['request_token' => (string) Str::uuid()])));
        $this->assertHeldWrite($parcel, fn () => $recovery->beginRetry($parcel, $handler, $hub, $parcel->tracking_number, 'delivery_failed', $attempt->reference));

        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $returns = app(DeliveryReturnService::class);
        $route = $returns->route($parcel->fresh());
        foreach (array_slice($route->hub_ids, 1) as $hubId) {
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->fresh()->current_hub_id), true, direction: 'return');
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($hubId), false, direction: 'return');
        }
        $origin = $parcel->originBayanHub;
        $handler = $this->flowHandler($origin);
        $returns->stage($parcel, $handler, $origin, $parcel->tracking_number, $parcel->fresh()->status, $route->reference);
        $this->held($parcel->fresh());
        $this->assertHeldWrite($parcel, fn () => $returns->stage($parcel, $handler, $origin, $parcel->tracking_number, $parcel->fresh()->status, $route->reference));
        $this->assertHeldWrite($parcel, fn () => $returns->sellerReceive($parcel, $parcel->order->shop->user, $parcel->order->shop,
            ['barcode' => $parcel->tracking_number, 'route_reference' => $route->reference, 'notes' => 'I received the original returned parcel.', 'request_token' => (string) Str::uuid()]));
    }

    public function test_other_parcel_and_restricted_custody_authorizations_cannot_compete_with_handover_hold(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $holder = User::findOrFail($parcel->courier_id);
        $this->restrictFlowAccount($holder, 'suspend');
        $work = RestrictionAffectedWork::where('delivery_id', $parcel->id)->latest('id')->firstOrFail();
        $owner = $this->reviewer($parcel);
        $legacy = app(RestrictedCustodyRecoveryService::class);
        $proposal = $legacy->proposal($owner, $work);
        $this->held($parcel);
        $this->assertHeldWrite($parcel, fn () => $legacy->grant($owner, $work, ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'reason' => 'Return this parcel to its original facility.']));

    }

    public function test_other_physical_recovery_authorization_cannot_compete_with_the_handover_hold(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $owner = $this->reviewer($parcel);
        $other = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_destination_hub');
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($other)['manifest_id']);
        $handler = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'wrong_hub', 'barcode' => $parcel->tracking_number, 'notes' => 'Observed the unexpected actual parcel in this arriving load.']))->assertOk();
        $observation = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $physical = app(ParcelRecoveryService::class);
        $proposal = $physical->proposal($owner, $observation);
        $this->held($parcel);
        $this->assertHeldWrite($parcel, fn () => $physical->authorize($owner, $observation, ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'notes' => 'Recover this original parcel to its expected hub.']));
    }
}
