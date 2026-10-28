<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\CodAccount;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AccountClosureService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\FinancialOversightService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnMoneyService;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class BuyerReturnMoneyTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private string $reviewToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function reconcile(Order $order, User $admin): void
    {
        $cash = CodAccount::where('order_id', $order->id)->sole();
        $handler = $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id));
        $service = app(CodCashService::class);
        $command = fn (User $actor, string $action, array $data) => $service->command($actor, $cash, $action, $data + [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(), 'receipt_confirmed' => true,
            'evidence_reference' => 'RETURN-ORIGINAL-COD-'.$cash->id, 'reason' => 'The named recipient checked actual cash against the retained original collection.']);
        foreach (['rider' => $handler, 'hub' => $admin] as $stage => $recipient) {
            foreach ($cash->fresh()->state['balances'] as $key => $cents) {
                if ($cents > 0 && str_starts_with($key, $stage.':')) {
                    $holder = User::findOrFail(explode(':', $key)[1]);
                    $offer = $command($holder, 'offer', ['holder_id' => $holder->id, 'recipient_id' => $recipient->id, 'amount' => SellerSettlementService::pesos($cents)]);
                    $command($recipient, 'receive', ['offer_reference' => $offer->reference, 'received_amount' => SellerSettlementService::pesos($cents)]);
                }
            }
        }
        $command($admin, 'reconcile', []);
    }

    private function settlement(User $admin, Order $order, string $action): void
    {
        $record = $order->fresh()->sellerSettlement;
        app(SellerSettlementService::class)->command($admin, $order->id, $action, [
            'expected_version' => $record ? $record->events()->max('sequence') : 0, 'request_token' => (string) Str::uuid(),
            'reason' => 'Review the actual remaining proceeds and retained original transfer evidence.',
            ...($action === 'record-payment' ? ['payment_reference' => 'SELLER-ACTUAL-'.$order->id, 'payment_confirmed' => true] : []),
        ], $action === 'record-payment' ? UploadedFile::fake()->image('seller-payment.png') : null);
    }

    private function received(?Order $original = null, int $quantity = 1, bool $paid = false, bool $authorized = false, bool $disputed = false, bool $reconciled = true): array
    {
        $order = $original ?? $this->newFlowOrder('ready_for_pickup');
        $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $admin = $this->createApprovedUser('admin');
        if ($reconciled) {
            $this->reconcile($order, $admin);
        }
        if ($paid || $authorized) {
            $this->settlement($admin, $order, 'authorize');
        }
        if ($paid) {
            $this->settlement($admin, $order, 'record-payment');
        }
        $order = $order->fresh(['buyer', 'items']);
        $service = app(BuyerReturnService::class);
        $case = $service->request($order->buyer, $order, ['request_token' => $service->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The delivered fastening is broken and cannot be used.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => $quantity]]], [UploadedFile::fake()->image('damage.png')]);
        $this->reviewToken = (string) Str::uuid();
        $service->command($order->shop->user, $case, 'approve', ['request_token' => $this->reviewToken, 'expected_version' => 1,
            'reason' => 'The original evidence supports an actual item return.']);
        $case = $case->fresh(['parcel', 'order']);
        $this->finishCustody($case, $disputed);

        return [$case->fresh(), $admin];
    }

    private function finishCustody(BuyerReturnCase $case, bool $disputed = false): void
    {
        $this->intake($case);
        foreach (range(0, count($case->parcel->hub_ids) - 2) as $index) {
            $this->leg($case);
        }
        $origin = LogisticsHub::findOrFail($case->parcel->hub_ids[count($case->parcel->hub_ids) - 1]);
        $this->actingAs($this->flowHandler($origin))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $origin->id]))->assertSessionHasNoErrors();
        $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
            'condition' => $disputed ? 'disputed' : 'accepted', 'photo' => UploadedFile::fake()->image('seller-receipt.png'),
        ]))->assertSessionHasNoErrors();

    }

    private function payload(BuyerReturnCase $case, string $action, array $extra = []): array
    {
        $proof = in_array($action, ['offer-recovery', 'receive-recovery', 'offer-funding', 'receive-funding', 'record-repayment', 'resolve-nonreceipt'], true);

        return ['action' => $action, 'expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The original owner checked the actual transfer and its retained receipt.',
            ...($proof ? ['proof' => UploadedFile::fake()->image('actual-receipt.png'), 'payment_confirmed' => true] : []), ...$extra];
    }

    private function money(User $actor, BuyerReturnCase $case, string $action, array $extra = []): BuyerReturnMoneyEvent
    {
        $response = $this->actingAs($actor)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, $action, $extra), ['Accept' => 'application/json']);
        $this->assertSame(302, $response->status(), $response->getContent());
        $response->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();

        return BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->latest('sequence')->firstOrFail();
    }

    public function test_a_complete_return_repays_original_buyer_once_and_closes_zero_proceeds_without_a_cash_transfer(): void
    {
        [$case, $admin] = $this->received(quantity: 2);
        $order = $case->order->fresh();
        $original = [$order->getAttributes(), $order->delivery->getAttributes(), $order->codAccount->getAttributes(), $order->delivery->checkpoints()->get()->map->getAttributes()->all()];
        $this->money($admin, $case, 'approve-repayment');
        $adjustment = BuyerReturnAdjustment::sole();
        $this->assertSame(90000, $adjustment->buyer_cents);
        $this->assertSame(81000, $adjustment->seller_cents);
        $this->assertSame(9000, $adjustment->platform_cents);
        $this->assertSame(0, $adjustment->recovery_due_cents);
        $payload = $this->payload($case, 'record-repayment', ['cash_reference' => 'buyer-return-transfer-001']);
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $payment = BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->sole();
        $this->assertSame($case->buyer_id, $payment->recipient_id);
        $this->assertSame('payment_recorded', $case->fresh()->status);
        $this->assertSame(1, BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->count());
        $this->money($order->buyer, $case, 'confirm-receipt', ['source_event_id' => $payment->id]);
        $this->assertSame('repaid', $case->fresh()->status);
        $this->settlement($admin, $order, 'authorize');
        $this->settlement($admin, $order, 'close-proceeds');
        $this->assertNull(app(SellerSettlementService::class)->verifiedPayment($order->fresh()->sellerSettlement));
        $this->assertNotNull(app(SellerSettlementService::class)->verifiedClosure($order->fresh()->sellerSettlement));
        $this->assertSame($original, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->codAccount->fresh()->getAttributes(), $order->delivery->checkpoints()->get()->map->getAttributes()->all()]);
        $this->assertSame('settled', $order->fresh()->commissionLedger->status);
    }

    public function test_a_changed_unpaid_authorization_requires_new_consent_and_preserves_the_original_gross_record(): void
    {
        [$case, $admin] = $this->received(authorized: true);
        $order = $case->order;
        $record = $order->sellerSettlement;
        $before = $record->getAttributes();
        $this->money($admin, $case, 'approve-repayment');
        $payment = $this->money($admin, $case, 'record-repayment', ['cash_reference' => 'RETURN-PARTIAL-001']);
        $this->money($order->buyer, $case, 'confirm-receipt', ['source_event_id' => $payment->id]);
        $this->actingAs($admin)->postJson('/seller-settlements/'.$order->id.'/record-payment', [
            'expected_version' => 1, 'request_token' => (string) Str::uuid(), 'reason' => 'Check the original remaining seller share before paying.',
            'payment_reference' => 'STALE-SELLER-001', 'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('stale.png'),
        ])->assertConflict();
        $this->settlement($admin, $order, 'authorize');
        $this->settlement($admin, $order, 'record-payment');
        $actual = app(SellerSettlementService::class)->verifiedPayment($record->fresh());
        $this->assertSame(40500, $actual->amount_cents);
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame(1, $record->events()->where('event_type', 'release_reauthorized')->count());
    }

    public function test_paid_seller_proof_stays_paid_and_an_offer_is_not_recovered_cash(): void
    {
        [$case, $admin] = $this->received(paid: true);
        $order = $case->order;
        $original = $order->sellerSettlement->events()->get()->map->getAttributes()->all();
        $this->money($admin, $case, 'approve-repayment');
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['cash_reference' => 'NOT-BACKED-001']), ['Accept' => 'application/json'])->assertConflict();
        $offer = $this->money($order->shop->user, $case, 'offer-recovery', ['amount' => '405.00', 'cash_reference' => 'SELLER-RECOVERY-001', 'recipient_id' => $admin->id]);
        $this->assertSame(9000, app(BuyerReturnMoneyService::class)->backing($order->fresh())['available_cents']);
        $this->money($admin, $case, 'receive-recovery', ['source_event_id' => $offer->id]);
        $payment = $this->money($admin, $case, 'record-repayment', ['cash_reference' => 'BACKED-RETURN-001']);
        $this->money($order->buyer, $case, 'confirm-receipt', ['source_event_id' => $payment->id]);
        $this->assertSame($original, $order->sellerSettlement->events()->get()->map->getAttributes()->all());
        $this->assertSame(81000, app(SellerSettlementService::class)->verifiedPayment($order->sellerSettlement)->amount_cents);
        $this->assertSame(0, app(BuyerReturnMoneyService::class)->debt($case)['recovery_remaining_cents']);
    }

    public function test_actual_backed_funding_can_repay_buyer_but_never_erases_unreceived_seller_debt(): void
    {
        [$case, $admin] = $this->received(paid: true);
        $this->money($admin, $case, 'approve-repayment');
        $funder = $this->createApprovedUser('admin');
        $offer = $this->money($funder, $case, 'offer-funding', ['amount' => '405.00', 'cash_reference' => 'ACTUAL-PLATFORM-FUNDING', 'recipient_id' => $admin->id]);
        $proofUrl = '/buyer-returns/'.$case->id.'/money/'.$offer->id.'/proof';
        $this->actingAs($case->order->shop->user)->get($proofUrl)->assertNotFound();
        $this->actingAs($case->order->buyer)->get($proofUrl)->assertNotFound();
        $this->actingAs($admin)->get($proofUrl)->assertOk();
        $pendingCodes = array_column(app(AccountClosureService::class)->state($funder)['blockers'], 'code');
        $this->assertContains('buyer_return_cash_receipt', $pendingCodes);
        $this->assertSame(9000, app(BuyerReturnMoneyService::class)->backing($case->order->fresh())['available_cents']);
        $this->actingAs($funder)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'receive-funding', ['source_event_id' => $offer->id]), ['Accept' => 'application/json'])->assertConflict();
        $this->money($admin, $case, 'receive-funding', ['source_event_id' => $offer->id]);
        $payment = $this->money($admin, $case, 'record-repayment', ['cash_reference' => 'FUNDED-BUYER-REPAYMENT']);
        $this->money($case->order->buyer, $case, 'confirm-receipt', ['source_event_id' => $payment->id]);
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame(40500, app(BuyerReturnMoneyService::class)->debt($case)['recovery_remaining_cents']);
        $seller = $case->order->shop->user;
        $codes = array_column(app(AccountClosureService::class)->state($seller)['blockers'], 'code');
        $this->assertContains('buyer_return_recovery', $codes);
        $seller->update(['status' => 'suspended']);
        $partial = $this->money($seller, $case, 'offer-recovery', ['amount' => '100.00', 'cash_reference' => 'RECOVERY-PART-ONE', 'recipient_id' => $admin->id]);
        $this->money($admin, $case, 'receive-recovery', ['source_event_id' => $partial->id]);
        $this->assertSame(30500, app(BuyerReturnMoneyService::class)->debt($case)['recovery_remaining_cents']);
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'receive-recovery', ['source_event_id' => $partial->id]), ['Accept' => 'application/json'])->assertConflict();
        $last = $this->money($seller, $case, 'offer-recovery', ['amount' => '305.00', 'cash_reference' => 'RECOVERY-PART-TWO', 'recipient_id' => $admin->id]);
        $this->money($admin, $case, 'receive-recovery', ['source_event_id' => $last->id]);
        $this->assertSame(0, app(BuyerReturnMoneyService::class)->debt($case)['recovery_remaining_cents']);
        $this->assertSame(1, BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->count());
    }

    public function test_nonreceipt_retains_one_actual_payment_until_the_original_buyer_confirms(): void
    {
        [$case, $admin] = $this->received();
        $this->money($admin, $case, 'approve-repayment');
        $payment = $this->money($admin, $case, 'record-repayment', ['cash_reference' => 'ACTUAL-RETURN-WITH-REVIEW']);
        $before = $payment->getAttributes();
        $this->money($case->order->buyer, $case, 'report-nonreceipt', ['source_event_id' => $payment->id]);
        $this->assertSame('repayment_disputed', $case->fresh()->status);
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['cash_reference' => 'DO-NOT-PAY-AGAIN']), ['Accept' => 'application/json'])->assertForbidden();
        $this->money($admin, $case, 'resolve-nonreceipt', ['source_event_id' => $payment->id]);
        $this->assertSame('payment_recorded', $case->fresh()->status);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->money($case->order->buyer, $case, 'confirm-receipt', ['source_event_id' => $payment->id]);
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame(1, BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->count());
    }

    public function test_exact_discount_remainders_and_signed_platform_contributions_conserve_all_original_units(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $product = $this->createE2EProduct($shop, ['price' => '333.35']);
        $shopOffer = Voucher::factory()->funded()->create(['code' => 'RETURN_SHOP', 'shop_id' => $shop->id, 'min_spend' => 0, 'discount_value' => '100.01']);
        $platformOffer = Voucher::factory()->funded()->create(['code' => 'RETURN_PLATFORM', 'min_spend' => 0, 'discount_value' => '150.02']);
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product, 'quantity' => 3]], 'ready_for_pickup',
            ['shop_voucher_revision_ids' => [$shop->id => $shopOffer->current_revision_id], 'platform_voucher_revision_id' => $platformOffer->current_revision_id]);
        [$case, $admin] = $this->received($order);
        $source = app(BuyerReturnAmounts::class)->original($order->fresh());
        $this->assertSame(100005, array_sum(array_column($source['units'], 'gross_cents')));
        $this->assertSame(10001, array_sum(array_column($source['units'], 'commission_cents')));
        $this->assertSame(10001, array_sum(array_column($source['units'], 'shop_discount_cents')));
        $this->assertSame(15002, array_sum(array_column($source['units'], 'platform_discount_cents')));
        foreach ($source['units'] as $unit) {
            $this->assertSame($unit['buyer_cents'], $unit['seller_cents'] + $unit['platform_cents']);
            $this->assertLessThan(0, $unit['platform_cents']);
        }
        $fundingBefore = DB::table('voucher_funding_entries')->get()->map(fn ($row) => (array) $row)->all();
        $usesBefore = DB::table('voucher_redemptions')->get()->map(fn ($row) => (array) $row)->all();
        $this->money($admin, $case, 'approve-repayment');
        $adjustment = BuyerReturnAdjustment::sole();
        $this->assertSame($source['units'][0]['buyer_cents'], $adjustment->buyer_cents);
        $this->assertSame($source['units'][0]['platform_cents'], $adjustment->platform_cents);
        $this->assertLessThan($adjustment->buyer_cents, app(BuyerReturnMoneyService::class)->backing($order->fresh())['available_cents']);
        $this->assertSame($fundingBefore, DB::table('voucher_funding_entries')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($usesBefore, DB::table('voucher_redemptions')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, $shopOffer->fresh()->used_count);
        $this->assertSame(1, $platformOffer->fresh()->used_count);
    }

    public function test_condition_review_and_unreconciled_cod_cannot_silently_become_a_repayment(): void
    {
        [$case, $admin] = $this->received(disputed: true, reconciled: false);
        $this->assertSame('condition_review', $case->status);
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['cash_reference' => 'UNAPPROVED-001']), ['Accept' => 'application/json'])->assertForbidden();
        $this->money($admin, $case, 'approve-repayment');
        $event = BuyerReturnMoneyEvent::where('event_type', 'approve-repayment')->sole();
        $this->assertTrue($event->context['condition_review_resolved']);
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['cash_reference' => 'UNRECONCILED-001']), ['Accept' => 'application/json'])->assertConflict();
        $this->reconcile($case->order, $admin);
        $this->money($admin, $case, 'record-repayment', ['cash_reference' => 'RECONCILED-RETURN-001']);
    }

    public function test_ownership_role_stale_conflicting_and_forged_amounts_cannot_change_the_recipient_or_payment(): void
    {
        [$case, $admin] = $this->received();
        foreach (['buyer', 'seller'] as $role) {
            $this->actingAs($this->createApprovedUser($role))->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'approve-repayment'), ['Accept' => 'application/json'])->assertNotFound();
        }
        $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'approve-repayment'), ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->createApprovedUser('courier'))->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'approve-repayment'), ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'approve-repayment', ['request_token' => $this->reviewToken]), ['Accept' => 'application/json'])->assertConflict();
        $payload = $this->payload($case, 'approve-repayment');
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/money', [...$payload, 'reason' => 'A changed decision cannot reuse the saved request.'], ['Accept' => 'application/json'])->assertConflict();
        $this->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['expected_version' => 1, 'cash_reference' => 'STALE-001']), ['Accept' => 'application/json'])->assertConflict();
        foreach (['amount' => '0.01', 'buyer_id' => $admin->id, 'recipient_id' => $admin->id, 'amount_cents' => 1] as $field => $value) {
            $this->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['cash_reference' => 'FORGED-001', $field => $value]), ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $admin->update(['status' => 'suspended']);
        $this->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['cash_reference' => 'RESTRICTED-001']), ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(1, BuyerReturnAdjustment::count());
        $this->assertSame(0, BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->count());
    }

    public function test_private_proof_and_failed_notice_write_roll_back_without_orphans_then_retry_once(): void
    {
        [$case, $admin] = $this->received();
        $this->money($admin, $case, 'approve-repayment');
        $payload = $this->payload($case, 'record-repayment', ['cash_reference' => 'ROLLBACK-BUYER-001']);
        foreach ([UploadedFile::fake()->create('video.mp4', 2, 'video/mp4'), UploadedFile::fake()->createWithContent('fake.png', 'not an image'), UploadedFile::fake()->createWithContent('fake.pdf', '%PDF-1.4 no actual end')] as $bad) {
            $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', [...$payload, 'proof' => $bad], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $before = [$case->fresh()->version, $case->events()->count(), count(Storage::disk('local')->allFiles())];
        $original = app(NotificationDeliveryService::class);
        $this->mock(NotificationDeliveryService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Controlled outbox failure'));
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasErrors('payment');
        $this->assertSame($before, [$case->fresh()->version, $case->events()->count(), count(Storage::disk('local')->allFiles())]);
        $this->assertDatabaseMissing('buyer_return_money_events', ['event_type' => 'record-repayment']);
        $this->app->instance(NotificationDeliveryService::class, $original);
        $this->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $event = BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->sole();
        $url = '/buyer-returns/'.$case->id.'/money/'.$event->id.'/proof';
        $this->actingAs($case->order->buyer)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->createApprovedUser('buyer'))->get($url)->assertNotFound();
        Storage::disk('local')->put($event->proof_path, 'changed evidence');
        $this->actingAs($admin)->get($url)->assertNotFound();
        $this->assertSame('payment_recorded', $case->fresh()->status);
    }

    public function test_zero_discounted_entitlement_closes_without_recording_any_outgoing_payment(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['price' => '100.00']);
        $voucher = Voucher::factory()->funded()->create(['code' => 'RETURN_ZERO', 'min_spend' => 0, 'discount_value' => '100.00']);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product, 'quantity' => 1]], 'ready_for_pickup',
            ['platform_voucher_revision_id' => $voucher->current_revision_id]);
        [$case, $admin] = $this->received($order);
        $this->money($admin, $case, 'approve-repayment');
        $this->assertSame(0, BuyerReturnAdjustment::sole()->buyer_cents);
        $this->money($admin, $case, 'close-zero-return');
        $this->assertSame('closed_no_repayment', $case->fresh()->status);
        $this->assertDatabaseMissing('buyer_return_money_events', ['event_type' => 'record-repayment']);
    }

    public function test_two_original_unit_cases_conserve_the_total_and_cannot_reuse_a_cash_reference(): void
    {
        [$first, $admin] = $this->received();
        $this->money($admin, $first, 'approve-repayment');
        $paid = $this->money($admin, $first, 'record-repayment', ['cash_reference' => 'ORIGINAL-UNIT-ONE']);
        $this->money($first->order->buyer, $first, 'confirm-receipt', ['source_event_id' => $paid->id]);
        $order = $first->order->fresh(['buyer', 'items']);
        $service = app(BuyerReturnService::class);
        $second = $service->request($order->buyer, $order, ['request_token' => $service->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The second purchased item has its own broken fastening.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('second-unit.png')]);
        $service->command($order->shop->user, $second, 'approve', ['request_token' => (string) Str::uuid(), 'expected_version' => 1,
            'reason' => 'The second original unit has independent return evidence.']);
        $second = $second->fresh(['parcel', 'order']);
        $this->finishCustody($second);
        $order->items->first()->product->update(['price' => '9999.99']);
        $this->money($admin, $second, 'approve-repayment');
        $this->assertSame([0], $first->items()->first()->unit_positions);
        $this->assertSame([1], $second->items()->first()->unit_positions);
        $this->assertSame(90000, BuyerReturnAdjustment::sum('buyer_cents'));
        $this->assertSame(81000, BuyerReturnAdjustment::sum('seller_cents'));
        $this->actingAs($admin)->post('/buyer-returns/'.$second->id.'/money', $this->payload($second, 'record-repayment', ['cash_reference' => ' original-unit-one ']), ['Accept' => 'application/json'])->assertConflict();
        $last = $this->money($admin, $second, 'record-repayment', ['cash_reference' => 'ORIGINAL-UNIT-TWO']);
        $this->money($order->buyer, $second, 'confirm-receipt', ['source_event_id' => $last->id]);
        $this->assertSame(90000, BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->sum('amount_cents'));
        $this->assertSame(0, app(BuyerReturnMoneyService::class)->backing($order->fresh())['available_cents']);
        $this->assertSame(0, app(BuyerReturnAmounts::class)->sellerBasis($order->fresh(), 81000)['payable_seller_cents']);
    }

    public function test_finance_metrics_keep_original_cash_and_actual_paid_proceeds_separate_from_return_money(): void
    {
        [$case, $admin] = $this->received(paid: true);
        $this->money($admin, $case, 'approve-repayment');
        $seller = $case->order->shop->user;
        $offer = $this->money($seller, $case, 'offer-recovery', ['amount' => '405.00', 'cash_reference' => 'FINANCE-RECOVERY', 'recipient_id' => $admin->id]);
        $this->money($admin, $case, 'receive-recovery', ['source_event_id' => $offer->id]);
        $this->money($admin, $case, 'record-repayment', ['cash_reference' => 'FINANCE-REPAYMENT']);
        $service = app(FinancialOversightService::class);
        $row = $service->row($case->order->fresh(), $admin, true);
        $this->assertSame('96000', $row['metrics']['collected']);
        $this->assertSame('96000', $row['metrics']['reconciled']);
        $this->assertSame('81000', $row['metrics']['settled']);
        $this->assertSame('40500', $row['metrics']['return_deductions']);
        $this->assertSame('45000', $row['metrics']['return_paid']);
        $this->assertSame('40500', $row['metrics']['recovery_received']);
        $this->assertSame('0', $row['metrics']['recovery_due']);
        $buyerView = app(BuyerReturnMoneyService::class)->presentation($case->order->buyer, $case->fresh());
        $this->assertArrayNotHasKey('backing', $buyerView);
        $this->assertArrayNotHasKey('receivers', $buyerView);
        $this->assertNotContains('offer-recovery', array_column($buyerView['history'], 'event_type'));
    }

    public function test_approval_requires_physical_seller_receipt_and_sql_cannot_rewrite_return_money(): void
    {
        $early = $this->approvedReturn();
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->post('/buyer-returns/'.$early->id.'/money', $this->payload($early, 'approve-repayment'), ['Accept' => 'application/json'])->assertForbidden();
        [$case, $admin] = $this->received();
        $this->money($admin, $case, 'approve-repayment');
        foreach (['buyer_return_adjustments', 'buyer_return_money_events'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    $action === 'update' ? DB::table($table)->update(['created_at' => now()->addDay()]) : DB::table($table)->delete();
                    $this->fail('Retained return money must reject '.$action);
                } catch (QueryException $error) {
                    $this->assertStringContainsString('Retain original return money evidence', $error->getMessage());
                }
            }
        }
        $this->assertSame(1, BuyerReturnAdjustment::count());
        $this->assertSame(1, BuyerReturnMoneyEvent::count());
    }

    public function test_original_shipping_offer_stays_separate_and_its_budget_cannot_fake_repayment_backing(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $voucher = Voucher::factory()->funded()->create(['code' => 'RETURN_SHIPPING', 'discount_type' => 'free_shipping',
            'discount_value' => 0, 'min_spend' => 0, 'max_discount' => 50]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $this->createE2EProduct($shop), 'quantity' => 2]], 'ready_for_pickup',
            ['platform_voucher_revision_id' => $voucher->current_revision_id]);
        [$case, $admin] = $this->received($order);
        $before = $order->fresh()->getAttributes();
        $this->money($admin, $case, 'approve-repayment');
        $adjustment = BuyerReturnAdjustment::sole();
        $this->assertSame(45000, $adjustment->buyer_cents);
        $this->assertSame(0, $adjustment->platform_discount_cents);
        $this->assertSame(5000, $adjustment->source_snapshot['shipping_discount_cents']);
        $backing = app(BuyerReturnMoneyService::class)->backing($order->fresh());
        $this->assertSame(44500, $backing['available_cents']);
        $this->assertSame(6000, $backing['protected_shipping_cents']);
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', $this->payload($case, 'record-repayment', ['cash_reference' => 'NOT-FUNDED-SHIPPING']), ['Accept' => 'application/json'])->assertConflict();
        $funder = $this->createApprovedUser('admin');
        $offer = $this->money($funder, $case, 'offer-funding', ['amount' => '5.00', 'cash_reference' => 'ACTUAL-SHIPPING-FUNDING', 'recipient_id' => $admin->id]);
        $this->money($admin, $case, 'receive-funding', ['source_event_id' => $offer->id]);
        $this->money($admin, $case, 'record-repayment', ['cash_reference' => 'BACKED-SHIPPING-RETURN']);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(1, $voucher->fresh()->used_count);
    }
}
