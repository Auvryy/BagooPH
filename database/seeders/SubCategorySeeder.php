<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubCategorySeeder extends Seeder
{
    public function run(): void
    {
        $taxonomy = [
            'mens-apparel' => [
                'name' => "Men's Apparel",
                'image' => '/images/products/boxy_tee_front.jpg',
                'subs' => [
                    ['name' => 'Tees & Tops', 'slug' => 'tees-and-tops', 'sort_order' => 1],
                    ['name' => 'Outerwear', 'slug' => 'mens-outerwear', 'sort_order' => 2],
                    ['name' => 'Pants & Bottoms', 'slug' => 'pants-and-bottoms', 'sort_order' => 3],
                    ['name' => 'Underwear & Basics', 'slug' => 'underwear-and-basics', 'sort_order' => 4],
                    ['name' => 'Headwear', 'slug' => 'headwear', 'sort_order' => 5],
                    ['name' => 'Footwear', 'slug' => 'footwear', 'sort_order' => 6],
                ],
            ],
            'backpacks-and-bags' => [
                'name' => 'Bags & Everyday Carry (EDC)',
                'image' => '/images/products/stealth_sling_front.jpg',
                'subs' => [
                    ['name' => 'Crossbody Slings & Chest Packs', 'slug' => 'crossbody-slings', 'sort_order' => 1],
                    ['name' => 'Commuter Backpacks & Roll-Tops', 'slug' => 'commuter-backpacks', 'sort_order' => 2],
                    ['name' => 'Modular Tech Pouches & Cable Organizers', 'slug' => 'tech-pouches-and-organizers', 'sort_order' => 3],
                    ['name' => 'Wallets & Leathercraft', 'slug' => 'wallets-and-leathercraft', 'sort_order' => 4],
                    ['name' => 'EDC Hardware', 'slug' => 'edc-hardware', 'sort_order' => 5],
                    ['name' => 'Travel & Duffel Bags', 'slug' => 'travel-and-duffels', 'sort_order' => 6],
                ],
            ],
            'electronics-and-gadgets' => [
                'name' => 'Electronics & Gadgets',
                'image' => '/images/categories/electronics_audio.jpg',
                'subs' => [
                    ['name' => 'Power & Charging', 'slug' => 'power-and-charging', 'sort_order' => 1],
                    ['name' => 'Audio & Sound', 'slug' => 'audio-and-sound', 'sort_order' => 2],
                    ['name' => 'Mobile Accessories', 'slug' => 'mobile-accessories', 'sort_order' => 3],
                    ['name' => 'Desk Setup', 'slug' => 'desk-setup', 'sort_order' => 4],
                    ['name' => 'Smart Living & Ambient Lighting', 'slug' => 'smart-living', 'sort_order' => 5],
                    ['name' => 'Content Creation', 'slug' => 'content-creation', 'sort_order' => 6],
                ],
            ],
            'jewelry-and-watches' => [
                'name' => 'Jewelry & Watches',
                'image' => '/images/products/field_watch_front.jpg',
                'subs' => [
                    ['name' => 'Tactical & Field Watches', 'slug' => 'tactical-field-watches', 'sort_order' => 1],
                    ['name' => 'Automatic Divers & Chronographs', 'slug' => 'automatic-divers-chronographs', 'sort_order' => 2],
                    ['name' => 'Watch Straps', 'slug' => 'watch-straps', 'sort_order' => 3],
                    ['name' => 'Paracord Survival Bracelets & Cuffs', 'slug' => 'survival-bracelets-cuffs', 'sort_order' => 4],
                    ['name' => 'Chains, Rings & Pendants', 'slug' => 'chains-rings-pendants', 'sort_order' => 5],
                ],
            ],
            'sports-and-outdoors' => [
                'name' => 'Sports, Outdoor & Tactical',
                'image' => '/images/products/tactical_gloves_front.jpg',
                'subs' => [
                    ['name' => 'Tactical Wear', 'slug' => 'tactical-wear', 'sort_order' => 1],
                    ['name' => 'Camping & Trekking', 'slug' => 'camping-and-trekking', 'sort_order' => 2],
                    ['name' => 'Hydration', 'slug' => 'hydration-gear', 'sort_order' => 3],
                    ['name' => 'Multi-Tools & Hardware', 'slug' => 'multitools-and-hardware', 'sort_order' => 4],
                    ['name' => 'Fitness Gear', 'slug' => 'fitness-gear', 'sort_order' => 5],
                    ['name' => 'Cycling Accessories', 'slug' => 'cycling-accessories', 'sort_order' => 6],
                ],
            ],
            'bagoo-demo-accessories-5' => [
                'name' => 'Eyewear & Accessories',
                'image' => '/images/products/aviator_glasses_front.jpg',
                'subs' => [
                    ['name' => 'Polarized Sunglasses', 'slug' => 'polarized-sunglasses', 'sort_order' => 1],
                    ['name' => 'Blue-Light & Computer Glasses', 'slug' => 'bluelight-glasses', 'sort_order' => 2],
                    ['name' => 'Eyewear Storage & Cases', 'slug' => 'eyewear-storage', 'sort_order' => 3],
                    ['name' => 'Apparel Accessories & Belts', 'slug' => 'apparel-accessories', 'sort_order' => 4],
                ],
            ],
            'home-and-garden' => [
                'name' => 'Home, Studio & Living',
                'image' => '/images/carousel/botanical-candle.jpg',
                'subs' => [
                    ['name' => 'Aromatherapy & Candles', 'slug' => 'aromatherapy-and-candles', 'sort_order' => 1],
                    ['name' => 'Coffee & Tableware', 'slug' => 'coffee-and-tableware', 'sort_order' => 2],
                    ['name' => 'Desk Organization', 'slug' => 'desk-organization', 'sort_order' => 3],
                    ['name' => 'Ambient Lighting & Lamps', 'slug' => 'ambient-lighting', 'sort_order' => 4],
                    ['name' => 'Linens & Quick-Dry Throws', 'slug' => 'linens-and-throws', 'sort_order' => 5],
                ],
            ],
            'womens-apparel' => [
                'name' => "Women's Apparel & Fashion",
                'image' => '/images/categories/womens_apparel.jpg',
                'subs' => [
                    ['name' => 'Tops & Blouses', 'slug' => 'womens-tops-and-blouses', 'sort_order' => 1],
                    ['name' => 'Dresses & Skirts', 'slug' => 'dresses-and-skirts', 'sort_order' => 2],
                    ['name' => 'Outerwear & Jackets', 'slug' => 'womens-outerwear', 'sort_order' => 3],
                    ['name' => 'Pants & Trousers', 'slug' => 'womens-pants-and-trousers', 'sort_order' => 4],
                    ['name' => 'Loungewear & Intimates', 'slug' => 'loungewear-and-intimates', 'sort_order' => 5],
                    ['name' => 'Shoulder Bags & Everyday Totes', 'slug' => 'shoulder-bags-and-totes', 'sort_order' => 6],
                ],
            ],
            'health-and-beauty' => [
                'name' => 'Health, Grooming & Beauty',
                'image' => '/images/categories/health_beauty.jpg',
                'subs' => [
                    ['name' => "Men's Grooming", 'slug' => 'mens-grooming', 'sort_order' => 1],
                    ['name' => 'Skincare & Sunscreen', 'slug' => 'skincare', 'sort_order' => 2],
                    ['name' => 'Fragrances & Colognes', 'slug' => 'fragrances', 'sort_order' => 3],
                    ['name' => 'Personal Care Appliances', 'slug' => 'personal-care-appliances', 'sort_order' => 4],
                    ['name' => 'Daily Wellness & Supplements', 'slug' => 'daily-wellness', 'sort_order' => 5],
                ],
            ],
            'automotive-motorcycle-group-9' => [
                'name' => 'Automotive & Motorcycle Gear',
                'image' => '/images/categories/automotive.jpg',
                'subs' => [
                    ['name' => 'Rider Protective Gear', 'slug' => 'rider-protective-gear', 'sort_order' => 1],
                    ['name' => 'Cockpit & Phone Mounts', 'slug' => 'cockpit-mounts', 'sort_order' => 2],
                    ['name' => 'Helmets & Visor Accessories', 'slug' => 'helmets-and-visors', 'sort_order' => 3],
                    ['name' => 'Car & Bike Detailing', 'slug' => 'detailing-and-maintenance', 'sort_order' => 4],
                    ['name' => 'Motorcycle Luggage & Bags', 'slug' => 'motorcycle-luggage', 'sort_order' => 5],
                ],
            ],
            'food-and-gourmet' => [
                'name' => 'Food, Gourmet & Coffee',
                'image' => '/images/categories/food_coffee.jpg',
                'subs' => [
                    ['name' => 'Philippine Single-Origin Coffee', 'slug' => 'single-origin-coffee', 'sort_order' => 1],
                    ['name' => 'Local Treats & Snacks', 'slug' => 'local-treats-and-snacks', 'sort_order' => 2],
                    ['name' => 'Artisan Condiments & Sauces', 'slug' => 'artisan-condiments', 'sort_order' => 3],
                    ['name' => 'Cold Brew Concentrates & Teas', 'slug' => 'cold-brew-and-teas', 'sort_order' => 4],
                ],
            ],
            'office-and-school-supplies-group-12' => [
                'name' => 'Office & School Supplies',
                'image' => '/images/categories/office_school.jpg',
                'subs' => [
                    ['name' => 'Notebooks & Journals', 'slug' => 'notebooks-and-journals', 'sort_order' => 1],
                    ['name' => 'Writing Instruments & Pens', 'slug' => 'writing-instruments', 'sort_order' => 2],
                    ['name' => 'Desk Organizers & Cases', 'slug' => 'office-desk-organizers', 'sort_order' => 3],
                    ['name' => 'Canvas Sleeves & Tech Bags', 'slug' => 'canvas-sleeves-and-pouches', 'sort_order' => 4],
                ],
            ],
            'kids-and-baby' => [
                'name' => 'Kids, Baby & Toys',
                'image' => '/images/categories/kids_baby.jpg',
                'subs' => [
                    ['name' => 'Baby Apparel & Onesies', 'slug' => 'baby-apparel', 'sort_order' => 1],
                    ['name' => 'Developmental & Wooden Toys', 'slug' => 'developmental-toys', 'sort_order' => 2],
                    ['name' => 'Feeding & Nursery Gear', 'slug' => 'nursery-and-feeding', 'sort_order' => 3],
                ],
            ],
            'pet-supplies' => [
                'name' => 'Pet Supplies',
                'image' => '/images/categories/pet_supplies.jpg',
                'subs' => [
                    ['name' => 'Dog Gear & Tactical Harnesses', 'slug' => 'dog-gear', 'sort_order' => 1],
                    ['name' => 'Cat Accessories & Scratchers', 'slug' => 'cat-accessories', 'sort_order' => 2],
                    ['name' => 'Pet Health & Grooming', 'slug' => 'pet-health-and-grooming', 'sort_order' => 3],
                ],
            ],
        ];

        DB::transaction(function () use ($taxonomy) {
            foreach ($taxonomy as $catSlug => $catData) {
                $category = Category::where('slug', $catSlug)->first();
                if ($category) {
                    $category->update([
                        'image' => $catData['image'],
                    ]);

                    foreach ($catData['subs'] as $sub) {
                        SubCategory::updateOrCreate(
                            ['slug' => $sub['slug']],
                            [
                                'category_id' => $category->id,
                                'name' => $sub['name'],
                                'sort_order' => $sub['sort_order'],
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }

            // Product associations
            $productSubMap = [
                'APX-BRI-001' => 'underwear-and-basics',
                'BGO-BRIE-IQCIW' => 'underwear-and-basics',
                'APX-VNG-001' => 'commuter-backpacks',
                'APX-NMD-004' => 'tech-pouches-and-organizers',
                'APX-GRP-011' => 'commuter-backpacks',
                'APX-STL-002' => 'crossbody-slings',
                'APX-HRZ-003' => 'travel-and-duffels',
                'APX-TAC-005' => 'crossbody-slings',
                'APX-KNT-006' => 'crossbody-slings',
                'APX-TRN-006' => 'commuter-backpacks',
                'APX-TRN-007' => 'commuter-backpacks',
                'APX-CBR-007' => 'apparel-accessories',
                'APX-BLT-008' => 'apparel-accessories',
                'APX-HRW-008' => 'wallets-and-leathercraft',
                'APX-WLT-009' => 'wallets-and-leathercraft',
                'APX-WTC-009' => 'tactical-field-watches',
                'APX-WTC-010' => 'tactical-field-watches',
                'APX-AVT-010' => 'polarized-sunglasses',
                'APX-GLS-011' => 'polarized-sunglasses',
                'APX-CAP-012' => 'headwear',
                'APX-CRB-013' => 'edc-hardware',
                'APX-PRC-014' => 'survival-bracelets-cuffs',
                'APX-BRC-014' => 'survival-bracelets-cuffs',
                'APX-GLV-015' => 'tactical-wear',
                'APX-TEE-016' => 'tees-and-tops',
                'APX-STP-017' => 'watch-straps',
            ];

            foreach ($productSubMap as $sku => $subSlug) {
                $sub = SubCategory::where('slug', $subSlug)->first();
                if ($sub) {
                    DB::table('products')->where('sku', $sku)->update(['sub_category_id' => $sub->id]);
                }
            }

            // Keyword-based fallback for other seeded products without APX sku
            $nameSubMap = [
                'Headphones' => 'audio-and-sound',
                'Keyboard' => 'desk-setup',
                'Mouse' => 'desk-setup',
                'Flask' => 'hydration-gear',
                'Task Lamp' => 'ambient-lighting',
                'Coffee Press' => 'coffee-and-tableware',
            ];

            foreach ($nameSubMap as $keyword => $subSlug) {
                $sub = SubCategory::where('slug', $subSlug)->first();
                if ($sub) {
                    DB::table('products')->where('name', 'like', "%{$keyword}%")
                        ->whereNull('sub_category_id')
                        ->update(['sub_category_id' => $sub->id]);
                }
            }
        });
    }
}
