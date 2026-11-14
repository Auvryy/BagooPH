<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\ShipmentLossAccountingService;
use App\Services\Finance\ShipmentLossControls;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossWallet;
use App\Services\Wallet\WalletFundingCredits;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletLossCreditService;
use App\Services\Wallet\WalletLossPlatformReturn;
use App\Services\Wallet\WalletLossReserve;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private function sourceReturnInput(ShipmentFinancialPlan $plan, ShipmentFinancialEvent $source): array
    {
        return ['action' => 'return-company-source', 'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'source_event_id' => $source->id, 'reason' => 'Return my actual unused original reserve contribution while retaining its original reviewed grant restrictions.',
            'company_source_return_confirmed' => true];
    }

    private function platformReturnInput(ShipmentFinancialPlan $plan, ShipmentFinancialEvent $source): array
    {
        return ['action' => 'return-platform-source', 'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'source_event_id' => $source->id, 'reason' => 'Independently review the actual finding and restore only the unused original platform source without a buyer repayment or parcel release.',
            'platform_source_return_confirmed' => true];
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

    public function test_an_actual_unused_company_contribution_returns_to_its_owner_and_restores_its_original_grant_before_returning_issuance(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        $this->contribute($owner, $plan, 15000);
        $source = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        $this->assertSame(3833, app(WalletFundingCredits::class)->grantBalances($owner)[$grant->id]);
        $this->quarantineMissing($plan);
        $input = $this->sourceReturnInput($plan, $source);
        $before = [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()];
        $this->actingAs($owner)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.controls.actions.1.id', 'return-company-source')
            ->assertJsonPath('props.loss.controls.actions.1.sources.0.amount_cents', 15000);
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk()
            ->assertJsonPath('event.event_type', 'loss-return-company-source')->assertJsonPath('event.amount_cents', 15000);
        $this->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk();
        $credits = app(WalletFundingCredits::class);
        $this->assertSame(18833, $credits->grantBalances($owner)[$grant->id]);
        $this->assertSame(0, $credits->available($owner, 'another-original-authority'));
        $state = app(WalletLossReserve::class)->summary($plan);
        $this->assertSame(0, $state['company_received_cents']);
        $this->assertSame(18833, $state['company_remaining_cents']);
        $this->assertSame(0, $state['balance_cents']);
        $this->assertSame(1, WalletRefundReserveEntry::where('source_kind', 'company_source_return')->count());
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()]);
        $this->postJson('/shipment-finances/'.$plan->id.'/actions', $this->payload($plan, $grant))->assertOk()->assertJsonPath('event.amount_cents', 18833);
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
    }

    public function test_returning_contributions_in_reverse_order_restores_the_exact_grants_they_actually_consumed(): void
    {
        [$plan, $admin, , $owner] = $this->plan();
        $first = $this->grant($owner, $admin, $plan, 10000);
        $this->contribute($owner, $plan, 5000);
        $firstSource = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        $second = $this->grant($owner, $admin, $plan, 8833);
        $this->contribute($owner, $plan, 10000);
        $secondSource = $plan->events()->where('event_type', 'loss-contribute-company')->where('amount_cents', 10000)->sole();
        $this->quarantineMissing($plan);
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $credits = app(WalletFundingCredits::class);
        $this->actingAs($owner)->postJson($url, $this->payload($plan, $second))->assertOk()->assertJsonPath('event.amount_cents', 3833);
        $this->postJson($url, $this->sourceReturnInput($plan, $secondSource))->assertOk();
        $this->assertSame([$first->id => 5000, $second->id => 5000], $credits->grantBalances($owner));
        $this->postJson($url, $this->payload($plan, $first))->assertOk()->assertJsonPath('event.amount_cents', 5000);
        $this->postJson($url, $this->sourceReturnInput($plan, $firstSource))->assertOk();
        $this->assertSame([$first->id => 5000, $second->id => 5000], $credits->grantBalances($owner));
        $this->assertSame(0, $credits->available($owner, 'a-different-original-authority'));
        $this->postJson($url, $this->payload($plan, $first))->assertOk()->assertJsonPath('event.amount_cents', 5000);
        $this->postJson($url, $this->payload($plan, $second))->assertOk()->assertJsonPath('event.amount_cents', 5000);
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
        $this->assertSame(0, app(WalletLossReserve::class)->summary($plan)['balance_cents']);
        $this->assertSame(2, WalletRefundReserveEntry::where('source_kind', 'company_source_return')->count());
    }

    public function test_restoring_owned_credits_contributed_before_any_grant_keeps_them_unrestricted_without_consuming_later_or_other_case_grants(): void
    {
        [$plan, $admin, , $owner] = $this->plan();
        $ledger = app(WalletLedger::class);
        DB::transaction(fn () => $ledger->post('isolated-original-unrestricted-source', 'demo_credit', 10000,
            [$ledger->user($owner)->id => 10000, $ledger->platform('demo_issuance')->id => -10000], $admin,
            'Isolated actual balanced preexisting unrestricted credits, before any reviewed liability grant.'));
        $this->contribute($owner, $plan, 10000);
        $source = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        [$other] = $this->plan();
        $otherGrant = $this->grant($owner, $admin, $other);
        $this->quarantineMissing($plan);
        $input = $this->sourceReturnInput($plan, $source);
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $input)->assertOk();
        $credits = app(WalletFundingCredits::class);
        $this->assertSame([$otherGrant->id => 18833], $credits->grantBalances($owner));
        $this->assertSame(10000, $credits->available($owner, $plan->reference));
        $this->assertSame(28833, $credits->available($owner, $other->reference));
        $this->contribute($owner, $other, 18833);
        $this->assertSame(10000, $ledger->balance($owner));
    }

    public function test_a_company_source_return_does_not_restore_original_escrow_or_undo_an_actual_advance_retirement(): void
    {
        [$plan, $admin, , $owner] = $this->plan();
        $this->grant($owner, $admin, $plan);
        foreach (['reserve-original' => 'original_reserve_confirmed', 'approve-advance' => 'demo_advance_confirmed'] as $action => $field) {
            app(ShipmentLossWallet::class)->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Retain actual original escrow and a separately approved demonstration advance without paying compensation yet.',
                $field => true, ...$action === 'approve-advance' ? ['amount' => '188.33'] : []]);
        }
        $this->contribute($owner, $plan, 15000);
        $source = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        $this->quarantineMissing($plan);
        $before = app(WalletLossReserve::class)->summary($plan);
        $this->assertSame(15000, $before['advance_retired_cents']);
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->sourceReturnInput($plan, $source))->assertOk();
        $after = app(WalletLossReserve::class)->summary($plan);
        foreach (['original_reserved_cents', 'advance_cents', 'advance_retired_cents', 'advance_outstanding_cents', 'resolved'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
        $this->assertSame(28833, $after['balance_cents']);
        $this->assertSame(18833, $after['company_remaining_cents']);
        $this->assertSame(0, WalletTransaction::where('kind', 'wallet_loss_refund')->count());
    }

    public function test_actual_paid_compensation_cannot_be_reversed_as_if_the_original_company_contribution_were_unused(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $this->grant($owner, $admin, $plan);
        $this->contribute($owner, $plan, 18833);
        $source = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        foreach (['reserve-original' => 'original_reserve_confirmed', 'resolve-wallet' => 'loss_settlement_confirmed'] as $action => $field) {
            app(ShipmentLossWallet::class)->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Use the actual original payment and company funding for the independently approved original buyer and seller.', $field => true]);
        }
        $this->quarantineMissing($plan);
        $ledger = app(WalletLedger::class);
        $before = [$ledger->balance($owner), $ledger->balance($order->buyer), $ledger->balance($order->shop->user), app(WalletLossReserve::class)->summary($plan), WalletTransaction::count()];
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->sourceReturnInput($plan, $source))->assertConflict();
        $this->assertSame($before, [$ledger->balance($owner), $ledger->balance($order->buyer), $ledger->balance($order->shop->user), app(WalletLossReserve::class)->summary($plan), WalletTransaction::count()]);
        $this->assertSame(0, WalletRefundReserveEntry::where('source_kind', 'company_source_return')->count());
        $this->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()->assertJsonPath('props.loss.controls.actions', []);
    }

    public function test_source_returns_require_current_original_owner_evidence_and_consent_and_cannot_be_repeated_with_a_new_identity(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $this->grant($owner, $admin, $plan);
        $this->contribute($owner, $plan, 15000);
        $source = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $this->actingAs($owner)->postJson($url, $this->sourceReturnInput($plan, $source))->assertConflict();
        [$other] = $this->plan();
        $this->grant($owner, $admin, $other);
        $this->contribute($owner, $other, 18833);
        $otherSource = $other->events()->where('event_type', 'loss-contribute-company')->sole();
        $otherReserve = app(WalletLossReserve::class)->summary($other);
        $finding = $this->quarantineMissing($plan);
        $input = $this->sourceReturnInput($plan, $source);
        foreach ([$admin, $order->buyer, $order->shop->user] as $wrong) {
            $this->actingAs($wrong)->postJson($url, $input)->assertForbidden();
        }
        $this->actingAs($owner);
        foreach ([['company_source_return_confirmed' => false], ['amount' => '1.00'], ['recipient_id' => $admin->id],
            ['reason' => 'short'], ['reason' => str_repeat('x', 1001)]] as $invalid) {
            $this->postJson($url, [...$input, ...$invalid])->assertUnprocessable();
        }
        $this->postJson($url, [...$input, 'expected_version' => 1])->assertConflict();
        $grantEvent = $plan->events()->where('event_type', 'loss-credit-approved')->sole();
        $this->postJson($url, [...$input, 'source_event_id' => $grantEvent->id])->assertConflict();
        $this->postJson($url, [...$input, 'source_event_id' => $otherSource->id])->assertConflict();
        $photo = Storage::disk('local')->get($finding->proof_path);
        Storage::disk('local')->delete($finding->proof_path);
        $this->postJson($url, $input)->assertConflict();
        Storage::disk('local')->put($finding->proof_path, $photo);
        $owner->update(['status' => 'suspended']);
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, $this->sourceReturnInput($plan, $source))->assertConflict();
        Storage::disk('local')->delete($finding->proof_path);
        $this->postJson($url, $input)->assertOk();
        $this->assertSame(18833, app(WalletFundingCredits::class)->available($owner, $plan->reference));
        $this->assertSame(0, app(WalletFundingCredits::class)->available($owner, 'another-authority'));
        $this->assertSame($otherReserve, app(WalletLossReserve::class)->summary($other));
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_loss_company_source_return')->count());
    }

    public function test_a_partial_buyer_return_restores_only_its_typed_company_source_without_changing_the_completed_purchase_or_quarantine(): void
    {
        [$plan, $admin, $order, $owner, $case] = $this->plan(true);
        $due = app(WalletLossReserve::class)->summary($plan)['company_remaining_cents'];
        $grant = $this->grant($owner, $admin, $plan, $due);
        $this->contribute($owner, $plan, $due);
        $source = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        $this->quarantineMissing($plan);
        $before = [$order->fresh()->getAttributes(), $case->parcel->fresh()->state()];
        $this->actingAs($owner)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->sourceReturnInput($plan, $source))->assertOk();
        $this->assertSame($before, [$order->fresh()->getAttributes(), $case->parcel->fresh()->state()]);
        $this->assertSame($due, app(WalletFundingCredits::class)->grantBalances($owner)[$grant->id]);
        $this->assertSame(0, app(WalletLossReserve::class)->summary($plan)['balance_cents']);
    }

    public function test_source_return_reserve_and_financial_failures_rollback_actual_money_and_grant_restoration_before_the_same_retry(): void
    {
        [$plan, $admin, , $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        $this->contribute($owner, $plan, 15000);
        $source = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        $this->quarantineMissing($plan);
        $input = $this->sourceReturnInput($plan, $source);
        $state = fn () => [WalletTransaction::count(), WalletRefundReserveEntry::count(), (int) $plan->events()->max('sequence'),
            app(WalletLedger::class)->balance($owner), app(WalletFundingCredits::class)->grantBalances($owner)];
        $before = $state();
        $failedReserve = false;
        Event::listen('eloquent.creating: '.WalletRefundReserveEntry::class, static function ($entry) use (&$failedReserve) {
            if ($entry->source_kind === 'company_source_return' && ! $failedReserve) {
                $failedReserve = true;
                throw new \RuntimeException('Injected actual source-return reserve failure after its journal.');
            }
        });
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $this->actingAs($owner)->postJson($url, $input)->assertServerError();
        $this->assertSame($before, $state());
        $failedEvent = false;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, static function ($event) use (&$failedEvent) {
            if ($event->event_type === 'loss-return-company-source' && ! $failedEvent) {
                $failedEvent = true;
                $event->context = [...$event->context, 'wallet_reference' => 'NOT-THE-ACTUAL-SOURCE-RETURN'];
            }
        });
        $this->postJson($url, $input)->assertConflict();
        $this->assertSame($before, $state());
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, $input)->assertOk();
        $this->assertSame(18833, app(WalletFundingCredits::class)->grantBalances($owner)[$grant->id]);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_loss_company_source_return')->count());
    }

    public function test_unused_original_escrow_and_only_the_remaining_advance_return_to_their_actual_sources_after_owned_funding_is_restored(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        $grant = $this->grant($owner, $admin, $plan);
        foreach (['reserve-original' => 'original_reserve_confirmed', 'approve-advance' => 'demo_advance_confirmed'] as $action => $field) {
            app(ShipmentLossWallet::class)->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Retain actual unused original escrow and the independently approved advance before the parcel is genuinely found.',
                $field => true, ...$action === 'approve-advance' ? ['amount' => '188.33'] : []]);
        }
        $original = $plan->events()->where('event_type', 'loss-reserve-original')->sole();
        $advance = $plan->events()->where('event_type', 'loss-approve-advance')->sole();
        $this->contribute($owner, $plan, 15000);
        $company = $plan->events()->where('event_type', 'loss-contribute-company')->sole();
        $this->quarantineMissing($plan);
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $this->actingAs($owner)->postJson($url, $this->sourceReturnInput($plan, $company))->assertOk();
        $this->postJson($url, $this->payload($plan, $grant))->assertOk();
        $ledger = app(WalletLedger::class);
        $buyerBalance = $ledger->balance($order->buyer);
        $escrowBalance = WalletAccount::where('kind', 'escrow')->sole()->balance_cents;
        $issuanceBalance = WalletAccount::where('kind', 'demo_issuance')->sole()->balance_cents;
        $physical = $order->delivery->fresh()->getAttributes();
        $input = $this->platformReturnInput($plan, $advance);
        $this->actingAs($admin)->postJson($url, $input)->assertOk()->assertJsonPath('event.amount_cents', 3833);
        $this->postJson($url, $input)->assertOk();
        $this->assertSame($issuanceBalance + 3833, WalletAccount::where('kind', 'demo_issuance')->sole()->balance_cents);
        $this->postJson($url, $this->platformReturnInput($plan, $original))->assertOk()->assertJsonPath('event.amount_cents', 25000);
        $this->assertSame($escrowBalance + 25000, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $state = app(WalletLossReserve::class)->summary($plan);
        $this->assertSame(0, $state['balance_cents']);
        $this->assertSame(0, $state['original_reserved_cents']);
        $this->assertSame(0, $state['advance_outstanding_cents']);
        $this->assertSame(15000, $state['advance_retired_cents']);
        $this->assertSame(3833, $state['advance_returned_cents']);
        $this->assertSame(18833, $state['company_remaining_cents']);
        $this->assertSame($buyerBalance, $ledger->balance($order->buyer));
        $this->assertSame($physical, $order->delivery->fresh()->getAttributes());
        $this->assertSame(25000, app(WalletSettlementService::class)->basis($order)['escrow_cents']);
        $this->assertSame(0, WalletTransaction::where('kind', 'wallet_loss_refund')->count());
    }

    public function test_platform_source_return_handles_multiple_advances_fifo_retirement_and_preserves_unretired_balances(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        foreach ([['action' => 'reserve-original', 'field' => 'original_reserve_confirmed'],
            ['action' => 'approve-advance', 'field' => 'demo_advance_confirmed', 'amount' => '100.00'],
            ['action' => 'approve-advance', 'field' => 'demo_advance_confirmed', 'amount' => '88.33']] as $step) {
            app(ShipmentLossWallet::class)->command($admin, $plan, $step['action'], ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Retain actual original escrow and staged advances before physical discovery.',
                $step['field'] => true, ...(isset($step['amount']) ? ['amount' => $step['amount']] : [])]);
        }
        $advances = $plan->events()->where('event_type', 'loss-approve-advance')->orderBy('sequence')->get()->values();
        $original = $plan->events()->where('event_type', 'loss-reserve-original')->sole();
        app(WalletLedger::class);
        DB::transaction(fn () => app(WalletLedger::class)->post('preexisting-unrestricted-owner-credit', 'demo_credit', 15000,
            [app(WalletLedger::class)->user($owner)->id => 15000, app(WalletLedger::class)->platform('demo_issuance')->id => -15000], $admin,
            'Preexisting balanced company funds used to partially retire the earliest advance first.'));
        $this->contribute($owner, $plan, 15000);
        $this->quarantineMissing($plan);
        $url = '/shipment-finances/'.$plan->id;
        $page = $this->actingAs($admin)->get($url, $this->inertia())->assertOk();
        $platformAction = collect($page->json('props.loss.controls.actions'))->firstWhere('id', 'return-platform-source');
        $this->assertNotNull($platformAction);
        $this->assertEqualsCanonicalizing([['id' => $advances[1]->id, 'amount_cents' => 3833], ['id' => $original->id, 'amount_cents' => 25000]],
            array_map(fn ($s) => ['id' => $s['id'], 'amount_cents' => $s['amount_cents']], $platformAction['sources']));
        $this->postJson($url.'/actions', $this->platformReturnInput($plan, $advances[0]))->assertConflict();
        $this->postJson($url.'/actions', $this->platformReturnInput($plan, $advances[1]))->assertOk()->assertJsonPath('event.amount_cents', 3833);
        $this->postJson($url.'/actions', $this->platformReturnInput($plan, $original))->assertOk()->assertJsonPath('event.amount_cents', 25000);
        $state = app(WalletLossReserve::class)->summary($plan);
        $this->assertSame(0, $state['advance_outstanding_cents']);
        $this->assertSame(15000, $state['advance_retired_cents']);
        $this->assertSame(3833, $state['advance_returned_cents']);
        $this->assertSame(0, $state['original_reserved_cents']);
        $this->assertSame(15000, $state['balance_cents']);
        $this->assertSame(25000, app(WalletSettlementService::class)->basis($order)['escrow_cents']);
    }

    public function test_platform_source_returns_enforce_independent_admin_found_hold_unpaid_outcome_validation_and_cross_case_isolation(): void
    {
        [$plan, $admin, $order, $owner] = $this->plan();
        foreach (['reserve-original' => 'original_reserve_confirmed', 'approve-advance' => 'demo_advance_confirmed'] as $action => $field) {
            app(ShipmentLossWallet::class)->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Retain actual unused original escrow and advance for independent review checks.',
                $field => true, ...$action === 'approve-advance' ? ['amount' => '188.33'] : []]);
        }
        $advance = $plan->events()->where('event_type', 'loss-approve-advance')->sole();
        $url = '/shipment-finances/'.$plan->id;
        $this->actingAs($admin)->postJson($url.'/actions', $this->platformReturnInput($plan, $advance))->assertConflict();
        [$other] = $this->plan();
        app(ShipmentLossWallet::class)->command($admin, $other, 'approve-advance', ['expected_version' => (int) $other->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'reason' => 'Separate advance on another loss plan.', 'demo_advance_confirmed' => true, 'amount' => '100.00']);
        $otherAdvance = $other->events()->where('event_type', 'loss-approve-advance')->sole();
        $finding = $this->quarantineMissing($plan);
        $controls = app(ShipmentLossControls::class);
        $taintedPlan = clone $plan;
        $taintedPlan->snapshot = [...$plan->snapshot, 'source_actor_ids' => [...$plan->snapshot['source_actor_ids'], $admin->id]];
        $sourceAdminControls = $controls->present($admin, $taintedPlan, app(WalletLossReserve::class)->summary($plan), $plan->events()->get());
        $this->assertFalse(in_array('return-platform-source', array_column($sourceAdminControls['actions'], 'id'), true));
        try {
            app(WalletLossPlatformReturn::class)->sources($admin, $taintedPlan);
            $this->fail('A source-participant admin cannot authorize returning platform sources.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        foreach ([$owner, $order->buyer, $order->shop->user] as $wrong) {
            $this->actingAs($wrong)->postJson($url.'/actions', $this->platformReturnInput($plan, $advance))->assertForbidden();
        }
        $this->actingAs($admin);
        foreach ([['platform_source_return_confirmed' => false], ['amount' => '1.00'], ['recipient_id' => $owner->id],
            ['reason' => 'short'], ['reason' => str_repeat('x', 1001)]] as $invalid) {
            $this->postJson($url.'/actions', [...$this->platformReturnInput($plan, $advance), ...$invalid])->assertUnprocessable();
        }
        $this->postJson($url.'/actions', [...$this->platformReturnInput($plan, $advance), 'expected_version' => 1])->assertConflict();
        $this->postJson($url.'/actions', $this->platformReturnInput($plan, $otherAdvance))->assertConflict();
        $photo = Storage::disk('local')->get($finding->proof_path);
        Storage::disk('local')->delete($finding->proof_path);
        $this->postJson($url.'/actions', $this->platformReturnInput($plan, $advance))->assertConflict();
        Storage::disk('local')->put($finding->proof_path, $photo);
        $this->postJson($url.'/actions', $this->platformReturnInput($plan, $advance))->assertOk();
        $this->actingAs($owner)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.history', fn ($events) => ! in_array('loss-return-platform-source', array_column($events, 'event_type'), true));
        $this->actingAs($order->buyer)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.history', fn ($events) => ! in_array('loss-return-platform-source', array_column($events, 'event_type'), true));
    }

    public function test_platform_source_return_cannot_reverse_paid_compensation_or_leave_partial_writes_on_failure(): void
    {
        [$plan, $admin] = $this->plan();
        foreach (['reserve-original' => 'original_reserve_confirmed', 'approve-advance' => 'demo_advance_confirmed', 'resolve-wallet' => 'loss_settlement_confirmed'] as $action => $field) {
            app(ShipmentLossWallet::class)->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Resolve actual compensation cleanly before discovery.', $field => true,
                ...$action === 'approve-advance' ? ['amount' => '188.33'] : []]);
        }
        $advance = $plan->events()->where('event_type', 'loss-approve-advance')->sole();
        $this->quarantineMissing($plan);
        $this->actingAs($admin)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->platformReturnInput($plan, $advance))->assertConflict();
        $this->assertSame(0, WalletRefundReserveEntry::whereIn('source_kind', ['original_escrow_return', 'found_advance_return'])->count());

        [$freshPlan, $freshAdmin] = $this->plan();
        app(ShipmentLossWallet::class)->command($freshAdmin, $freshPlan, 'approve-advance', ['expected_version' => (int) $freshPlan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'reason' => 'Retain advance before rollback verification.', 'demo_advance_confirmed' => true, 'amount' => '188.33']);
        $freshAdvance = $freshPlan->events()->where('event_type', 'loss-approve-advance')->sole();
        $this->quarantineMissing($freshPlan);
        $input = $this->platformReturnInput($freshPlan, $freshAdvance);
        $state = fn () => [WalletTransaction::count(), WalletRefundReserveEntry::count(), (int) $freshPlan->events()->max('sequence'),
            WalletAccount::where('kind', 'demo_issuance')->sole()->balance_cents];
        $before = $state();
        $failedReserve = false;
        Event::listen('eloquent.creating: '.WalletRefundReserveEntry::class, static function ($entry) use (&$failedReserve) {
            if ($entry->source_kind === 'found_advance_return' && ! $failedReserve) {
                $failedReserve = true;
                throw new \RuntimeException('Injected platform source-return reserve failure.');
            }
        });
        $url = '/shipment-finances/'.$freshPlan->id.'/actions';
        $this->actingAs($freshAdmin)->postJson($url, $input)->assertServerError();
        $this->assertSame($before, $state());
        $failedEvent = false;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, static function ($event) use (&$failedEvent) {
            if ($event->event_type === 'loss-return-platform-source' && ! $failedEvent) {
                $failedEvent = true;
                $event->context = [...$event->context, 'wallet_reference' => 'NOT-ACTUAL-PLATFORM-RETURN'];
            }
        });
        $this->postJson($url, $input)->assertConflict();
        $this->assertSame($before, $state());
        $this->postJson($url, $input)->assertOk()->assertJsonPath('event.amount_cents', 18833);
        $this->postJson($url, $input)->assertOk();
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_loss_platform_source_return')->count());
    }

    public function test_platform_source_return_on_partial_buyer_return_advance_preserves_order_and_quarantine(): void
    {
        [$plan, $admin, $order, , $case] = $this->plan(true);
        $due = app(WalletLossReserve::class)->summary($plan)['company_remaining_cents'];
        app(ShipmentLossWallet::class)->command($admin, $plan, 'approve-advance', ['expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'reason' => 'Advance only for partial return missing custody.', 'demo_advance_confirmed' => true,
            'amount' => SellerSettlementService::pesos($due)]);
        $advance = $plan->events()->where('event_type', 'loss-approve-advance')->sole();
        $this->quarantineMissing($plan);
        $before = [$order->fresh()->getAttributes(), $case->parcel->fresh()->state()];
        $this->actingAs($admin)->postJson('/shipment-finances/'.$plan->id.'/actions', $this->platformReturnInput($plan, $advance))->assertOk()->assertJsonPath('event.amount_cents', $due);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $case->parcel->fresh()->state()]);
        $summary = app(WalletLossReserve::class)->summary($plan);
        $this->assertSame(0, $summary['advance_outstanding_cents']);
        $this->assertSame(0, $summary['balance_cents']);
    }
}
