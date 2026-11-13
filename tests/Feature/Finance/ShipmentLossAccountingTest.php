<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossAccounting;
use App\Models\WalletTransaction;
use App\Services\Finance\ShipmentLossAccountingService;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Finance\ShipmentLossWallet;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsTripService;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Orders\OrderTrackingService;
use App\Services\Returns\BuyerReturnRecoveryService;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletLossCreditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossAccountingTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function reviewed(bool $return = false, string $mode = 'wallet', bool $disposition = true): array
    {
        [$observation, $admin, $order, $case] = $this->missing($mode, $return);
        $plan = app(ShipmentLossReview::class)->approve($admin, $observation, $this->approval($observation, $admin, $return ? '0.00' : '180.00'),
            UploadedFile::fake()->image('actual-reviewed-loss.png'));
        $record = $disposition ? app(ShipmentLossDispositionService::class)->record($admin, $plan,
            ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'reason' => 'Independent original operational investigation confirms this particular typed missing parcel.',
                'disposition_confirmed' => true], UploadedFile::fake()->image('actual-operational-disposition.png')) : null;

        return [$observation, $plan, $admin, $order, $case, $record];
    }

    private function input(ShipmentFinancialPlan $plan, array $extra = []): array
    {
        return ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'reason' => 'Account for the independently confirmed original parcel loss while retaining its actual missing custody and every original transport identity.',
            'loss_accounting_confirmed' => true, ...$extra];
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    public function test_public_accounting_is_independent_admin_only_and_floor_receipts_expose_no_financial_or_private_evidence(): void
    {
        [$observation, $plan, $admin, $order] = $this->reviewed();
        $url = '/shipment-finances/'.$plan->id;
        $input = ['action' => 'account-loss', ...$this->input($plan)];
        $this->actingAs($admin)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.controls.actions', fn ($actions) => in_array('account-loss', array_column($actions, 'id'), true));
        $this->postJson($url.'/actions', $input)->assertOk()->assertJsonPath('event.event_type', 'loss-accounted');
        $this->postJson($url.'/actions', $input)->assertOk()->assertJsonPath('event.event_type', 'loss-accounted');
        $this->get($url, $this->inertia())->assertOk()->assertJsonPath('props.loss.controls.accounting.verified', true)
            ->assertJsonPath('props.loss.controls.actions', fn ($actions) => ! in_array('account-loss', array_column($actions, 'id'), true));
        $this->actingAs($order->buyer)->get($url, $this->inertia())->assertOk()->assertJsonPath('props.loss.controls.accounting', null);
        $this->postJson($url.'/actions', ['action' => 'account-loss', ...$this->input($plan)])->assertForbidden();
        $handler = $this->flowHandler($observation->manifest->destinationHub);
        $this->actingAs($handler)->get('/hub/manifests/'.$observation->manifest_id, $this->inertia())->assertOk()
            ->assertJsonPath('props.selectedManifest.receiptCounts.confirmed_lost', 1)
            ->assertJsonPath('props.selectedManifest.receiptCounts.normal_received', 0)
            ->assertJsonPath('props.selectedManifest.parcels.0.confirmed_lost', true)
            ->assertJsonPath('props.selectedManifest.parcels.0.received_at', null);
        $this->get($url)->assertNotFound();
        $this->assertSame(1, ShipmentLossAccounting::count());
        $tracking = app(OrderTrackingService::class)->present($order->fresh(['delivery.latestCustodyCheckpoint', 'delivery.checkpoints']));
        $this->assertSame('Confirmed parcel loss', $tracking['stage_label']);
        $this->assertSame('Last known transport; parcel confirmed lost', $tracking['custodian']);
    }

    public function test_wallet_funding_continues_from_the_original_paid_source_after_supported_loss_closure_and_exact_accounting_retries_do_not_move_more_money(): void
    {
        [$observation, $plan, $admin] = $this->reviewed();
        $input = $this->input($plan);
        $service = app(ShipmentLossAccountingService::class);
        $record = $service->record($admin, $plan, $input);
        $this->actingAs($observation->manifest->company->user)->postJson('/hub/manifests/'.$observation->manifest_id.'/close',
            $this->flowManifestPayload($observation->manifest))->assertOk();
        $before = WalletTransaction::count();
        $event = app(ShipmentLossWallet::class)->command($admin, $plan, 'reserve-original', [
            'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'reason' => 'Reserve only the original actual paid source after separately supported physical loss accounting.', 'original_reserve_confirmed' => true]);
        $this->assertSame(25000, $event->amount_cents);
        $this->assertSame($before + 1, WalletTransaction::count());
        $this->assertSame($record->id, $service->record($admin, $plan, $input)->id);
        $this->assertSame($before + 1, WalletTransaction::count());
        app(ShipmentLossSource::class)->assertApproved($plan);
        $this->quarantineMissing($plan);
        $this->assertTrue($service->verified($record));
        $this->assertSame(1, app(LogisticsManifestService::class)->receiptCounts($observation->manifest)['confirmed_lost']);
        try {
            app(ShipmentLossSource::class)->assertApproved($plan);
            $this->fail('A later actual finding must hold new compensation without erasing original loss accounting.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_an_actual_failed_delivery_rts_loss_can_be_accounted_without_a_seller_receipt_or_completed_reverse_route(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->fresh()->current_hub_id), true, direction: 'return');
        $manifest = LogisticsManifest::where('direction', 'return')->where('status', 'dispatched')->sole();
        $member = $manifest->parcels()->where('delivery_id', $parcel->id)->sole();
        $this->actingAs($this->flowHandler($manifest->destinationHub))->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'The actual departed RTS transport lacks this particular expected original parcel.']))->assertOk();
        $source = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $admin = $this->createApprovedUser('admin');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('rts-loss-review.png'));
        app(ShipmentLossDispositionService::class)->record($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'reason' => 'Independent operational evidence confirms the particular parcel missing on actual RTS transport.', 'disposition_confirmed' => true], UploadedFile::fake()->image('rts-loss-disposition.png'));
        app(ShipmentLossAccountingService::class)->record($admin, $plan, $this->input($plan));
        $this->actingAs($manifest->company->user)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertSame('delivery_failed', $order->fresh()->status);
        $this->assertSame('return_in_transit', $parcel->fresh()->status);
        $this->assertNull($member->fresh()->received_at);
        $this->assertSame(0, $parcel->checkpoints()->where('checkpoint_type', 'parcel_returned')->count());
        app(ShipmentLossSource::class)->assertApproved($plan);
    }

    public function test_actual_return_sack_arrival_can_finish_a_lost_return_trip_without_a_fictitious_parcel_or_seller_receipt(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $order = $this->flowDelivery($order, 'delivered')->order->fresh(['buyer', 'items']);
        $returns = app(BuyerReturnService::class);
        $case = $returns->request($order->buyer, $order, ['request_token' => $returns->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The actual delivered original unit has a broken fastening and cannot be used.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('original-damaged-unit.png')]);
        $returns->command($order->shop->user, $case, 'approve', ['expected_version' => $case->version, 'request_token' => (string) Str::uuid(),
            'fault' => 'seller', 'reason' => 'The owning seller accepts the evidenced original defect and actual return for independent logistics handling.']);
        $case = $case->fresh(['parcel', 'order', 'items']);
        $this->intake($case);
        $this->measureReturn($case);
        $manifest = $this->prepareLeg($case);
        $handler = $this->flowHandler($manifest->sourceHub);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest,
            ['barcode' => $case->parcel->barcode]))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $trips = app(LogisticsTripService::class);
        $trip = $trips->create($handler, ['manifest_ids' => [$manifest->id], 'request_token' => (string) Str::uuid()]);
        $bag = $this->sealTripReturn($trip, $handler, $case->parcel->barcode);
        $trips->command($trip, $handler, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        $member = $manifest->buyerReturnParcels()->sole();
        $receiver = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'This actual departed return sack load has one original parcel missing at arrival.']))->assertOk();
        $source = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $admin = $this->createApprovedUser('admin');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, '0.00'), UploadedFile::fake()->image('sack-loss-review.png'));
        app(ShipmentLossDispositionService::class)->record($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'reason' => 'Independent operational evidence confirms the actual particular missing return parcel in this original sack load.', 'disposition_confirmed' => true], UploadedFile::fake()->image('sack-loss-disposition.png'));
        $before = [$case->order->fresh()->getAttributes(), $case->parcel->fresh()->state(), WalletTransaction::count()];
        app(ShipmentLossAccountingService::class)->record($admin, $plan, $this->input($plan));
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/close', ['version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertSame('in_transit', $bag->fresh()->status);
        $this->openTripReturn($trip, $bag, $receiver);
        $this->assertSame('received', $bag->fresh()->status);
        $this->assertNull($bag->fresh()->active_physical_key);
        $this->assertNull($bag->returnParcels()->sole()->received_at);
        $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());
        $this->postJson('/hub/manifests/trips/'.$trip->id.'/close', ['version' => $trip->fresh()->version, 'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertSame('closed', $trip->fresh()->status);
        $this->assertSame($before, [$case->order->fresh()->getAttributes(), $case->parcel->fresh()->state(), WalletTransaction::count()]);
        $this->assertNull($member->fresh()->received_at);
        $this->assertSame('in_transit', $case->fresh()->status);
        app(ShipmentLossSource::class)->assertApproved($plan);
    }

    public function test_paid_wallet_compensation_survives_later_accounting_but_missing_original_evidence_cannot_authorize_new_funding_credits(): void
    {
        [$observation, $plan, $admin, $order, , $disposition] = $this->reviewed();
        $wallet = app(ShipmentLossWallet::class);
        foreach (['reserve-original' => 'original_reserve_confirmed', 'approve-advance' => 'demo_advance_confirmed', 'resolve-wallet' => 'loss_settlement_confirmed'] as $action => $confirmation) {
            $wallet->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Use the actual original paid source and explicit backed advance for the independently approved original loss.',
                $confirmation => true, ...($action === 'approve-advance' ? ['amount' => '188.33'] : [])]);
        }
        $ledger = app(WalletLedger::class);
        $before = [WalletTransaction::count(), $ledger->balance($order->buyer), $ledger->balance($order->shop->user)];
        $service = app(ShipmentLossAccountingService::class);
        $record = $service->record($admin, $plan, $this->input($plan));
        $this->actingAs($observation->manifest->company->user)->postJson('/hub/manifests/'.$observation->manifest_id.'/close',
            $this->flowManifestPayload($observation->manifest))->assertOk();
        app(ShipmentLossSource::class)->assertApproved($plan);
        $this->assertSame($before, [WalletTransaction::count(), $ledger->balance($order->buyer), $ledger->balance($order->shop->user)]);
        $this->assertTrue($service->verified($record));
        Storage::disk('local')->delete($disposition->proof_path);
        $this->assertFalse($service->verified($record));
        try {
            app(WalletLossCreditService::class)->request($observation->manifest->company->user, $plan, [
                'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
                'amount' => '188.33', 'reason' => 'New demonstration issuance still requires the original retained evidence after the buyer refund was paid.']);
            $this->fail('Already paid compensation cannot bypass missing original independent evidence when authorizing new funding credits.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($before, [WalletTransaction::count(), $ledger->balance($order->buyer), $ledger->balance($order->shop->user)]);
    }

    public static function sources(): array
    {
        return [[false, 'wallet'], [false, 'cod'], [true, 'wallet'], [true, 'cod']];
    }

    public static function recoveryScopes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('recoveryScopes')]
    public function test_a_later_actual_finding_keeps_closed_loss_accounting_history_without_receipt_or_route_progress_and_holds_new_money(bool $return): void
    {
        [$observation, $plan, $admin, $order, $case] = $this->reviewed($return);
        $service = app(ShipmentLossAccountingService::class);
        $record = $service->record($admin, $plan, $this->input($plan));
        $manifest = $observation->manifest;
        $this->actingAs($manifest->company->user)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $position = $case?->parcel->fresh()->route_position;
        $count = WalletTransaction::count();
        $finding = $this->quarantineMissing($plan);
        $this->assertTrue($service->verified($record));
        $this->assertSame(1, app(LogisticsManifestService::class)->receiptCounts($manifest)['confirmed_lost']);
        $this->assertSame(0, app(LogisticsManifestService::class)->receiptCounts($manifest)['normal_received']);
        $this->assertSame($count, WalletTransaction::count());
        $this->assertSame($position, $case?->parcel->fresh()->route_position);
        $this->assertFalse($manifest->events()->whereIn('event_type', ['parcel_received', 'buyer-return-received'])->exists());
        $this->assertSame($plan->snapshot['source']['physical_state'], $finding->source_state);
        try {
            app(ShipmentLossSource::class)->assertApproved($plan);
            $this->fail('The actual finding must retain its independent financial reconciliation hold.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    #[DataProvider('recoveryScopes')]
    public function test_a_loss_during_actual_recovery_accounts_for_both_typed_transport_obligations_without_completing_the_recovery(bool $return): void
    {
        [$oldMissing, $admin, $order, $case] = $this->missing('wallet', $return);
        $original = $oldMissing->manifest->fresh();
        $wrong = $return ? $order->delivery->originBayanHub : $order->delivery->destinationBayanHub;
        $observer = $this->flowHandler($wrong);
        $manager = $original->company->user;
        $barcode = $return ? $case->parcel->barcode : $order->delivery->tracking_number;
        if ($return) {
            $recoveries = app(BuyerReturnRecoveryService::class);
            $recovery = $recoveries->observe($observer, $case->parcel->fresh(), $wrong);
            $input = ['version' => $recovery->version, 'request_token' => (string) Str::uuid(), 'notes' => 'Review the real return found at this different actual child hub.',
                'source_token' => $recoveries->sourceToken($recovery)];
            $recovery = $recoveries->command($manager, $recovery, 'authorize', $input);
            $recovery = $recoveries->command($observer, $recovery, 'intake', ['version' => $recovery->version, 'request_token' => (string) Str::uuid(),
                'notes' => 'The distinct observing handler physically holds the actual return in the recorded recovery hub.', 'barcode' => $barcode], UploadedFile::fake()->image('actual-recovery-intake.png'));
            $this->measureReturn($case, ['length_cm' => '70']);
            $destination = LogisticsHub::findOrFail($recovery->hub_ids[1]);
        } else {
            $this->actingAs($observer)->postJson('/hub/scan', ['barcode' => $barcode, 'hub_id' => $wrong->id, 'mode' => 'inspect'])->assertConflict();
            $observation = $original->events()->where('payload->scanner_misroute', true)->sole();
            $recoveries = app(ParcelRecoveryService::class);
            $proposal = $recoveries->proposal($manager, $observation);
            $recovery = $recoveries->authorize($manager, $observation, ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(),
                'notes' => 'Independently check the actual unexpected parcel and its original expected hub.']);
            $recovery = $recoveries->intake($observer, $recovery, ['version' => $recovery->version, 'request_token' => (string) Str::uuid(),
                'notes' => 'The named wrong-hub handler received the actual loose parcel for its authorized recovery.', 'barcode' => $barcode]);
            $destination = LogisticsHub::findOrFail($recovery->hub_ids[1]);
        }
        $driver = $this->createApprovedUser('courier');
        $fleet = LogisticsFleet::create(['logistics_company_id' => $original->logistics_company_id, 'hub_id' => $wrong->id,
            'plate_number' => 'LOSS-REC-'.LogisticsFleet::count(), 'vehicle_type' => 'l300_van', 'model' => 'Actual recovery transport',
            'capacity_kg' => 1000, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $original->logistics_company_id, 'assigned_hub_id' => $wrong->id, 'vehicle_id' => $fleet->id, 'is_available' => true]);
        $token = (string) Str::uuid();
        $manifest = app(LogisticsManifestService::class)->create($observer, ['source_hub_id' => $wrong->id,
            'destination_hub_id' => $destination->id, 'vehicle_id' => $fleet->id, 'creation_token' => $token,
            'direction' => $return ? 'buyer_return' : 'recovery', ...($return ? ['buyer_return_case_id' => $case->id, 'buyer_return_recovery_id' => $recovery->id]
                : ['parcel_recovery_case_id' => $recovery->id])], $token);
        $this->actingAs($observer)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $barcode]))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        if ($return) {
            $trips = app(LogisticsTripService::class);
            $trip = $trips->create($observer, ['manifest_ids' => [$manifest->id], 'request_token' => (string) Str::uuid()]);
            $trips->command($trip, $observer, 'dispatch', ['version' => 1, 'request_token' => (string) Str::uuid()]);
        } else {
            $this->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        }
        $member = $return ? $manifest->buyerReturnParcels()->sole() : $manifest->parcels()->sole();
        $this->actingAs($this->flowHandler($destination))->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'The originally recovered parcel is missing on this actual subsequent recovery transport.']))->assertOk();
        $source = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'), UploadedFile::fake()->image('recovery-loss-review.png'));
        app(ShipmentLossDispositionService::class)->record($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'reason' => 'Independent operational investigation confirms the particular loss during this actual recovery transport.', 'disposition_confirmed' => true], UploadedFile::fake()->image('recovery-loss-disposition.png'));
        $before = [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $case?->parcel->fresh()->state(), $recovery->fresh()->getAttributes(), WalletTransaction::count()];
        $service = app(ShipmentLossAccountingService::class);
        $record = $service->record($admin, $plan, $this->input($plan));
        foreach ([$manifest, $original] as $related) {
            $counts = app(LogisticsManifestService::class)->receiptCounts($related);
            $this->assertSame(1, $counts['confirmed_lost']);
            $this->assertSame(0, $counts['normal_received']);
            $this->assertSame(0, $counts['outstanding']);
            $this->actingAs($manager)->postJson('/hub/manifests/'.$related->id.'/close', $this->flowManifestPayload($related))->assertOk();
            $this->assertNull($related->fresh()->received_at);
            $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($related->id)->exists());
        }
        app(ShipmentLossSource::class)->assertApproved($plan);
        $this->assertTrue($service->verified($record));
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $case?->parcel->fresh()->state(), $recovery->fresh()->getAttributes(), WalletTransaction::count()]);
        if ($return) {
            $version = $trip->fresh()->version;
            $this->actingAs($this->flowHandler($destination))->postJson('/hub/manifests/trips/'.$trip->id.'/close',
                ['version' => $version, 'request_token' => (string) Str::uuid()])->assertConflict();
            $this->assertSame('dispatched', $trip->fresh()->status);
            $this->assertSame($version, $trip->fresh()->version);
            $this->assertFalse(DB::table('logistics_trip_events')->where('trip_id', $trip->id)->where('event_type', 'close')->exists());
        }
    }

    #[DataProvider('sources')]
    public function test_loss_accounting_and_manifest_closure_keep_actual_receipts_custody_sale_units_and_money_unchanged(bool $return, string $mode): void
    {
        [$observation, $plan, $admin, $order, $case] = $this->reviewed($return, $mode);
        $manifest = $observation->manifest->fresh();
        $member = $return ? $manifest->buyerReturnParcels()->sole() : $manifest->parcels()->sole();
        $before = [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $member->getAttributes(),
            $case?->parcel->fresh()->state(), $order->delivery->checkpoints()->count(), WalletTransaction::count()];
        $input = $this->input($plan);
        $service = app(ShipmentLossAccountingService::class);
        $record = $service->record($admin, $plan, $input);
        $this->assertTrue($service->verified($record));
        $this->assertSame($record->id, $service->record($admin, $plan, $input)->id);
        $counts = app(LogisticsManifestService::class)->receiptCounts($manifest);
        $this->assertSame(1, $counts['confirmed_lost']);
        $this->assertSame(0, $counts['normal_received']);
        $this->assertSame(0, $counts['outstanding']);
        $this->assertTrue(app(LogisticsManifestService::class)->openDiscrepancies($manifest)->isEmpty());
        $this->actingAs($manifest->company->user)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertSame('closed', $manifest->fresh()->status);
        $this->assertNull($manifest->fresh()->received_at);
        $this->assertNull($member->fresh()->received_at);
        $this->assertNull($member->fresh()->received_by_id);
        $this->assertFalse($manifest->events()->whereIn('event_type', ['parcel_received', 'buyer-return-received', 'resolve-discrepancy'])->exists());
        $this->assertTrue($manifest->events()->where('event_type', 'close-loss-accounting')->whereJsonContains('payload->accounting_references', $record->reference)->exists());
        app(ShipmentLossSource::class)->assertApproved($plan);
        $this->assertTrue($service->verified($record));
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $member->fresh()->getAttributes(),
            $case?->parcel->fresh()->state(), $order->delivery->checkpoints()->count(), WalletTransaction::count()]);
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());
        $this->assertSame(1, ShipmentLossAccounting::count());
        $this->assertSame(0, $plan->events()->where('event_type', 'loss-accounted')->sole()->amount_cents);
        $this->assertSame($record->id, $service->record($admin, $plan, $input)->id);
        if (! $return) {
            $bag = MotherBag::where('manifest_id', $manifest->id)->sole();
            $this->assertSame('in_transit', $bag->status);
            $receiver = $this->flowHandler($manifest->destinationHub);
            $this->actingAs($receiver)->postJson('/hub/mother-bags/'.$bag->id.'/open', ['version' => $bag->version,
                'barcode' => $bag->reference, 'seal_number' => $bag->seal_number, 'seal_intact' => true])->assertOk();
            $this->assertSame('received', $bag->fresh()->status);
            $this->assertNull($bag->parcels()->sole()->received_at);
            $this->assertFalse(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());
            app(ShipmentLossSource::class)->assertApproved($plan);
        }
    }

    public function test_an_accounting_without_original_disposition_independent_admin_current_version_and_exact_server_identity_is_rejected(): void
    {
        [, $plan, $admin, $order] = $this->reviewed(disposition: false);
        $service = app(ShipmentLossAccountingService::class);
        try {
            $service->record($admin, $plan, $this->input($plan));
            $this->fail('A financial approval cannot replace its required original operational disposition.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        foreach ([$order->buyer, $order->shop->user, $order->delivery->originBayanHub->company->user] as $actor) {
            try {
                $service->record($actor, $plan, $this->input($plan));
                $this->fail('A source participant cannot impersonate an independent reviewer.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        foreach ([['reason' => 'short'], ['reason' => str_repeat('A', 1001)], ['expected_version' => '01'], ['received' => true],
            ['arrival_confirmed' => true], ['amount' => '1.00'], ['manifest_id' => $plan->snapshot['source']['manifest_id']], ['loss_accounting_confirmed' => false]] as $change) {
            try {
                $service->record($admin, $plan, $this->input($plan, $change));
                $this->fail('Invalid inputs cannot supply another parcel identity, amount or physical receipt.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $this->assertSame(0, ShipmentLossAccounting::count());
    }

    public function test_retained_accounting_and_typed_manifest_links_cannot_be_rewritten_and_missing_private_disposition_holds_the_claim(): void
    {
        [$observation, $plan, $admin, , , $disposition] = $this->reviewed();
        $service = app(ShipmentLossAccountingService::class);
        $record = $service->record($admin, $plan, $this->input($plan));
        foreach (['shipment_loss_accountings', 'shipment_loss_accounting_manifests'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::table($table)->{$operation}(...($operation === 'update' ? [['id' => 999]] : []));
                    $this->fail('Raw writes cannot erase or substitute this original accounting.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString('Retain original loss accounting', $error->getMessage());
                }
            }
        }
        Storage::disk('local')->delete($disposition->proof_path);
        $this->assertFalse($service->verified($record));
        $this->assertSame(1, app(LogisticsManifestService::class)->receiptCounts($observation->manifest)['outstanding']);
        $this->actingAs($observation->manifest->company->user)->postJson('/hub/manifests/'.$observation->manifest_id.'/close',
            $this->flowManifestPayload($observation->manifest))->assertConflict();
        $this->assertSame('dispatched', $observation->manifest->fresh()->status);
    }

    public function test_a_failed_retained_financial_event_rolls_back_all_accounting_and_original_transport_events_before_exact_retry(): void
    {
        [$observation, $plan, $admin] = $this->reviewed();
        $input = $this->input($plan);
        $before = [$observation->manifest->fresh()->getAttributes(), $observation->manifest->events()->count(), $plan->events()->count()];
        $enabled = true;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, function ($event) use (&$enabled) {
            if ($enabled && $event->event_type === 'loss-accounted') {
                throw new \RuntimeException('Simulated original loss accounting event failure.');
            }
        });
        try {
            app(ShipmentLossAccountingService::class)->record($admin, $plan, $input);
            $this->fail('A failed retained event must roll back accounting and its manifest version.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated original loss accounting event failure.', $error->getMessage());
        } finally {
            $enabled = false;
        }
        $this->assertSame($before, [$observation->manifest->fresh()->getAttributes(), $observation->manifest->events()->count(), $plan->events()->count()]);
        $this->assertSame(0, ShipmentLossAccounting::count());
        $this->assertSame(0, DB::table('shipment_loss_accounting_manifests')->count());
        $this->assertTrue(app(ShipmentLossAccountingService::class)->verified(app(ShipmentLossAccountingService::class)->record($admin, $plan, $input)));
    }
}
