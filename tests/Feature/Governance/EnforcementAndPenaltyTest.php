<?php

namespace Tests\Feature\Governance;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DispatchEscalation;
use App\Models\EnforcementAppeal;
use App\Models\EnforcementSanction;
use App\Models\FinancialPenalty;
use App\Models\HubPickupPause;
use App\Models\LogisticsHub;
use App\Models\ModerationSignal;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ScaleDiscrepancyLog;
use App\Models\SlaClock;
use App\Models\SlaPause;
use App\Models\User;
use App\Services\Commerce\MarketplaceMessageService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Governance\EnforcementService;
use App\Services\Governance\FinancialPenaltyService;
use App\Services\Governance\ModerationSignalService;
use App\Services\ShopEligibilityService;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletPaymentService;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class EnforcementAndPenaltyTest extends TestCase
{
    use CreatesE2EOrders;
    use InteractsWithCheckoutSubmission;
    use InteractsWithOrderActions;
    use InteractsWithRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function seedWallet(User $user, int $cents, User $admin, string $ref): void
    {
        $ledger = app(WalletLedger::class);
        $ledger->post(
            $ref,
            'demo_credit',
            $cents,
            [$ledger->platform('demo_issuance')->id => -$cents, $ledger->user($user)->id => $cents],
            $admin,
            'Test wallet liquidity seed'
        );
    }

    public function test_scoped_cod_restriction_blocks_cod_checkout_allows_wallet_and_enforces_independent_appeal_review(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $buyer = User::where('email', 'buyer@bagoo.test')->sole();
        $product = Product::whereHas('shop')->firstOrFail();
        $product->update(['stock' => 20, 'price' => 250.00]);
        $company = LogisticsHub::where('code', 'BH-LBN-01')->sole()->company;

        $issuerAdmin = $this->createApprovedUser('admin');
        $reviewerAdmin = $this->createApprovedUser('admin');
        $this->seedWallet($buyer, 200000, $issuerAdmin, 'seed-cod-test');

        $this->actingAs($issuerAdmin)->postJson(route('governance.moderation.sanctions.store'), [
            'subject_type' => 'buyer',
            'subject_id' => $buyer->id,
            'scope' => 'cod_restricted',
            'reason_code' => 'repeated_cod_refusal',
            'reason' => 'Three documented COD refusals across closed dispatches.',
            'evidence_summary' => 'Plans SFP-101, SFP-102, SFP-103 confirmed carrier handover refusal.',
        ])->assertCreated();

        $sanction = EnforcementSanction::sole();
        $this->assertSame('cod_restricted', $sanction->scope);
        $this->assertSame($issuerAdmin->id, $sanction->issued_by_id);

        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $product->price]);

        $this->actingAs($buyer)->get(route('checkout.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('codRestriction.reference', $sanction->reference));

        $baseInput = [
            'checkout_token' => $this->checkoutToken($buyer, $cart),
            'item_ids' => [$line->id],
            'recipient_name' => $buyer->name,
            'recipient_phone' => $buyer->phone,
            'shipping_address' => '100 Test Recipient Road',
            'shipping_province' => 'Laguna',
            'shipping_city' => 'Santa Cruz',
            'shipping_postal_code' => '4009',
            'destination_barangay' => 'Poblacion III',
            'delivery_type' => 'doorstep',
            'logistics_company_id' => $company->id,
        ];

        $this->actingAs($buyer)->post(route('checkout.store'), [
            ...$baseInput,
            'payment_method' => 'cod',
        ])->assertSessionHas('error');
        $this->actingAs($buyer)->postJson(route('checkout.store'), [
            ...$baseInput,
            'checkout_token' => $this->checkoutToken($buyer, $cart),
            'payment_method' => 'cod',
        ])->assertUnprocessable();
        $this->assertSame(0, Order::count());

        $this->actingAs($buyer)->post(route('checkout.store'), [
            ...$baseInput,
            'checkout_token' => $this->checkoutToken($buyer, $cart),
            'payment_method' => 'wallet',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Order::count());

        $this->actingAs($buyer)->postJson(route('account.enforcement.appeal', $sanction), [
            'reason' => 'Previous failed COD delivery was caused by an unrecorded road closure.',
            'evidence_notes' => 'Barangay weather advisory attachment verified.',
        ])->assertCreated();

        $appeal = EnforcementAppeal::sole();
        $this->assertSame('pending', $appeal->status);

        // Issuer admin cannot decide appeals on their own sanction.
        $this->actingAs($issuerAdmin)->postJson(route('governance.moderation.appeals.decide', $appeal), [
            'decision' => 'overturned',
            'review_notes' => 'Attempting self review.',
        ])->assertForbidden();

        // Independent platform admin overturns and lifts COD restriction.
        $this->actingAs($reviewerAdmin)->postJson(route('governance.moderation.appeals.decide', $appeal), [
            'decision' => 'overturned',
            'review_notes' => 'Road closure advisory corroborated; restoring COD eligibility.',
        ])->assertOk();

        $this->assertSame('overturned', $sanction->fresh()->status);
        $this->assertNull(app(EnforcementService::class)->activeCodRestrictionForBuyer($buyer->fresh()));

        $line2 = CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $product->price]);
        $this->actingAs($buyer)->post(route('checkout.store'), [
            ...$baseInput,
            'item_ids' => [$line2->id],
            'checkout_token' => $this->checkoutToken($buyer, $cart->fresh()),
            'payment_method' => 'cod',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Order::count());
    }

    public function test_non_negative_wallet_debt_hold_gates_catalog_and_payouts_until_wallet_settlement(): void
    {
        $order = $this->flowDelivery($this->newFlowOrder(), 'delivered')->order->fresh(['items.product', 'buyer']);
        $shop = $order->items->first()->shop;
        $seller = $shop->user;
        $product = $order->items->first()->product;
        $product->update(['status' => 'active', 'stock' => 10]);

        ProductSale::create([
            'product_id' => $product->id,
            'kind' => 'flash',
            'name' => 'Visible Before Debt Hold',
            'price' => 199.00,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'quantity' => 5,
            'used_quantity' => 0,
        ]);

        $admin = $this->createApprovedUser('admin');
        $walletOrder = $this->checkoutFlowOrder($order->buyer, $shop, [['product' => $product, 'quantity' => 1]], shipping: ['payment_method' => 'wallet']);
        $this->seedWallet($order->buyer, 100000, $admin, 'buyer-wallet-seed');
        app(WalletPaymentService::class)->command($order->buyer, $walletOrder->walletPayment, 'pay', [
            'payment_confirmed' => true,
            'request_token' => (string) Str::uuid(),
        ]);
        $walletDelivery = $this->flowDelivery($walletOrder, 'out_for_delivery');

        $ledger = app(WalletLedger::class);
        $this->assertSame(0, $ledger->balance($seller));

        $this->actingAs($admin)->postJson(route('governance.moderation.penalties.store'), [
            'subject_type' => 'seller',
            'subject_id' => $seller->id,
            'category' => 'policy_breach',
            'amount_cents' => 20000,
            'reason_code' => 'verified_off_platform_bypass',
            'reason' => 'Verified off-platform payment demand corroborated with buyer receipt.',
            'evidence_summary' => 'Verified transfer screenshot and buyer safety check-in confirmed.',
            'order_id' => $order->id,
        ])->assertCreated();

        $penalty = FinancialPenalty::sole();
        $this->actingAs($admin)->postJson(route('governance.moderation.penalties.decide', $penalty), [
            'decision' => 'approve',
            'decision_notes' => 'Approved after corroborating payment bypass evidence.',
        ])->assertOk();

        // Non-negative wallet rule: wallet stays >= 0, moves to unpaid_debt_hold with scoped auto-release holds.
        $this->assertSame('unpaid_debt_hold', $penalty->fresh()->status);
        $this->assertSame(20000, $penalty->fresh()->outstanding_cents);
        $this->assertSame(0, $ledger->balance($seller->fresh()));

        $this->assertFalse(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
        $this->assertSame(0, Product::query()->whereKey($product->id)->availableForSale()->count());

        $this->actingAs($order->buyer)->get(route('marketplace'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flashSaleProducts', fn ($items) => collect($items)->where('id', $product->id)->isEmpty()));

        // Existing order settlement is blocked while unpaid_debt_hold / payout_hold is active.
        $sellerEligibility = app(SellerSettlementService::class)->eligibility($order->fresh());
        $this->assertFalse($sellerEligibility['eligible']);
        $this->assertTrue(collect($sellerEligibility['blockers'])->contains(fn ($msg) => str_contains($msg, 'payout hold')));

        $this->actingAs(User::findOrFail($walletDelivery->assigned_rider_id))
            ->patch(route('courier.updateStatus', $walletDelivery), [
                ...$this->codCollectionInput($walletDelivery->fresh()),
                'status' => 'delivered',
                'proof_image_file' => UploadedFile::fake()->image('wallet-proof.jpg'),
            ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertSessionHas('success');

        $walletView = app(WalletSettlementService::class)->present($walletOrder->fresh());
        $this->assertFalse($walletView['can_settle']);
        $this->assertNull($walletOrder->walletPayment->fresh()->settlement_transaction_id);
        $this->assertTrue(collect($walletView['blockers'])->contains(fn ($msg) => str_contains($msg, 'payout hold')));

        // Existing completed order & returns view remains accessible to the buyer cleanly.
        $this->actingAs($order->buyer)->get(route('buyer.orders.show', $order))->assertOk();

        // Seller funds Bagoo Wallet and settles obligation -> holds auto-release cleanly.
        $this->seedWallet($seller, 50000, $admin, 'seller-topup-debt');
        $this->actingAs($seller)->postJson(route('account.enforcement.settle-penalty', $penalty))
            ->assertOk()
            ->assertJsonPath('penalty.status', 'approved_charged');

        $this->assertSame(30000, $ledger->balance($seller->fresh()));
        $this->assertSame(0, EnforcementSanction::where('status', 'active')->count());
        $this->assertTrue(app(WalletSettlementService::class)->present($walletOrder->fresh())['can_settle']);
        $this->assertTrue(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
        $this->assertSame(1, Product::query()->whereKey($product->id)->availableForSale()->count());
    }

    public function test_geo_fenced_sla_pickup_penalty_rejects_pauses_exemptions_and_last_scan_only(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery->fresh();
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $company = $delivery->company ?? $hub->company;
        $delivery->update(['logistics_company_id' => $company->id]);
        $shop = $order->items->first()->shop;
        $shop->update(['pickup_province' => 'Laguna']);
        $hub->update(['province' => 'Laguna']);

        $clock = SlaClock::create([
            'subject_type' => 'delivery',
            'subject_id' => $delivery->id,
            'clock_kind' => 'pickup_handoff',
            'stage' => 'pickup',
            'status' => 'breached',
            'pause_exempt' => false,
            'order_id' => $order->id,
            'delivery_id' => $delivery->id,
            'logistics_company_id' => $company->id,
            'logistics_hub_id' => $hub->id,
            'started_at' => now()->subHours(20),
            'deadline_at' => now()->subHours(2),
            'breach_at' => now()->subHours(2),
        ]);

        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin);

        // 1. Last scan alone never establishes fault or justifies a penalty.
        $this->postJson(route('governance.moderation.sla-clocks.propose-penalty', $clock), [
            'evidence_basis' => 'last_scan_only',
        ])->assertUnprocessable();

        // 2. Paused or force-majeure exempt clock is protected.
        $clock->update(['status' => 'paused', 'pause_exempt' => true]);
        $this->postJson(route('governance.moderation.sla-clocks.propose-penalty', $clock))->assertUnprocessable();
        $clock->update(['status' => 'breached', 'pause_exempt' => false]);

        // 3. No service coverage / pickup_paused / no_available_rider escalation is protected.
        $esc = DispatchEscalation::create([
            'reference' => 'ESC-NO-COVER-1',
            'delivery_id' => $delivery->id,
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'origin_hub_id' => $hub->id,
            'status' => 'open',
            'severity' => 'advisory',
            'trigger_reason' => 'no_available_rider',
            'coverage_outcome' => 'no_available_rider',
            'penalty_exempt' => true,
            'opened_at' => now()->subHour(),
        ]);
        $this->postJson(route('governance.moderation.sla-clocks.propose-penalty', $clock))->assertUnprocessable();
        $esc->delete();

        // 4. Geo-fenced HubPickupPause is protected.
        $pickupPause = HubPickupPause::create([
            'reference' => 'HPU-TEST-1',
            'logistics_company_id' => $company->id,
            'logistics_hub_id' => $hub->id,
            'barangay' => 'Poblacion I',
            'reason_code' => 'flood',
            'reason' => 'Flooded barangay road.',
            'status' => 'active',
            'paused_by_id' => $admin->id,
            'paused_at' => now()->subHours(5),
        ]);
        $this->postJson(route('governance.moderation.sla-clocks.propose-penalty', $clock))->assertUnprocessable();
        $pickupPause->delete();

        // 5. Geo-fenced province Force Majeure SlaPause is protected.
        $fm = SlaPause::create([
            'reference' => 'FM-LAG-1',
            'scope_type' => 'province',
            'scope_value' => 'Laguna',
            'reason_code' => 'typhoon',
            'title' => 'Signal #2 Laguna',
            'status' => 'active',
            'starts_at' => now()->subHours(5),
            'created_by_id' => $admin->id,
        ]);
        $this->postJson(route('governance.moderation.sla-clocks.propose-penalty', $clock))->assertUnprocessable();
        $fm->delete();

        // 6. Cross-province origin mismatch (no_service_coverage) is protected.
        $shop->update(['pickup_province' => 'Cavite']);
        $this->postJson(route('governance.moderation.sla-clocks.propose-penalty', $clock))->assertUnprocessable();
        $shop->update(['pickup_province' => 'Laguna']);

        // 7. Valid geo-fenced breach proposes P150 pending review without auto-charging yet.
        $this->postJson(route('governance.moderation.sla-clocks.propose-penalty', $clock), [
            'reason' => 'Corroborated dispatch abandonment within active Laguna coverage zone.',
        ])->assertCreated();

        $penalty = FinancialPenalty::sole();
        $this->assertSame(FinancialPenaltyService::DEFAULT_SLA_PENALTY_CENTS, $penalty->amount_cents);
        $this->assertSame('pending_review', $penalty->status);

        $companyOwner = $company->user;
        $this->seedWallet($companyOwner, 30000, $admin, 'company-sla-liquidity');
        $this->postJson(route('governance.moderation.penalties.decide', $penalty), [
            'decision' => 'approve',
            'decision_notes' => 'Geo-fence, active riders, and hub capacity verified.',
        ])->assertOk();

        $this->assertSame('approved_charged', $penalty->fresh()->status);
        $this->assertSame(15000, app(WalletLedger::class)->balance($companyOwner->fresh()));
    }

    public function test_hub_scale_discrepancy_computes_tariff_delta_and_charges_seller_only_on_approval(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery->fresh();
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $company = $delivery->company ?? $hub->company;
        $delivery->update(['logistics_company_id' => $company->id]);
        $company->update(['base_shipping_cents' => 8500, 'included_weight_grams' => 1000, 'additional_kg_cents' => 3500]);
        $order->update([
            'shipping_fee' => 85.00,
            'shipping_quote' => ['billable_weight_grams' => 1000, 'payable_shipping_cents' => 8500],
        ]);

        $admin = $this->createApprovedUser('admin');
        $seller = $order->items->first()->shop->user;
        $this->seedWallet($seller, 25000, $admin, 'seller-scale-liquidity');

        $this->actingAs($admin)->postJson(route('governance.moderation.deliveries.scale-log', $delivery), [
            'measured_weight_grams' => 3000,
            'notes' => 'Calibrated bay bench scale weighed 3.00 kg.',
        ])->assertCreated();

        $log = ScaleDiscrepancyLog::sole();
        $this->assertSame(2000, $log->overage_grams);
        $this->assertSame(15500, $log->recalculated_shipping_cents);
        $this->assertSame(7000, $log->tariff_delta_cents);
        $this->assertSame('flagged_for_penalty', $log->status);
        $this->assertDatabaseHas('moderation_signals', ['signal_type' => 'scale_discrepancy', 'is_punitive' => false]);

        $this->postJson(route('governance.moderation.scale-logs.propose-penalty', $log))->assertCreated();
        $penalty = FinancialPenalty::sole();
        $this->assertSame(7000, $penalty->amount_cents);
        $this->assertSame('pending_review', $penalty->status);

        $this->postJson(route('governance.moderation.penalties.decide', $penalty), [
            'decision' => 'approve',
            'decision_notes' => 'Tariff difference approved with calibrated weigh-in evidence.',
        ])->assertOk();

        $this->assertSame('approved_charged', $penalty->fresh()->status);
        $this->assertSame(18000, app(WalletLedger::class)->balance($seller->fresh()));
    }

    public function test_chat_keyword_signals_and_stalled_order_checkin_never_auto_ban(): void
    {
        $order = $this->newFlowOrder('placed');
        $buyer = $order->buyer;
        $shop = $order->items->first()->shop;
        $seller = $shop->user;
        $admin = $this->createApprovedUser('admin');

        app(MarketplaceMessageService::class)->send($buyer, [
            'receiver_id' => $seller->id,
            'shop_id' => $shop->id,
            'order_id' => $order->id,
            'message' => 'Can I pay via GCash or message you on WhatsApp?',
        ]);

        $signal = ModerationSignal::where('signal_type', 'off_platform_bypass_keyword')->sole();
        $this->assertFalse($signal->is_punitive);
        $this->assertContains('gcash', $signal->matched_terms);
        $this->assertSame(0, EnforcementSanction::count());
        $this->assertSame(0, FinancialPenalty::count());

        // Keyword signal alone cannot justify a punitive restriction or manual penalty.
        $this->actingAs($admin)->postJson(route('governance.moderation.sanctions.store'), [
            'subject_type' => 'seller',
            'subject_id' => $seller->id,
            'scope' => 'payout_hold',
            'reason_code' => 'verified_off_platform_bypass',
            'reason' => 'Checking keyword match',
            'source_signal_id' => $signal->id,
        ])->assertUnprocessable();

        $this->expectException(ValidationException::class);
        app(FinancialPenaltyService::class)->proposeManualPenalty($admin, [
            'subject_type' => 'seller',
            'subject_id' => $seller->id,
            'category' => 'off_platform_bypass',
            'amount_cents' => 15000,
            'reason_code' => 'verified_off_platform_bypass',
            'reason' => 'Manual test on open signal.',
            'evidence_summary' => 'Open keyword signal reference.',
            'source_signal_id' => $signal->id,
        ], app(EnforcementService::class));
    }

    public function test_stalled_order_checkin_prompts_buyer_non_punitively_and_admin_can_resolve(): void
    {
        $order = $this->newFlowOrder('placed');
        $admin = $this->createApprovedUser('admin');
        $signal = app(ModerationSignalService::class)->recordStalledOrderSignal($order);
        $this->assertNotNull($signal);
        $this->assertFalse($signal->is_punitive);

        $this->actingAs($order->buyer)->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('safetyCheckinSignals', 1)
                ->where('safetyCheckinSignals.0.reference', $signal->reference));

        $this->actingAs($order->buyer)->postJson(route('buyer.safety-checkins.submit', $signal), [
            'response' => 'safe_on_platform',
            'notes' => 'Just waiting for seller pack confirmation inside Bagoo.',
        ])->assertOk();

        $this->assertSame('checked_in', $signal->fresh()->status);

        $this->actingAs($admin)->postJson(route('governance.moderation.signals.review', $signal), [
            'status' => 'dismissed_benign',
            'review_notes' => 'Buyer confirmed legitimate on-platform transaction.',
        ])->assertOk();

        $this->assertSame('dismissed_benign', $signal->fresh()->status);
    }
}
