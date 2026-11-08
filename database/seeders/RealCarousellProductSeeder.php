<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RealCarousellProductSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/carousell_products.json');
        if (! file_exists($jsonPath)) {
            $this->command?->error("JSON file not found: {$jsonPath}");

            return;
        }

        $categoriesData = json_decode(file_get_contents($jsonPath), true);

        // Department to subcategory matching rules
        $subCategoryMapping = [
            'pet-supplies' => ['dog-food-and-treats', 'pet-grooming-products'],
            'electronics-and-gadgets' => ['audio-and-video-equipment', 'wearable-technology'],
            'womens-apparel' => ['dresses-and-skirts', 'jackets-and-coats'],
            'mens-apparel' => ['tees-and-tops', 'casual-shirts-and-pants'],
            'kids-and-baby' => ['baby-clothes-and-accessories', 'toys-and-games'],
            'home-and-garden' => ['furniture-and-decor', 'kitchen-appliances'],
            'sports-and-outdoors' => ['camping-and-hiking-gear', 'fitness-equipment'],
            'health-and-beauty' => ['skincare-products', 'makeup-and-cosmetics'],
            'books-and-media' => ['fiction-and-nonfiction-books', 'video-games-and-consoles'],
            'food-and-gourmet' => ['coffee-tea-and-beverages', 'specialty-foods-and-international-cuisine'],
            'automotive-motorcycle-group-9' => ['protective-gear', 'electrical-components'],
            'furniture-and-office-equipment' => ['office-desks-and-chairs', 'computer-tables-and-workstations'],
            'jewelry-and-watches' => ['watches-for-men-and-women', 'necklaces-and-pendants'],
            'office-and-school-supplies-group-12' => ['writing-instruments', 'notebooks-and-paper-products'],
            'backpacks-and-bags' => ['crossbody-slings', 'commuter-backpacks'],
        ];

        DB::transaction(function () use ($categoriesData, $subCategoryMapping) {
            $admin = User::where('role', 'admin')->first() ?: User::factory()->create([
                'name' => 'System Governance Admin',
                'email' => 'admin.audit@bagoo.ph',
                'role' => 'admin',
                'status' => 'active',
            ]);

            $shopLocations = [
                'TechCore Gadgets PH' => ['address' => 'Cyberzone, Aurora Blvd, Cubao', 'city' => 'Quezon City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1100'],
                'Atelier Femme Studio' => ['address' => 'Salcedo Village, L.P. Leviste St.', 'city' => 'Makati City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1200'],
                'Urban Threads Manila' => ['address' => 'Escolta St, Binondo Heritage District', 'city' => 'Manila', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1000'],
                'Little Wonders Nursery & Toys' => ['address' => 'Tomas Morato Ave, South Triangle', 'city' => 'Quezon City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1104'],
                'Living Space Home & Garden' => ['address' => 'Batong Malake, Forestry Road', 'city' => 'Los Banos', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4030'],
                'Summit Trail & Fitness' => ['address' => 'Canlubang Commercial Complex', 'city' => 'Calamba City', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4027'],
                'PureGlow Skincare Studio' => ['address' => 'Legazpi Village, Dela Rosa St.', 'city' => 'Makati City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1203'],
                'The Media Collector PH' => ['address' => 'Poblacion Heritage District', 'city' => 'Santa Cruz', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4009'],
                'Artisan Pantry Gourmet' => ['address' => 'Poblacion, Anos Highway', 'city' => 'Los Banos', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4030'],
                'MotoGear Performance PH' => ['address' => 'Bucal Bypass Road, National Highway', 'city' => 'Calamba City', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4029'],
                'ErgoWork Workspace Furniture' => ['address' => 'Poblacion, Century City Walk', 'city' => 'Makati City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1210'],
                'Heritage Horology & Gems' => ['address' => 'Ongpin St, Chinatown District', 'city' => 'Manila', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1008'],
                'Papercraft & Scholar Supplies' => ['address' => 'Katipunan Ave, Loyola Heights', 'city' => 'Quezon City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1105'],
                'Apex Tactical & Carry Goods' => ['address' => 'Taft Ave, Ermita Commercial Plaza', 'city' => 'Manila', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1004'],
                'Manila Pet Supplies Depot' => ['address' => 'Bel-Air Village, Jupiter St.', 'city' => 'Makati City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1230'],
            ];

            foreach ($categoriesData as $catData) {
                $categorySlug = $catData['category_slug'];
                $category = Category::where('slug', $categorySlug)->first();
                if (! $category) {
                    continue;
                }

                $loc = $shopLocations[$catData['shop_name']] ?? [
                    'address' => 'Bonifacio Global City, Taguig, Metro Manila',
                    'city' => 'Taguig',
                    'pickup_province' => 'Metro Manila',
                    'pickup_postal_code' => '1630',
                ];

                // 1. Create or Find Verified Seller Account
                $seller = User::firstOrCreate(
                    ['email' => $catData['seller_email']],
                    [
                        'name' => $catData['seller_name'],
                        'password' => Hash::make('Password123!'),
                        'role' => 'seller',
                        'status' => 'active',
                        'kyc_status' => 'approved',
                        'email_verified_at' => now(),
                        'phone' => '+63917' . str_pad((string) mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                        'city' => $loc['city'],
                        'address' => $loc['address'],
                        'postal_code' => $loc['pickup_postal_code'] ?? null,
                        'birthday' => '1992-05-15',
                    ]
                );

                // 2. Create or Find Approved Shop for this Category
                $shopSlug = Str::slug($catData['shop_name']);
                $shop = Shop::where('user_id', $seller->id)->first();
                if (! $shop) {
                    $shop = Shop::create([
                        'user_id' => $seller->id,
                        'root_category_id' => $category->id,
                        'name' => $catData['shop_name'],
                        'slug' => $shopSlug,
                        'description' => "Verified official store for {$category->name} on BagooPH with guaranteed authentic stock, secure parcel packing, and fast doorstep COD fulfillment.",
                        'phone' => $seller->phone,
                        'address' => $loc['address'],
                        'city' => $loc['city'],
                        'pickup_province' => $loc['pickup_province'] ?? null,
                        'pickup_postal_code' => $loc['pickup_postal_code'] ?? null,
                        'status' => 'active',
                        'review_status' => 'approved',
                        'is_default' => true,
                    ]);
                } else {
                    $shop->update([
                        'address' => $loc['address'],
                        'city' => $loc['city'],
                        'pickup_province' => $loc['pickup_province'] ?? null,
                        'pickup_postal_code' => $loc['pickup_postal_code'] ?? null,
                    ]);
                }

                // Admin review decision for shop eligibility
                $decision = ShopReviewDecision::firstOrCreate(
                    [
                        'shop_id' => $shop->id,
                        'decision' => 'approved',
                    ],
                    [
                        'reviewer_id' => $admin->id,
                        'reviewer_role' => 'admin',
                        'reviewer_name' => $admin->name,
                        'submission_token' => (string) Str::uuid(),
                        'reason' => 'Store passes business identity verification and authorized department inspection.',
                        'reviewed_at' => now(),
                        'seller_id' => $seller->id,
                        'root_category_id' => $category->id,
                        'before_state' => [],
                        'after_state' => [
                            'shop' => [
                                'name' => $shop->name,
                                'address' => $shop->address,
                                'city' => $shop->city,
                            ],
                        ],
                        'submission' => [
                            'shop' => [
                                'name' => $shop->name,
                                'address' => $shop->address,
                                'city' => $shop->city,
                            ],
                        ],
                    ]
                );

                $shop->update([
                    'review_decision_id' => $decision->id,
                    'review_status' => 'approved',
                    'status' => 'active',
                ]);

                // 3. Seed 2 Real Products from Carousell
                $subSlugs = $subCategoryMapping[$categorySlug] ?? [];

                foreach ($catData['products'] as $pIdx => $prodData) {
                    $subSlug = $prodData['sub_slug'] ?? ($subSlugs[$pIdx] ?? null);
                    $subCategory = $subSlug ? SubCategory::where('slug', $subSlug)->first() : null;

                    $prodName = trim($prodData['title']);
                    if (strlen($prodName) < 5) {
                        $prodName = $category->name . ' - ' . $prodData['query'];
                    }
                    if (strlen($prodName) > 120) {
                        $prodName = substr($prodName, 0, 117) . '...';
                    }

                    $existingProduct = Product::where('shop_id', $shop->id)
                        ->where(function ($q) use ($prodData, $prodName) {
                            $q->where('featured_image', $prodData['image_path'])
                                ->orWhere('name', $prodName);
                        })
                        ->first();

                    if ($existingProduct) {
                        $existingProduct->update([
                            'name' => $prodName,
                            'featured_image' => $prodData['image_path'],
                            'price' => $prodData['price'],
                            'compare_at_price' => $prodData['compare_at_price'],
                            'sub_category_id' => $subCategory?->id,
                            'status' => 'active',
                        ]);
                        continue;
                    }

                    $skuPrefix = strtoupper(substr(str_replace('-', '', $categorySlug), 0, 4));
                    $sku = "BGO-{$skuPrefix}-" . str_pad((string) ($pIdx + 1), 3, '0', STR_PAD_LEFT);
                    while (Product::where('sku', $sku)->exists()) {
                        $sku = "BGO-{$skuPrefix}-" . mt_rand(100, 999);
                    }

                    $slug = Product::generateUniqueSlug($prodName);

                    Product::create([
                        'shop_id' => $shop->id,
                        'category_id' => $category->id,
                        'sub_category_id' => $subCategory?->id,
                        'name' => $prodName,
                        'slug' => $slug,
                        'description' => "Authentic verified {$prodName}. High quality preloved/new item in pristine condition with complete accessories and safe protective packaging. Available nationwide with Doorstep COD via BagooPH Express.",
                        'price' => $prodData['price'],
                        'compare_at_price' => $prodData['compare_at_price'],
                        'stock' => mt_rand(12, 45),
                        'sku' => $sku,
                        'featured_image' => $prodData['image_path'],
                        'weight_kg' => round(mt_rand(20, 350) / 100, 2),
                        'status' => 'active',
                        'rating' => round(4.7 + (mt_rand(0, 3) / 10), 1),
                        'sales_count' => mt_rand(45, 680),
                        'brand' => 'Authentic Marketplace Goods',
                        'condition' => 'new',
                    ]);
                }
            }
        });
    }
}
