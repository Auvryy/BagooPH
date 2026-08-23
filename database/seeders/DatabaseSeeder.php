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
        $admin = User::updateOrCreate(
            ['email' => 'admin@bagoo.test'],
            [
                'name' => 'Bagoo Super Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'phone' => '+1 (555) 010-0001',
                'address' => '100 Bagoo HQ Way, Floor 12',
                'city' => 'Metro City',
                'postal_code' => '90001',
                'status' => 'active',
            ]
        );

        $sellerUser = User::updateOrCreate(
            ['email' => 'seller@bagoo.test'],
            [
                'name' => 'Sarah Merchant',
                'password' => Hash::make('password'),
                'role' => 'seller',
                'phone' => '+1 (555) 020-0002',
                'address' => '456 Artisan District, Suite B',
                'city' => 'San Francisco',
                'postal_code' => '94103',
                'status' => 'active',
            ]
        );

        $buyer = User::updateOrCreate(
            ['email' => 'buyer@bagoo.test'],
            [
                'name' => 'Alex Customer',
                'password' => Hash::make('password'),
                'role' => 'buyer',
                'phone' => '+1 (555) 030-0003',
                'address' => '789 Sunset Blvd, Apt 4C',
                'city' => 'Los Angeles',
                'postal_code' => '90028',
                'status' => 'active',
            ]
        );

        $courier = User::updateOrCreate(
            ['email' => 'courier@bagoo.test'],
            [
                'name' => 'Dave Speed Courier',
                'password' => Hash::make('password'),
                'role' => 'courier',
                'phone' => '+1 (555) 040-0004',
                'address' => '12 Dispatcher Ave',
                'city' => 'Los Angeles',
                'postal_code' => '90015',
                'status' => 'active',
            ]
        );

        // 2. Create Seller Shop
        $shop = Shop::updateOrCreate(
            ['user_id' => $sellerUser->id],
            [
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
            ]
        );

        // 3. Create 14 Verified Departments
        $categoriesData = [
            [
                'name' => 'Backpacks & Bags',
                'slug' => 'backpacks-and-bags',
                'icon' => 'ShoppingBag',
                'image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=600&q=80',
                'description' => 'Ergonomic, waterproof, and modular packs for commute and travel.',
            ],
            [
                'name' => 'Consumer Electronics',
                'slug' => 'consumer-electronics',
                'icon' => 'Laptop',
                'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=600&q=80',
                'description' => 'Smart gadgets, ANC audio, mechanical peripherals, and gear.',
            ],
            [
                'name' => 'Apparel & Techwear',
                'slug' => 'apparel-and-footwear',
                'icon' => 'Shirt',
                'image' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Heavyweight organic cotton, waterproof shells, and streetwear.',
            ],
            [
                'name' => 'Travel & Luggage',
                'slug' => 'travel-and-accessories',
                'icon' => 'Compass',
                'image' => 'https://images.unsplash.com/photo-1581553680321-4fffae59fccd?auto=format&fit=crop&w=600&q=80',
                'description' => 'Polycarbonate spinners, packing cubes, and RFID organizers.',
            ],
            [
                'name' => 'Footwear & Sneakers',
                'slug' => 'footwear-and-sneakers',
                'icon' => 'Footprints',
                'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=600&q=80',
                'description' => 'Cushioned running shoes, trail sneakers, and slip-ons.',
            ],
            [
                'name' => 'Smart Home & Living',
                'slug' => 'smart-home-and-living',
                'icon' => 'Home',
                'image' => 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?auto=format&fit=crop&w=600&q=80',
                'description' => 'Minimalist desk lamps, ambient LED bars, and acoustic diffusers.',
            ],
            [
                'name' => 'Beauty & Grooming',
                'slug' => 'beauty-and-grooming',
                'icon' => 'Sparkles',
                'image' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?auto=format&fit=crop&w=600&q=80',
                'description' => 'Organic botanical skincare, beard balms, and facial cleansers.',
            ],
            [
                'name' => 'Mobile & Gadgets',
                'slug' => 'mobile-and-gadgets',
                'icon' => 'Smartphone',
                'image' => 'https://images.unsplash.com/photo-1580910051074-3eb694886505?auto=format&fit=crop&w=600&q=80',
                'description' => 'MagSafe power banks, titanium cables, and precision cases.',
            ],
            [
                'name' => 'Audio & Spatial Sound',
                'slug' => 'audio-and-spatial-sound',
                'icon' => 'Headphones',
                'image' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Hi-Res DAC amplifiers, true wireless earbuds, and studio monitors.',
            ],
            [
                'name' => 'Sports & Outdoors',
                'slug' => 'sports-and-outdoor',
                'icon' => 'Activity',
                'image' => 'https://images.unsplash.com/photo-1517838277536-f5f99be501cd?auto=format&fit=crop&w=600&q=80',
                'description' => 'Insulated flasks, compression sleeves, and hydration packs.',
            ],
            [
                'name' => 'Watches & EDC',
                'slug' => 'watches-and-edc',
                'icon' => 'Watch',
                'image' => 'https://images.unsplash.com/photo-1524805444758-089113d48a6d?auto=format&fit=crop&w=600&q=80',
                'description' => 'Automatic field watches, titanium pens, and pocket tools.',
            ],
            [
                'name' => 'Automotive & Moto',
                'slug' => 'automotive-essentials',
                'icon' => 'Car',
                'image' => 'https://images.unsplash.com/photo-1563720223185-11003d516935?auto=format&fit=crop&w=600&q=80',
                'description' => 'Phone car mounts, tire inflators, and detailing microfibers.',
            ],
            [
                'name' => 'Desk Studio & Stationery',
                'slug' => 'desk-studio-and-stationery',
                'icon' => 'PenTool',
                'image' => 'https://images.unsplash.com/photo-1585776245991-cf89dd7fc73a?auto=format&fit=crop&w=600&q=80',
                'description' => 'Full-grain leather desk mats, brass rulers, and grid notebooks.',
            ],
            [
                'name' => 'Health & Wellness',
                'slug' => 'health-and-wellness',
                'icon' => 'HeartPulse',
                'image' => 'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Massage guns, posture correctors, and ergonomic seat cushions.',
            ],
        ];

        $categories = [];
        foreach ($categoriesData as $c) {
            $categories[$c['slug']] = Category::updateOrCreate(['slug' => $c['slug']], $c);
        }

        // 4. Create Products
        $productsData = [
            [
                'name' => 'Bagoo Stealth Commuter Backpack 24L',
                'slug' => 'bagoo-stealth-commuter-backpack-24l',
                'category' => 'backpacks-and-bags',
                'price' => 4890.00,
                'compare_at_price' => 5990.00,
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
                'price' => 2450.00,
                'compare_at_price' => 3190.00,
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
                'category' => 'audio-and-spatial-sound',
                'price' => 9990.00,
                'compare_at_price' => 12490.00,
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
                'category' => 'watches-and-edc',
                'price' => 1890.00,
                'compare_at_price' => 2490.00,
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
                'price' => 8490.00,
                'compare_at_price' => 10990.00,
                'stock' => 18,
                'sku' => 'TRV-LUGGAGE-38L',
                'featured_image' => 'https://images.unsplash.com/photo-1581553680321-4fffae59fccd?auto=format&fit=crop&w=800&q=80',
                'description' => 'Polycarbonate aerospace shell, whisper-quiet Hinomoto 360-degree ball bearing wheels, and TSA approved integrated lock.',
                'rating' => 4.95,
                'sales_count' => 74,
            ],
            [
                'name' => 'Bagoo Signature Heavyweight Hoodie 480GSM',
                'slug' => 'bagoo-signature-heavyweight-hoodie',
                'category' => 'apparel-and-footwear',
                'price' => 3750.00,
                'compare_at_price' => 4500.00,
                'stock' => 50,
                'sku' => 'APP-HOODIE-BLK',
                'featured_image' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=800&q=80',
                'description' => '480 GSM 100% combed organic French terry cotton, double-needle coverstitching, and pre-shrunk custom relaxed fit.',
                'rating' => 4.88,
                'sales_count' => 110,
            ],
            [
                'name' => 'Quantum 65W GaN Fast Charger Block',
                'slug' => 'quantum-65w-gan-fast-charger',
                'category' => 'mobile-and-gadgets',
                'price' => 1650.00,
                'compare_at_price' => 2100.00,
                'stock' => 65,
                'sku' => 'MOB-GAN-65W',
                'featured_image' => 'https://images.unsplash.com/photo-1583863788434-e58a36330cf0?auto=format&fit=crop&w=800&q=80',
                'description' => 'Gallium Nitride 3-port ultra-compact wall charger with dual USB-C Power Delivery 3.0 and thermal protection.',
                'rating' => 4.91,
                'sales_count' => 420,
            ],
            [
                'name' => 'Lumina Minimalist ScreenBar Desk Light',
                'slug' => 'lumina-minimalist-screenbar-desk-light',
                'category' => 'smart-home-and-living',
                'price' => 2890.00,
                'compare_at_price' => 3600.00,
                'stock' => 32,
                'sku' => 'HOME-LUM-BAR',
                'featured_image' => 'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=800&q=80',
                'description' => 'Zero-glare asymmetrical optical design with auto-dimming touch sensor and wireless dial controller.',
                'rating' => 4.84,
                'sales_count' => 88,
            ],
            [
                'name' => 'Stratos Tactical Field Watch 10ATM',
                'slug' => 'stratos-tactical-field-watch',
                'category' => 'watches-and-edc',
                'price' => 6990.00,
                'compare_at_price' => 8990.00,
                'stock' => 22,
                'sku' => 'WATCH-STRAT-10',
                'featured_image' => 'https://images.unsplash.com/photo-1524805444758-089113d48a6d?auto=format&fit=crop&w=800&q=80',
                'description' => 'Titanium sandblasted case, sapphire crystal with anti-reflective coating, and Japanese automatic mechanical movement.',
                'rating' => 4.94,
                'sales_count' => 54,
            ],
            [
                'name' => 'HyperFoam Carbon Plate Trail Runner',
                'slug' => 'hyperfoam-carbon-plate-trail-runner',
                'category' => 'footwear-and-sneakers',
                'price' => 5490.00,
                'compare_at_price' => 6800.00,
                'stock' => 38,
                'sku' => 'FOOT-TRAIL-01',
                'featured_image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=80',
                'description' => 'Propulsive carbon plate sandwich, Vibram Megagrip lugged outsole, and breathable ripstop mesh upper.',
                'rating' => 4.89,
                'sales_count' => 165,
            ],
            [
                'name' => 'Nordic Full-Grain Leather Desk Mat',
                'slug' => 'nordic-full-grain-leather-desk-mat',
                'category' => 'desk-studio-and-stationery',
                'price' => 2150.00,
                'compare_at_price' => 2750.00,
                'stock' => 70,
                'sku' => 'DSK-MAT-LTHR',
                'featured_image' => 'https://images.unsplash.com/photo-1585776245991-cf89dd7fc73a?auto=format&fit=crop&w=800&q=80',
                'description' => '900x400mm vegetable-tanned full-grain leather desk pad with natural suede anti-slip backing and burnished edges.',
                'rating' => 4.96,
                'sales_count' => 190,
            ],
            [
                'name' => 'Kinetic Recovery Percussion Massage Gun',
                'slug' => 'kinetic-recovery-massage-gun',
                'category' => 'health-and-wellness',
                'price' => 4200.00,
                'compare_at_price' => 5400.00,
                'stock' => 25,
                'sku' => 'HLT-MASSAGE-K1',
                'featured_image' => 'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?auto=format&fit=crop&w=800&q=80',
                'description' => 'Brushless high-torque motor delivering 3200 RPM amplitude with 5 interchangeable heads and whisper-quiet operation.',
                'rating' => 4.87,
                'sales_count' => 78,
            ],
        ];

        $createdProducts = [];
        foreach ($productsData as $item) {
            $catSlug = $item['category'];
            unset($item['category']);

            $product = Product::updateOrCreate(
                ['slug' => $item['slug']],
                [
                    ...$item,
                    'shop_id' => $shop->id,
                    'category_id' => $categories[$catSlug]->id ?? null,
                    'status' => 'active',
                ]
            );

            ProductImage::updateOrCreate(
                ['product_id' => $product->id, 'is_primary' => true],
                [
                    'image_url' => $product->featured_image,
                    'sort_order' => 0,
                ]
            );

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

        // 5. Create a Delivered Order for Rating & Review testing
        $deliveredOrder = Order::create([
            'order_number' => 'BGO-' . strtoupper(Str::random(8)),
            'buyer_id' => $buyer->id,
            'subtotal' => 89.00,
            'shipping_fee' => 0.00,
            'total_amount' => 89.00,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'status' => 'delivered',
            'recipient_name' => $buyer->name,
            'recipient_phone' => $buyer->phone,
            'shipping_address' => $buyer->address,
            'shipping_city' => $buyer->city,
            'shipping_postal_code' => $buyer->postal_code,
            'notes' => 'Left at reception desk with signed proof of receipt.',
        ]);

        OrderItem::create([
            'order_id' => $deliveredOrder->id,
            'product_id' => $createdProducts[0]->id,
            'shop_id' => $shop->id,
            'quantity' => 1,
            'unit_price' => 89.00,
            'subtotal' => 89.00,
        ]);

        Delivery::create([
            'order_id' => $deliveredOrder->id,
            'courier_id' => $courier->id,
            'tracking_number' => 'TRK-BGO-' . rand(10000000, 99999999),
            'logistics_partner' => 'BagooPH Express Dispatch',
            'status' => 'delivered',
            'pickup_store_name' => $shop->name,
            'pickup_address' => $shop->address . ', ' . $shop->city,
            'pickup_phone' => $shop->phone,
            'delivery_address' => $deliveredOrder->shipping_address . ', ' . $deliveredOrder->shipping_city . ' ' . $deliveredOrder->shipping_postal_code,
            'delivery_recipient_name' => $deliveredOrder->recipient_name,
            'delivery_phone' => $deliveredOrder->recipient_phone,
            'assigned_at' => now()->subDays(2),
            'picked_up_at' => now()->subDay(),
            'delivered_at' => now()->subHours(4),
            'courier_notes' => 'Handed over directly to recipient.',
        ]);

        // 6. Seed Verified Customer Reviews with Photo Attachments
        \App\Models\Review::create([
            'product_id' => $createdProducts[0]->id,
            'buyer_id' => $buyer->id,
            'order_id' => $deliveredOrder->id,
            'rating' => 5,
            'comment' => 'Exceptional build quality! The waterproof zippers and structured ergonomic back panel feel fantastic for my daily commute.',
            'images' => [
                'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=600&q=80',
                'https://images.unsplash.com/photo-1581605405669-fcdf81165afa?auto=format&fit=crop&w=600&q=80',
            ],
        ]);

        \App\Models\Review::create([
            'product_id' => $createdProducts[1]->id,
            'buyer_id' => $buyer->id,
            'order_id' => $deliveredOrder->id,
            'rating' => 5,
            'comment' => 'Super compact and lightweight! Fits my passport, keys, and phone perfectly during quick runs.',
            'images' => [
                'https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?auto=format&fit=crop&w=600&q=80',
            ],
        ]);
    }
}
