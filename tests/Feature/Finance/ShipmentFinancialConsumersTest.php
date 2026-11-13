<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ExceptionOversightService;
use App\Services\Finance\ShipmentFinancialLinks;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Finance\ShipmentLossWallet;
use App\Services\Returns\BuyerReturnService;
use App\Services\Returns\ReturnFaultReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentFinancialConsumersTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

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

    private function reviewed(string $mode = 'wallet', bool $return = false): array
    {
        [$source, $admin, $order, $case] = $this->missing($mode, $return);
        $this->assertSame('/shipment-finances/missing/'.$source->id.'/review',
            app(ExceptionOversightService::class)->detail($admin, 'manifest', $source->id)['financialReviewUrl']);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'), UploadedFile::fake()->image('actual-original-loss-review.png'));

        return [$plan, $admin, $order, $case];
    }

    public static function sources(): array
    {
        return [['wallet', false], ['wallet', true], ['cod', false], ['cod', true]];
    }

    #[DataProvider('sources')]
    public function test_original_buyer_order_and_wallet_link_to_their_approved_loss_without_private_review_data(string $mode, bool $return): void
    {
        [$plan, , $order, $case] = $this->reviewed($mode, $return);
        $page = $this->actingAs($order->buyer)->get('/buyer/orders/'.$order->id, $this->inertia())->assertOk();
        $page->assertJsonPath('props.shipmentFinance.id', $plan->id)
            ->assertJsonPath('props.shipmentFinances.records.0.href', '/shipment-finances/'.$plan->id)
            ->assertJsonPath('props.shipmentFinances.records.0.return_reference', $case?->reference);
        $this->assertArrayNotHasKey('proof_path', $page->json('props.shipmentFinances.records.0'));
        $this->assertArrayNotHasKey('reason', $page->json('props.shipmentFinances.records.0'));
        if ($mode === 'wallet') {
            $this->get('/wallet/payments/'.$order->walletPayment->id, $this->inertia())->assertOk()
                ->assertJsonPath('props.payment.shipment_financial_url', '/shipment-finances/'.$plan->id)
                ->assertJsonPath('props.payment.shipment_finances.records.0.id', $plan->id);
        }
        $this->assertSame(1, ShipmentFinancialPlan::count());
        $oversight = app(ExceptionOversightService::class);
        $admin = User::findOrFail($plan->actor_id);
        $this->assertSame('/shipment-finances/'.$plan->id, $oversight->detail($admin, 'manifest', $plan->loss_observation_id)['financialReviewUrl']);
        $company = LogisticsCompany::findOrFail($plan->logistics_company_id);
        $this->assertNull($oversight->detail($company->user, 'manifest', $plan->loss_observation_id)['financialReviewUrl']);
    }

    public function test_seller_proceeds_distinguish_approved_and_actually_credited_loss_protection_and_link_the_original_authority(): void
    {
        [$plan, $admin, $order] = $this->reviewed();
        $seller = $order->shop->user;
        $this->actingAs($seller)->get('/wallet/proceeds', $this->inertia())->assertOk()
            ->assertJsonPath('props.records.data.0.shipment_financial_url', '/shipment-finances/'.$plan->id)
            ->assertJsonPath('props.records.data.0.shipment_finances.records.0.id', $plan->id)
            ->assertJsonPath('props.records.data.0.seller_protection_cents', 18000)
            ->assertJsonPath('props.records.data.0.seller_protection_paid_cents', 0)
            ->assertJsonPath('props.records.data.0.can_settle', false);
        $wallet = app(ShipmentLossWallet::class);
        foreach (['reserve-original' => 'original_reserve_confirmed', 'approve-advance' => 'demo_advance_confirmed', 'resolve-wallet' => 'loss_settlement_confirmed'] as $action => $confirmation) {
            $wallet->command($admin, $plan, $action, ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
                'reason' => 'Retain the actual original payment and explicitly backed advance before the independent loss settlement.', $confirmation => true,
                ...$action === 'approve-advance' ? ['amount' => '188.33'] : []]);
        }
        $this->get('/wallet/proceeds', $this->inertia())->assertOk()
            ->assertJsonPath('props.records.data.0.seller_protection_paid_cents', 18000)
            ->assertJsonPath('props.records.data.0.refunded_cents', 25000)
            ->assertJsonPath('props.records.data.0.can_settle', false)
            ->assertJsonPath('props.records.data.0.can_refund_delivery', false);
        $this->actingAs($this->createApprovedUser('seller'))->get('/wallet/proceeds', $this->inertia())->assertOk()->assertJsonCount(0, 'props.records.data');
        $this->actingAs($this->createApprovedUser('buyer'))->get('/buyer/orders/'.$order->id)->assertForbidden();
        $this->get('/wallet/payments/'.$order->walletPayment->id)->assertForbidden();
    }

    public function test_original_cash_seller_proceeds_link_to_the_same_independent_loss_without_granting_foreign_seller_access(): void
    {
        [$plan, $admin, $order] = $this->reviewed('cod');
        foreach ([$order->shop->user, $admin] as $actor) {
            $this->actingAs($actor)->get('/seller-settlements/'.$order->id, $this->inertia())->assertOk()
                ->assertJsonPath('props.record.shipment_finances.records.0.href', '/shipment-finances/'.$plan->id)
                ->assertJsonPath('props.record.status', 'pending');
        }
        $this->actingAs($this->createApprovedUser('seller'))->get('/seller-settlements/'.$order->id)->assertNotFound();
        $this->assertDatabaseCount('seller_settlement_events', 0);
        $this->assertDatabaseCount('shipment_financial_plans', 1);
    }

    public function test_each_partial_return_links_its_own_loss_and_an_approved_authority_freezes_the_original_fault(): void
    {
        [$plan, $admin, $order, $case] = $this->reviewed('wallet', true);
        foreach ([$order->buyer, $order->shop->user, $admin] as $actor) {
            $this->actingAs($actor)->get('/buyer-returns/'.$case->id, $this->inertia())->assertOk()
                ->assertJsonPath('props.returnCase.money.shipment_finance.id', $plan->id)
                ->assertJsonPath('props.returnCase.money.actions', [])
                ->assertJsonPath('props.returnCase.money.plan', null);
        }
        $owner = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $page = $this->actingAs($owner)->get('/return-liabilities/'.$case->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.financialCase.money.shipment_finance.id', $plan->id)
            ->assertJsonPath('props.financialCase.canViewReturn', false);
        $this->assertArrayNotHasKey('proof_url', $page->json('props.financialCase.money.shipment_finance'));
        $wrong = $this->flowHandler($case->parcel->currentHub ?? LogisticsHub::findOrFail($case->parcel->hub_ids[0]));
        $this->actingAs($wrong)->get('/return-liabilities/'.$case->id)->assertNotFound();
        $before = [$case->fresh()->getAttributes(), $case->events()->count(), WalletTransaction::count()];
        $this->actingAs($admin)->postJson('/buyer-returns/'.$case->id.'/actions', ['action' => 'review-fault',
            'expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(), 'fault' => 'courier',
            'fault_evidence' => 'An already approved independent loss authority cannot have its original unit finding replaced.',
            'reason' => 'A second ordinary fault decision must not replace the already approved original loss basis.'])->assertForbidden();
        try {
            app(ReturnFaultReviewService::class)->review($admin, $case, ['fault' => 'seller']);
            $this->fail('The retained financial authority must also guard the direct domain writer.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($before, [$case->fresh()->getAttributes(), $case->events()->count(), WalletTransaction::count()]);
        app(ShipmentLossSource::class)->assertApproved($plan);
        $owner->update(['status' => 'suspended']);
        $this->actingAs($owner)->get('/return-liabilities/'.$case->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.financialCase.money.shipment_finance.id', $plan->id);
    }

    private function anotherReturnLoss(Order $order, User $admin): ShipmentFinancialPlan
    {
        $order = $order->fresh(['buyer', 'items']);
        $returns = app(BuyerReturnService::class);
        $case = $returns->request($order->buyer, $order, ['request_token' => $returns->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'A different actually delivered original unit has a broken fastening and needs its own review.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('distinct-original-unit.png')]);
        $returns->command($order->shop->user, $case, 'approve', ['expected_version' => $case->version, 'request_token' => (string) Str::uuid(),
            'fault' => 'seller', 'reason' => 'The owning seller approves this separate evidenced original unit for an actual return.']);
        $case = $case->fresh(['parcel', 'order']);
        $this->intake($case);
        $this->measureReturn($case);
        $manifest = $this->prepareLeg($case);
        $source = $this->flowHandler($manifest->sourceHub);
        foreach (['load', 'seal', 'dispatch'] as $action) {
            $this->actingAs($source)->postJson('/hub/manifests/'.$manifest->id.'/'.$action, $this->flowManifestPayload($manifest,
                $action === 'load' ? ['barcode' => $case->parcel->barcode] : []))->assertOk();
        }
        $member = $manifest->buyerReturnParcels()->sole();
        $this->actingAs($this->flowHandler($manifest->destinationHub))->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'This separately claimed original return is missing on its actual departed reverse transport.']))->assertOk();
        $observation = $manifest->events()->where('event_type', 'report-discrepancy')->sole();

        return app(ShipmentLossReview::class)->approve($admin, $observation, $this->approval($observation, $admin, '0.00'), UploadedFile::fake()->image('independent-next-unit-investigation.png'));
    }

    public function test_bounded_previews_keep_all_six_actual_partial_claim_reviews_accessible_through_exact_order_history(): void
    {
        [$source, $admin, $order] = $this->missing('wallet', true, quantity: 6);
        $review = app(ShipmentLossReview::class);
        $plans = [$review->approve($admin, $source, $this->approval($source, $admin, '0.00'), UploadedFile::fake()->image('first-original-claim.png'))];
        for ($unit = 2; $unit <= 6; $unit++) {
            $plans[] = $this->anotherReturnLoss($order, $admin);
        }
        [$foreign] = $this->reviewed();
        $links = app(ShipmentFinancialLinks::class)->forOrder($order->buyer, $order);
        $this->assertCount(ShipmentFinancialLinks::PREVIEW_LIMIT, $links['records']);
        $this->assertSame(6, $links['histories'][0]['count']);
        $this->assertCount(5, array_unique(array_column($links['records'], 'return_reference')));
        $history = $this->actingAs($order->buyer)->get($links['histories'][0]['href'], $this->inertia())->assertOk()
            ->assertJsonPath('props.filters.order_id', $order->id)->assertJsonPath('props.cases.total', 6);
        $this->assertSame(array_reverse(array_map(fn ($plan) => $plan->id, $plans)), array_column($history->json('props.cases.data'), 'id'));
        $this->get('/return-liabilities?view=losses&order_id='.$foreign->order_id, $this->inertia())->assertOk()->assertJsonCount(0, 'props.cases.data');
        $this->actingAs($admin)->get($links['histories'][0]['href'], $this->inertia())->assertOk()
            ->assertJsonPath('props.cases.total', 6)->assertJsonPath('props.reviewCandidates', null);
        $this->getJson('/return-liabilities?view=losses&order_id=1e2')->assertUnprocessable();
        foreach ($plans as $plan) {
            app(ShipmentLossSource::class)->assertApproved($plan);
        }
        foreach (array_slice($plans, 1) as $plan) {
            $review->voidUnfunded($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
                'reason' => 'Independently withdraw this unfunded review without erasing its original retained evidence or any physical claim.', 'unfunded_void_confirmed' => true]);
        }
        $current = app(ShipmentFinancialLinks::class)->forOrder($order->buyer, $order);
        $this->assertSame($plans[0]->id, $current['primary']['id']);
        $this->assertSame($plans[0]->id, $current['records'][0]['id']);
        $this->assertSame(6, $current['histories'][0]['count']);
        $owner = LogisticsCompany::findOrFail($plans[1]->logistics_company_id)->user;
        $this->actingAs($owner)->get('/return-liabilities/'.$plans[1]->buyer_return_case_id)->assertNotFound();
    }
}
