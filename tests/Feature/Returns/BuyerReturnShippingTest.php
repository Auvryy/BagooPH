<?php

namespace Tests\Feature\Returns;

use App\Models\Order;
use App\Models\Voucher;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnService;
use Brick\Math\BigInteger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class BuyerReturnShippingTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function discounted(bool $zero = false, bool $shipping = false): Order
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['price' => $zero ? '33.33' : '333.35']);
        $voucher = Voucher::factory()->funded()->create(['code' => 'RETURN_ALLOCATED', 'discount_type' => $shipping ? 'free_shipping' : 'fixed',
            'min_spend' => 0, 'max_discount' => $shipping ? 20 : null, 'discount_value' => $shipping ? 0 : ($zero ? '99.99' : '100.01')]);

        return $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product, 'quantity' => 3]], 'ready_for_pickup',
            ['platform_voucher_revision_id' => $voucher->current_revision_id]);
    }

    public function test_original_net_unit_weights_retain_every_shipping_cent_across_separate_partial_claims(): void
    {
        $order = $this->discounted();
        $source = app(BuyerReturnAmounts::class)->shipping($order);
        $weights = array_column($source['units'], 'buyer_cents');
        $this->assertSame('net_merchandise', $source['allocation_basis']);
        $this->assertNotSame($weights[0], $weights[2]);
        $prefix = 0;
        $allocated = 0;
        foreach ($source['units'] as $index => $unit) {
            $prefix += $weights[$index];
            $expected = BigInteger::of($source['buyer_paid_shipping_cents'])->multipliedBy($prefix)->quotient(array_sum($weights))->toInt();
            $this->assertSame($expected - $allocated, $unit['buyer_paid_shipping_cents']);
            $allocated = $expected;
        }
        $this->assertSame($source['buyer_paid_shipping_cents'], $allocated);
        $this->flowDelivery($order, 'delivered');
        $service = app(BuyerReturnService::class);
        $totals = [];
        foreach ([0, 1, 2] as $position) {
            $order = $order->fresh(['buyer', 'items']);
            $case = $service->request($order->buyer, $order, ['request_token' => $service->form($order->buyer, $order)['requestToken'],
                'reason' => 'damaged', 'description' => 'The named purchased unit has an evidenced broken fastening.',
                'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('unit-'.$position.'.png')]);
            $shipping = app(BuyerReturnAmounts::class)->shippingForCase($case->fresh(['order', 'items']));
            $this->assertSame($position, $shipping['unit_snapshot'][0]['unit_position']);
            $this->assertSame($source['units'][$position]['buyer_paid_shipping_cents'], $shipping['buyer_paid_shipping_cents']);
            $totals[] = $shipping['buyer_paid_shipping_cents'];
        }
        $this->assertSame($source['buyer_paid_shipping_cents'], array_sum($totals));
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
        $this->assertSame(1, $order->shipping_quote['refund_policy_version']);
    }

    public function test_shipping_voucher_subsidy_is_excluded_and_reading_allocations_preserves_original_commitments(): void
    {
        $order = $this->discounted(shipping: true);
        $before = [$order->getAttributes(), DB::table('voucher_funding_entries')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('voucher_redemptions')->get()->map(fn ($row) => (array) $row)->all()];
        $source = app(BuyerReturnAmounts::class)->shipping($order);
        $this->assertSame(2000, $source['shipping_discount_cents']);
        $this->assertSame($source['shipping_cents'] - 2000, $source['buyer_paid_shipping_cents']);
        $this->assertSame($source['buyer_paid_shipping_cents'], array_sum(array_column($source['units'], 'buyer_paid_shipping_cents')));
        $this->assertSame($source, app(BuyerReturnAmounts::class)->shipping($order->fresh()));
        $this->assertSame($before, [$order->fresh()->getAttributes(), DB::table('voucher_funding_entries')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('voucher_redemptions')->get()->map(fn ($row) => (array) $row)->all()]);
    }

    public function test_zero_net_merchandise_uses_original_gross_weights_without_refunding_campaign_funding(): void
    {
        $order = $this->discounted(zero: true);
        $source = app(BuyerReturnAmounts::class)->shipping($order);
        $this->assertSame('gross_merchandise', $source['allocation_basis']);
        $this->assertSame(0, array_sum(array_column($source['units'], 'buyer_cents')));
        $this->assertSame($source['buyer_paid_shipping_cents'], array_sum(array_column($source['units'], 'buyer_paid_shipping_cents')));
        $this->assertGreaterThan(0, $source['buyer_paid_shipping_cents']);
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
    }

    public function test_unknown_purchased_policy_and_unbalanced_original_total_require_review(): void
    {
        $order = $this->discounted();
        $quote = $order->shipping_quote;
        $order->update(['shipping_quote' => [...$quote, 'refund_policy_version' => 99]]);
        try {
            app(BuyerReturnAmounts::class)->shipping($order->fresh());
            $this->fail('Unknown purchased policy must not be interpreted as supported terms.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $order->update(['shipping_quote' => $quote, 'total_amount' => '1.00']);
        try {
            app(BuyerReturnAmounts::class)->shipping($order->fresh());
            $this->fail('A changed original total cannot generate shipping entitlements.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }
}
