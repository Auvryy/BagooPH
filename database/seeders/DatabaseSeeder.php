<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Platform Admin
        User::updateOrCreate(
            ['email' => 'admin@bagoo.test'],
            [
                'name'            => 'Bagoo Admin',
                'password'        => 'Password1234',
                'role'            => 'admin',
                'phone'           => '+63 917 000 0001',
                'address'         => 'Bagoo HQ, BGC',
                'city'            => 'Taguig, Metro Manila',
                'postal_code'     => '1634',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 2. Buyer
        User::updateOrCreate(
            ['email' => 'buyer@bagoo.test'],
            [
                'name'            => 'Test Buyer',
                'password'        => 'Password1234',
                'role'            => 'buyer',
                'phone'           => '+63 917 000 0002',
                'address'         => 'Unit 1, Test Street',
                'city'            => 'Quezon City',
                'postal_code'     => '1100',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 3. Seller
        $sellerUser = User::updateOrCreate(
            ['email' => 'seller@bagoo.test'],
            [
                'name'            => 'Test Seller',
                'password'        => 'Password1234',
                'role'            => 'seller',
                'phone'           => '+63 917 000 0003',
                'address'         => 'Warehouse 1, Test Ave',
                'city'            => 'Pasig City',
                'postal_code'     => '1600',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 4. Courier / Rider
        $courierUser = User::updateOrCreate(
            ['email' => 'rider@bagoo.test'],
            [
                'name'            => 'Test Rider',
                'password'        => 'Password1234',
                'role'            => 'courier',
                'phone'           => '+63 917 000 0004',
                'address'         => 'Block 5, Rider Lane',
                'city'            => 'Makati City',
                'postal_code'     => '1200',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        \App\Models\CourierProfile::updateOrCreate(
            ['user_id' => $courierUser->id],
            [
                'vehicle_type'   => 'Motorcycle',
                'plate_number'   => 'BG-2026-PH',
                'license_number' => 'N01-26-888999',
                'or_cr_status'   => 'Verified',
                'is_available'   => true,
            ]
        );

        // 5. Logistics / Hub Operator
        User::updateOrCreate(
            ['email' => 'logistics@bagoo.test'],
            [
                'name'            => 'Test Logistics',
                'password'        => 'Password1234',
                'role'            => 'logistics',
                'phone'           => '+63 917 000 0005',
                'address'         => 'Hub Station 01, C5 Road',
                'city'            => 'Pasig City',
                'postal_code'     => '1604',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 6. Fresh Seller Shop (Empty placeholder logo & cover banner)
        $shop = Shop::updateOrCreate(
            ['user_id' => $sellerUser->id],
            [
                'name'        => 'Apex Gear & Studio',
                'slug'        => 'apex-gear-and-studio',
                'description' => 'Official flagship store for premium weatherproof commuter packs, modular everyday carry, caps, tactical belts, and utilitarian men\'s body accessories.',
                'logo'        => null,
                'banner'      => null,
                'phone'       => '+63 917 000 0003',
                'address'     => 'Warehouse 1, Test Ave',
                'city'        => 'Pasig City',
                'status'      => 'active',
                'rating'      => 5.00,
            ]
        );

        // 7. Category: Men's Gear & Accessories
        $category = Category::updateOrCreate(
            ['slug' => 'mens-gear-and-accessories'],
            [
                'name'        => "Men's Gear & Accessories",
                'icon'        => 'ShoppingBag',
                'image'       => '/images/products/vanguard_commuter_front.jpg',
                'description' => 'Curated everyday carry, tactical commuter packs, weatherproof caps, heavy-duty belts, and utilitarian men\'s body accessories.',
                'is_active'   => true,
            ]
        );

        // 8. 16 Fresh Products (3 Retained + 13 Men's Accessories: Caps, Belts, Wallets, Watch, Sunglasses, Gloves, Hardware)
        // All have 20 stocks each, 0 sales, 2 multi-angle images with natural/lifestyle non-white backgrounds
        $productsData = [
            // --- RETAINED CORE COMMUTER BAGS (3 ITEMS) ---
            [
                'name'             => 'Vanguard Commuter Backpack 26L',
                'slug'             => 'vanguard-commuter-backpack-26l',
                'description'      => 'Engineered for high-density daily transit. Constructed from weatherproof 500D Cordura ballistic nylon with Fidlock magnetic sternum clips, a quick-access clamshell main compartment, and a dedicated suspended 16-inch padded laptop sleeve with waterproof YKK AquaGuard zippers.',
                'price'            => 2850.00,
                'compare_at_price' => 3500.00,
                'stock'            => 20,
                'sku'              => 'APX-VNG-001',
                'weight_kg'        => 1.10,
                'images'           => [
                    ['url' => '/images/products/vanguard_commuter_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/vanguard_commuter_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Nomad Modular Crossbody Tech Pouch',
                'slug'             => 'nomad-modular-crossbody-tech-pouch',
                'description'      => 'Versatile modular tech organization pouch with detachable paracord shoulder strap. Multi-tier elastic loops hold power banks, cables, SSDs, and stylus pens. Weather-resistant ripstop canvas shell with high-visibility interior organizing mesh.',
                'price'            => 1150.00,
                'compare_at_price' => 1450.00,
                'stock'            => 20,
                'sku'              => 'APX-NMD-004',
                'weight_kg'        => 0.35,
                'images'           => [
                    ['url' => '/images/products/nomad_pouch_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/nomad_pouch_open.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Aero Graphite Weatherproof Roll-Top 26L',
                'slug'             => 'aero-graphite-weatherproof-roll-top-26l',
                'description'      => 'Heavy-duty roll-top backpack engineered for intense tropical monsoons and cycle commutes. Features high-frequency welded seams, reinforced anodized aluminum hardware, exterior U-lock holster, and contoured ergonomic shoulder straps.',
                'price'            => 2750.00,
                'compare_at_price' => 3350.00,
                'stock'            => 20,
                'sku'              => 'APX-GRP-011',
                'weight_kg'        => 1.05,
                'images'           => [
                    ['url' => '/images/products/aero_rolltop_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/aero_rolltop_angle.jpg', 'is_primary' => false],
                ],
            ],

            // --- 13 MEN\'S BODY & GEAR ACCESSORIES (CAPS, BELTS, WALLETS, WATCH, GLASSES, GLOVES, HARDWARE) ---
            [
                'name'             => 'Tactical Ripstop Dad Cap (Waterproof)',
                'slug'             => 'tactical-ripstop-dad-cap-waterproof',
                'description'      => 'Low-profile 6-panel unstructured ball cap tailored from water-repellent micro-ripstop nylon. Features tonal embroidered ventilation eyelets, interior moisture-wicking sweatband, and an adjustable nylon webbing strap with matte alloy slide buckle.',
                'price'            => 890.00,
                'compare_at_price' => 1150.00,
                'stock'            => 20,
                'sku'              => 'APX-CAP-001',
                'weight_kg'        => 0.12,
                'images'           => [
                    ['url' => '/images/products/tactical_cap_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/tactical_cap_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Apex 5-Panel Techwear Camp Cap',
                'slug'             => 'apex-5-panel-techwear-camp-cap',
                'description'      => 'Streamlined 5-panel camp cap engineered for warm-weather city commuting and outdoor training. Features laser-perforated side ventilation panels, a crushable EVA soft-foam brim that packs flat into any bag, and a reflective rear safety pull tab.',
                'price'            => 950.00,
                'compare_at_price' => 1250.00,
                'stock'            => 20,
                'sku'              => 'APX-CAP-002',
                'weight_kg'        => 0.10,
                'images'           => [
                    ['url' => '/images/products/camp_cap_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/camp_cap_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Merino Ribbed Thermal Watch Beanie',
                'slug'             => 'merino-ribbed-thermal-watch-beanie',
                'description'      => 'Thermal cold-weather watch cap knit from 100% Australian extra-fine Merino wool. Features a dense 7-gauge double-ribbed construction providing itch-free natural thermal regulation, moisture management, and an adjustable folded cuff.',
                'price'            => 790.00,
                'compare_at_price' => 990.00,
                'stock'            => 20,
                'sku'              => 'APX-HED-003',
                'weight_kg'        => 0.08,
                'images'           => [
                    ['url' => '/images/products/merino_beanie_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/merino_beanie_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Cobra Tactical Heavy-Duty Nylon Belt',
                'slug'             => 'cobra-tactical-heavy-duty-nylon-belt',
                'description'      => 'Heavy-duty 1.5-inch tactical EDC belt woven from high-tensile 1000D mil-spec nylon webbing. Fitted with an authentic quick-release aerospace aluminum Cobra alloy buckle that locks securely under heavy loads with zero slip.',
                'price'            => 1250.00,
                'compare_at_price' => 1600.00,
                'stock'            => 20,
                'sku'              => 'APX-BLT-001',
                'weight_kg'        => 0.28,
                'images'           => [
                    ['url' => '/images/products/cobra_belt_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/cobra_belt_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Horween Full-Grain Leather Work Belt',
                'slug'             => 'horween-full-grain-leather-work-belt',
                'description'      => 'Rugged single-piece men\'s leather belt cut from 10oz vegetable-tanned full-grain steerhide. Features hand-burnished beveled edges, heavy bonded stitching, and a solid antiqued brass roller buckle secured by removable threaded Chicago screws.',
                'price'            => 1750.00,
                'compare_at_price' => 2200.00,
                'stock'            => 20,
                'sku'              => 'APX-BLT-002',
                'weight_kg'        => 0.35,
                'images'           => [
                    ['url' => '/images/products/leather_belt_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/leather_belt_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Fidlock Magnetic Stretch Web Belt',
                'slug'             => 'fidlock-magnetic-stretch-web-belt',
                'description'      => 'Adaptive stretch-woven utility belt that flexes seamlessly with your body motion throughout active days. Features a patented German Fidlock magnetic slide buckle that snaps securely into place with one-handed tactile operation.',
                'price'            => 1150.00,
                'compare_at_price' => 1450.00,
                'stock'            => 20,
                'sku'              => 'APX-BLT-003',
                'weight_kg'        => 0.18,
                'images'           => [
                    ['url' => '/images/products/magnetic_belt_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/magnetic_belt_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Ridgeback Titanium RFID Cardholder Wallet',
                'slug'             => 'ridgeback-titanium-rfid-cardholder-wallet',
                'description'      => 'Ultra-compact minimalist front-pocket wallet precision CNC-milled from Grade-2 aerospace titanium. Blocks 13.56 MHz RFID skimming frequencies while holding 1 to 12 credit cards with expandable elastic weave and an integrated spring steel bill clip.',
                'price'            => 1650.00,
                'compare_at_price' => 2100.00,
                'stock'            => 20,
                'sku'              => 'APX-WLT-001',
                'weight_kg'        => 0.09,
                'images'           => [
                    ['url' => '/images/products/titanium_wallet_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/titanium_wallet_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Horween Leather Minimalist Bifold Wallet',
                'slug'             => 'horween-leather-minimalist-bifold-wallet',
                'description'      => 'Classic slimline bifold wallet handcrafted from pull-up vegetable-tanned leather that develops a rich, personalized patina over time. Accommodates 8 cards and full-size Philippine Peso banknotes with an ultra-thin profile that eliminates pocket bulge.',
                'price'            => 1450.00,
                'compare_at_price' => 1850.00,
                'stock'            => 20,
                'sku'              => 'APX-WLT-002',
                'weight_kg'        => 0.07,
                'images'           => [
                    ['url' => '/images/products/leather_wallet_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/leather_wallet_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Sector Automatic Titanium Field Watch 38mm',
                'slug'             => 'sector-automatic-titanium-field-watch-38mm',
                'description'      => 'Field wrist instrument encased in lightweight sandblasted Grade-2 titanium with scratch-proof sapphire crystal lens. Powered by a self-winding 24-jewel automatic mechanical movement with 41-hour power reserve, 100m water resistance, and heavy Cordura strap.',
                'price'            => 5850.00,
                'compare_at_price' => 7200.00,
                'stock'            => 20,
                'sku'              => 'APX-WTC-001',
                'weight_kg'        => 0.08,
                'images'           => [
                    ['url' => '/images/products/field_watch_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/field_watch_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Apex Polarized Aviator Matte Sunglasses',
                'slug'             => 'apex-polarized-aviator-matte-sunglasses',
                'description'      => 'Featherweight surgical-grade stainless steel aviator frame with non-reflective matte black PVD coating. Outfitted with 7-layer TAC polarized lenses providing complete UV400 glare filtering, anti-scratch coating, and spring-loaded comfort hinges.',
                'price'            => 1650.00,
                'compare_at_price' => 2150.00,
                'stock'            => 20,
                'sku'              => 'APX-EYE-001',
                'weight_kg'        => 0.04,
                'images'           => [
                    ['url' => '/images/products/aviator_glasses_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/aviator_glasses_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Recon Touchscreen Tactical Utility Gloves',
                'slug'             => 'recon-touchscreen-tactical-utility-gloves',
                'description'      => 'Dexterous hard-wearing work and commute gloves. Features abrasion-resistant synthetic leather palms with capacitive touchscreen conductive fingertips, breathable 4-way stretch back-of-hand mesh, and flexible TPR knuckle impact protectors.',
                'price'            => 1150.00,
                'compare_at_price' => 1450.00,
                'stock'            => 20,
                'sku'              => 'APX-GLV-001',
                'weight_kg'        => 0.14,
                'images'           => [
                    ['url' => '/images/products/tactical_gloves_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/tactical_gloves_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Titanium EDC Multi-Tool Carabiner',
                'slug'             => 'titanium-edc-multi-tool-carabiner',
                'description'      => 'Solid skeletonized TC4 titanium alloy keychain carabiner. Integrates a bottle cap lifter, flathead pry bar, metric stepped hex wrench cutouts, and a secure wire-gate spring mechanism in a lightweight, non-magnetic, corrosion-proof design.',
                'price'            => 650.00,
                'compare_at_price' => 850.00,
                'stock'            => 20,
                'sku'              => 'APX-KEY-001',
                'weight_kg'        => 0.03,
                'images'           => [
                    ['url' => '/images/products/edc_carabiner_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/edc_carabiner_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Vanguard Braided Paracord Shackle Bracelet',
                'slug'             => 'vanguard-braided-paracord-shackle-bracelet',
                'description'      => 'Rugged outdoor EDC wristband hand-woven from 550lb mil-spec 7-strand nylon parachute cord. Secured with an adjustable 3-hole solid stainless steel bow shackle with knurled threaded pin. Can be deployed in emergencies into over 3 meters of load-bearing rope.',
                'price'            => 550.00,
                'compare_at_price' => 750.00,
                'stock'            => 20,
                'sku'              => 'APX-BRC-001',
                'weight_kg'        => 0.05,
                'images'           => [
                    ['url' => '/images/products/paracord_bracelet_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/paracord_bracelet_angle.jpg', 'is_primary' => false],
                ],
            ],
        ];

        // Clean up any previously seeded products under this shop not in the current catalog
        $currentSlugs = array_column($productsData, 'slug');
        $oldProducts = Product::where('shop_id', $shop->id)->whereNotIn('slug', $currentSlugs)->get();
        foreach ($oldProducts as $oldProd) {
            ProductImage::where('product_id', $oldProd->id)->delete();
            $oldProd->delete();
        }

        foreach ($productsData as $data) {
            $images = $data['images'];
            unset($data['images']);

            $product = Product::updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, [
                    'shop_id'        => $shop->id,
                    'category_id'    => $category->id,
                    'featured_image' => $images[0]['url'],
                    'status'         => 'active',
                    'rating'         => 5.00,
                    'sales_count'    => 0,
                ])
            );

            // Clean up and seed exact 2 multi-angle images per product
            ProductImage::where('product_id', $product->id)->delete();
            foreach ($images as $index => $img) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_url'  => $img['url'],
                    'is_primary' => $img['is_primary'],
                    'sort_order' => $index,
                ]);
            }
        }
    }
}
