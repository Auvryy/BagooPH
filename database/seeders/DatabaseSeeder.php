<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Demo Users for each role
        $admin = User::create([
            'name' => 'Bagoo Super Admin',
            'email' => 'admin@bagoo.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'phone' => '+1 (555) 010-0001',
            'address' => '100 Bagoo HQ Way, Floor 12',
            'city' => 'Metro City',
            'postal_code' => '90001',
            'status' => 'active',
        ]);

        $sellerUser = User::create([
            'name' => 'Sarah Merchant',
            'email' => 'seller@bagoo.test',
            'password' => Hash::make('password'),
            'role' => 'seller',
            'phone' => '+1 (555) 020-0002',
            'address' => '456 Artisan District, Suite B',
            'city' => 'San Francisco',
            'postal_code' => '94103',
            'status' => 'active',
        ]);

        $buyer = User::create([
            'name' => 'Alex Customer',
            'email' => 'buyer@bagoo.test',
            'password' => Hash::make('password'),
            'role' => 'buyer',
            'phone' => '+1 (555) 030-0003',
            'address' => '789 Sunset Blvd, Apt 4C',
            'city' => 'Los Angeles',
            'postal_code' => '90028',
            'status' => 'active',
        ]);

        $courier = User::create([
            'name' => 'Dave Speed Courier',
            'email' => 'courier@bagoo.test',
            'password' => Hash::make('password'),
            'role' => 'courier',
            'phone' => '+1 (555) 040-0004',
            'address' => '12 Dispatcher Ave',
            'city' => 'Los Angeles',
            'postal_code' => '90015',
            'status' => 'active',
        ]);

        // 2. Create Seller Shop
        $shop = Shop::create([
            'user_id' => $sellerUser->id,
            'name' => 'Bagoo Prime Store',
            'slug' => 'bagoo-prime-store',
            'description' => 'Official flagship store for premium bags, tech accessories, and modern everyday essentials.',
            'logo' => 'https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=300&q=80',
            'banner' => 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1200&q=80',
            'phone' => '+1 (555) 020-0002',
            'address' => '456 Artisan District, Suite B',
            'city' => 'San Francisco',
            'rating' => 4.95,
            'status' => 'active',
        ]);

        // 3. Create Categories
        $categoriesData = [
            [
                'name' => 'Backpacks & Bags',
                'slug' => 'backpacks-and-bags',
                'icon' => 'ShoppingBag',
                'image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=600&q=80',
                'description' => 'Ergonomic, waterproof, and stylish backpacks for work and travel.',
            ],
            [
                'name' => 'Consumer Electronics',
                'slug' => 'consumer-electronics',
                'icon' => 'Laptop',
                'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=600&q=80',
                'description' => 'Smart gadgets, noise-cancelling audio, and wireless gear.',
            ],
            [
                'name' => 'Travel & Accessories',
                'slug' => 'travel-and-accessories',
                'icon' => 'Compass',
                'image' => 'https://images.unsplash.com/photo-1581553680321-4fffae59fccd?auto=format&fit=crop&w=600&q=80',
                'description' => 'Luggage tags, organizers, and portable essentials.',
            ],
            [
                'name' => 'Apparel & Footwear',
                'slug' => 'apparel-and-footwear',
                'icon' => 'Shirt',
                'image' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Comfortable techwear, organic cotton hoodies, and sneakers.',
            ],
        ];

        $categories = [];
        foreach ($categoriesData as $c) {
            $categories[$c['slug']] = Category::create($c);
        }

        // 4. Create Products
        $productsData = [
            [
                'name' => 'Bagoo Stealth Commuter Backpack 24L',
                'slug' => 'bagoo-stealth-commuter-backpack-24l',
                'category' => 'backpacks-and-bags',
                'price' => 89.00,
                'compare_at_price' => 119.00,
                'stock' => 45,
                'sku' => 'BAG-COMMUTER-24L',
                'featured_image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=800&q=80',
                'description' => 'Engineered with weather-resistant Cordura nylon, quick-access magnetic laptop sleeve (up to 16"), and ergonomic air-mesh shoulder support.',
                'rating' => 4.90,
                'sales_count' => 142,
            ],
            [
                'name' => 'AeroFit Active Crossbody Sling Bag',
                'slug' => 'aerofit-active-crossbody-sling-bag',
                'category' => 'backpacks-and-bags',
                'price' => 45.00,
                'compare_at_price' => 59.00,
                'stock' => 80,
                'sku' => 'BAG-SLING-01',
                'featured_image' => 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=800&q=80',
                'description' => 'Ultra-lightweight modular crossbody bag with hidden passport pocket and Fidlock V-buckle magnetic strap.',
                'rating' => 4.85,
                'sales_count' => 98,
            ],
            [
                'name' => 'Aura Pro ANC Wireless Headphones',
                'slug' => 'aura-pro-anc-wireless-headphones',
                'category' => 'consumer-electronics',
                'price' => 199.00,
                'compare_at_price' => 249.00,
                'stock' => 28,
                'sku' => 'TECH-AURA-PRO',
                'featured_image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=800&q=80',
                'description' => 'Studio-grade hybrid active noise cancellation, 45-hour battery lifespan, spatial audio DSP, and plush memory foam earcups.',
                'rating' => 4.92,
                'sales_count' => 215,
            ],
            [
                'name' => 'Apex Carbon Fiber Minimalist Wallet',
                'slug' => 'apex-carbon-fiber-minimalist-wallet',
                'category' => 'travel-and-accessories',
                'price' => 38.00,
                'compare_at_price' => 49.00,
                'stock' => 120,
                'sku' => 'ACC-WALLET-CF',
                'featured_image' => 'https://images.unsplash.com/photo-1627123424574-724758594e93?auto=format&fit=crop&w=800&q=80',
                'description' => 'RFID-blocking aviation grade 3K matte carbon fiber cardholder with integrated money clip and quick-fan card access.',
                'rating' => 4.78,
                'sales_count' => 330,
            ],
            [
                'name' => 'Voyager Hard Shell Carry-On Spinner 38L',
                'slug' => 'voyager-hard-shell-carry-on-spinner-38l',
                'category' => 'travel-and-accessories',
                'price' => 165.00,
                'compare_at_price' => 210.00,
                'stock' => 18,
                'sku' => 'TRV-LUGGAGE-38L',
                'featured_image' => 'https://images.unsplash.com/photo-1581553680321-4fffae59fccd?auto=format&fit=crop&w=800&q=80',
                'description' => 'Polycarbonate aerospace shell, whisper-quiet Hinomoto 360-degree ball bearing wheels, and TSA approved integrated lock.',
                'rating' => 4.95,
                'sales_count' => 74,
            ],
            [
                'name' => 'Bagoo Signature Heavyweight Hoodie',
                'slug' => 'bagoo-signature-heavyweight-hoodie',
                'category' => 'apparel-and-footwear',
                'price' => 75.00,
                'compare_at_price' => 95.00,
                'stock' => 50,
                'sku' => 'APP-HOODIE-BLK',
                'featured_image' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=800&q=80',
                'description' => '480 GSM 100% combed organic French terry cotton, double-needle coverstitching, and pre-shrunk custom relaxed fit.',
                'rating' => 4.88,
                'sales_count' => 110,
            ],
        ];

        $createdProducts = [];
        foreach ($productsData as $item) {
            $catSlug = $item['category'];
            unset($item['category']);

            $product = Product::create([
                ...$item,
                'shop_id' => $shop->id,
                'category_id' => $categories[$catSlug]->id ?? null,
                'status' => 'active',
            ]);

            ProductImage::create([
                'product_id' => $product->id,
                'image_url' => $product->featured_image,
                'is_primary' => true,
                'sort_order' => 0,
            ]);

            $createdProducts[] = $product;
        }

        // 5. Create Sample Order & Delivery for Alex (Buyer) assigned to Dave (Courier)
        $order = Order::create([
            'order_number' => 'BGO-' . strtoupper(Str::random(8)),
            'buyer_id' => $buyer->id,
            'subtotal' => 134.00,
            'shipping_fee' => 10.00,
            'total_amount' => 144.00,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'shipped',
            'recipient_name' => $buyer->name,
            'recipient_phone' => $buyer->phone,
            'shipping_address' => $buyer->address,
            'shipping_city' => $buyer->city,
            'shipping_postal_code' => $buyer->postal_code,
            'notes' => 'Please leave with front desk if not home.',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $createdProducts[0]->id,
            'shop_id' => $shop->id,
            'quantity' => 1,
            'unit_price' => 89.00,
            'subtotal' => 89.00,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $createdProducts[1]->id,
            'shop_id' => $shop->id,
            'quantity' => 1,
            'unit_price' => 45.00,
            'subtotal' => 45.00,
        ]);

        Delivery::create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'tracking_number' => 'TRK-BGO-' . rand(10000000, 99999999),
            'logistics_partner' => 'Bagoo Express Dispatch',
            'status' => 'out_for_delivery',
            'pickup_store_name' => $shop->name,
            'pickup_address' => $shop->address . ', ' . $shop->city,
            'pickup_phone' => $shop->phone,
            'delivery_address' => $order->shipping_address . ', ' . $order->shipping_city . ' ' . $order->shipping_postal_code,
            'delivery_recipient_name' => $order->recipient_name,
            'delivery_phone' => $order->recipient_phone,
            'estimated_delivery_at' => now()->addHours(2),
            'assigned_at' => now()->subHours(3),
            'picked_up_at' => now()->subHours(1),
            'courier_notes' => 'Package safely loaded on bike rack, en route to customer destination.',
        ]);

        // Create a secondary pending pickup order for courier testing
        $pendingOrder = Order::create([
            'order_number' => 'BGO-' . strtoupper(Str::random(8)),
            'buyer_id' => $buyer->id,
            'subtotal' => 199.00,
            'shipping_fee' => 0.00,
            'total_amount' => 199.00,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'ready_for_pickup',
            'recipient_name' => $buyer->name,
            'recipient_phone' => $buyer->phone,
            'shipping_address' => $buyer->address,
            'shipping_city' => $buyer->city,
            'shipping_postal_code' => $buyer->postal_code,
            'notes' => 'Fragile electronics package.',
        ]);

        OrderItem::create([
            'order_id' => $pendingOrder->id,
            'product_id' => $createdProducts[2]->id,
            'shop_id' => $shop->id,
            'quantity' => 1,
            'unit_price' => 199.00,
            'subtotal' => 199.00,
        ]);

        Delivery::create([
            'order_id' => $pendingOrder->id,
            'courier_id' => null, // Available for any courier to pick up!
            'tracking_number' => 'TRK-BGO-' . rand(10000000, 99999999),
            'logistics_partner' => 'BagooPH Express Dispatch',
            'status' => 'unassigned',
            'pickup_store_name' => $shop->name,
            'pickup_address' => $shop->address . ', ' . $shop->city,
            'pickup_phone' => $shop->phone,
            'delivery_address' => $pendingOrder->shipping_address . ', ' . $pendingOrder->shipping_city . ' ' . $pendingOrder->shipping_postal_code,
            'delivery_recipient_name' => $pendingOrder->recipient_name,
            'delivery_phone' => $pendingOrder->recipient_phone,
            'estimated_delivery_at' => now()->addDay(),
        ]);
    }
}
