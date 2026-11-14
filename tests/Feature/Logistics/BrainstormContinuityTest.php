<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\OrderCancellationRequest;
use App\Services\Logistics\ParcelScanEvidenceService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class BrainstormContinuityTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function journeys(): array
    {
        return [['local', 'cod'], ['shared_mother', 'cod'], ['cross_mother', 'cod'], ['local', 'wallet'], ['shared_mother', 'wallet'], ['cross_mother', 'wallet']];
    }

    #[DataProvider('journeys')]
    public function test_purchased_route_workers_money_acceptance_and_actual_receipts_stay_connected(string $route, string $payment): void
    {
        $this->withoutVite();
        $shop = $this->createApprovedUser('seller')->shop;
        $buyer = $this->createApprovedUser('buyer');
        $destination = $route === 'local' ? $shop->city : ($route === 'cross_mother' ? 'Dasmariñas' : 'Santa Cruz');
        $this->flowNetwork($shop, $destination);
        if ($route === 'cross_mother') {
            $child = LogisticsHub::where('city_municipality', $destination)->sole();
            $mother = LogisticsHub::create(['logistics_company_id' => $child->logistics_company_id, 'code' => 'TEST-CAV-MH', 'name' => 'Cavite Mother Hub',
                'tier' => 'regional_mother_hub', 'province' => 'Cavite', 'city_municipality' => $destination, 'address' => '100 Hub Road', 'is_active' => true]);
            $child->update(['province' => 'Cavite', 'parent_hub_id' => $mother->id, 'postal_codes' => ['4114']]);
        }
        $shipping = ['shipping_city' => $destination, 'payment_method' => $payment];
        if ($route === 'cross_mother') {
            $shipping += ['shipping_province' => 'Cavite', 'shipping_postal_code' => '4114', 'destination_barangay' => 'Paliparan I'];
        }
        if ($route === 'local') {
            $shipping += ['shipping_postal_code' => '4030'];
        }
        $product = $this->createE2EProduct($shop, ['weight_kg' => '0.50', 'length_cm' => '10.00', 'width_cm' => '10.00', 'height_cm' => '10.00']);
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product, 'quantity' => 1]], shipping: $shipping);
        $quote = $order->shipping_quote;
        $routeIds = $order->delivery->route_hub_ids;
        $this->assertCount(match ($route) {
            'local' => 1, 'shared_mother' => 3, default => 4
        }, $routeIds);
        $this->assertSame(5000, $quote['base_fee_cents']);
        $this->assertSame(2, $quote['cancellation_policy_version']);
        $this->assertSame(2, $quote['refund_policy_version']);
        $this->actingAs($shop->user)->get('/seller/orders')->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.can_print_waybill', false)->where('orders.data.0.recipient_phone', null));
        if ($payment === 'wallet') {
            $credits = app(WalletCreditService::class);
            $request = $credits->request($buyer, ['amount' => '5000.00', 'reason' => 'Isolated original purchase credits.', 'request_token' => (string) Str::uuid()]);
            $credits->review($this->createApprovedUser('admin'), $request, ['decision' => 'approved', 'reason' => 'Approved isolated continuity credits.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
            app(WalletPaymentService::class)->command($buyer, $order->walletPayment, 'pay', ['request_token' => (string) Str::uuid(), 'payment_confirmed' => true]);
        }
        $this->sellerFlowStage($order, 'confirmed');
        $this->actingAs($buyer)->postJson(route('buyer.orders.cancellation-request', $order), ['reason' => 'Ordered by mistake', 'request_token' => (string) Str::uuid()])->assertCreated();
        $cancel = OrderCancellationRequest::where('order_id', $order->id)->sole();
        $this->actingAs($shop->user)->postJson(route('seller.orders.pack', $order))->assertConflict();
        $this->postJson(route('seller.orders.cancellation-review', [$order, $cancel, 'reject']), ['expected_version' => 1,
            'decision_reason' => 'The buyer reviewed the original selection and the order will continue.', 'request_token' => (string) Str::uuid()])->assertOk();
        $this->postJson(route('seller.orders.pack', $order))->assertOk();
        $this->postJson(route('seller.orders.ready', $order))->assertOk();
        $parcel = $this->flowDelivery($order, 'picked_up');
        $hub = $parcel->originBayanHub;
        $handler = $this->flowHandler($hub);
        $handler->update(['password' => 'Continuity-Test!4832']);
        $token = $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $handler->email, 'password' => 'Continuity-Test!4832', 'device_name' => 'Isolated continuity scanner'])->assertOk()->json('data.token');
        $input = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id];
        $before = $parcel->checkpoints()->count();
        $preview = $this->withToken($token)->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'inspect'])->assertOk()->assertJsonPath('confirmed', false);
        $this->assertSame($before, $parcel->checkpoints()->count());
        $confirm = [...$input, 'mode' => 'confirm', 'action' => $preview->json('prompt.action'), 'expected_status' => $preview->json('prompt.expected_status')];
        $first = $this->postJson('/api/v1/logistics/scan', $confirm)->assertOk()->assertJsonPath('confirmed', true)->json('scan_evidence');
        $this->postJson('/api/v1/logistics/scan', $confirm)->assertOk()->assertJsonPath('scan_evidence', $first);
        $this->withHeader('Authorization', '');
        // Later published tariffs cannot rewrite this purchase or prevent its original custody journey.
        LogisticsCompany::whereKey($parcel->logistics_company_id)->update(['base_shipping_cents' => 9000]);
        $parcel->refresh();
        for ($index = 0; $index < count($routeIds) - 1; $index++) {
            $source = LogisticsHub::findOrFail($routeIds[$index]);
            $destinationHub = LogisticsHub::findOrFail($routeIds[$index + 1]);
            if ($source->isMotherHub()) {
                $this->confirmFlowScan($parcel, $source, 'SORT_TO_LINE_HAUL');
            }
            $this->confirmFlowManifest($parcel, $source, true);
            $this->confirmFlowManifest($parcel, $destinationHub, false);
        }
        $destinationHub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($destinationHub))->postJson(route('hub.sort'), ['delivery_id' => $parcel->id, 'barangay' => $order->destination_barangay])->assertOk();
        $parcel->refresh();
        $this->assertSame($quote, $order->fresh()->shipping_quote);
        $this->assertSame($routeIds, $parcel->route_hub_ids);
        $this->assertSame($payment === 'wallet' ? 'paid' : 'pending', $order->fresh()->payment_status);
        $this->assertSame('rejected', $cancel->fresh()->status);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseMissing('wallet_transactions', ['kind' => 'wallet_cancellation_refund']);
        $evidence = app(ParcelScanEvidenceService::class)->summary($parcel);
        $this->assertSame(count($routeIds), $evidence['route_hub_count']);
        $this->assertSame($route === 'local' ? 1 : 0, $evidence['derived_local_records']);
        $this->assertSame('hub', DeliveryCheckpoint::lastCustody($parcel)['kind']);
    }
}
