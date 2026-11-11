<?php

namespace Tests\Feature\Buyer;

use App\Models\CheckoutSubmission;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class PurchasedCheckoutTermsTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function selection(string $method): array
    {
        $buyer = $this->createApprovedUser('buyer');
        $products = collect([1, 2])->map(function () {
            $shop = $this->createE2EShop($this->createApprovedUser('seller'));

            return $this->createE2EProduct($shop, ['stock' => 10, 'weight_kg' => '1.000',
                'length_cm' => '10.00', 'width_cm' => '10.00', 'height_cm' => '10.00']);
        });
        $input = $this->flowCheckoutPayload($buyer, $products->map(fn ($product) => ['product' => $product])->all());

        return [$buyer, $products, [...$input, 'payment_method' => $method]];
    }

    public static function lateChanges(): array
    {
        return ['COD tariff' => ['cod', 'tariff'], 'Wallet tariff' => ['wallet', 'tariff'],
            'COD measurements' => ['cod', 'measurements'], 'Wallet measurements' => ['wallet', 'measurements']];
    }

    #[DataProvider('lateChanges')]
    public function test_a_late_source_change_rolls_back_both_shops_and_the_same_confirmation_can_retry(string $method, string $change): void
    {
        [$buyer, $products, $input] = $this->selection($method);
        $mutated = false;
        Order::created(function (Order $order) use (&$mutated, $change, $products) {
            if ($mutated) {
                return;
            }
            $mutated = true;
            if ($change === 'tariff') {
                LogisticsCompany::whereKey($order->shipping_quote['company_id'])->increment('base_shipping_cents', 1000);
            } else {
                $products->first()->fresh()->update(['length_cm' => '100.00']);
            }
        });
        $this->actingAs($buyer)->postJson('/checkout', $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertDatabaseCount('wallet_payments', 0);
        foreach ($products as $product) {
            $this->assertSame(10, $product->fresh()->stock);
            $this->assertEquals('10.00', $product->fresh()->length_cm);
        }
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertDatabaseCount('checkout_submissions', 1);
    }

    public function test_two_purchased_routes_measurements_and_earnings_survive_changed_sources_and_a_lost_response(): void
    {
        [$buyer, $products, $input] = $this->selection('cod');
        $this->actingAs($buyer)->post('/checkout', $input)->assertSessionHas('success');
        $orders = Order::orderBy('id')->get();
        $snapshots = $orders->map(fn ($order) => [$order->getAttributes(), $order->delivery->getAttributes()])->all();
        foreach ($orders as $order) {
            $this->assertSame(6000, array_sum($order->shipping_quote['earnings_split_cents']));
            $this->assertSame($order->delivery->route_hub_ids, array_column($order->shipping_quote['route'], 'id'));
            $this->assertEquals('10.00', $order->shipping_quote['measurements'][0]['length_cm']);
        }
        LogisticsCompany::query()->update(['base_shipping_cents' => 12000, 'shipping_earnings_model' => 'standard', 'tariff_version' => 2]);
        foreach ($products as $product) {
            $product->fresh()->update(['length_cm' => '100.00', 'price' => '999.00']);
        }
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->assertSame($snapshots, Order::orderBy('id')->get()->map(fn ($order) => [$order->getAttributes(), $order->delivery->getAttributes()])->all());
        $this->assertSame(2, Delivery::count());
        $this->assertSame(1, CheckoutSubmission::count());
        foreach ($products as $product) {
            $this->assertSame(9, $product->fresh()->stock);
        }
    }
}
