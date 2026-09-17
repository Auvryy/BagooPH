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
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'BG-2026-PH',
                'license_number' => 'N01-26-888999',
                'or_cr_status' => 'Verified',
                'is_available' => true,
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
                'description' => 'Official flagship store for premium weatherproof commuter bags, modular EDC gear, and utilitarian travel packs.',
                'logo'        => null,
                'banner'      => null,
                'phone'       => '+63 917 000 0003',
                'address'     => 'Warehouse 1, Test Ave',
                'city'        => 'Pasig City',
                'status'      => 'active',
                'rating'      => 5.00,
            ]
        );

        // 7. Category: Backpacks & Bags
        $category = Category::updateOrCreate(
            ['slug' => 'backpacks-and-bags'],
            [
                'name'        => 'Backpacks & Bags',
                'icon'        => 'ShoppingBag',
                'image'       => '/images/products/vanguard_commuter_front.jpg',
                'description' => 'Ergonomic, waterproof, and modular packs for commute, EDC, and travel.',
                'is_active'   => true,
            ]
        );

        // 8. 15 Fresh Products (20 stocks each, 0 sales, 2 multi-angle AI images each)
        $productsData = [
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
                'name'             => 'Stealth Urban Sling Bag 8L',
                'slug'             => 'stealth-urban-sling-bag-8l',
                'description'      => 'Low-profile crossbody sling bag engineered for streamlined urban mobility. Features weather-sealed YKK zippers, internal RFID-shielded passport pocket, quick-release aluminum shoulder buckle, and breathable air-mesh back padding.',
                'price'            => 1450.00,
                'compare_at_price' => 1850.00,
                'stock'            => 20,
                'sku'              => 'APX-STL-002',
                'weight_kg'        => 0.45,
                'images'           => [
                    ['url' => '/images/products/stealth_sling_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/stealth_sling_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Horizon Expandable Travel Pack 35L',
                'slug'             => 'horizon-expandable-travel-pack-35l',
                'description'      => 'Airline carry-on approved expandable weekender travel pack. Expands from 28L daily mode to 35L flight mode. Features full 180-degree suitcase opening, integrated compression straps, dirty laundry separation pod, and hidden passport security pocket.',
                'price'            => 3950.00,
                'compare_at_price' => 4800.00,
                'stock'            => 20,
                'sku'              => 'APX-HRZ-003',
                'weight_kg'        => 1.45,
                'images'           => [
                    ['url' => '/images/products/horizon_travel_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/horizon_travel_side.jpg', 'is_primary' => false],
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
                'name'             => 'Aero Roll-Top Cycling Backpack 24L',
                'slug'             => 'aero-roll-top-cycling-backpack-24l',
                'description'      => 'Built for urban cyclists and all-weather commuters. Features an expandable roll-top collar secured by an anodized aluminum G-hook, subtle 360-degree reflective perimeter accents, U-lock holster slot, and high-tenacity waterproof laminated fabric.',
                'price'            => 2650.00,
                'compare_at_price' => 3200.00,
                'stock'            => 20,
                'sku'              => 'APX-AER-005',
                'weight_kg'        => 0.95,
                'images'           => [
                    ['url' => '/images/products/aero_rolltop_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/aero_rolltop_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Kinetic Modular Chest Rig Pack',
                'slug'             => 'kinetic-modular-chest-rig-pack',
                'description'      => 'Ergonomic hands-free chest utility rig with 4-point quick-adjust harness. Dual zippered main chambers accommodate EDC tools, smartphone, and wallet. Reinforced laser-cut MOLLE attachment points on front face for modular expansions.',
                'price'            => 1350.00,
                'compare_at_price' => 1750.00,
                'stock'            => 20,
                'sku'              => 'APX-KNT-006',
                'weight_kg'        => 0.50,
                'images'           => [
                    ['url' => '/images/products/kinetic_chest_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/kinetic_chest_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Transit Executive Leather-Trim Briefpack 20L',
                'slug'             => 'transit-executive-leather-trim-briefpack-20l',
                'description'      => 'Sophisticated business commuter backpack seamlessly bridging the boardroom and the metro. Crafted from ballistic twill nylon accented with full-grain vegetable-tanned leather handles and zipper pulls. Structured standing base with luggage trolley pass-through.',
                'price'            => 3650.00,
                'compare_at_price' => 4500.00,
                'stock'            => 20,
                'sku'              => 'APX-TRN-007',
                'weight_kg'        => 1.25,
                'images'           => [
                    ['url' => '/images/products/transit_brief_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/transit_brief_detail.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Apex Ergonomic Commuter Pack Edition II',
                'slug'             => 'apex-ergonomic-commuter-pack-edition-ii',
                'description'      => 'Refined second-generation daily commuter pack featuring high-density EVA back ventilation channels, dual magnetic hydration side pockets, top tricot-lined eyewear compartment, and quick-draw side laptop access.',
                'price'            => 2950.00,
                'compare_at_price' => 3650.00,
                'stock'            => 20,
                'sku'              => 'APX-ED2-008',
                'weight_kg'        => 1.15,
                'images'           => [
                    ['url' => '/images/products/apex_commuter_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/apex_commuter_back.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Stealth EDC Compact Mini Sling 5L',
                'slug'             => 'stealth-edc-compact-mini-sling-5l',
                'description'      => 'Ultra-compact everyday sling designed for minimal carry. Securely stows keys, compact camera, smartphone, and cards with zero bulk. Weatherproof coated canvas shell with auto-locating magnetic buckle.',
                'price'            => 1250.00,
                'compare_at_price' => 1600.00,
                'stock'            => 20,
                'sku'              => 'APX-MIN-009',
                'weight_kg'        => 0.38,
                'images'           => [
                    ['url' => '/images/products/stealth_mini_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/stealth_mini_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Expedition Overnighter Duffle-Backpack 40L',
                'slug'             => 'expedition-overnighter-duffle-backpack-40l',
                'description'      => 'Dual-mode travel utility bag that transitions from a spacious duffle to an ergonomic backpack via stowable shoulder straps. Includes ventilated bottom compartment for shoes, reinforced grab handles, and abrasion-resistant base.',
                'price'            => 3850.00,
                'compare_at_price' => 4600.00,
                'stock'            => 20,
                'sku'              => 'APX-EXP-010',
                'weight_kg'        => 1.50,
                'images'           => [
                    ['url' => '/images/products/expedition_duffle_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/expedition_duffle_side.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Aero Graphite Weatherproof Roll-Top 26L',
                'slug'             => 'aero-graphite-weatherproof-roll-top-26l',
                'description'      => 'Heavy-duty roll-top backpack engineered for intense tropical monsoons. Features high-frequency welded seams, reinforced anodized aluminum hardware, exterior U-lock holster, and contoured ergonomic shoulder straps.',
                'price'            => 2750.00,
                'compare_at_price' => 3350.00,
                'stock'            => 20,
                'sku'              => 'APX-GRP-011',
                'weight_kg'        => 1.05,
                'images'           => [
                    ['url' => '/images/products/aero_graphite_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/aero_graphite_buckle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Nomad Utility Field Organizer Case',
                'slug'             => 'nomad-utility-field-organizer-case',
                'description'      => 'Semi-rigid protective gear case tailored for mobile workstations, cables, charging adapters, and hard drives. Features 360-degree EVA bumper shell with water-repellent AquaGuard zipper seal.',
                'price'            => 1200.00,
                'compare_at_price' => 1550.00,
                'stock'            => 20,
                'sku'              => 'APX-ORG-012',
                'weight_kg'        => 0.40,
                'images'           => [
                    ['url' => '/images/products/nomad_organizer_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/nomad_organizer_detail.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Operator Tactical Chest Utility Rig',
                'slug'             => 'operator-tactical-chest-utility-rig',
                'description'      => 'Low-profile tactical chest rig engineered for immediate hands-free access to EDC tools, navigation device, and radio. Built from 1000D abrasion-resistant Cordura with laser-cut composite front panels.',
                'price'            => 1400.00,
                'compare_at_price' => 1800.00,
                'stock'            => 20,
                'sku'              => 'APX-OPR-013',
                'weight_kg'        => 0.55,
                'images'           => [
                    ['url' => '/images/products/operator_rig_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/operator_rig_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Vector Weatherproof City Daypack 22L',
                'slug'             => 'vector-weatherproof-city-daypack-22l',
                'description'      => 'Minimalist urban silhouette optimized for lightweight commuting. Features water-resistant polyurethane-coated canvas, concealed vertical security pocket, and padded 15-inch laptop compartment.',
                'price'            => 2450.00,
                'compare_at_price' => 3100.00,
                'stock'            => 20,
                'sku'              => 'APX-VCT-014',
                'weight_kg'        => 0.88,
                'images'           => [
                    ['url' => '/images/products/vector_daypack_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/vector_daypack_angle.jpg', 'is_primary' => false],
                ],
            ],
            [
                'name'             => 'Transit Executive Slim Folio Pack 18L',
                'slug'             => 'transit-executive-slim-folio-pack-18l',
                'description'      => 'Ultra-slim business portfolio pack for executive professionals. Designed with premium full-grain leather trim, document divider sleeves, RFID-shielded pockets, and weather-resistant twill shell.',
                'price'            => 3450.00,
                'compare_at_price' => 4200.00,
                'stock'            => 20,
                'sku'              => 'APX-FLX-015',
                'weight_kg'        => 1.00,
                'images'           => [
                    ['url' => '/images/products/transit_folio_front.jpg', 'is_primary' => true],
                    ['url' => '/images/products/transit_folio_detail.jpg', 'is_primary' => false],
                ],
            ],
        ];

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
