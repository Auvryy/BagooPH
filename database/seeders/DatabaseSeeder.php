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
        // 1. Platform Admin Accounts
        User::updateOrCreate(
            ['email' => 'sarneandy6@gmail.com'],
            [
                'name' => 'Andy Super Admin',
                'password' => 'password',
                'role' => 'admin',
                'phone' => '+63 917 888 8888',
                'address' => '100 Bagoo HQ Way, Floor 12',
                'city' => 'Taguig, Metro Manila',
                'postal_code' => '1634',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@bagoo.ph'],
            [
                'name' => 'Bagoo Platform Admin',
                'password' => 'password',
                'role' => 'admin',
                'phone' => '+63 917 888 0000',
                'address' => 'Bagoo Tech Tower, BGC',
                'city' => 'Taguig, Metro Manila',
                'postal_code' => '1634',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 2. Verified Buyer Accounts
        User::updateOrCreate(
            ['email' => 'buyer@bagoo.ph'],
            [
                'name' => 'Juan Dela Cruz',
                'password' => 'password',
                'role' => 'buyer',
                'phone' => '+63 917 123 4567',
                'address' => 'Unit 402, Pioneer Woodlands, EDSA',
                'city' => 'Mandaluyong City',
                'postal_code' => '1550',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'sarneandy6+buyer@gmail.com'],
            [
                'name' => 'Andy Buyer',
                'password' => 'password',
                'role' => 'buyer',
                'phone' => '+63 917 777 7777',
                'address' => 'Unit 12A, High Street South Block',
                'city' => 'Taguig, Metro Manila',
                'postal_code' => '1634',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 3. Verified Seller Account & Store
        $sellerUser = User::updateOrCreate(
            ['email' => 'seller@bagoo.ph'],
            [
                'name' => 'Apex Apparel Merchant',
                'password' => 'password',
                'role' => 'seller',
                'phone' => '+63 917 222 3333',
                'address' => 'Warehouse 4, Pasig Mega Logistics Park',
                'city' => 'Pasig City',
                'postal_code' => '1600',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        $shop = Shop::updateOrCreate(
            ['user_id' => $sellerUser->id],
            [
                'name' => 'Apex Gear & Studio',
                'slug' => 'apex-gear-and-studio',
                'description' => 'Premium ergonomic techwear, waterproof commuter packs, and modular EDC gear.',
                'logo' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=200&q=80',
                'banner' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=1200&q=80',
                'phone' => '+63 917 222 3333',
                'address' => 'Warehouse 4, Pasig Mega Logistics Park',
                'city' => 'Pasig City',
                'status' => 'active',
                'rating' => 4.95,
            ]
        );

        // 4. Verified Courier Fleet Rider
        $courierUser = User::updateOrCreate(
            ['email' => 'courier@bagoo.ph'],
            [
                'name' => 'Ricardo Dalisay',
                'password' => 'password',
                'role' => 'courier',
                'phone' => '+63 917 555 7777',
                'address' => 'Block 12, Guadalupe Nuevo',
                'city' => 'Makati City',
                'postal_code' => '1212',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        \App\Models\CourierProfile::updateOrCreate(
            ['user_id' => $courierUser->id],
            [
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'BG-9876-PH',
                'license_number' => 'N01-18-999888',
                'or_cr_status' => 'Verified',
                'is_available' => true,
            ]
        );

        // 5. Logistics Hub Operator
        User::updateOrCreate(
            ['email' => 'hub@bagoo.ph'],
            [
                'name' => 'Metro Manila Central Hub Operator',
                'password' => 'password',
                'role' => 'logistics',
                'phone' => '+63 917 444 8888',
                'address' => 'Hub Station 01, C5 Road, Pasig City',
                'city' => 'Pasig City',
                'postal_code' => '1604',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
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

        // 4. Seed Platform Vouchers
        \App\Models\Voucher::updateOrCreate(
            ['code' => 'PAYDAY70'],
            [
                'name' => 'Payday 10% Discount',
                'description' => '10% discount on all orders above ₱500',
                'discount_type' => 'percent',
                'discount_value' => 10.00,
                'min_spend' => 500.00,
                'max_discount' => 500.00,
                'is_active' => true,
            ]
        );

        \App\Models\Voucher::updateOrCreate(
            ['code' => 'FREESHIP'],
            [
                'name' => 'Free Delivery Voucher',
                'description' => 'Zero shipping fee with no minimum spend required',
                'discount_type' => 'free_shipping',
                'discount_value' => 50.00,
                'min_spend' => 0.00,
                'is_active' => true,
            ]
        );

        \App\Models\Voucher::updateOrCreate(
            ['code' => 'BAGOO10'],
            [
                'name' => 'New Buyer ₱200 Bonus',
                'description' => '₱200 flat discount on orders ₱1,000 and up',
                'discount_type' => 'fixed',
                'discount_value' => 200.00,
                'min_spend' => 1000.00,
                'is_active' => true,
            ]
        );

        // 5. Seed Comprehensive Catalog Products for Apex Gear & Studio
        $sampleProducts = [
            [
                'category_slug' => 'backpacks-and-bags',
                'name' => 'Apex Waterproof Commuter Pack 28L',
                'slug' => 'apex-waterproof-commuter-pack-28l',
                'description' => 'Cordura 500D waterproof ballistic nylon commuter backpack with Fidlock magnetic buckles and dedicated 16-inch padded laptop compartment.',
                'price' => 2850.00,
                'compare_at_price' => 3500.00,
                'stock' => 50,
                'sku' => 'APX-BP-001',
                'featured_image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.95,
                'sales_count' => 128,
            ],
            [
                'category_slug' => 'backpacks-and-bags',
                'name' => 'Modular Sling Bag Cordura EDC',
                'slug' => 'modular-sling-bag-cordura-edc',
                'description' => 'Compact weatherproof crossbody sling bag with quick-release shoulder strap, YKK Aquaguard zippers, and internal mesh organizers.',
                'price' => 1450.00,
                'compare_at_price' => 1800.00,
                'stock' => 80,
                'sku' => 'APX-BP-002',
                'featured_image' => 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.89,
                'sales_count' => 96,
            ],
            [
                'category_slug' => 'consumer-electronics',
                'name' => 'Mechanical Wireless Keyboard 75% Layout',
                'slug' => 'mechanical-wireless-keyboard-75-layout',
                'description' => 'Hot-swappable tactile mechanical keyboard with CNC aluminum top plate, RGB per-key backlighting, and Bluetooth 5.2 / 2.4GHz dual mode.',
                'price' => 3450.00,
                'compare_at_price' => 4200.00,
                'stock' => 40,
                'sku' => 'APX-ELE-003',
                'featured_image' => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.93,
                'sales_count' => 115,
            ],
            [
                'category_slug' => 'audio-and-spatial-sound',
                'name' => 'Titanium ANC Spatial Headphones',
                'slug' => 'titanium-anc-spatial-headphones',
                'description' => 'Active Noise Cancelling over-ear headphones with 40mm beryllium drivers, LDAC lossless codec, and 45-hour playback battery.',
                'price' => 4990.00,
                'compare_at_price' => 6200.00,
                'stock' => 35,
                'sku' => 'APX-AUD-004',
                'featured_image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.90,
                'sales_count' => 84,
            ],
            [
                'category_slug' => 'audio-and-spatial-sound',
                'name' => 'Hi-Res True Wireless Earbuds Pro',
                'slug' => 'hi-res-true-wireless-earbuds-pro',
                'description' => 'Hybrid ANC earbuds with dual balanced armature drivers, IPX7 water resistance, wireless charging case, and ambient transparency mode.',
                'price' => 2290.00,
                'compare_at_price' => 2900.00,
                'stock' => 65,
                'sku' => 'APX-AUD-005',
                'featured_image' => 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.87,
                'sales_count' => 142,
            ],
            [
                'category_slug' => 'apparel-and-footwear',
                'name' => 'Weatherproof Heavy Techwear Hoodie',
                'slug' => 'weatherproof-heavy-techwear-hoodie',
                'description' => '500GSM heavyweight French terry cotton hoodie with DWR water-repellent coating and hidden magnetic utility pockets.',
                'price' => 1850.00,
                'compare_at_price' => 2400.00,
                'stock' => 60,
                'sku' => 'APX-APP-006',
                'featured_image' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.88,
                'sales_count' => 210,
            ],
            [
                'category_slug' => 'apparel-and-footwear',
                'name' => 'Oversized Heavyweight Cotton Graphic Tee',
                'slug' => 'oversized-heavyweight-cotton-graphic-tee',
                'description' => '280GSM combed cotton boxy-cut streetwear tee with reinforced ribbed collar and high-density screen-printed branding.',
                'price' => 850.00,
                'compare_at_price' => 1100.00,
                'stock' => 120,
                'sku' => 'APX-APP-007',
                'featured_image' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.91,
                'sales_count' => 340,
            ],
            [
                'category_slug' => 'travel-and-accessories',
                'name' => 'Polycarbonate Carry-On Hard Spinner 20-Inch',
                'slug' => 'polycarbonate-carry-on-hard-spinner-20-inch',
                'description' => 'Aero-grade Makrolon polycarbonate lightweight luggage with 360-degree silent Japanese Hinomoto wheels and TSA-approved lock.',
                'price' => 3950.00,
                'compare_at_price' => 5200.00,
                'stock' => 30,
                'sku' => 'APX-TRV-008',
                'featured_image' => 'https://images.unsplash.com/photo-1581553680321-4fffae59fccd?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.96,
                'sales_count' => 68,
            ],
            [
                'category_slug' => 'footwear-and-sneakers',
                'name' => 'Cushioned Urban Trail Runners',
                'slug' => 'cushioned-urban-trail-runners',
                'description' => 'Responsive supercritical foam midsole sneakers with Vibram lug outsole and breathable engineered knit upper.',
                'price' => 2750.00,
                'compare_at_price' => 3400.00,
                'stock' => 45,
                'sku' => 'APX-FTW-009',
                'featured_image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.94,
                'sales_count' => 176,
            ],
            [
                'category_slug' => 'smart-home-and-living',
                'name' => 'Minimalist Wireless Ambient Desk Bar',
                'slug' => 'minimalist-wireless-ambient-desk-bar',
                'description' => 'Touch-controlled dimmable LED studio light bar with auto-light sensing, anti-glare asymmetrical optics, and 20-hour wireless battery.',
                'price' => 1650.00,
                'compare_at_price' => 2100.00,
                'stock' => 50,
                'sku' => 'APX-HOM-010',
                'featured_image' => 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.86,
                'sales_count' => 88,
            ],
            [
                'category_slug' => 'beauty-and-grooming',
                'name' => 'Botanical Deep Hydration Cleanser & Care Kit',
                'slug' => 'botanical-deep-hydration-cleanser-and-care-kit',
                'description' => 'pH-balanced gentle daily facial wash infused with hyaluronic acid, centella asiatica, and organic green tea extracts.',
                'price' => 950.00,
                'compare_at_price' => 1250.00,
                'stock' => 75,
                'sku' => 'APX-BTY-011',
                'featured_image' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.89,
                'sales_count' => 220,
            ],
            [
                'category_slug' => 'mobile-and-gadgets',
                'name' => '65W GaN Fast Dual Travel Charger',
                'slug' => '65w-gan-fast-dual-travel-charger',
                'description' => 'Gallium Nitride high-efficiency dual USB-C Power Delivery wall charger with foldable Philippine plug prongs.',
                'price' => 1150.00,
                'compare_at_price' => 1500.00,
                'stock' => 100,
                'sku' => 'APX-GAD-012',
                'featured_image' => 'https://images.unsplash.com/photo-1580910051074-3eb694886505?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.92,
                'sales_count' => 312,
            ],
            [
                'category_slug' => 'mobile-and-gadgets',
                'name' => 'Magnetic Qi2 Fast Wireless Power Bank 10000mAh',
                'slug' => 'magnetic-qi2-fast-wireless-power-bank-10000mah',
                'description' => 'Ultra-slim 15W MagSafe and Qi2 compatible battery pack with foldable kickstand and digital LED battery percentage display.',
                'price' => 1890.00,
                'compare_at_price' => 2400.00,
                'stock' => 55,
                'sku' => 'APX-GAD-013',
                'featured_image' => 'https://images.unsplash.com/photo-1609091839311-d5365f9ff1c5?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.91,
                'sales_count' => 164,
            ],
            [
                'category_slug' => 'sports-and-outdoor',
                'name' => 'Insulated Stainless Steel Flask 1000ml',
                'slug' => 'insulated-stainless-steel-flask-1000ml',
                'description' => 'Double-wall vacuum insulated 18/8 food-grade stainless steel bottle with powder-coat finish keeping cold for 24h and hot for 12h.',
                'price' => 790.00,
                'compare_at_price' => 1050.00,
                'stock' => 90,
                'sku' => 'APX-SPT-014',
                'featured_image' => 'https://images.unsplash.com/photo-1517838277536-f5f99be501cd?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.95,
                'sales_count' => 280,
            ],
            [
                'category_slug' => 'watches-and-edc',
                'name' => 'Automatic Field Watch Titanium Edition',
                'slug' => 'automatic-field-watch-titanium-edition',
                'description' => 'Grade-2 Titanium 38mm case with NH35 automatic movement, sapphire crystal lens, and 100m water resistance.',
                'price' => 6450.00,
                'compare_at_price' => 8200.00,
                'stock' => 25,
                'sku' => 'APX-WTC-015',
                'featured_image' => 'https://images.unsplash.com/photo-1524805444758-089113d48a6d?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.97,
                'sales_count' => 42,
            ],
            [
                'category_slug' => 'desk-studio-and-stationery',
                'name' => 'Full-Grain Leather Desk Studio Mat',
                'slug' => 'full-grain-leather-desk-studio-mat',
                'description' => 'Vegetable-tanned full-grain leather 900x400mm workstation mat with suede anti-slip backing.',
                'price' => 1250.00,
                'compare_at_price' => 1600.00,
                'stock' => 60,
                'sku' => 'APX-DSK-016',
                'featured_image' => 'https://images.unsplash.com/photo-1585776245991-cf89dd7fc73a?auto=format&fit=crop&w=800&q=80',
                'rating' => 4.85,
                'sales_count' => 95,
            ],
        ];

        foreach ($sampleProducts as $p) {
            $cat = $categories[$p['category_slug']] ?? null;
            if ($cat) {
                Product::updateOrCreate(
                    ['slug' => $p['slug']],
                    [
                        'shop_id' => $shop->id,
                        'category_id' => $cat->id,
                        'name' => $p['name'],
                        'description' => $p['description'],
                        'price' => $p['price'],
                        'compare_at_price' => $p['compare_at_price'],
                        'stock' => $p['stock'],
                        'sku' => $p['sku'],
                        'featured_image' => $p['featured_image'],
                        'weight_kg' => 0.5,
                        'status' => 'active',
                        'rating' => $p['rating'],
                        'sales_count' => $p['sales_count'],
                    ]
                );
            }
        }
    }
}
