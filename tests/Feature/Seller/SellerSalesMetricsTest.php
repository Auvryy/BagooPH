<?php

namespace Tests\Feature\Seller;

use App\Models\CommissionLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerSalesMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_finances_and_products_share_lifecycle_aware_sales_totals(): void
    {
        $seller = User::factory()->seller()->create();
        $buyer = User::factory()->buyer()->create();
        $shop = Shop::factory()->approved()->create([
            'user_id' => $seller->id,
            'is_default' => true,
        ]);
        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'stock' => 25,
            'sales_count' => 9,
        ]);

        $openOrder = $this->createOrderItem($buyer, $shop, $product, 'placed', 2, 200);
        $completedOrder = $this->createOrderItem($buyer, $shop, $product, 'completed', 3, 300);
        $this->createOrderItem($buyer, $shop, $product, 'cancelled', 4, 400);

        $foreignSeller = User::factory()->seller()->create();
        $foreignShop = Shop::factory()->approved()->create(['user_id' => $foreignSeller->id]);
        $foreignProduct = Product::factory()->create(['shop_id' => $foreignShop->id]);
        $this->createOrderItem($buyer, $foreignShop, $foreignProduct, 'completed', 9, 900);

        $this->assertNull($openOrder->completed_at);
        $this->assertNotNull($completedOrder->completed_at);

        $this->actingAs($seller)
            ->get(route('seller.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Dashboard')
                ->where('stats.completedGrossSales', 300)
                ->where('stats.completedUnits', 3)
                ->where('stats.completedOrderCount', 1)
                ->where('stats.averageCompletedOrderValue', 300)
                ->where('stats.estimatedSellerShare', 270)
                ->where('stats.openOrderValue', 200)
                ->where('stats.openUnits', 2)
                ->where('stats.openOrderCount', 1)
                ->where('topProducts.0.id', $product->id)
                ->where('topProducts.0.completed_units', 3)
                ->where('topProducts.0.open_order_units', 2)
            );

        $this->actingAs($seller)
            ->get(route('seller.reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Reports')
                ->where('report.completedGrossSales', 300)
                ->where('report.completedUnits', 3)
                ->where('report.completedOrderCount', 1)
                ->where('report.averageCompletedOrderValue', 300)
                ->where('report.estimatedPlatformCommission', 30)
                ->where('report.estimatedSellerShare', 270)
                ->where('report.settledSellerAmount', 0)
                ->where('report.pendingSettlementAmount', 270)
                ->has('orderItems', 1)
                ->where('orderItems.0.order_id', $completedOrder->id)
            );

        $this->actingAs($seller)
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Products')
                ->has('products.data', 1)
                ->where('products.data.0.completed_units', 3)
                ->where('products.data.0.open_order_units', 2)
            );
    }

    public function test_finances_only_show_a_recorded_payout_after_completion_and_payment_reconciliation(): void
    {
        $seller = User::factory()->seller()->create();
        $buyer = User::factory()->buyer()->create();
        $shop = Shop::factory()->approved()->create([
            'user_id' => $seller->id,
            'is_default' => true,
        ]);
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $order = $this->createOrderItem($buyer, $shop, $product, 'completed', 2, 500);

        CommissionLedger::create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'gross_amount' => 500,
            'seller_amount' => 450,
            'platform_commission' => 50,
            'delivery_fee' => 0,
            'status' => 'settled',
        ]);

        $this->actingAs($seller)
            ->get(route('seller.reports'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.settledSellerAmount', 0)
                ->where('report.pendingSettlementAmount', 450)
            );

        $order->update(['payment_status' => 'paid']);

        $this->actingAs($seller)
            ->get(route('seller.reports'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.settledSellerAmount', 0)
                ->where('report.pendingSettlementAmount', 450)
            );
    }

    private function createOrderItem(
        User $buyer,
        Shop $shop,
        Product $product,
        string $status,
        int $quantity,
        float $subtotal
    ): Order {
        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => $subtotal,
            'shipping_fee' => 0,
            'total_amount' => $subtotal,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'quantity' => $quantity,
            'unit_price' => $subtotal / $quantity,
            'subtotal' => $subtotal,
        ]);

        return $order->fresh();
    }
}
