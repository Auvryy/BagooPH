<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function createCartWithProduct(User $buyer, float $price = 500.00, int $quantity = 1): array
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);

        $shop = Shop::factory()->create([
            'user_id' => $seller->id,
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'price' => $price,
            'stock' => 50,
            'status' => 'active',
        ]);

        $cart = Cart::create([
            'user_id' => $buyer->id,
        ]);

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->price,
        ]);

        return [$cart, $cartItem, $product];
    }

    public function test_checkout_page_renders_with_standard_shipping_and_cod_mode(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->createCartWithProduct($buyer, 500.00, 1);

        $response = $this->actingAs($buyer)->get('/checkout');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Checkout/Index')
            ->where('subtotal', 500)
            ->where('shippingFee', 50)
            ->where('total', 550)
        );
    }

    public function test_checkout_locks_order_to_cash_on_delivery_strictly(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->createCartWithProduct($buyer, 300.00, 2);

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Jane Buyer',
            'recipient_phone' => '+63 912 341 2341',
            'shipping_address' => '456 Rizal St, Brgy San Jose',
            'shipping_city' => 'Calamba',
            'shipping_postal_code' => '4027',
            'payment_method' => 'card', // Even if card is submitted, backend locks to COD
        ]);

        $response->assertRedirect(route('buyer.orders.index'));

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals('cod', $order->payment_method);
        $this->assertEquals('pending', $order->payment_status);
        $this->assertEquals(600.00, (float) $order->subtotal);
        $this->assertEquals(50.00, (float) $order->shipping_fee);
        $this->assertEquals(650.00, (float) $order->total_amount);
        $this->assertEquals('+63 912 341 2341', $order->recipient_phone);
    }

    public function test_checkout_waives_shipping_fee_for_orders_exceeding_threshold(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->createCartWithProduct($buyer, 2000.00, 1);

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'High Value Buyer',
            'recipient_phone' => '+63 917 888 9999',
            'shipping_address' => '789 High Street',
            'shipping_city' => 'Makati',
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect(route('buyer.orders.index'));

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals('cod', $order->payment_method);
        $this->assertEquals(2000.00, (float) $order->subtotal);
        $this->assertEquals(0.00, (float) $order->shipping_fee);
        $this->assertEquals(2000.00, (float) $order->total_amount);
    }
}
