<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletTransaction;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\ShipmentLossAccountingService;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossWallet;
use App\Services\Wallet\WalletFundingCredits;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletLossCreditService;
use App\Services\Wallet\WalletLossReserve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossGrantReturnTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function plan(bool $return = false): array
    {
        [$source, $admin, $order, $case] = $this->missing('wallet', $return);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'), UploadedFile::fake()->image('original-loss.png'));

        return [$plan, $admin, $order, LogisticsCompany::findOrFail($plan->logistics_company_id)->user, $case];
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    private function contribute(User $owner, ShipmentFinancialPlan $plan, int $amount): void
    {
        app(ShipmentLossWallet::class)->command($owner, $plan, 'contribute-company', ['expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'amount' => SellerSettlementService::pesos($amount), 'company_transfer_confirmed' => true,
            'reason' => 'The original owner actually transfers only its independently available credits into this original reserve.']);
    }

    private function grant(User $owner, User $admin, ShipmentFinancialPlan $plan, int $amount = 18833): WalletRefundCreditRequest
    {
        $credits = app(WalletLossCreditService::class);
        $request = $credits->request($owner, $plan, ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'amount' => SellerSettlementService::pesos($amount), 'reason' => 'The original company requests reviewed demonstration credits for its exact retained obligation.']);

        return $credits->review($admin, $request, ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved', 'demo_credit_confirmed' => true,
            'reason' => 'Independently reviewed the original company, remaining liability and actual owned source before issuing demonstration credits.']);
    }

    private function payload(ShipmentFinancialPlan $plan, WalletRefundCreditRequest $grant): array
    {
        return ['action' => 'return-unused-credit', 'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'source_event_id' => $plan->events()->where('event_type', 'loss-credit-approved')->where('context->funding_credit_request_id', $grant->id)->sole()->id,
            'reason' => 'I return only this original reviewed grant’s actual unused credits to its demonstration issuance source while the found parcel remains held.',
            'unused_credit_return_confirmed' => true];
    }

    public function test_the_original_owner_can_return_unused_reviewed_credits_after_a_real_finding_without_clearing_liability_or_releasing_property(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        app(ShipmentLossDispositionService::class)->record($admin, $plan, ['expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'reason' => 'Independent operational evidence confirms this original missing parcel without manufacturing an arrival.',
            'disposition_confirmed' => true], UploadedFile::fake()->image('actual-confirmed-loss.png'));
        app(ShipmentLossAccountingService::class)->record($admin, $plan, ['expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'reason' => 'Account for the independently confirmed loss while its original vehicle and outer bag remain separate.', 'loss_accounting_confirmed' => true]);
        $manifest = LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id']);
        $this->actingAs($this->flowHandler($manifest->destinationHub))->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $finding = $this->quarantineMissing($plan);
        $ledger = app(WalletLedger::class);
        $state = app(WalletLossReserve::class)->summary($plan);
        $before = [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $ledger->balance($order->buyer), $ledger->balance($order->shop->user)];
        $input = $this->payload($plan, $grant);
        $this->actingAs($owner)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.controls.actions.0.id', 'return-unused-credit')
            ->assertJsonPath('props.loss.controls.actions.0.sources.0.amount_cents', 18833)
            ->assertJsonPath('props.loss.controls.credits.maximum_cents', 0);
        $response = $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk()
            ->assertJsonPath('event.event_type', 'loss-return-unused-credit')->assertJsonPath('event.amount_cents', 18833);
        $this->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk()->assertJsonPath('event.id', $response->json('event.id'));
        $this->assertSame(0, $ledger->balance($owner));
        $this->assertSame(0, app(WalletFundingCredits::class)->available($owner, 'another-original-authority'));
        $this->assertSame($state, app(WalletLossReserve::class)->summary($plan));
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $ledger->balance($order->buyer), $ledger->balance($order->shop->user)]);
        $this->assertSame('approved', $grant->fresh()->status);
        $this->assertSame($finding->reference, $order->delivery->latestCustodyCheckpoint->custody_after['finding_reference']);
        $this->assertSame(1, WalletTransaction::where('kind', 'demo_loss_credit_return')->count());
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());
        $this->assertNull($manifest->parcels()->sole()->received_at);
        $this->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()->assertJsonPath('props.loss.controls.actions', []);
        $this->actingAs($order->buyer)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.history', fn ($events) => ! in_array('loss-return-unused-credit', array_column($events, 'event_type'), true));
    }

    public function test_a_partial_contribution_consumes_only_its_earlier_grant_and_cannot_return_the_already_contributed_portion(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $first = $this->grant($owner, $admin, $plan, 10000);
        $this->contribute($owner, $plan, 5000);
        $second = $this->grant($owner, $admin, $plan, 8833);
        $this->contribute($owner, $plan, 10000);
        $balances = app(WalletFundingCredits::class)->grantBalances($owner);
        $this->assertSame(0, $balances[$first->id]);
        $this->assertSame(3833, $balances[$second->id]);
        $this->quarantineMissing($plan);
        $state = app(WalletLossReserve::class)->summary($plan);
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->payload($plan, $first))->assertConflict();
        $this->postJson('/shipment-finances/'.$plan->id.'/actions', $this->payload($plan, $second))->assertOk()->assertJsonPath('event.amount_cents', 3833);
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
        $this->assertSame($state, app(WalletLossReserve::class)->summary($plan));
        $this->assertSame(15000, $state['company_received_cents']);
        $this->assertSame(15000, $state['balance_cents']);
        $this->assertSame(3833, $state['company_remaining_cents']);
    }

    public function test_returning_one_found_case_grant_preserves_the_other_original_grant_and_actual_available_funding(): void
    {
        [$first, $admin, , $owner] = $this->plan();
        [$second] = $this->plan();
        $this->assertSame($first->logistics_company_id, $second->logistics_company_id);
        $grant = $this->grant($owner, $admin, $first);
        $other = $this->grant($owner, $admin, $second);
        $this->quarantineMissing($first);
        $wrongSource = $this->payload($first, $grant);
        $wrongSource['source_event_id'] = $this->payload($second, $other)['source_event_id'];
        $this->actingAs($owner)->postJson('/shipment-finances/'.$first->id.'/actions', $wrongSource)->assertConflict();
        $this->postJson('/shipment-finances/'.$first->id.'/actions', $this->payload($first, $grant))->assertOk();
        $credits = app(WalletFundingCredits::class);
        $this->assertSame(18833, $credits->available($owner, $second->reference));
        $this->assertSame(0, $credits->available($owner, $first->reference));
        $this->contribute($owner, $second, 18833);
        $this->assertSame(18833, app(WalletLossReserve::class)->summary($second)['company_received_cents']);
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
    }

    public function test_an_unused_grant_return_after_actual_compensation_preserves_paid_beneficiaries_and_the_original_advance_debt(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        foreach (['reserve-original' => 'original_reserve_confirmed', 'approve-advance' => 'demo_advance_confirmed', 'resolve-wallet' => 'loss_settlement_confirmed'] as $action => $field) {
            app(ShipmentLossWallet::class)->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Retain the original actual payment and funded advance for the independently approved separate recipients.', $field => true,
                ...$action === 'approve-advance' ? ['amount' => '188.33'] : []]);
        }
        $this->quarantineMissing($plan);
        $ledger = app(WalletLedger::class);
        $before = [$ledger->balance($order->buyer), $ledger->balance($order->shop->user), app(WalletLossReserve::class)->summary($plan)];
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->payload($plan, $grant))->assertOk();
        $this->assertSame($before, [$ledger->balance($order->buyer), $ledger->balance($order->shop->user), app(WalletLossReserve::class)->summary($plan)]);
        $this->assertSame(18833, $before[2]['advance_outstanding_cents']);
        $this->assertSame(18833, $before[2]['company_remaining_cents']);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_loss_refund')->count());
        $this->assertSame(0, WalletTransaction::where('kind', 'wallet_loss_advance_retirement')->count());
    }

    public function test_a_real_partial_return_finding_retains_its_typed_quarantine_and_original_completed_sale(): void
    {
        [$plan, $admin, $order, $owner, $case] = $this->plan(true);
        $due = app(WalletLossReserve::class)->summary($plan)['company_remaining_cents'];
        $grant = $this->grant($owner, $admin, $plan, $due);
        $this->quarantineMissing($plan);
        $before = [$order->fresh()->getAttributes(), $case->parcel->fresh()->state(), app(WalletLossReserve::class)->summary($plan)];
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->payload($plan, $grant))->assertOk();
        $this->assertSame($before, [$order->fresh()->getAttributes(), $case->parcel->fresh()->state(), app(WalletLossReserve::class)->summary($plan)]);
        $this->assertSame('in_transit', $case->fresh()->status);
    }

    public function test_new_returns_require_original_owner_current_version_consent_and_current_private_evidence_while_exact_saved_retries_remain_actual(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $this->actingAs($owner)->postJson($url, $this->payload($plan, $grant))->assertConflict();
        $finding = $this->quarantineMissing($plan);
        $input = $this->payload($plan, $grant);
        foreach ([$admin, $order->buyer, $order->shop->user] as $wrong) {
            $this->actingAs($wrong)->postJson($url, $input)->assertForbidden();
        }
        $this->actingAs($owner);
        foreach ([['unused_credit_return_confirmed' => false], ['reason' => 'short'], ['reason' => str_repeat('x', 1001)],
            ['amount' => '1.00'], ['recipient_id' => $admin->id], ['received_at' => now()->toIso8601String()]] as $invalid) {
            $this->postJson($url, [...$input, ...$invalid])->assertUnprocessable();
        }
        $this->postJson($url, [...$input, 'expected_version' => 1])->assertConflict();
        $photo = Storage::disk('local')->get($finding->proof_path);
        Storage::disk('local')->delete($finding->proof_path);
        $this->postJson($url, $input)->assertConflict();
        Storage::disk('local')->put($finding->proof_path, $photo);
        $owner->update(['status' => 'suspended']);
        $this->postJson($url, $input)->assertOk();
        Storage::disk('local')->delete($finding->proof_path);
        $this->postJson($url, $input)->assertOk();
        $this->assertSame(0, app(WalletFundingCredits::class)->available($owner, 'another-original-authority'));
        $this->postJson($url, [...$input, 'reason' => 'An altered request cannot appropriate the retained actual grant return.'])->assertConflict();
        $this->assertSame(1, WalletTransaction::where('kind', 'demo_loss_credit_return')->count());
    }

    public function test_a_financial_event_failure_rolls_back_the_owner_debit_and_original_issuance_return_before_exact_retry(): void
    {
        [$plan, $admin, , $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        $this->quarantineMissing($plan);
        $input = $this->payload($plan, $grant);
        $before = [WalletTransaction::count(), app(WalletLedger::class)->balance($owner), app(WalletFundingCredits::class)->grantBalances($owner)];
        $failed = false;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, static function ($event) use (&$failed) {
            if ($event->event_type === 'loss-return-unused-credit' && ! $failed) {
                $failed = true;
                throw new \RuntimeException('Injected retained return event failure after its actual journal.');
            }
        });
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertServerError();
        $this->assertSame($before, [WalletTransaction::count(), app(WalletLedger::class)->balance($owner), app(WalletFundingCredits::class)->grantBalances($owner)]);
        $this->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk();
        $this->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk();
        $this->assertSame(1, WalletTransaction::where('kind', 'demo_loss_credit_return')->count());
    }

    public function test_a_mismatched_saved_return_reference_cannot_leave_a_real_owner_debit_without_its_exact_financial_provenance(): void
    {
        [$plan, $admin, , $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        $this->quarantineMissing($plan);
        $input = $this->payload($plan, $grant);
        $before = [WalletTransaction::count(), app(WalletLedger::class)->balance($owner), app(WalletFundingCredits::class)->grantBalances($owner)];
        $mismatched = false;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, static function ($event) use (&$mismatched) {
            if ($event->event_type === 'loss-return-unused-credit' && ! $mismatched) {
                $mismatched = true;
                $event->context = [...$event->context, 'wallet_reference' => 'A-DIFFERENT-RETURN-JOURNAL'];
            }
        });
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertConflict();
        $this->assertSame($before, [WalletTransaction::count(), app(WalletLedger::class)->balance($owner), app(WalletFundingCredits::class)->grantBalances($owner)]);
        $this->assertSame(0, $plan->events()->where('event_type', 'loss-return-unused-credit')->count());
        $this->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk();
        $this->assertSame(1, WalletTransaction::where('kind', 'demo_loss_credit_return')->count());
    }
}
