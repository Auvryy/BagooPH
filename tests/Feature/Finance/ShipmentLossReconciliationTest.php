<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BuyerReturnManifestParcel;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossReconciliation;
use App\Models\User;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletTransaction;
use App\Services\AccountClosureService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\ShipmentLossAccountingService;
use App\Services\Finance\ShipmentLossActions;
use App\Services\Finance\ShipmentLossCash;
use App\Services\Finance\ShipmentLossControls;
use App\Services\Finance\ShipmentLossCustody;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReconciliationService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\OrderStateMachineService;
use App\Services\Returns\BuyerReturnCustodyService;
use App\Services\Returns\BuyerReturnMoneyService;
use App\Services\Wallet\WalletFundingCredits;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletLossCreditService;
use App\Services\Wallet\WalletLossPlatformReturn;
use App\Services\Wallet\WalletLossReserve;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossReconciliationTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public static function paymentModes(): array
    {
        return [['wallet'], ['cod']];
    }

    public static function salvageMatrix(): array
    {
        return [[false, 'wallet'], [false, 'cod'], [true, 'wallet'], [true, 'cod']];
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    private function plan(string $mode = 'wallet', bool $return = false, bool $sellerPaid = false, int $quantity = 2): array
    {
        [$observation, $admin, $order, $case] = $this->missing($mode, $return, false, $sellerPaid, $quantity);
        $plan = app(ShipmentLossReview::class)->approve(
            $admin,
            $observation,
            $this->approval($observation, $admin, $return ? '0.00' : '180.00'),
            UploadedFile::fake()->image('actual-reviewed-loss.png')
        );

        return [$observation, $plan, $admin, $order, $case];
    }

    private function version(ShipmentFinancialPlan $plan): int
    {
        return (int) $plan->events()->max('sequence');
    }

    private function act(User $actor, ShipmentFinancialPlan $plan, string $action, array $extra = [], ?UploadedFile $proof = null): array
    {
        $spec = app(ShipmentLossActions::class)->definition($action, $plan->snapshot['payment_method']);
        $payload = [
            'action' => $action,
            'expected_version' => $this->version($plan),
            'request_token' => (string) Str::uuid(),
            'reason' => 'Independent participant reviewed the retained original custody, settlement balance and evidence before acting.',
            $spec['confirmation'] => true,
            ...$extra,
        ];
        if ($spec['proof'] && ! $proof) {
            $proof = UploadedFile::fake()->image('proof-'.$action.'.png');
        }
        $data = $proof ? [...$payload, 'proof' => $proof] : $payload;
        $response = $this->actingAs($actor)->postJson('/shipment-finances/'.$plan->id.'/actions', $data)->assertOk();
        // Exact retry with the same request_token must return the same event without creating duplicates.
        $retry = $this->actingAs($actor)->postJson('/shipment-finances/'.$plan->id.'/actions', $data)->assertOk();
        $this->assertSame($response->json('event.id'), $retry->json('event.id'));

        return $response->json('event');
    }

    private function grantOwnerCredits(User $owner, User $reviewer, ShipmentFinancialPlan $plan, string $amountPesos): void
    {
        $credits = app(WalletLossCreditService::class);
        $request = $credits->request($owner, $plan, [
            'expected_version' => $this->version($plan),
            'request_token' => (string) Str::uuid(),
            'amount' => $amountPesos,
            'reason' => 'Company requests reviewed demonstration credits to fund the remaining actual loss balance.',
        ]);
        $credits->review($reviewer, $request, [
            'version' => 1,
            'request_token' => (string) Str::uuid(),
            'decision' => 'approved',
            'demo_credit_confirmed' => true,
            'reason' => 'Independent reviewer verified the remaining liability gap before granting demonstration credits.',
        ]);
    }

    private function approveReconciliation(User $reconciler, ShipmentFinancialPlan $plan, string $path): ShipmentLossReconciliation
    {
        $event = $this->act($reconciler, $plan, 'reconcile-found', ['outcome_path' => $path]);
        $this->assertSame('loss-reconciliation-approved', $event['event_type']);

        return ShipmentLossReconciliation::where('active_plan_id', $plan->id)->firstOrFail();
    }

    private function releaseQuarantine(User $handler, ShipmentFinancialPlan $plan, LogisticsHub $hub, ShipmentLossReconciliation $reconciliation, string $barcode): ShipmentLossReconciliation
    {
        $payload = [
            'hub_id' => $hub->id,
            'expected_version' => $this->version($plan),
            'reconciliation_reference' => $reconciliation->reference,
            'request_token' => (string) Str::uuid(),
            'barcode' => $barcode,
            'reason' => 'Physically scanned the original parcel out of quarantine under the approved reconciliation corridor.',
            'release_confirmed' => true,
        ];
        $response = $this->actingAs($handler)->postJson('/loss-found/'.$plan->id.'/release', $payload)->assertOk();
        $this->assertSame('released', $response->json('reconciliation.status'));
        $retry = $this->actingAs($handler)->postJson('/loss-found/'.$plan->id.'/release', $payload)->assertOk();
        $this->assertSame($response->json('reconciliation.id'), $retry->json('reconciliation.id'));

        return $reconciliation->fresh();
    }

    private function completeForwardCorridor(Delivery $delivery, LogisticsHub $startHub, ShipmentLossReconciliation $reconciliation): Delivery
    {
        $hubIds = $reconciliation->forward_route_hub_ids ?? [];
        $this->assertNotEmpty($hubIds);
        $this->assertSame($startHub->id, $hubIds[0]);
        for ($i = 0; $i < count($hubIds) - 1; $i++) {
            $fromHub = LogisticsHub::findOrFail($hubIds[$i]);
            $toHub = LogisticsHub::findOrFail($hubIds[$i + 1]);
            $this->confirmFlowManifest($delivery->fresh(), $fromHub, true);
            $this->confirmFlowManifest($delivery->fresh(), $toHub, false);
            $delivery->refresh();
            if ($toHub->isMotherHub() && ($i + 1) < count($hubIds) - 1) {
                $this->confirmFlowScan($delivery, $toHub, 'SORT_TO_LINE_HAUL');
            }
        }
        $destHub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $destHandler = $this->flowHandler($destHub);
        $delivery = $delivery->fresh();
        $this->actingAs($destHandler)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'barangay' => $delivery->order->destination_barangay,
        ])->assertOk();
        $rider = $this->flowRider($destHub);
        $this->actingAs($destHandler)->postJson(route('hub.assignRider', $delivery->fresh()), ['rider_id' => $rider->id])->assertOk();
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery->fresh()), [
            'status' => 'out_for_delivery',
            'barcode' => $delivery->tracking_number,
        ])->assertSessionHas('success');
        $this->flowAttemptStorageFaked = true;
        $this->flowStorageFaked = true;
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery->fresh()), [
            ...$this->codCollectionInput($delivery->fresh()),
            'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->image('resumed-delivered.jpg'),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertSessionHas('success');

        return $delivery->fresh();
    }

    public function test_controls_and_endpoints_enforce_independent_actor_eligibility_and_private_proof_authorization(): void
    {
        [$observation, $plan, $sourceAdmin, $order] = $this->plan('wallet', false);
        $finding = $this->quarantineMissing($plan);
        $hub = $observation->manifest->destinationHub;
        $handler = $this->flowHandler($hub);
        $reconciler = $this->createApprovedUser('admin');
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;

        $this->actingAs($reconciler)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.controls.finding.intake_url', '/loss-found/'.$plan->id.'?hub_id='.$hub->id)
            ->assertJsonPath('props.loss.controls.reconciliation.eligible_paths', ['unpaid_undelivered']);

        // Non-admins and source actors cannot approve reconciliation or download private reconciliation proof.
        foreach ([$order->buyer, $order->shop->user, $owner] as $actor) {
            $this->actingAs($actor)->postJson('/shipment-finances/'.$plan->id.'/actions', [
                'action' => 'reconcile-found',
                'expected_version' => $this->version($plan),
                'request_token' => (string) Str::uuid(),
                'outcome_path' => 'unpaid_undelivered',
                'reason' => 'Unauthorized or source actor must not approve independent found-parcel reconciliation.',
                'reconciliation_confirmed' => true,
                'proof' => UploadedFile::fake()->image('blocked-reconcile.png'),
            ])->assertForbidden();
        }
        $taintedControls = app(ShipmentLossControls::class)->present(
            $reconciler,
            tap(clone $plan, fn (ShipmentFinancialPlan $copy) => $copy->snapshot = [...$plan->snapshot, 'source_actor_ids' => [...$plan->snapshot['source_actor_ids'], $reconciler->id]]),
            app(WalletLossReserve::class)->summary($plan),
            $plan->events()->get(),
        );
        $this->assertNotContains('reconcile-found', array_column($taintedControls['actions'], 'id'));
        $this->actingAs($handler)->postJson('/shipment-finances/'.$plan->id.'/actions', [
            'action' => 'reconcile-found',
            'expected_version' => $this->version($plan),
            'request_token' => (string) Str::uuid(),
            'outcome_path' => 'unpaid_undelivered',
            'reason' => 'Non-party hub handler has no authority over the financial loss plan.',
            'reconciliation_confirmed' => true,
            'proof' => UploadedFile::fake()->image('blocked-handler-reconcile.png'),
        ])->assertNotFound();

        // Finding actor (even if promoted or holding admin check) cannot self-reconcile; independent admin succeeds.
        $reconciliation = $this->approveReconciliation($reconciler, $plan, 'unpaid_undelivered');
        $proofUrl = '/shipment-finances/'.$plan->id.'/reconciliation/'.$reconciliation->id.'/proof';

        $this->actingAs($reconciler)->get($proofUrl)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        foreach ([$order->buyer, $order->shop->user, $owner, $handler] as $unauthorized) {
            $this->actingAs($unauthorized)->get($proofUrl)->assertForbidden();
        }

        // Release screen shows approved release readiness for holding hub handler; bad barcode/wrong hub blocked.
        $this->actingAs($handler)->get('/loss-found/'.$plan->id.'?hub_id='.$hub->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.release.can_release', true)
            ->assertJsonPath('props.release.stale', false)
            ->assertJsonPath('props.release.reconciliation.reference', $reconciliation->reference);

        $this->actingAs($handler)->postJson('/loss-found/'.$plan->id.'/release', [
            'hub_id' => $hub->id,
            'expected_version' => $this->version($plan),
            'reconciliation_reference' => $reconciliation->reference,
            'request_token' => (string) Str::uuid(),
            'barcode' => 'WRONG-BARCODE-999',
            'reason' => 'Wrong barcode must fail validation before releasing quarantine.',
            'release_confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['barcode']);

        $otherHub = $order->delivery->destinationBayanHub;
        if ($otherHub->id !== $hub->id) {
            $otherHandler = $this->flowHandler($otherHub);
            $this->actingAs($otherHandler)->postJson('/loss-found/'.$plan->id.'/release', [
                'hub_id' => $otherHub->id,
                'expected_version' => $this->version($plan),
                'reconciliation_reference' => $reconciliation->reference,
                'request_token' => (string) Str::uuid(),
                'barcode' => $finding->barcode_scanned,
                'reason' => 'Non-holding hub must be blocked from releasing quarantine.',
                'release_confirmed' => true,
            ])->assertStatus(409);
        }
    }

    #[DataProvider('paymentModes')]
    public function test_wallet_and_cod_unpaid_undelivered_resume_multi_hub_forward_corridor_and_keep_manifest_accounting_verified(string $mode): void
    {
        [$observation, $plan, $sourceAdmin, $order] = $this->plan($mode, false);
        $manifest = $observation->manifest->fresh();
        $handler = $this->flowHandler($manifest->destinationHub);
        $reconciler = $this->createApprovedUser('admin');
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;

        app(ShipmentLossDispositionService::class)->record($reconciler, $plan, [
            'expected_version' => $this->version($plan),
            'request_token' => (string) Str::uuid(),
            'reason' => 'Independent investigation confirms the missing manifest parcel discrepancy.',
            'disposition_confirmed' => true,
        ], UploadedFile::fake()->image('disposition.png'));
        $accounting = app(ShipmentLossAccountingService::class)->record($reconciler, $plan, [
            'expected_version' => $this->version($plan),
            'request_token' => (string) Str::uuid(),
            'reason' => 'Close out original manifest accountability while keeping physical recovery open.',
            'loss_accounting_confirmed' => true,
        ]);
        $this->actingAs($owner)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());

        $finding = $this->quarantineMissing($plan);
        $reconciliation = $this->approveReconciliation($reconciler, $plan, 'unpaid_undelivered');
        $released = $this->releaseQuarantine($handler, $plan, $manifest->destinationHub, $reconciliation, $finding->barcode_scanned);

        $this->assertSame('released', $released->status);
        $this->assertSame(0, LogisticsManifestParcel::where('active_delivery_id', $order->delivery->id)->count());
        $this->assertTrue(app(ShipmentLossAccountingService::class)->verified($accounting->fresh()));
        $this->assertSame(1, app(LogisticsManifestService::class)->receiptCounts($manifest)['confirmed_lost']);
        $this->assertSame(0, app(LogisticsManifestService::class)->receiptCounts($manifest)['normal_received']);
        $this->assertSame(1, $order->delivery->checkpoints()->where('checkpoint_type', 'loss_quarantine_released')->count());

        $delivered = $this->completeForwardCorridor($order->delivery->fresh(), $manifest->destinationHub, $released);
        $this->assertSame('delivered', $delivered->status);
        $this->assertSame('completed', $order->fresh()->status);

        if ($mode === 'wallet') {
            $walletTxBefore = WalletTransaction::count();
            $settlementView = app(WalletSettlementService::class)->present($order->fresh(['walletPayment']));
            $this->assertNotNull($settlementView);
            $this->assertSame($walletTxBefore, WalletTransaction::count());
        } else {
            $this->flowSettlementStorageFaked = true;
            $this->settleFlowOrder($order->fresh());
            $this->assertSame('paid', $order->fresh()->payment_status);
        }
        $this->assertTrue(app(ShipmentLossAccountingService::class)->verified($accounting->fresh()));
    }

    #[DataProvider('paymentModes')]
    public function test_voluntary_buyer_compensation_return_and_priority_reimbursement_unblock_post_delivery_partial_return(string $mode): void
    {
        [$observation, $plan, $sourceAdmin, $order, $case] = $this->plan($mode, true, sellerPaid: $mode === 'cod');
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $reconciler = $this->createApprovedUser('admin');
        $buyer = $order->buyer->fresh();
        $refundCents = $plan->snapshot['buyer_refund_cents'];
        $this->assertSame(12500, $refundCents);

        if ($mode === 'wallet') {
            // Fund half via reviewed company credits and half via platform advance, then pay buyer compensation.
            $companyPart = 6000;
            $advancePart = $refundCents - $companyPart;
            $this->grantOwnerCredits($owner, $reconciler, $plan, SellerSettlementService::pesos($companyPart));
            $this->act($owner, $plan, 'contribute-company', ['amount' => SellerSettlementService::pesos($companyPart)]);
            $this->act($reconciler, $plan, 'approve-advance', ['amount' => SellerSettlementService::pesos($advancePart)]);
            $this->act($reconciler, $plan, 'resolve-wallet');
            $buyerBalanceAfterPayout = app(WalletLedger::class)->balance($buyer);
            $this->assertGreaterThanOrEqual($refundCents, $buyerBalanceAfterPayout);
        } else {
            $offer = $this->act($owner, $plan, 'offer-company', [
                'amount' => SellerSettlementService::pesos($refundCents),
                'recipient_id' => $sourceAdmin->id,
                'cash_reference' => 'COD-PARTIAL-COMPANY-LOSS-1',
            ]);
            $this->act($sourceAdmin, $plan, 'receive-company', ['source_event_id' => $offer['id']]);
            $payment = $this->act($sourceAdmin, $plan, 'record-buyer-payment', ['cash_reference' => 'COD-PARTIAL-BUYER-LOSS-1']);
            $this->act($buyer, $plan, 'confirm-buyer-receipt', ['source_event_id' => $payment['id']]);
            $this->act($reconciler, $plan, 'resolve-cash');
        }

        $finding = $this->quarantineMissing($plan);

        // Before voluntary compensation return, post_delivery_partial_return must be blocked (and outstanding advance blocks all paths).
        $assessment = app(ShipmentLossReconciliationService::class)->assessment($plan);
        $this->assertNotContains('post_delivery_partial_return', $assessment['eligible_paths']);
        if ($mode === 'wallet') {
            $this->assertSame([], $assessment['eligible_paths']);
        } else {
            $this->assertContains('already_settled_salvage', $assessment['eligible_paths']);
        }

        $unilateralAction = $mode === 'wallet' ? 'return-buyer-compensation' : 'offer-buyer-compensation-return';
        $this->actingAs($reconciler)->postJson('/shipment-finances/'.$plan->id.'/actions', [
            'action' => $unilateralAction,
            'expected_version' => $this->version($plan),
            'request_token' => (string) Str::uuid(),
            'reason' => 'Admin cannot unilaterally debit or force a voluntary buyer compensation return.',
            ...(app(ShipmentLossActions::class)->definition($unilateralAction, $mode)['receiver'] ? ['recipient_id' => $reconciler->id, 'cash_reference' => 'BAD-FORCED'] : []),
            ...(app(ShipmentLossActions::class)->definition($unilateralAction, $mode)['proof'] ? ['proof' => UploadedFile::fake()->image('bad.png')] : []),
            app(ShipmentLossActions::class)->definition($unilateralAction, $mode)['confirmation'] => true,
        ])->assertForbidden();

        if ($mode === 'wallet') {
            // Buyer returns compensation -> Priority 1 retires advance automatically -> Priority 2 reimburses company.
            $ownerBalanceBefore = app(WalletLedger::class)->balance($owner);
            $this->act($buyer, $plan, 'return-buyer-compensation');
            $reserve = app(WalletLossReserve::class)->summary($plan);
            $this->assertSame(0, $reserve['advance_outstanding_cents']);
            $this->assertSame(6000, $reserve['balance_cents']);
            $this->assertSame(1, WalletTransaction::where('kind', 'wallet_loss_advance_retirement')->count());

            // Escrow reimbursement blocked while company funding remains unreturned.
            $this->actingAs($reconciler)->postJson('/shipment-finances/'.$plan->id.'/actions', [
                'action' => 'restore-escrow-compensation',
                'expected_version' => $this->version($plan),
                'request_token' => (string) Str::uuid(),
                'reason' => 'Escrow cannot jump ahead of company reimbursement priority.',
                'escrow_compensation_reimbursement_confirmed' => true,
            ])->assertStatus(409);

            $this->act($reconciler, $plan, 'restore-company-compensation');
            $this->assertSame(6000, app(WalletLedger::class)->balance($owner) - $ownerBalanceBefore);
            $this->assertSame(0, app(WalletLossReserve::class)->summary($plan)['balance_cents']);
        } else {
            // COD voluntary return without editable amount -> independent admin receives -> returns company cash.
            $buyerOffer = $this->act($buyer, $plan, 'offer-buyer-compensation-return', [
                'recipient_id' => $reconciler->id,
                'cash_reference' => 'BUYER-VOLUNTARY-CASH-RETURN-1',
            ]);
            $this->assertSame($refundCents, $buyerOffer['amount_cents']);
            $this->act($reconciler, $plan, 'receive-buyer-compensation-return', ['source_event_id' => $buyerOffer['id']]);
            // Surplus cash still blocks partial return until company reimbursement is recorded & confirmed.
            $this->assertNotContains('post_delivery_partial_return', app(ShipmentLossReconciliationService::class)->assessment($plan)['eligible_paths']);

            $companyReturn = $this->act($reconciler, $plan, 'record-company-cash-return', [
                'amount' => SellerSettlementService::pesos($refundCents),
                'cash_reference' => 'COMPANY-REIMBURSE-COD-RETURN-1',
            ]);
            $this->assertNotContains('post_delivery_partial_return', app(ShipmentLossReconciliationService::class)->assessment($plan)['eligible_paths']);
            $this->act($owner, $plan, 'confirm-company-cash-return', ['source_event_id' => $companyReturn['id']]);
        }

        $reconciliation = $this->approveReconciliation($reconciler, $plan, 'post_delivery_partial_return');
        $manifest = $observation->manifest->fresh();
        $handler = $this->flowHandler($manifest->destinationHub);
        $released = $this->releaseQuarantine($handler, $plan, $manifest->destinationHub, $reconciliation, $finding->barcode_scanned);
        $this->assertSame('released', $released->status);
        $this->assertSame(0, BuyerReturnManifestParcel::where('active_return_parcel_id', $case->parcel->id)->count());

        // Complete resumed multi-hub return legs via ShipmentLossReturnRoute to seller receipt without duplicate payout.
        $hubIds = $released->return_route_hub_ids ?? [];
        $this->assertNotEmpty($hubIds);
        $case = $case->fresh(['parcel', 'order']);
        for ($i = 0; $i < count($hubIds) - 1; $i++) {
            $src = LogisticsHub::findOrFail($hubIds[$i]);
            $dst = LogisticsHub::findOrFail($hubIds[$i + 1]);
            $driver = $this->createApprovedUser('courier');
            $fleet = LogisticsFleet::create([
                'logistics_company_id' => $plan->logistics_company_id,
                'hub_id' => $src->id,
                'plate_number' => 'RET-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT),
                'vehicle_type' => $src->isMotherHub() && $dst->isMotherHub() ? 'wing_truck' : 'l300_van',
                'model' => 'Bagoo Return Corridor',
                'capacity_kg' => 1000,
                'assigned_driver_id' => $driver->id,
                'status' => 'active',
            ]);
            $driver->courierProfile->update(['logistics_company_id' => $plan->logistics_company_id, 'assigned_hub_id' => $src->id, 'vehicle_id' => $fleet->id, 'is_available' => true]);
            $this->actingAs($owner)->get('/hub/manifests')->assertOk();
            $created = $this->postJson('/hub/manifests', [
                'source_hub_id' => $src->id,
                'destination_hub_id' => $dst->id,
                'vehicle_id' => $fleet->id,
                'direction' => 'buyer_return',
                'buyer_return_case_id' => $case->id,
                'creation_token' => session()->get('manifest_creation_token'),
            ])->assertCreated();
            $nextManifest = LogisticsManifest::findOrFail($created->json('manifest.id'));
            $this->actingAs($this->flowHandler($src))->postJson('/hub/manifests/'.$nextManifest->id.'/load', $this->flowManifestPayload($nextManifest, ['barcode' => $case->parcel->barcode]))->assertOk();
            $this->actingAs($owner)->postJson('/hub/manifests/'.$nextManifest->id.'/seal', $this->flowManifestPayload($nextManifest))->assertOk();
            $this->actingAs($this->flowHandler($src))->postJson('/hub/manifests/'.$nextManifest->id.'/dispatch', $this->flowManifestPayload($nextManifest))->assertOk();
            $this->actingAs($this->flowHandler($dst))->postJson('/hub/manifests/'.$nextManifest->id.'/receive', $this->flowManifestPayload($nextManifest, ['barcode' => $case->parcel->barcode]))->assertOk();
            $this->actingAs($owner)->postJson('/hub/manifests/'.$nextManifest->id.'/close', $this->flowManifestPayload($nextManifest))->assertOk();
        }
        $finalHub = LogisticsHub::findOrFail($hubIds[count($hubIds) - 1]);
        $originHandler = $this->flowHandler($finalHub);
        app(BuyerReturnCustodyService::class)->command($originHandler, $case->fresh(), 'stage', [
            'expected_version' => $case->fresh()->version,
            'request_token' => (string) Str::uuid(),
            'barcode' => $case->parcel->barcode,
            'hub_id' => $finalHub->id,
            'reason' => 'Stage the resumed buyer return parcel for final seller pickup after quarantine release.',
        ]);
        app(BuyerReturnCustodyService::class)->command($order->shop->user, $case->fresh(), 'seller-receive', [
            'expected_version' => $case->fresh()->version,
            'request_token' => (string) Str::uuid(),
            'barcode' => $case->parcel->barcode,
            'condition' => 'accepted',
            'reason' => 'Owning seller received the resumed partial-return parcel without duplicate loss refund.',
        ], UploadedFile::fake()->image('seller-return-receipt.png'));

        $this->assertSame('seller_received', $case->fresh()->status);
        $this->assertFalse(app(BuyerReturnMoneyService::class)->blockedByLossPlan($case->fresh()));
        $this->assertSame(['approve-repayment'], app(BuyerReturnMoneyService::class)->actions($reconciler, $case->fresh()));
    }

    public function test_unpaid_wallet_and_cod_reserves_pending_requests_and_company_disputes_must_clear_before_resumption(): void
    {
        // Part A: Unpaid Wallet reserves (escrow + advance + pending grant + company) block unpaid_undelivered until returned/declined.
        [$obsW, $planW, $sourceAdminW] = $this->plan('wallet', false);
        $ownerW = LogisticsCompany::findOrFail($planW->logistics_company_id)->user;
        $reconcilerW = $this->createApprovedUser('admin');
        $this->act($sourceAdminW, $planW, 'reserve-original');
        $this->grantOwnerCredits($ownerW, $reconcilerW, $planW, '50.00');
        $this->act($ownerW, $planW, 'contribute-company', ['amount' => '50.00']);
        $this->act($sourceAdminW, $planW, 'approve-advance', ['amount' => '138.33']);
        $pendingGrant = app(WalletLossCreditService::class)->request($ownerW, $planW, [
            'expected_version' => $this->version($planW),
            'request_token' => (string) Str::uuid(),
            'amount' => '10.00',
            'reason' => 'Pending credit request before quarantine intake.',
        ]);
        $this->quarantineMissing($planW);

        // Advance outstanding blocks reconciliation and blocks platform escrow return ahead of advance.
        $this->assertSame([], app(ShipmentLossReconciliationService::class)->assessment($planW)['eligible_paths']);
        $platformSources = app(WalletLossPlatformReturn::class)->sources($reconcilerW, $planW);
        $escrowSource = collect($platformSources)->first(fn ($row) => str_contains($row['label'], 'Original escrow'));
        $advanceSource = collect($platformSources)->first(fn ($row) => str_contains($row['label'], 'Original demo advance'));
        $this->assertNotNull($escrowSource);
        $this->assertNotNull($advanceSource);

        $this->act($reconcilerW, $planW, 'return-platform-source', ['source_event_id' => $advanceSource['id']]);
        // Reject pending credit request so no pending requests block reconciliation.
        app(WalletLossCreditService::class)->review($reconcilerW, $pendingGrant->fresh(), [
            'version' => 1,
            'request_token' => (string) Str::uuid(),
            'decision' => 'rejected',
            'reason' => 'No additional credit issuance is needed because the found parcel will resume delivery.',
        ]);
        $this->assertNotContains('unpaid_undelivered', app(ShipmentLossReconciliationService::class)->assessment($planW)['eligible_paths']);

        // Return unused company contribution -> grant -> return platform escrow.
        $companyEvent = $planW->events()->where('event_type', 'loss-contribute-company')->firstOrFail();
        $this->act($ownerW, $planW, 'return-company-source', ['source_event_id' => $companyEvent->id]);
        $grant = WalletRefundCreditRequest::where('shipment_financial_plan_id', $planW->id)->where('status', 'approved')->firstOrFail();
        $grantEvent = $planW->events()
            ->where('event_type', 'loss-credit-approved')
            ->where('context->funding_credit_request_id', $grant->id)
            ->firstOrFail();
        // Unused grant credits remain in owner's balance until returned or expired; return them cleanly via WalletLossGrantReturn.
        $this->assertSame(5000, app(WalletFundingCredits::class)->available($ownerW, $planW->reference));
        $this->act($ownerW, $planW, 'return-unused-credit', ['source_event_id' => $grantEvent->id]);
        $this->act($reconcilerW, $planW, 'return-platform-source', ['source_event_id' => $escrowSource['id']]);

        $this->assertContains('unpaid_undelivered', app(ShipmentLossReconciliationService::class)->assessment($planW)['eligible_paths']);

        // Part B: Unpaid COD with company cash return dispute + advance retirement must resolve cleanly via independent admin.
        [$obsC, $planC, $sourceAdminC, $orderC] = $this->plan('cod', false);
        $ownerC = LogisticsCompany::findOrFail($planC->logistics_company_id)->user;
        $funderC = $this->createApprovedUser('admin');
        $reconcilerC = $this->createApprovedUser('admin');
        $companyOffer = $this->act($ownerC, $planC, 'offer-company', [
            'amount' => '100.00',
            'recipient_id' => $sourceAdminC->id,
            'cash_reference' => 'COD-UNPAID-COMPANY-100',
        ]);
        $this->act($sourceAdminC, $planC, 'receive-company', ['source_event_id' => $companyOffer['id']]);
        $advOffer = $this->act($funderC, $planC, 'offer-advance', [
            'amount' => '88.33',
            'recipient_id' => $sourceAdminC->id,
            'cash_reference' => 'COD-UNPAID-ADVANCE-8833',
        ]);
        $advReceipt = $this->act($sourceAdminC, $planC, 'receive-advance', ['source_event_id' => $advOffer['id']]);
        $this->quarantineMissing($planC);

        $this->act($reconcilerC, $planC, 'retire-advance', [
            'source_event_id' => $advReceipt['id'],
            'amount' => '88.33',
            'cash_reference' => 'COD-RETIRE-UNPAID-ADVANCE',
        ]);
        // Non-admins and source actors cannot record company cash return; independent admin succeeds.
        foreach ([$orderC->buyer, $orderC->shop->user, $ownerC] as $actor) {
            $this->actingAs($actor)->postJson('/shipment-finances/'.$planC->id.'/actions', [
                'action' => 'record-company-cash-return',
                'expected_version' => $this->version($planC),
                'request_token' => (string) Str::uuid(),
                'amount' => '100.00',
                'cash_reference' => 'BLOCKED-SOURCE-ADMIN-RETURN-'.$actor->id,
                'reason' => 'Unauthorized or source actor must not record company cash return without independent check.',
                'payment_confirmed' => true,
                'proof' => UploadedFile::fake()->image('blocked-return.png'),
            ])->assertForbidden();
        }
        $taintedCodControls = app(ShipmentLossControls::class)->present(
            $reconcilerC,
            tap(clone $planC, fn (ShipmentFinancialPlan $copy) => $copy->snapshot = [...$planC->snapshot, 'source_actor_ids' => [...$planC->snapshot['source_actor_ids'], $reconcilerC->id]]),
            app(ShipmentLossCash::class)->state($planC),
            $planC->events()->get(),
        );
        $this->assertNotContains('record-company-cash-return', array_column($taintedCodControls['actions'], 'id'));

        $retEvent = $this->act($reconcilerC, $planC, 'record-company-cash-return', [
            'amount' => '100.00',
            'cash_reference' => 'INDEP-COMPANY-CASH-RETURN-100',
        ]);
        // Unconfirmed company return blocks both reconciliation and owner account closure.
        $this->assertSame([], app(ShipmentLossReconciliationService::class)->assessment($planC)['eligible_paths']);
        $this->assertContains('shipment_loss_cash_dispute', array_column(app(AccountClosureService::class)->state($ownerC)['blockers'], 'code'));

        // Company disputes nonreceipt -> blocks -> independent admin resolves -> owner confirms.
        $this->act($ownerC, $planC, 'report-company-cash-nonreceipt', ['source_event_id' => $retEvent['id']]);
        $this->assertContains('shipment_loss_cash_dispute', array_column(app(AccountClosureService::class)->state($ownerC)['blockers'], 'code'));
        $this->assertSame([], app(ShipmentLossReconciliationService::class)->assessment($planC)['eligible_paths']);

        $this->act($reconcilerC, $planC, 'resolve-company-cash-nonreceipt', ['source_event_id' => $retEvent['id']]);
        $this->act($ownerC, $planC, 'confirm-company-cash-return', ['source_event_id' => $retEvent['id']]);

        $this->assertContains('unpaid_undelivered', app(ShipmentLossReconciliationService::class)->assessment($planC)['eligible_paths']);
    }

    public function test_already_settled_seller_return_keeps_cancelled_order_prevents_double_payout_resumes_reverse_transit_and_allows_closure(): void
    {
        [$observation, $plan, $sourceAdmin, $order] = $this->plan('cod', false);
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $seller = $order->items->first()->shop->user;
        $reconciler = $this->createApprovedUser('admin');
        $due = $plan->snapshot['company_funding_due_cents']; // 18833 (18000 seller protection + 833 earned shipping)

        $offer = $this->act($owner, $plan, 'offer-company', [
            'amount' => SellerSettlementService::pesos($due),
            'recipient_id' => $sourceAdmin->id,
            'cash_reference' => 'COD-SELLER-RET-FUND',
        ]);
        $this->act($sourceAdmin, $plan, 'receive-company', ['source_event_id' => $offer['id']]);
        $this->act($sourceAdmin, $plan, 'assess-zero-buyer');
        $sellerPay = $this->act($sourceAdmin, $plan, 'record-seller-protection', ['cash_reference' => 'COD-SELLER-PROT-PAY']);
        $this->act($seller, $plan, 'confirm-seller-receipt', ['source_event_id' => $sellerPay['id']]);
        $this->act($reconciler, $plan, 'resolve-cash');

        $finding = $this->quarantineMissing($plan);

        // Before voluntary seller return, seller_return path is blocked.
        $this->assertNotContains('already_settled_seller_return', app(ShipmentLossReconciliationService::class)->assessment($plan)['eligible_paths']);

        // Seller voluntarily returns protection (no editable amount), independent admin receives and reimburses company (leaving 833 earned shipping retained).
        $sellerOffer = $this->act($seller, $plan, 'offer-seller-compensation-return', [
            'recipient_id' => $reconciler->id,
            'cash_reference' => 'SELLER-VOLUNTARY-PROT-RETURN',
        ]);
        $this->assertSame(18000, $sellerOffer['amount_cents']);
        $this->act($reconciler, $plan, 'receive-seller-compensation-return', ['source_event_id' => $sellerOffer['id']]);
        $companyReturn = $this->act($reconciler, $plan, 'record-company-cash-return', [
            'amount' => '180.00',
            'cash_reference' => 'COMPANY-REIMBURSE-FROM-SELLER-PROT',
        ]);
        $this->act($owner, $plan, 'confirm-company-cash-return', ['source_event_id' => $companyReturn['id']]);
        $this->assertSame(833, app(ShipmentLossCash::class)->state($plan)['balance_cents']);

        $reconciliation = $this->approveReconciliation($reconciler, $plan, 'already_settled_seller_return');
        $handler = $this->flowHandler($observation->manifest->destinationHub);
        $released = $this->releaseQuarantine($handler, $plan, $observation->manifest->destinationHub, $reconciliation, $finding->barcode_scanned);

        $order->refresh();
        $delivery = $order->delivery->fresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(OrderStateMachineService::STATUS_RETURN_TO_SENDER, $delivery->status);
        $this->assertTrue($delivery->checkpoints()->where('checkpoint_type', 'loss_seller_return_released')->exists());

        // Move through reverse corridor hub(s) back to origin_bayan_hub_id.
        $hubIds = $released->return_route_hub_ids ?? [];
        $this->assertNotEmpty($hubIds);
        for ($i = 0; $i < count($hubIds) - 1; $i++) {
            $this->confirmFlowManifest($delivery->fresh(), LogisticsHub::findOrFail($hubIds[$i]), true, direction: 'return');
            $this->confirmFlowManifest($delivery->fresh(), LogisticsHub::findOrFail($hubIds[$i + 1]), false, direction: 'return');
        }
        $originHub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $originHandler = $this->flowHandler($originHub);
        $this->actingAs($originHandler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
            'mode' => 'confirm',
            'action' => 'STAGE_SELLER_RETURN',
            'expected_status' => 'return_to_sender',
            'route_reference' => $released->reference,
        ])->assertOk();

        $sellerBalanceBefore = app(WalletLedger::class)->balance($seller);
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $order->items->first()->shop_id])
            ->post('/seller/orders/'.$order->id.'/return-receipt', [
                'barcode' => $delivery->tracking_number,
                'route_reference' => $released->reference,
                'notes' => 'Seller verified and accepted the physically returned settled merchandise.',
                'request_token' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Your original parcel receipt was recorded. The settled order remains cancelled.');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('returned', $delivery->fresh()->status);
        $this->assertSame($sellerBalanceBefore, app(WalletLedger::class)->balance($seller));
        $this->assertDatabaseCount('seller_settlement_events', 0);

        // Buyer has zero blockers; seller and owner retain only active structural resource responsibilities.
        $this->assertSame([], app(AccountClosureService::class)->state($order->buyer->fresh())['blockers']);
        $this->assertSame([], array_values(array_diff(
            array_column(app(AccountClosureService::class)->state($seller->fresh())['blockers'], 'code'),
            ['active_shops']
        )));
        $this->assertSame([], array_values(array_diff(
            array_column(app(AccountClosureService::class)->state($owner->fresh())['blockers'], 'code'),
            ['active_companies', 'active_hubs', 'active_handlers', 'active_riders', 'active_fleet']
        )));
    }

    #[DataProvider('salvageMatrix')]
    public function test_already_settled_salvage_and_stale_invalidation_block_double_claims_and_allow_closure(bool $return, string $mode): void
    {
        [$observation, $plan, $sourceAdmin, $order, $case] = $this->plan($mode, $return, sellerPaid: $return && $mode === 'cod');
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $reconciler = $this->createApprovedUser('admin');
        $due = $plan->snapshot['company_funding_due_cents'];

        if ($mode === 'wallet') {
            if (! $return) {
                $this->act($sourceAdmin, $plan, 'reserve-original');
            }
            $this->grantOwnerCredits($owner, $reconciler, $plan, SellerSettlementService::pesos($due));
            $this->act($owner, $plan, 'contribute-company', ['amount' => SellerSettlementService::pesos($due)]);
            $this->act($reconciler, $plan, 'resolve-wallet');
        } else {
            $offer = $this->act($owner, $plan, 'offer-company', [
                'amount' => SellerSettlementService::pesos($due),
                'recipient_id' => $sourceAdmin->id,
                'cash_reference' => 'SALVAGE-COD-FUND-'.$order->id,
            ]);
            $this->act($sourceAdmin, $plan, 'receive-company', ['source_event_id' => $offer['id']]);
            if ($return) {
                $p = $this->act($sourceAdmin, $plan, 'record-buyer-payment', ['cash_reference' => 'SALVAGE-BUYER-PAY-'.$order->id]);
                $this->act($order->buyer, $plan, 'confirm-buyer-receipt', ['source_event_id' => $p['id']]);
            } else {
                $this->act($sourceAdmin, $plan, 'assess-zero-buyer');
                $p = $this->act($sourceAdmin, $plan, 'record-seller-protection', ['cash_reference' => 'SALVAGE-SELLER-PAY-'.$order->id]);
                $this->act($order->items->first()->shop->user, $plan, 'confirm-seller-receipt', ['source_event_id' => $p['id']]);
            }
            $this->act($reconciler, $plan, 'resolve-cash');
        }

        $finding = $this->quarantineMissing($plan);
        $reconciliation = $this->approveReconciliation($reconciler, $plan, 'already_settled_salvage');
        $manifest = $observation->manifest->fresh();
        $handler = $this->flowHandler($manifest->destinationHub);

        // Make source facts stale (close/suspend shop after approval without disturbing company eligibility) -> release 409.
        $shop = $order->items->first()->shop;
        $shop->update(['status' => 'suspended']);
        $this->actingAs($handler)->get('/loss-found/'.$plan->id.'?hub_id='.$manifest->destinationHub->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.release.stale', true)
            ->assertJsonPath('props.release.can_release', false);
        $this->actingAs($handler)->postJson('/loss-found/'.$plan->id.'/release', [
            'hub_id' => $manifest->destinationHub->id,
            'expected_version' => $this->version($plan),
            'reconciliation_reference' => $reconciliation->reference,
            'request_token' => (string) Str::uuid(),
            'barcode' => $finding->barcode_scanned,
            'reason' => 'Stale reconciliation must be rejected with 409.',
            'release_confirmed' => true,
        ])->assertStatus(409);

        // Independent admin invalidates stale approval -> restores eligibility -> re-approves -> releases salvage.
        $invEvent = $this->act($reconciler, $plan, 'invalidate-reconciliation');
        $this->assertSame('loss-reconciliation-invalidated', $invEvent['event_type']);
        $shop->update(['status' => 'active']);
        $freshReconciliation = $this->approveReconciliation($reconciler, $plan, 'already_settled_salvage');
        $released = $this->releaseQuarantine($handler, $plan, $manifest->destinationHub, $freshReconciliation, $finding->barcode_scanned);
        $this->assertSame('released', $released->status);

        // Salvage hold prevents downstream return/delivery movement, payouts, and double claims, while permitting account closure.
        $this->assertSame($return ? 'completed' : 'cancelled', $order->fresh()->status);
        if ($return && $case) {
            $custodyView = app(BuyerReturnCustodyService::class)->presentation($handler, $case->fresh());
            $this->assertSame(ShipmentLossCustody::SALVAGE_HOLD, $custodyView['loss_hold']);
            $this->assertSame('salvaged', $case->fresh()->status);
            $this->assertTrue(app(BuyerReturnMoneyService::class)->blockedByLossPlan($case->fresh()));
            $this->assertSame([], app(BuyerReturnMoneyService::class)->actions($order->buyer, $case->fresh()));
        } else {
            try {
                app(DeliveryReturnService::class)->nextHub($order->delivery->fresh());
                $this->fail('Salvage delivery cannot route to seller return.');
            } catch (\DomainException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
            $this->actingAs($order->items->first()->shop->user)
                ->withSession(['active_seller_shop_id' => $shop->id])
                ->postJson('/seller/orders/'.$order->id.'/return-receipt', [
                    'barcode' => $order->delivery->tracking_number,
                    'route_reference' => $released->reference,
                    'notes' => 'Salvaged merchandise cannot be claimed via normal seller return receipt.',
                    'request_token' => (string) Str::uuid(),
                ])
                ->assertStatus(409);
        }

        // Subsequent financial actions remain locked after physical release.
        $this->actingAs($reconciler)->postJson('/shipment-finances/'.$plan->id.'/actions', [
            'action' => 'invalidate-reconciliation',
            'expected_version' => $this->version($plan),
            'request_token' => (string) Str::uuid(),
            'reason' => 'Released reconciliation cannot be invalidated.',
            'invalidation_confirmed' => true,
        ])->assertStatus(409);

        $this->assertSame([], app(AccountClosureService::class)->state($order->buyer->fresh())['blockers']);
        $this->assertSame([], array_values(array_diff(
            array_column(app(AccountClosureService::class)->state($shop->user->fresh())['blockers'], 'code'),
            ['active_shops']
        )));
        $this->assertSame([], array_values(array_diff(
            array_column(app(AccountClosureService::class)->state($owner->fresh())['blockers'], 'code'),
            ['active_companies', 'active_hubs', 'active_handlers', 'active_riders', 'active_fleet']
        )));
    }
}
