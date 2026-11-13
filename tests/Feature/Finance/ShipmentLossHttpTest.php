<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossDisposition;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use App\Services\Finance\ShipmentLossCash;
use App\Services\Finance\ShipmentLossReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossHttpTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    public static function sources(): array
    {
        return [['wallet', false, 25000], ['cod', false, 0], ['cod', true, 12500]];
    }

    #[DataProvider('sources')]
    public function test_admin_preview_uses_the_prepared_page_contract_and_retains_only_safe_source_scoped_fields(string $mode, bool $return, int $refund): void
    {
        [$source, $admin, $order] = $this->missing($mode, $return);
        $url = '/shipment-finances/missing/'.$source->id;
        $this->actingAs($admin)->get($url.'/review', $this->inertia())->assertOk()
            ->assertJsonPath('component', 'Finance/LossReview')->assertJsonPath('props.order.id', $order->id)
            ->assertJsonPath('props.source.company_name', $source->manifest->company->name)
            ->assertJsonPath('props.basis.buyer_refund_cents', $refund)->assertJsonPath('props.preview', null)
            ->assertHeader('Cache-Control', 'no-store, private');
        $safe = ['seller_protection' => $return ? '0.00' : '180.00', 'investigation_reference' => 'ACTUAL-INDEPENDENT-REVIEW'];
        $this->post($url.'/preview', $safe + ['reason' => 'Private unfinished text must not enter the cached preview.', 'loss_confirmed' => true])
            ->assertRedirect($url.'/review');
        $saved = session('shipment_loss_preview');
        $this->assertSame($source->id, $saved['observation_id']);
        $this->assertSame($admin->id, $saved['actor_id']);
        $this->assertSame($safe, $saved['value']['input']);
        $this->assertSame(['input', 'review_token', 'amounts'], array_keys($saved['value']));
        $this->assertCount(6, $saved['value']['amounts']);
        $page = $this->get($url.'/review', $this->inertia())->assertOk()
            ->assertJsonPath('props.preview', $saved['value']);
        $this->assertTrue(Str::isUuid($page->json('props.requestToken')));
        $this->assertStringNotContainsString('Private unfinished text', json_encode($page->json(), JSON_THROW_ON_ERROR));
        $this->actingAs($this->createApprovedUser('admin'))->get($url.'/review', $this->inertia())
            ->assertOk()->assertJsonPath('props.preview', null);
        $this->assertDatabaseCount('shipment_financial_plans', 0);
    }

    public function test_browser_approval_reaches_retained_scoped_outcome_and_exact_json_retry_does_not_move_money_or_custody(): void
    {
        [$source, $admin, $order] = $this->missing();
        $url = '/shipment-finances/missing/'.$source->id;
        $input = $this->approval($source, $admin);
        $proof = UploadedFile::fake()->image('actual-investigation.png');
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), WalletTransaction::count()];
        $this->actingAs($admin)->post($url.'/preview', $input)->assertRedirect($url.'/review');
        $this->post($url.'/approve', $input + ['proof' => $proof])->assertRedirect('/shipment-finances/1')->assertSessionHas('success');
        $plan = ShipmentFinancialPlan::sole();
        $this->assertNull(session('shipment_loss_preview'));
        $this->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('component', 'Finance/LossOutcome')->assertJsonPath('props.loss.status', 'awaiting_payment')
            ->assertJsonPath('props.loss.amounts.Buyer refund', 25000)
            ->assertJsonPath('props.loss.amounts.Separate seller protection', 18000)
            ->assertJsonPath('props.loss.history.0.event_type', 'approved')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url.'/approve', $input + ['proof' => $proof])->assertCreated()->assertJsonPath('plan.id', $plan->id);
        $this->get($url.'/review')->assertRedirect('/shipment-finances/'.$plan->id);
        $this->assertDatabaseCount('shipment_financial_plans', 1);
        $this->assertDatabaseCount('shipment_financial_events', 1);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), WalletTransaction::count()]);
    }

    public function test_review_rejects_stale_actual_receipt_and_clears_only_its_own_preview_without_creating_approval(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $url = '/shipment-finances/missing/'.$source->id;
        $input = $this->approval($source, $admin);
        $this->actingAs($admin)->post($url.'/preview', $input)->assertRedirect($url.'/review');
        $manifest = $source->manifest->fresh();
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->openFlowMotherBags($manifest, $receiver);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest,
            ['barcode' => $order->delivery->tracking_number, 'notes' => 'The original missing parcel was actually found and received.']))->assertOk();
        $this->actingAs($admin)->postJson($url.'/approve', $input + ['proof' => UploadedFile::fake()->image('stale.png')])->assertStatus(409);
        $this->get($url.'/review', $this->inertia())->assertOk()->assertJsonPath('props.preview', null)
            ->assertJsonPath('props.source', null)->assertJsonPath('props.basis', null)->assertJsonPath('props.blocker', fn ($value) => is_string($value) && $value !== '');
        $this->assertNull(session('shipment_loss_preview'));
        $this->assertDatabaseCount('shipment_financial_plans', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('shipment-finance'));
    }

    public function test_browser_validation_does_not_flash_private_reason_or_confirmation_and_direct_amounts_are_rejected(): void
    {
        [$source, $admin] = $this->missing('cod');
        $url = '/shipment-finances/missing/'.$source->id;
        $input = $this->approval($source, $admin);
        $this->actingAs($admin)->from($url.'/review')->post($url.'/approve', $input)
            ->assertRedirect($url.'/review')->assertSessionHasErrors('proof');
        $this->assertSame([], session()->getOldInput());
        foreach (['company_id' => 1, 'buyer_id' => 1, 'buyer_refund_cents' => 999, 'amount_cents' => 999] as $field => $value) {
            $this->postJson($url.'/preview', $input + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($url.'/approve', $input + ['proof' => UploadedFile::fake()->createWithContent('fake.pdf', 'This is not real PDF evidence.')])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertDatabaseCount('shipment_financial_plans', 0);
    }

    public function test_original_parties_receive_only_their_own_financial_summary_and_independent_proof_remains_admin_only(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('private-review.png'));
        $url = '/shipment-finances/'.$plan->id;
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        foreach ([$order->buyer, $order->shop->user, $owner] as $actor) {
            $this->actingAs($actor)->get($url, $this->inertia())->assertOk()->assertJsonPath('props.loss.source', null)
                ->assertJsonPath('props.loss.review_reason', null)->assertJsonPath('props.loss.review_proof_url', null);
            $this->get($url.'/proof')->assertNotFound();
            foreach (['review', 'preview', 'approve'] as $action) {
                $review = '/shipment-finances/missing/'.$source->id.'/'.$action;
                ($action === 'review' ? $this->getJson($review) : $this->postJson($review, []))->assertForbidden();
            }
        }
        $this->actingAs($order->buyer)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.amounts', ['Buyer refund' => 0]);
        $this->actingAs($order->shop->user)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.amounts', ['Separate seller protection' => 18000]);
        $owner->update(['status' => 'suspended']);
        $this->actingAs($owner)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.amounts.Company funding outstanding', 18833);
        foreach (['buyer', 'seller', 'logistics'] as $role) {
            $this->actingAs($this->createApprovedUser($role))->get($url, $this->inertia())->assertNotFound();
        }
        $floor = $this->flowHandler(LogisticsHub::findOrFail($source->manifest->destination_hub_id));
        $this->actingAs($floor)->get($url, $this->inertia())->assertNotFound();
        $this->actingAs($admin)->get($url.'/proof')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($url.'/events/'.$plan->events()->sole()->id.'/proof')->assertNotFound();
        Storage::disk('local')->put($plan->proof_path, 'Changed bytes must not download as the reviewed evidence.');
        $this->get($url.'/proof')->assertNotFound();
        $this->get($url, $this->inertia())->assertOk();
    }

    public function test_company_and_seller_cash_evidence_are_isolated_even_when_they_share_the_same_original_loss(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('private-review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $receiver = $this->createApprovedUser('admin');
        $service = app(ShipmentLossCash::class);
        $command = function ($actor, string $action, array $extra) use ($service, $plan) {
            return $service->command($actor, $plan, $action, $extra + ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Checked the actual original company transfer and its separate recipient evidence.',
                'payment_confirmed' => true], UploadedFile::fake()->image('actual-'.$action.'.png'));
        };
        $offer = $command($owner, 'offer-company', ['amount' => '188.33', 'recipient_id' => $receiver->id, 'cash_reference' => 'PRIVATE-COMPANY-TRANSFER']);
        $received = $command($receiver, 'receive-company', ['source_event_id' => $offer->id]);
        $payment = $command($admin, 'record-seller-protection', ['cash_reference' => 'PRIVATE-SELLER-PROTECTION']);
        $url = '/shipment-finances/'.$plan->id;
        foreach ([$owner, $order->shop->user, $order->buyer] as $actor) {
            $company = $actor->id === $owner->id;
            $seller = $actor->id === $order->shop->user_id;
            foreach ([$offer, $received, $payment] as $event) {
                $allowed = $company ? $event->id !== $payment->id : ($seller && $event->id === $payment->id);
                $response = $this->actingAs($actor)->get($url.'/events/'.$event->id.'/proof');
                $allowed ? $response->assertOk() : $response->assertNotFound();
            }
            $page = $this->get($url, $this->inertia())->assertOk();
            $history = $page->json('props.loss.history');
            $this->assertSame($company ? [1, $offer->id, $received->id] : ($seller ? [1, $payment->id] : [1]), array_column($history, 'id'));
            $this->assertStringNotContainsString($company ? 'PRIVATE-SELLER-PROTECTION' : 'PRIVATE-COMPANY-TRANSFER', json_encode($page->json(), JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($plan->proof_path, json_encode($page->json(), JSON_THROW_ON_ERROR));
        }
        $this->actingAs($admin)->get($url.'/events/'.$payment->id.'/proof')->assertOk();
    }

    public function test_voided_unfunded_review_retains_history_without_presenting_its_cancelled_company_obligation_as_due(): void
    {
        [$source, $admin] = $this->missing('cod');
        $review = app(ShipmentLossReview::class);
        $plan = $review->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('original-review.png'));
        $review->voidUnfunded($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'unfunded_void_confirmed' => true, 'reason' => 'Retain this unfunded superseded investigation for an independently evidenced new review.']);
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $this->actingAs($owner)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.status', 'voided')->assertJsonPath('props.loss.amounts.Company funding outstanding', 0)
            ->assertJsonCount(2, 'props.loss.history')->assertJsonPath('props.loss.history.1.event_type', 'voided');
        $this->actingAs($admin)->get('/shipment-finances/missing/'.$source->id.'/review', $this->inertia())->assertOk()
            ->assertJsonPath('component', 'Finance/LossReview')->assertJsonPath('props.preview', null);
        $this->assertDatabaseCount('shipment_financial_plans', 1);
    }

    #[DataProvider('sources')]
    public function test_finance_queue_discovers_actual_missing_sources_and_keeps_owned_loss_amounts_and_status_filters_separate(string $mode, bool $return, int $refund): void
    {
        [$source, $admin, $order] = $this->missing($mode, $return);
        $url = '/return-liabilities?view=losses';
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), WalletTransaction::count()];
        $this->actingAs($admin)->get($url, $this->inertia())->assertOk()->assertJsonPath('component', 'Finance/LossQueue')
            ->assertJsonCount(0, 'props.cases.data')->assertJsonCount(1, 'props.reviewCandidates.data')
            ->assertJsonPath('props.reviewCandidates.data.0.href', '/shipment-finances/missing/'.$source->id.'/review')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url.'&search='.urlencode($order->order_number), $this->inertia())->assertOk()
            ->assertJsonCount(1, 'props.reviewCandidates.data')->assertJsonPath('props.reviewCandidates.data.0.id', $source->id);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'),
            UploadedFile::fake()->image('actual-queue-review.png'));
        $this->get($url, $this->inertia())->assertOk()->assertJsonCount(1, 'props.cases.data')
            ->assertJsonPath('props.cases.data.0.amount_cents', $refund)->assertJsonCount(0, 'props.reviewCandidates.data');
        $this->get($url.'&status=awaiting_payment', $this->inertia())->assertOk()->assertJsonCount(1, 'props.cases.data');
        $this->get($url.'&status=financially_resolved', $this->inertia())->assertOk()->assertJsonCount(0, 'props.cases.data');
        $this->get($url.'&search='.urlencode($order->order_number), $this->inertia())->assertOk()->assertJsonCount(1, 'props.cases.data');
        $this->get($url.'&search=%25', $this->inertia())->assertOk()->assertJsonCount(0, 'props.cases.data');
        $this->getJson($url.'&status=shipping_due')->assertUnprocessable()->assertJsonValidationErrors('status');
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        foreach ([$order->buyer, $order->shop->user, $owner] as $actor) {
            $page = $this->actingAs($actor)->get($url, $this->inertia())->assertOk()
                ->assertJsonCount(1, 'props.cases.data')->assertJsonPath('props.reviewCandidates', null);
            $row = $page->json('props.cases.data.0');
            $seller = $actor->isSeller();
            $this->assertSame($seller ? 'Separate seller protection' : 'Buyer refund', $row['amount_label']);
            $this->assertSame($seller ? ($return ? 0 : 18000) : $refund, $row['amount_cents']);
            $this->assertArrayNotHasKey('buyer_refund_cents', $row);
            $this->assertSame($actor->isLogistics() ? $plan->snapshot['company_funding_due_cents'] : null, $row['outstanding_cents']);
        }
        $this->actingAs($this->createApprovedUser('buyer'))->get($url, $this->inertia())->assertOk()->assertJsonCount(0, 'props.cases.data');
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), WalletTransaction::count()]);
        app(ShipmentLossReview::class)->voidUnfunded($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'unfunded_void_confirmed' => true, 'reason' => 'Keep the original unfunded review in history while the independently reviewed basis is corrected.']);
        $this->actingAs($owner)->get($url.'&status=voided', $this->inertia())->assertOk()->assertJsonCount(1, 'props.cases.data')
            ->assertJsonPath('props.cases.data.0.outstanding_cents', 0);
        $this->get($url.'&status=awaiting_payment', $this->inertia())->assertOk()->assertJsonCount(0, 'props.cases.data');
    }

    private function lossInput(ShipmentFinancialPlan $plan, string $action, string $confirmation, array $extra = []): array
    {
        return ['action' => $action, 'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'reason' => 'Checked the original actual funding, separate beneficiary and retained loss evidence.', $confirmation => true, ...$extra];
    }

    private function lossCommand(User $actor, ShipmentFinancialPlan $plan, string $action, string $confirmation, array $extra = []): array
    {
        $input = $this->lossInput($plan, $action, $confirmation, $extra);
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $saved = $this->actingAs($actor)->postJson($url, $input)->assertOk()->json('event');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('event.id', $saved['id']);

        return $saved;
    }

    private function available(User $actor, ShipmentFinancialPlan $plan): array
    {
        return $this->actingAs($actor)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()->json('props.loss.controls');
    }

    public static function walletJourneys(): array
    {
        return [[false, false], [false, true], [true, false]];
    }

    #[DataProvider('walletJourneys')]
    public function test_public_wallet_loss_requires_an_independent_grant_and_separate_original_reserve_before_once_only_refund(bool $return, bool $voucher): void
    {
        [$source, $admin, $order, $case] = $this->missing('wallet', $return, $voucher);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'), UploadedFile::fake()->image('review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), $case?->getAttributes()];
        $url = '/shipment-finances/'.$plan->id;
        $this->assertNotContains('resolve-wallet', array_column($this->available($admin, $plan)['actions'], 'id'));
        if (! $return) {
            $this->lossCommand($admin, $plan, 'reserve-original', 'original_reserve_confirmed');
        }
        $cents = $plan->snapshot['company_funding_due_cents'];
        $amount = number_format($cents / 100, 2, '.', '');
        $this->assertSame($cents, $this->available($owner, $plan)['credits']['maximum_cents']);
        $requestInput = ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'reason' => 'Request only the original company shortfall in owned demonstration funding credits.', 'amount' => $amount];
        $funding = $this->actingAs($owner)->postJson($url.'/wallet-funding-credits', $requestInput)->assertOk()->json('request');
        $this->postJson($url.'/wallet-funding-credits', $requestInput)->assertOk()->assertJsonPath('request.id', $funding['id']);
        $this->assertTrue($this->available($admin, $plan)['credits']['requests'][0]['can_approve']);
        $this->assertFalse($this->available($owner, $plan)['credits']['requests'][0]['can_review']);
        foreach ([$order->buyer, $order->shop->user] as $other) {
            $controls = $this->available($other, $plan);
            $this->assertNull($controls['credits']);
            $this->assertNull($controls['funding']);
            $this->assertSame([], $controls['actions']);
        }
        $review = ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved', 'demo_credit_confirmed' => true,
            'reason' => 'Independently checked the original company need and its separate owned transfer requirement.'];
        $this->actingAs($owner)->postJson($url.'/wallet-funding-credits/'.$funding['id'].'/review', $review)->assertForbidden();
        $this->actingAs($admin)->postJson($url.'/wallet-funding-credits/'.$funding['id'].'/review', $review)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame($cents, $this->available($owner, $plan)['funding']['Company funding outstanding']);
        $owner->update(['status' => 'suspended']);
        $this->lossCommand($owner, $plan, 'contribute-company', 'company_transfer_confirmed', ['amount' => $amount]);
        $this->assertSame(0, $this->available($owner, $plan)['funding']['Company funding outstanding']);
        $resolved = $this->lossCommand($admin, $plan, 'resolve-wallet', 'loss_settlement_confirmed');
        $this->assertSame($plan->snapshot['buyer_refund_cents'], $resolved['amount_cents']);
        $this->assertSame(1, WalletTransaction::where('business_key', 'shipment-loss:'.$plan->id)->count());
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
        $this->assertDatabaseCount('wallet_refund_credit_requests', 1);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $case?->fresh()->getAttributes()]);
        $this->actingAs($admin)->postJson($url.'/wallet-funding-credits/'.$funding['id'].'/review', $review)->assertOk()->assertJsonPath('request.id', $funding['id']);
        $this->assertNotContains('resolve-wallet', array_column($this->available($admin, $plan)['actions'], 'id'));
    }

    public function test_public_unpaid_cod_loss_records_actual_advance_separate_seller_receipt_dispute_and_actual_advance_return(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $receiver = $this->createApprovedUser('admin');
        $receipt = fn () => UploadedFile::fake()->image('actual-transfer.png');
        $offer = $this->lossCommand($admin, $plan, 'offer-advance', 'payment_confirmed',
            ['amount' => '188.33', 'recipient_id' => $receiver->id, 'cash_reference' => 'ACTUAL-LOSS-ADVANCE', 'proof' => $receipt()]);
        $this->assertNotContains('record-seller-protection', array_column($this->available($admin, $plan)['actions'], 'id'));
        $this->lossCommand($receiver, $plan, 'receive-advance', 'payment_confirmed', ['source_event_id' => $offer['id'], 'proof' => $receipt()]);
        $pay = $this->lossCommand($admin, $plan, 'record-seller-protection', 'payment_confirmed', ['cash_reference' => 'ACTUAL-SELLER-PROTECTION', 'proof' => $receipt()]);
        $this->lossCommand($order->shop->user, $plan, 'report-seller-nonreceipt', 'action_confirmed', ['source_event_id' => $pay['id']]);
        $this->lossCommand($admin, $plan, 'resolve-seller-nonreceipt', 'payment_confirmed', ['source_event_id' => $pay['id'], 'proof' => $receipt()]);
        $this->lossCommand($order->shop->user, $plan, 'confirm-seller-receipt', 'action_confirmed', ['source_event_id' => $pay['id']]);
        $this->lossCommand($admin, $plan, 'assess-zero-buyer', 'zero_refund_confirmed');
        $this->lossCommand($admin, $plan, 'resolve-cash', 'financial_resolution_confirmed');
        $this->assertSame(18833, $this->available($owner, $plan)['funding']['Company funding outstanding']);
        $own = $this->lossCommand($owner, $plan, 'offer-company', 'payment_confirmed',
            ['amount' => '188.33', 'recipient_id' => $receiver->id, 'cash_reference' => 'ACTUAL-COMPANY-REPAYMENT', 'proof' => $receipt()]);
        $this->lossCommand($receiver, $plan, 'receive-company', 'payment_confirmed', ['source_event_id' => $own['id'], 'proof' => $receipt()]);
        $advanced = $plan->events()->where('event_type', 'loss-receive-advance')->sole();
        $this->lossCommand($receiver, $plan, 'retire-advance', 'payment_confirmed',
            ['amount' => '188.33', 'source_event_id' => $advanced->id, 'cash_reference' => 'ACTUAL-ADVANCE-RETURN', 'proof' => $receipt()]);
        $controls = $this->available($owner, $plan);
        $this->assertSame(0, $controls['funding']['Company funding outstanding']);
        $this->assertSame(0, $controls['funding']['Advance outstanding']);
        $this->assertSame(1, $plan->events()->where('event_type', 'loss-record-seller-protection')->count());
        $this->assertFalse($plan->events()->where('event_type', 'loss-record-buyer-payment')->exists());
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertFalse($order->delivery->checkpoints()->whereIn('checkpoint_type', ['delivered', 'seller_return_received'])->exists());
    }

    public function test_public_paid_cod_return_loss_uses_original_collection_and_keeps_repayment_private_to_the_buyer(): void
    {
        [$source, $admin, $order, $case] = $this->missing('cod', true, false, true);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, '0.00'), UploadedFile::fake()->image('review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $receiver = $this->createApprovedUser('admin');
        $before = [$order->getAttributes(), $case->getAttributes(), $order->delivery->getAttributes()];
        $proof = fn () => UploadedFile::fake()->image('original-actual-payment.png');
        $offer = $this->lossCommand($owner, $plan, 'offer-company', 'payment_confirmed',
            ['amount' => '125.00', 'recipient_id' => $receiver->id, 'cash_reference' => 'ACTUAL-RETURN-COMPANY', 'proof' => $proof()]);
        $this->lossCommand($receiver, $plan, 'receive-company', 'payment_confirmed', ['source_event_id' => $offer['id'], 'proof' => $proof()]);
        $event = $plan->events()->where('event_type', 'loss-receive-company')->sole();
        $bytes = Storage::disk('local')->get($event->proof_path);
        Storage::disk('local')->delete($event->proof_path);
        $blocked = $this->available($admin, $plan);
        $this->assertNotNull($blocked['hold_message']);
        $this->assertNotContains('record-buyer-payment', array_column($blocked['actions'], 'id'));
        Storage::disk('local')->put($event->proof_path, $bytes);
        $pay = $this->lossCommand($admin, $plan, 'record-buyer-payment', 'payment_confirmed', ['cash_reference' => 'ACTUAL-ORIGINAL-BUYER-REFUND', 'proof' => $proof()]);
        $privateUrl = '/shipment-finances/'.$plan->id.'/events/'.$pay['id'].'/proof';
        $this->actingAs($order->buyer)->get($privateUrl)->assertOk();
        $this->actingAs($owner)->get($privateUrl)->assertNotFound();
        $this->actingAs($order->shop->user)->get($privateUrl)->assertNotFound();
        $this->lossCommand($order->buyer, $plan, 'report-buyer-nonreceipt', 'action_confirmed', ['source_event_id' => $pay['id']]);
        $this->lossCommand($admin, $plan, 'resolve-buyer-nonreceipt', 'payment_confirmed', ['source_event_id' => $pay['id'], 'proof' => $proof()]);
        $this->lossCommand($order->buyer, $plan, 'confirm-buyer-receipt', 'action_confirmed', ['source_event_id' => $pay['id']]);
        $this->lossCommand($admin, $plan, 'resolve-cash', 'financial_resolution_confirmed');
        $this->assertSame(1, $plan->events()->where('event_type', 'loss-record-buyer-payment')->count());
        $this->assertSame($before, [$order->fresh()->getAttributes(), $case->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()]);
    }

    public function test_public_loss_disposition_is_independent_private_and_cannot_fake_receipt_or_release_its_authority(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('review.png'));
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), $source->manifest->getAttributes()];
        $this->lossCommand($admin, $plan, 'record-disposition', 'disposition_confirmed', ['proof' => UploadedFile::fake()->image('independent-operational-loss.png')]);
        $record = ShipmentLossDisposition::sole();
        $controls = $this->available($admin, $plan);
        $this->assertSame($record->reference, $controls['disposition']['reference']);
        $this->assertNotContains('void-unfunded', array_column($controls['actions'], 'id'));
        $this->actingAs($admin)->get($controls['disposition']['proof_url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($order->buyer)->get($controls['disposition']['proof_url'])->assertForbidden();
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $source->manifest->fresh()->getAttributes()]);
        $this->assertSame($order->id, $plan->fresh()->active_order_id);
    }

    public function test_public_loss_commands_reject_injected_authority_and_do_not_cache_private_failed_input(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('review.png'));
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $input = $this->lossInput($plan, 'assess-zero-buyer', 'zero_refund_confirmed');
        $this->actingAs($admin)->postJson($url, $input + ['amount_cents' => 999])->assertUnprocessable()->assertJsonValidationErrors('amount_cents');
        $this->from('/shipment-finances/'.$plan->id)->post($url, [...$input, 'reason' => 'x'])
            ->assertRedirect('/shipment-finances/'.$plan->id)->assertSessionHasErrors('reason');
        $this->assertSame([], session()->getOldInput());
        $this->actingAs($order->buyer)->postJson($url, $input)->assertForbidden();
        $this->actingAs($this->createApprovedUser('admin'))->postJson($url, [...$input, 'request_token' => (string) Str::uuid(), 'expected_version' => 999])->assertStatus(409);
        $this->assertSame(1, $plan->events()->count());
        $this->assertDatabaseCount('wallet_refund_credit_requests', 0);
    }

    private function actuallyFindOriginal($source, $order): void
    {
        $this->quarantineMissing(ShipmentFinancialPlan::where('loss_observation_id', $source->id)->sole());
    }

    public function test_public_stale_loss_credit_request_can_be_declined_and_voided_without_creating_a_grant(): void
    {
        [$source, $admin, $order] = $this->missing();
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $url = '/shipment-finances/'.$plan->id;
        $funding = $this->actingAs($owner)->postJson($url.'/wallet-funding-credits', ['expected_version' => 1,
            'request_token' => (string) Str::uuid(), 'amount' => '188.33', 'reason' => 'Request the original liable company’s remaining virtual funding shortfall.'])->assertOk()->json('request');
        $this->actuallyFindOriginal($source, $order);
        $controls = $this->available($admin, $plan);
        $this->assertNotNull($controls['hold_message']);
        $this->assertTrue($controls['credits']['requests'][0]['can_review']);
        $this->assertFalse($controls['credits']['requests'][0]['can_approve']);
        $review = ['version' => 1, 'decision' => 'approved', 'request_token' => (string) Str::uuid(),
            'demo_credit_confirmed' => true, 'reason' => 'The original source changed and must not create new virtual backing.'];
        $this->actingAs($admin)->postJson($url.'/wallet-funding-credits/'.$funding['id'].'/review', $review)->assertStatus(409);
        $rejection = ['version' => 1, 'decision' => 'rejected', 'request_token' => (string) Str::uuid(),
            'reason' => 'The actual found parcel requires reconciliation rather than approval of this original funding request.'];
        $this->postJson($url.'/wallet-funding-credits/'.$funding['id'].'/review', $rejection)->assertOk()->assertJsonPath('request.status', 'rejected');
        $this->lossCommand($admin, $plan, 'void-unfunded', 'unfunded_void_confirmed');
        $this->postJson($url.'/wallet-funding-credits/'.$funding['id'].'/review', $rejection)->assertOk()->assertJsonPath('request.status', 'rejected');
        $this->assertSame(0, WalletTransaction::where('kind', 'demo_loss_funding_credit')->count());
        $this->assertNull($plan->fresh()->active_order_id);
    }

    public function test_public_found_source_retains_only_the_named_actual_receipt_and_blocks_new_compensation(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $receiver = $this->createApprovedUser('admin');
        $offer = $this->lossCommand($owner, $plan, 'offer-company', 'payment_confirmed', ['amount' => '188.33', 'recipient_id' => $receiver->id,
            'cash_reference' => 'ACTUAL-OFFER-BEFORE-FOUND', 'proof' => UploadedFile::fake()->image('original-offer.png')]);
        $this->actuallyFindOriginal($source, $order);
        $receiverControls = $this->available($receiver, $plan);
        $this->assertNotNull($receiverControls['hold_message']);
        $this->assertSame(['receive-company'], array_column($receiverControls['actions'], 'id'));
        $this->lossCommand($receiver, $plan, 'receive-company', 'payment_confirmed', ['source_event_id' => $offer['id'], 'proof' => UploadedFile::fake()->image('actual-found-source-receipt.png')]);
        $this->assertSame([], $this->available($admin, $plan)['actions']);
        $this->assertSame([], $this->available($owner, $plan)['actions']);
        $this->assertFalse($plan->events()->where('event_type', 'loss-record-seller-protection')->exists());
    }

    public function test_public_uncertain_financial_write_rolls_back_private_files_and_retries_the_exact_original_request(): void
    {
        [$source, $admin] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $before = Storage::disk('local')->allFiles('shipment-finance');
        $fail = true;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, static function (ShipmentFinancialEvent $event) use (&$fail): void {
            if ($fail && $event->event_type === 'loss-offer-company') {
                throw new \RuntimeException('Isolated uncertain original funding-event persistence.');
            }
        });
        $input = $this->lossInput($plan, 'offer-company', 'payment_confirmed', ['amount' => '188.33', 'recipient_id' => $admin->id,
            'cash_reference' => 'ACTUAL-ORIGINAL-UNCERTAIN', 'proof' => UploadedFile::fake()->image('actual-private-source.png')]);
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $this->actingAs($owner)->postJson($url, $input)->assertStatus(503)->assertJsonPath('message',
            'This financial update could not be confirmed. Reload its saved history before retrying.');
        $this->assertSame($before, Storage::disk('local')->allFiles('shipment-finance'));
        $this->assertSame(1, $plan->events()->count());
        $fail = false;
        $event = $this->postJson($url, $input)->assertOk()->json('event');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('event.id', $event['id']);
        $this->assertSame(2, $plan->events()->count());
        $this->assertCount(count($before) + 1, Storage::disk('local')->allFiles('shipment-finance'));
        $this->assertSame(0, app(ShipmentLossCash::class)->state($plan)['company_received_cents']);
    }
}
