<?php

namespace Tests\Feature\Admin;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\ExceptionDecision;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\PickupClaim;
use App\Models\RestrictionAffectedWork;
use App\Models\User;
use App\Services\Logistics\PickupClaimService;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class ExceptionOversightTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function parcel(): Delivery
    {
        return $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'out_for_delivery');
    }

    private function manager(Delivery $parcel): User
    {
        return $parcel->company->user;
    }

    private function detail(User $actor, string $kind, int $id): array
    {
        return $this->actingAs($actor)->getJson('http://localhost/exceptions/'.$kind.'/'.$id)->assertOk()->json('exception');
    }

    private function command(array $detail, string $action = 'resolve', ?User $person = null): array
    {
        return ['source_token' => $detail['sourceToken'], 'request_token' => (string) Str::uuid(), 'action' => $action,
            'reason' => 'Reviewed the actual source records and responsible handover.'] + ($person ? ['responsible_user_id' => $person->id] : []);
    }

    private function snapshot(Delivery $parcel): array
    {
        $state = ['order' => $parcel->order->fresh()->getAttributes(), 'delivery' => $parcel->fresh()->getAttributes()];
        foreach (['delivery_checkpoints', 'delivery_attempts', 'delivery_recovery_events', 'delivery_return_routes', 'delivery_return_events', 'pickup_claims', 'pickup_claim_events', 'cod_custody_entries', 'restriction_affected_work', 'restriction_decisions', 'users', 'products'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    private function restricted(): array
    {
        $parcel = $this->parcel();
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->restrictFlowAccount($rider, 'suspend');
        $work = RestrictionAffectedWork::where('delivery_id', $parcel->id)->latest('id')->firstOrFail();

        return [$parcel->fresh(), $rider, $work];
    }

    public static function hosts(): array
    {
        return [['http://localhost'], ['http://admin.localhost'], ['http://hub.localhost']];
    }

    #[DataProvider('hosts')]
    public function test_governance_assignment_and_owned_receipt_preserve_physical_cash_and_restriction_history(string $host): void
    {
        [$parcel, $rider, $work] = $this->restricted();
        $manager = $this->manager($parcel);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $detail = $this->detail($manager, 'restriction', $work->id);
        $this->assertSame($rider->name, $detail['state']['holderName']);
        $this->assertFalse($detail['canResolve']);
        $this->assertSame('/custody-recovery/work/'.$work->id, $detail['recoveryUrl']);
        $before = $this->snapshot($parcel);
        $input = $this->command($detail, 'assign', $handler);
        $this->actingAs($manager)->postJson($host.'/exceptions/restriction/'.$work->id, $input)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $decision = ExceptionDecision::sole();
        $this->assertSame($handler->id, $decision->responsible_user_id);
        $this->assertSame($manager->id, $decision->actor_id);
        $this->postJson($host.'/exceptions/restriction/'.$work->id, $input)->assertJsonPath('reference', $decision->reference);
        $this->assertDatabaseCount('exception_decisions', 1);
        $stale = $this->command($detail);
        $this->postJson($host.'/exceptions/restriction/'.$work->id, $stale)->assertConflict();
        $service = app(RestrictedCustodyRecoveryService::class);
        $proposal = $service->proposal($manager, $work);
        $grant = $service->grant($manager, $work, ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'reason' => 'Arrange actual receipt at the original delivery hub.']);
        $receipt = ['barcode' => $parcel->tracking_number, 'notes' => 'The original parcel was counted at the receiving hub.', 'request_token' => (string) Str::uuid()];
        $this->actingAs($rider)->postJson('/custody-recovery/'.$grant->id.'/handover', $receipt)->assertOk();
        $detail = $this->detail($manager, 'restriction', $work->id);
        $this->assertFalse($detail['canResolve']);
        $this->actingAs($manager)->postJson('/exceptions/restriction/'.$work->id, $this->command($detail))->assertConflict();
        $this->actingAs($handler)->postJson('/custody-recovery/'.$grant->id.'/receipt', array_replace($receipt, ['request_token' => (string) Str::uuid()]))->assertOk();
        $this->actingAs($manager)->postJson('/exceptions/restriction/'.$work->id, $this->command($detail))->assertConflict();
        $detail = $this->detail($manager, 'restriction', $work->id);
        $this->assertTrue($detail['canResolve']);
        $before = $this->snapshot($parcel);
        $input = $this->command($detail);
        $response = $this->postJson('/exceptions/restriction/'.$work->id, $input)->assertOk();
        $this->postJson('/exceptions/restriction/'.$work->id, $input)->assertJsonPath('reference', $response->json('reference'));
        $this->postJson('/exceptions/restriction/'.$work->id, array_replace($input, ['reason' => 'Different outcome']))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertDatabaseCount('exception_decisions', 2);
        $this->assertSame('suspended', $rider->fresh()->status);
        $this->assertSame('unverified', $this->detail($manager, 'restriction', $work->id)['state']['cash']['reconciliation']);
        $this->getJson('/exceptions?status=resolved')->assertJsonPath('exceptions.total', 1);
    }

    public function test_scope_current_eligibility_and_responsible_identity_are_checked_for_every_action(): void
    {
        [$parcel, $rider, $work] = $this->restricted();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'restriction', $work->id);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id));
        $foreignManager = $this->createApprovedUser('logistics');
        LogisticsCompany::create(['user_id' => $foreignManager->id, 'name' => 'Independent Bagoo Logistics', 'code' => 'INDEP', 'slug' => 'independent-bagoo-logistics', 'status' => 'active', 'is_active' => true]);
        $before = $this->snapshot($parcel);
        foreach ([$foreignManager, $handler, $rider, $parcel->order->buyer, $parcel->order->shop->user] as $actor) {
            $this->actingAs($actor)->getJson('/exceptions/restriction/'.$work->id)->assertForbidden();
            $this->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $manager))->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot($parcel));
        $this->actingAs($foreignManager)->getJson('/exceptions')->assertJsonPath('exceptions.total', 0);
        $admin = $this->createApprovedUser('admin');
        $this->detail($admin, 'restriction', $work->id);
        $this->actingAs($manager)->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $foreignManager))->assertConflict();
        $this->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $rider))->assertConflict();
        $manager->update(['status' => 'suspended']);
        $this->actingAs($manager)->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $handler))->assertForbidden();
        $this->assertDatabaseCount('exception_decisions', 0);
    }

    public function test_failed_attempt_cannot_close_before_real_hub_return_retry_and_new_departure(): void
    {
        $parcel = $this->parcel();
        $this->reportFlowFailure($parcel);
        $attempt = DeliveryAttempt::sole();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'attempt', $attempt->id);
        $this->assertFalse($detail['canResolve']);
        $this->assertStringNotContainsString('proof_path', json_encode($detail));
        $this->assertStringNotContainsString($attempt->proof_path, json_encode($detail));
        $this->actingAs($manager)->get('/exceptions/attempt/'.$attempt->id.'/proof')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $before = $this->snapshot($parcel);
        $this->postJson('/exceptions/attempt/'.$attempt->id, $this->command($detail))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->receiveFailureFlow($parcel);
        $this->assertFalse($this->detail($manager, 'attempt', $attempt->id)['canResolve']);
        $this->retryFailureFlow($parcel);
        $this->assertTrue($this->detail($manager, 'attempt', $attempt->id)['canResolve']);
        $before = $this->snapshot($parcel);
        $this->postJson('/exceptions/attempt/'.$attempt->id, $this->command($this->detail($manager, 'attempt', $attempt->id)))->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_third_attempt_requires_real_reverse_route_and_owning_seller_receipt(): void
    {
        $parcel = $this->parcel();
        for ($number = 1; $number <= 3; $number++) {
            $this->actingAs(User::findOrFail($parcel->fresh()->assigned_rider_id))->patch(route('courier.updateStatus', $parcel), $this->flowFailurePayload($parcel))->assertSessionHas('success');
            $this->receiveFailureFlow($parcel);
            if ($number < 3) {
                $this->retryFailureFlow($parcel);
            }
        }
        $attempt = DeliveryAttempt::where('attempt_number', 3)->sole();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'attempt', $attempt->id);
        $this->assertFalse($detail['canResolve']);
        $this->actingAs($manager)->postJson('/exceptions/attempt/'.$attempt->id, $this->command($detail))->assertConflict();
        $this->returnToSellerFlow($parcel);
        $detail = $this->detail($manager, 'attempt', $attempt->id);
        $this->assertTrue($detail['canResolve']);
        $this->postJson('/exceptions/attempt/'.$attempt->id, $this->command($detail))->assertOk();
        $this->assertDatabaseCount('delivery_attempts', 3);
        $this->assertSame('returned', $parcel->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
    }

    private function pickup(): Delivery
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $hub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', 'Santa Cruz')->sole();
        $hub->update(['allows_self_pickup' => true]);
        $this->actingAs($hub->company->user)->postJson('/hub/counter/hours', ['hub_id' => $hub->id, 'operating_hours' => 'Monday to Saturday, 08:00 to 17:00'])->assertOk();
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, shipping: ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id]);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'STAGE_FOR_PICKUP', 'expected_status' => 'arrived_at_destination_hub'])->assertOk();

        return $parcel->fresh();
    }

    public function test_counter_oversight_never_reveals_codes_or_releases_and_keeps_recorded_cash_holder(): void
    {
        $parcel = $this->pickup();
        $claim = PickupClaim::sole();
        $code = $this->actingAs($parcel->order->buyer)->postJson('/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertOk()->json('code');
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'pickup', $claim->id);
        $this->assertStringNotContainsString($code, json_encode($detail));
        $this->assertStringNotContainsString($claim->fresh()->getRawOriginal('code_hash'), json_encode($detail));
        $before = $this->snapshot($parcel);
        $this->postJson('/exceptions/pickup/'.$claim->id, $this->command($detail))->assertConflict();
        $this->postJson('/exceptions/pickup/'.$claim->id, $this->command($detail) + ['code' => $code])->assertJsonValidationErrors('code');
        $this->assertSame($before, $this->snapshot($parcel));
        $handler = $this->flowHandler(LogisticsHub::findOrFail($claim->hub_id));
        $this->actingAs($handler)->postJson('/hub/release', ['barcode' => $parcel->tracking_number, 'hub_id' => $claim->hub_id,
            'claim_code' => $code, 'buyer_id' => $parcel->order->buyer_id, 'recipient_name' => $parcel->order->buyer->name, 'identity_confirmed' => true,
            'cash_received' => (string) $parcel->order->total_amount, 'change_given' => '0', 'cash_confirmed' => true, 'request_token' => (string) Str::uuid(), 'notes' => 'Actual buyer ID and counted cash checked.'])->assertOk();
        $detail = $this->detail($manager, 'pickup', $claim->id);
        $this->assertTrue($detail['canResolve']);
        $this->assertSame($handler->name, $detail['state']['cash']['holderName']);
        $before = $this->snapshot($parcel);
        $this->postJson('/exceptions/pickup/'.$claim->id, $this->command($detail))->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
    }

    public function test_expired_holding_and_stale_clock_require_actual_return_and_seller_receipt(): void
    {
        $parcel = $this->pickup();
        $claim = PickupClaim::sole();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'pickup', $claim->id);
        $this->travelTo($claim->expires_at);
        $this->actingAs($manager)->postJson('/exceptions/pickup/'.$claim->id, $this->command($detail, 'assign', $manager))->assertConflict();
        app(PickupClaimService::class)->processDue();
        $this->assertFalse($this->detail($manager, 'pickup', $claim->id)['canResolve']);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($claim->hub_id));
        $this->actingAs($handler)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $claim->hub_id, 'mode' => 'confirm',
            'action' => 'START_EXPIRED_PICKUP_RETURN', 'expected_status' => 'ready_for_hub_pickup', 'claim_reference' => $claim->reference])->assertOk();
        $this->assertFalse($this->detail($manager, 'pickup', $claim->id)['canResolve']);
        $this->returnToSellerFlow($parcel);
        $detail = $this->detail($manager, 'pickup', $claim->id);
        $this->assertTrue($detail['canResolve']);
        $this->postJson('/exceptions/pickup/'.$claim->id, $this->command($detail))->assertOk();
    }

    public function test_manifest_observation_requires_real_receipt_and_source_owned_resolution(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'in_transit_to_mother_hub');
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $member = $manifest->parcels()->sole();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest, ['kind' => 'missing', 'parcel_id' => $member->id, 'barcode' => null, 'notes' => 'Expected parcel was not found on the first unload.']))->assertOk();
        $source = LogisticsManifestEvent::where('event_type', 'report-discrepancy')->sole();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'manifest', $source->id);
        $this->postJson('/exceptions/manifest/'.$source->id, $this->command($detail))->assertConflict();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->assertFalse($this->detail($manager, 'manifest', $source->id)['canResolve']);
        $this->postJson('/hub/manifests/'.$manifest->id.'/resolve-discrepancy', $this->flowManifestPayload($manifest, ['source_event_id' => $source->id, 'notes' => 'The expected parcel was found and actually scanned.']))->assertOk();
        $detail = $this->detail($manager, 'manifest', $source->id);
        $this->assertTrue($detail['canResolve']);
        $before = $this->snapshot($parcel);
        $this->postJson('/exceptions/manifest/'.$source->id, $this->command($detail))->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_stale_responsibility_controls_and_audit_failure_preserve_all_original_sources(): void
    {
        [$parcel, , $work] = $this->restricted();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'restriction', $work->id);
        $before = $this->snapshot($parcel);
        $this->postJson('/exceptions/restriction/'.$work->id, array_replace($this->command($detail, 'assign', $manager), ['reason' => "\tHidden control text"]))->assertJsonValidationErrors('reason');
        $this->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $manager) + ['actor_id' => $manager->id])->assertJsonValidationErrors('actor_id');
        $this->assertSame($before, $this->snapshot($parcel));
        Event::listen('eloquent.creating: '.ExceptionDecision::class, fn () => throw new \RuntimeException('Oversight audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $manager));
            $this->fail('Expected an audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Oversight audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('exception_decisions', 0);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_missing_physical_source_stays_blocked_and_governance_history_cannot_be_rewritten(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $parcel = $this->flowDelivery($order, 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        $this->restrictFlowAccount($rider, 'suspend');
        $work = RestrictionAffectedWork::where('delivery_id', $parcel->id)->sole();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'restriction', $work->id);
        $this->assertFalse($detail['canResolve']);
        $this->assertNull($detail['recoveryUrl']);
        $before = $this->snapshot($parcel);
        $this->postJson('/exceptions/restriction/'.$work->id, $this->command($detail))->assertConflict();
        $this->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $manager))->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('exception_decisions');
                $operation === 'update' ? $query->update(['reason' => 'Rewrite original reason']) : $query->delete();
                $this->fail('Expected immutable SQL evidence.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('exception_decisions', 1);
    }

    public function test_legacy_labels_are_visible_blockers_and_cannot_create_or_close_source_records(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $parcel = $order->delivery;
        // This deliberately represents an ambiguous old label, not a successful custody writer.
        $parcel->update(['status' => 'delivery_failed']);
        $manager = $this->manager($parcel);
        $this->actingAs($manager)->getJson('/exceptions')->assertOk()->assertJsonPath('sourceGaps.total', 1)->assertJsonPath('sourceGaps.data.0.trackingNumber', $parcel->tracking_number);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('exception_decisions', 0);
        $this->getJson('/exceptions/attempt/999')->assertNotFound();
        $this->assertSame('delivery_failed', $parcel->fresh()->status);
    }

    public function test_private_attempt_proof_requires_current_company_authority_and_original_file(): void
    {
        $parcel = $this->parcel();
        $this->reportFlowFailure($parcel);
        $attempt = DeliveryAttempt::sole();
        $manager = $this->manager($parcel);
        foreach ([$parcel->order->buyer, User::findOrFail($attempt->rider_id), $this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id))] as $actor) {
            $this->actingAs($actor)->get('/exceptions/attempt/'.$attempt->id.'/proof')->assertForbidden();
        }
        $this->actingAs($this->createApprovedUser('admin'))->get('/exceptions/attempt/'.$attempt->id.'/proof')->assertOk();
        Storage::disk('local')->put($attempt->proof_path, 'Changed original proof');
        $detail = $this->detail($manager, 'attempt', $attempt->id);
        $this->assertFalse($detail['state']['source']['details']['proofAvailable']);
        $this->get('/exceptions/attempt/'.$attempt->id.'/proof')->assertConflict();
        Storage::disk('local')->delete($attempt->proof_path);
        $this->get('/exceptions/attempt/'.$attempt->id.'/proof')->assertNotFound();
    }

    public function test_sql_decision_requires_exactly_one_real_foreign_source(): void
    {
        [$parcel, , $work] = $this->restricted();
        $manager = $this->manager($parcel);
        $detail = $this->detail($manager, 'restriction', $work->id);
        $this->postJson('/exceptions/restriction/'.$work->id, $this->command($detail, 'assign', $manager))->assertOk();
        $invalid = ExceptionDecision::sole()->getAttributes();
        unset($invalid['id']);
        $invalid['reference'] = 'EXD-'.Str::uuid();
        $invalid['request_token'] = (string) Str::uuid();
        $invalid['restriction_affected_work_id'] = null;
        try {
            DB::table('exception_decisions')->insert($invalid);
            $this->fail('Expected a source constraint rejection.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('exactly one actual exception source', $error->getMessage());
        }
        $this->assertDatabaseCount('exception_decisions', 1);
    }
}
