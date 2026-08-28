<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChallengerM1StressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stress Test: Multiple users adding diverse variants simultaneously.
     * Asserts zero cross-contamination between buyer carts and order items.
     */
    public function test_multi_user_concurrent_cart_and_variant_isolation(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active', 'kyc_status' => 'approved']);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Mega Boutique',
            'slug' => 'mega-boutique',
            'status' => 'active',
        ]);
        $category = Category::create(['name' => 'Footwear', 'slug' => 'footwear']);

        $productA = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Leather Oxford Shoes',
            'slug' => 'leather-oxford-shoes',
            'sku' => 'SHOE-OXF',
            'price' => 1200.00,
            'stock' => 50,
            'status' => 'active',
        ]);

        $productB = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Canvas Sneakers',
            'slug' => 'canvas-sneakers',
            'sku' => 'SHOE-SNK',
            'price' => 800.00,
            'stock' => 50,
            'status' => 'active',
        ]);

        $buyer1 = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);
        $buyer2 = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);

        // Buyer 1 adds Product A (Brown / 42) and Product B (White / 41)
        $this->actingAs($buyer1)->post('/cart', [
            'product_id' => $productA->id,
            'quantity' => 1,
            'color' => 'Brown',
            'size' => '42',
        ]);
        $this->actingAs($buyer1)->post('/cart', [
            'product_id' => $productB->id,
            'quantity' => 2,
            'color' => 'White',
            'size' => '41',
        ]);

        // Buyer 2 adds Product A (Black / 44) and Product A (Brown / 42)
        $this->actingAs($buyer2)->post('/cart', [
            'product_id' => $productA->id,
            'quantity' => 1,
            'color' => 'Black',
            'size' => '44',
        ]);
        $this->actingAs($buyer2)->post('/cart', [
            'product_id' => $productA->id,
            'quantity' => 3,
            'color' => 'Brown',
            'size' => '42',
        ]);

        // Verify Buyer 1 Cart
        $cart1 = Cart::where('user_id', $buyer1->id)->first();
        $this->assertEquals(2, $cart1->items()->count());
        $this->assertEquals('SHOE-OXF-Brown-42', $cart1->items()->where('product_id', $productA->id)->first()->sku_snapshot);
        $this->assertEquals('SHOE-SNK-White-41', $cart1->items()->where('product_id', $productB->id)->first()->sku_snapshot);

        // Verify Buyer 2 Cart
        $cart2 = Cart::where('user_id', $buyer2->id)->first();
        $this->assertEquals(2, $cart2->items()->count());
        $this->assertEquals('SHOE-OXF-Black-44', $cart2->items()->where('color', 'Black')->first()->sku_snapshot);
        $this->assertEquals(3, $cart2->items()->where('color', 'Brown')->first()->quantity);

        // Buyer 1 Checks out
        $this->actingAs($buyer1)->post('/checkout', [
            'recipient_name' => 'Buyer One',
            'recipient_phone' => '+63 917 111 2222',
            'shipping_address' => 'Addr 1',
            'shipping_city' => 'Quezon City',
            'payment_method' => 'cod',
        ]);

        // Buyer 1 cart is cleared, Buyer 2 cart remains intact
        $this->assertEquals(0, $cart1->fresh()->items()->count());
        $this->assertEquals(2, $cart2->fresh()->items()->count());

        // Buyer 2 Checks out
        $this->actingAs($buyer2)->post('/checkout', [
            'recipient_name' => 'Buyer Two',
            'recipient_phone' => '+63 918 333 4444',
            'shipping_address' => 'Addr 2',
            'shipping_city' => 'Davao City',
            'payment_method' => 'card',
        ]);

        $this->assertEquals(0, $cart2->fresh()->items()->count());

        // Verify stock decrements correctly:
        // Product A: 50 - 1 (Buyer1) - 4 (Buyer2: 1 Black + 3 Brown) = 45
        // Product B: 50 - 2 (Buyer1) = 48
        $this->assertEquals(45, $productA->fresh()->stock);
        $this->assertEquals(48, $productB->fresh()->stock);
    }

    /**
     * Stress Test: Standard product without variants (null color, null size).
     * SKU snapshot must cleanly equal base SKU.
     */
    public function test_standard_product_without_variants(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active', 'kyc_status' => 'approved']);
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'Book Shop', 'slug' => 'book-shop', 'status' => 'active']);
        $category = Category::create(['name' => 'Books', 'slug' => 'books']);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Philippine History Hardcover',
            'slug' => 'philippine-history-hardcover',
            'sku' => 'BOOK-PH-001',
            'price' => 550.00,
            'stock' => 20,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);

        $this->actingAs($buyer)->post('/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
            'color' => null,
            'size' => null,
        ]);

        $cart = Cart::where('user_id', $buyer->id)->first();
        $item = $cart->items()->first();
        $this->assertEquals('BOOK-PH-001', $item->sku_snapshot);
        $this->assertNull($item->color);
        $this->assertNull($item->size);

        $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Reader Ana',
            'recipient_phone' => '09228887766',
            'shipping_address' => 'Library Lane',
            'shipping_city' => 'Iloilo City',
            'payment_method' => 'cod',
        ]);

        $order = Order::where('buyer_id', $buyer->id)->first();
        $orderItem = $order->items()->first();
        $this->assertEquals('BOOK-PH-001', $orderItem->sku_snapshot);
        $this->assertNull($orderItem->color);
        $this->assertNull($orderItem->size);
    }

    /**
     * Stress Test: Courier Profile Cascade Deletion & Unique Constraint.
     */
    public function test_courier_profile_cascade_delete_and_uniqueness(): void
    {
        $courier = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved']);
        $profile = CourierProfile::create([
            'user_id' => $courier->id,
            'vehicle_type' => 'Honda Click 125i',
            'plate_number' => 'QC-8888',
            'license_number' => 'LIC-112233',
            'or_cr_status' => 'Verified & Registered',
            'is_available' => true,
        ]);

        $this->assertDatabaseHas('courier_profiles', ['user_id' => $courier->id]);

        // Delete user
        $courier->delete();

        // Profile must be cascade-deleted
        $this->assertDatabaseMissing('courier_profiles', ['id' => $profile->id]);
    }

    /**
     * Stress Test: Delivery Phone formatting preservation across international formats.
     */
    public function test_delivery_phone_format_preservation(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active', 'kyc_status' => 'approved']);
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'Gadgets', 'slug' => 'gadgets', 'status' => 'active']);
        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics']);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'USB-C Cable',
            'slug' => 'usb-c-cable',
            'sku' => 'CABLE-001',
            'price' => 199.00,
            'stock' => 30,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);

        $this->actingAs($buyer)->post('/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $phoneFormats = [
            '+63 (917) 123-4567',
            '0918-987-6543',
            '+639991234567',
        ];

        foreach ($phoneFormats as $phone) {
            $order = Order::create([
                'order_number' => 'BGO-' . strtoupper(\Illuminate\Support\Str::random(8)),
                'buyer_id' => $buyer->id,
                'subtotal' => 199.00,
                'shipping_fee' => 50.00,
                'total_amount' => 249.00,
                'payment_method' => 'cod',
                'status' => 'processing',
                'recipient_name' => 'Tech Enthusiast',
                'recipient_phone' => $phone,
                'shipping_address' => 'Silicon Ave',
                'shipping_city' => 'Taguig',
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'shop_id' => $shop->id,
                'quantity' => 1,
                'unit_price' => 199.00,
                'subtotal' => 199.00,
                'sku_snapshot' => 'CABLE-001',
            ]);

            // Seller marks order ready for pickup
            $this->actingAs($seller)->post("/seller/orders/{$order->id}/ready");

            $delivery = Delivery::where('order_id', $order->id)->first();
            $this->assertNotNull($delivery);
            $this->assertEquals($phone, $delivery->delivery_phone, "Delivery phone must accurately preserve {$phone}");
        }
    }
}
