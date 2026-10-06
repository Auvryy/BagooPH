<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class ChallengerM1StressTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use RefreshDatabase;

    /**
     * Interleaved requests verify isolation; SQLite does not prove simultaneous locking.
     * Asserts zero cross-contamination between buyer carts and order items.
     */
    public function test_interleaved_buyers_keep_cart_variants_and_orders_isolated(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active', 'kyc_status' => 'approved']);
        $shop = Shop::factory()->approved()->create([
            'user_id' => $seller->id,
            'name' => 'Mega Boutique',
            'slug' => 'mega-boutique',
            'status' => 'active',
        ]);
        $category = Category::create(['name' => 'Footwear', 'slug' => 'footwear', 'parent_id' => $shop->root_category_id]);

        $productA = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Leather Oxford Shoes',
            'slug' => 'leather-oxford-shoes',
            'sku' => 'SHOE-OXF',
            'variants' => ['colors' => [['name' => 'Brown'], ['name' => 'Black']], 'sizes' => [['name' => '42', 'stock' => 25], ['name' => '44', 'stock' => 25]]],
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
            'variants' => ['colors' => [['name' => 'White']], 'sizes' => [['name' => '41', 'stock' => 50]]],
            'price' => 800.00,
            'stock' => 50,
            'status' => 'active',
        ]);

        $buyer1 = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);
        $buyer2 = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);

        $this->createCheckoutNetwork($shop, ['Quezon City' => 'Metro Manila', 'Taguig City' => 'Metro Manila']);

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
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

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
            'shipping_province' => 'Metro Manila',
            'destination_barangay' => 'Poblacion',
            'item_ids' => $cart1->items()->pluck('id')->all(),
            'shipping_postal_code' => '1100',
            'payment_method' => 'cod',
        ])->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));

        // Buyer 1 cart is cleared, Buyer 2 cart remains intact
        $this->assertEquals(0, $cart1->fresh()->items()->count());
        $this->assertEquals(2, $cart2->fresh()->items()->count());

        // Buyer 2 Checks out
        $this->actingAs($buyer2)->post('/checkout', [
            'recipient_name' => 'Buyer Two',
            'recipient_phone' => '+63 918 333 4444',
            'shipping_address' => 'Addr 2',
            'shipping_city' => 'Taguig City',
            'shipping_postal_code' => '1634',
            'shipping_province' => 'Metro Manila',
            'destination_barangay' => 'Poblacion',
            'item_ids' => $cart2->items()->pluck('id')->all(),
            'payment_method' => 'card',
        ])->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));

        $this->assertSame('cod', Order::where('buyer_id', $buyer2->id)->firstOrFail()->payment_method);
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
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id, 'name' => 'Book Shop', 'slug' => 'book-shop', 'status' => 'active']);
        $category = Category::create(['name' => 'Books', 'slug' => 'books', 'parent_id' => $shop->root_category_id]);

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

        $this->createCheckoutNetwork($shop, ['Makati City' => 'Metro Manila']);

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
            'shipping_city' => 'Makati City',
            'shipping_province' => 'Metro Manila',
            'destination_barangay' => 'Poblacion',
            'item_ids' => $cart->items()->pluck('id')->all(),
            'shipping_postal_code' => '1226',
            'payment_method' => 'cod',
        ])->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));

        $order = Order::where('buyer_id', $buyer->id)->first();
        $this->assertNotNull($order);
        $this->assertNotNull($order->delivery->origin_mother_hub_id);
        $this->assertNotNull($order->delivery->destination_mother_hub_id);
        $this->assertSame('pending', $order->payment_status);
        $orderItem = $order->items()->firstOrFail();
        $this->assertEquals('BOOK-PH-001', $orderItem->sku_snapshot);
        $this->assertNull($orderItem->color);
        $this->assertNull($orderItem->size);
    }

    /**
     * Stress Test: Account removal cannot erase a referenced rider profile.
     */
    public function test_courier_profile_cascade_delete_and_uniqueness(): void
    {
        $courier = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved']);
        $profile = CourierProfile::create([
            'user_id' => $courier->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'QC-8888',
            'license_number' => 'LIC-112233',
            'or_cr_status' => 'Verified & Registered',
            'is_available' => true,
        ]);

        $this->assertDatabaseHas('courier_profiles', ['user_id' => $courier->id]);

        $before = $profile->fresh()->getAttributes();
        try {
            $courier->delete();
            $this->fail('An unsafe account deletion must not erase a rider profile.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('must be retained', $error->getMessage());
        }
        $this->assertDatabaseHas('users', ['id' => $courier->id]);
        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    /**
     * Stress Test: Delivery Phone formatting preservation across international formats.
     */
    public function test_delivery_phone_format_preservation(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active', 'kyc_status' => 'approved']);
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id, 'name' => 'Gadgets', 'slug' => 'gadgets', 'status' => 'active']);
        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'parent_id' => $shop->root_category_id]);

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

        $this->createCheckoutNetwork($shop, ['Taguig' => 'Metro Manila']);

        $phoneFormats = [
            '+63 (917) 123-4567',
            '0918-987-6543',
            '+639991234567',
        ];

        foreach ($phoneFormats as $phone) {
            $this->actingAs($buyer)->post('/cart', ['product_id' => $product->id, 'quantity' => 1])
                ->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->actingAs($buyer)->post('/checkout', [
                'recipient_name' => 'Tech Enthusiast', 'recipient_phone' => $phone,
                'shipping_address' => '100 Silicon Ave', 'shipping_city' => 'Taguig',
                'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1634',
                'destination_barangay' => 'Fort Bonifacio', 'payment_method' => 'cod',
                'item_ids' => Cart::where('user_id', $buyer->id)->firstOrFail()->items()->pluck('id')->all(),
            ])->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
            $order = Order::where('buyer_id', $buyer->id)->latest('id')->firstOrFail();
            $this->actingAs($seller)->post("/seller/orders/{$order->id}/accept")->assertSessionHas('success');
            $this->actingAs($seller)->post("/seller/orders/{$order->id}/pack")->assertSessionHas('success');
            $this->actingAs($seller)->post("/seller/orders/{$order->id}/ready")->assertSessionHas('success');
            $this->assertSame('ready_for_pickup', $order->fresh()->status);
            $delivery = Delivery::where('order_id', $order->id)->first();
            $this->assertNotNull($delivery);
            $this->assertEquals($phone, $delivery->delivery_phone, "Delivery phone must accurately preserve {$phone}");
        }
    }
}
