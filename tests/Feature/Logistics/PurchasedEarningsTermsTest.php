<?php

namespace Tests\Feature\Logistics;

use App\Models\CartItem;
use App\Models\LogisticsEarning;
use App\Models\Order;
use App\Services\Logistics\LogisticsRateService;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Returns\BuyerReturnAmounts;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class PurchasedEarningsTermsTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_delivery_uses_purchased_cent_allocations_without_recalculating_current_rates(): void
    {
        $order = $this->newFlowOrder();
        $this->assertSame(['pickup' => 833, 'delivery' => 2083, 'company' => 2084], $order->shipping_quote['earnings_split_cents']);
        $order->delivery->company->update(['shipping_earnings_model' => 'standard', 'base_shipping_cents' => 12000, 'tariff_version' => 2]);
        $this->partialMock(LogisticsRateService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('shippingSplit');
        });
        $this->flowDelivery($order, 'delivered');
        $amounts = LogisticsEarning::where('order_id', $order->id)->where('pool', 'shipping')->pluck('amount_cents', 'role')->all();
        $this->assertSame(5000, array_sum($amounts));
        foreach ($order->shipping_quote['earnings_split_cents'] as $role => $cents) {
            $this->assertSame($cents, $amounts[$role]);
        }
    }

    public function test_collection_quote_retains_zero_payable_shipping_and_zero_shipping_entitlements(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop);
        $item = new CartItem(['quantity' => 1]);
        $item->setRelation('product', $product);
        $quote = app(CheckoutQuoteService::class)->quote(collect([$item]), 'hub_self_pickup');
        $terms = $quote['shops'][0]['shipping_quote'];
        $this->assertSame(5000, $terms['shipping_cents']);
        $this->assertSame(0, $terms['payable_shipping_cents']);
        $this->assertSame(['pickup' => 0, 'delivery' => 0, 'company' => 0], $terms['earnings_split_cents']);
        $this->assertSame(0, $quote['shops'][0]['shipping']);
    }

    public function test_earlier_orders_without_explicit_cent_terms_keep_the_original_named_model(): void
    {
        $order = new Order(['shipping_fee' => '60.00', 'shipping_quote' => ['earnings_model' => 'standard']]);
        $this->assertSame(['pickup' => 0, 'delivery' => 3000, 'company' => 3000], app(LogisticsRateService::class)->purchasedShippingSplit($order));
    }

    public static function invalidTerms(): array
    {
        return [
            'negative' => [['pickup' => -1, 'delivery' => 3001, 'company' => 3000], 1],
            'nonconserving' => [['pickup' => 1000, 'delivery' => 2500, 'company' => 2501], 1],
            'fractional' => [['pickup' => 1000.5, 'delivery' => 2500, 'company' => 2500], 1],
            'missing recipient' => [['pickup' => 1000, 'delivery' => 5000], 1],
            'unknown version' => [['pickup' => 1000, 'delivery' => 2500, 'company' => 2500], 2],
        ];
    }

    #[DataProvider('invalidTerms')]
    public function test_inconsistent_original_terms_cannot_silently_release_earnings(array $split, int $version): void
    {
        $order = new Order(['shipping_fee' => '60.00', 'shipping_quote' => ['earnings_terms_version' => $version,
            'payable_shipping_cents' => 6000, 'earnings_split_cents' => $split]]);
        $this->expectException(DomainException::class);
        app(LogisticsRateService::class)->purchasedShippingSplit($order);
    }

    public function test_splits_conserve_large_integer_amounts_and_reject_unsupported_models(): void
    {
        $rates = app(LogisticsRateService::class);
        $this->assertSame(PHP_INT_MAX, array_sum($rates->shippingSplit(PHP_INT_MAX, 'dedicated_fleet')));
        $this->expectException(DomainException::class);
        $rates->shippingSplit(6000, 'unknown');
    }

    public function test_negative_shipping_cannot_create_negative_entitlements(): void
    {
        $this->expectException(DomainException::class);
        app(LogisticsRateService::class)->shippingSplit(-1, 'standard');
    }

    public function test_an_unsupported_refund_policy_cannot_silently_use_the_old_merchandise_only_calculation(): void
    {
        $order = new Order(['shipping_quote' => ['refund_policy_version' => 2]]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The purchased refund policy needs its supported review and funding workflow.');
        app(BuyerReturnAmounts::class)->original($order);
    }
}
