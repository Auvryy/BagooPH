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
                'TechCore Gadgets PH' => ['address' => 'Cyberzone, Aurora Blvd, Cubao', 'city' => 'Quezon City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1100', 'pickup_latitude' => 14.6208, 'pickup_longitude' => 121.0531],
                'Atelier Femme Studio' => ['address' => 'Salcedo Village, L.P. Leviste St.', 'city' => 'Makati City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1200', 'pickup_latitude' => 14.5596, 'pickup_longitude' => 121.0218],
                'Urban Threads Manila' => ['address' => 'Escolta St, Binondo Heritage District', 'city' => 'Manila', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1000', 'pickup_latitude' => 14.5992, 'pickup_longitude' => 120.9749],
                'Little Wonders Nursery & Toys' => ['address' => 'Calle Marcella Agoncillo, Poblacion', 'city' => 'Taal', 'pickup_province' => 'Batangas', 'pickup_postal_code' => '4208', 'pickup_latitude' => 13.8808, 'pickup_longitude' => 120.9235],
                'Living Space Home & Garden' => ['address' => 'Brgy. Bayanihan, Silangan Highway', 'city' => 'Dolores', 'pickup_province' => 'Quezon', 'pickup_postal_code' => '4326', 'pickup_latitude' => 14.0156, 'pickup_longitude' => 121.4014],
                'Summit Trail & Fitness' => ['address' => 'Canlubang Commercial Complex', 'city' => 'Calamba City', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4027', 'pickup_latitude' => 14.2059, 'pickup_longitude' => 121.1169],
                'PureGlow Skincare Studio' => ['address' => 'Poblacion East Commercial Strip', 'city' => 'Ibaan', 'pickup_province' => 'Batangas', 'pickup_postal_code' => '4230', 'pickup_latitude' => 13.8186, 'pickup_longitude' => 121.1333],
                'The Media Collector PH' => ['address' => 'Poblacion Heritage District', 'city' => 'Santa Cruz', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4009', 'pickup_latitude' => 14.2814, 'pickup_longitude' => 121.4161],
                'Artisan Pantry Gourmet' => ['address' => 'P. Paterno St., Poblacion I', 'city' => 'Nagcarlan', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4002', 'pickup_latitude' => 14.1369, 'pickup_longitude' => 121.4139],
                'MotoGear Performance PH' => ['address' => 'Bucal Bypass Road, National Highway', 'city' => 'Calamba City', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4029', 'pickup_latitude' => 14.1872, 'pickup_longitude' => 121.1684],
                'ErgoWork Workspace Furniture' => ['address' => 'Sala Business Park, National Hwy', 'city' => 'Cabuyao City', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4025', 'pickup_latitude' => 14.2775, 'pickup_longitude' => 121.1233],
                'Heritage Horology & Gems' => ['address' => 'Ongpin St, Chinatown District', 'city' => 'Manila', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1008', 'pickup_latitude' => 14.6011, 'pickup_longitude' => 120.9765],
                'Papercraft & Scholar Supplies' => ['address' => 'Katipunan Ave, Loyola Heights', 'city' => 'Quezon City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1105', 'pickup_latitude' => 14.6368, 'pickup_longitude' => 121.0746],
                'Apex Tactical & Carry Goods' => ['address' => 'Taft Ave, Ermita Commercial Plaza', 'city' => 'Manila', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1004', 'pickup_latitude' => 14.5812, 'pickup_longitude' => 120.9845],
                'Manila Pet Supplies Depot' => ['address' => 'Bel-Air Village, Jupiter St.', 'city' => 'Makati City', 'pickup_province' => 'Metro Manila', 'pickup_postal_code' => '1230', 'pickup_latitude' => 14.5621, 'pickup_longitude' => 121.0258],
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
                        'password' => Hash::make('Password1234'),
                        'role' => 'seller',
                        'status' => 'active',
                        'kyc_status' => 'approved',
                        'email_verified_at' => now(),
                        'phone' => '+63917'.str_pad((string) mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
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
                        'pickup_latitude' => $loc['pickup_latitude'] ?? null,
                        'pickup_longitude' => $loc['pickup_longitude'] ?? null,
                        'status' => 'active',
                        'review_status' => 'approved',
                        'is_default' => true,
                    ]);
                } else {
                    $locationChanged = $shop->city !== $loc['city']
                        || $shop->pickup_province !== ($loc['pickup_province'] ?? null)
                        || $shop->pickup_postal_code !== ($loc['pickup_postal_code'] ?? null);
                    $shop->update([
                        'address' => $loc['address'],
                        'city' => $loc['city'],
                        'pickup_province' => $loc['pickup_province'] ?? null,
                        'pickup_postal_code' => $loc['pickup_postal_code'] ?? null,
                    ]);
                    if (($locationChanged || $shop->pickup_latitude === null || $shop->pickup_longitude === null)
                        && isset($loc['pickup_latitude'], $loc['pickup_longitude'])) {
                        $shop->update([
                            'pickup_latitude' => $loc['pickup_latitude'],
                            'pickup_longitude' => $loc['pickup_longitude'],
                        ]);
                    }
                    if ($locationChanged) {
                        $seller->update([
                            'city' => $loc['city'],
                            'address' => $loc['address'],
                            'postal_code' => $loc['pickup_postal_code'] ?? $seller->postal_code,
                        ]);
                    }
                }

                // Admin review decision for shop eligibility
                $currentReview = $shop->currentReview;
                $needsNewDecision = ! $currentReview
                    || $currentReview->decision !== 'approved'
                    || $currentReview->reviewer_role !== 'admin'
                    || (int) $currentReview->seller_id !== (int) $seller->id
                    || (int) $currentReview->root_category_id !== (int) $category->id
                    || ($currentReview->submission['shop']['name'] ?? null) !== $shop->name
                    || ($currentReview->submission['shop']['address'] ?? null) !== $shop->address
                    || ($currentReview->submission['shop']['city'] ?? null) !== $shop->city;

                $decision = $needsNewDecision
                    ? ShopReviewDecision::create([
                        'shop_id' => $shop->id,
                        'decision' => 'approved',
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
                            'status' => $shop->status ?? 'active',
                            'review_status' => 'approved',
                            'reviewed_at' => now()->toISOString(),
                            'review_feedback' => 'Store passes business identity verification and authorized department inspection.',
                            'shop' => [
                                'name' => $shop->name,
                                'address' => $shop->address,
                                'city' => $shop->city,
                                'status' => $shop->status ?? 'active',
                            ],
                        ],
                        'submission' => [
                            'shop' => [
                                'name' => $shop->name,
                                'address' => $shop->address,
                                'city' => $shop->city,
                            ],
                        ],
                    ])
                    : $currentReview;

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

                    // Scraped titles carry HTML entities ("&amp;", "&#x27;") and double spaces; store plain text.
                    $prodName = trim(preg_replace('/\s+/u', ' ', html_entity_decode($prodData['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if (mb_strlen($prodName) < 5) {
                        $prodName = $category->name.' - '.$prodData['query'];
                    }
                    if (mb_strlen($prodName) > 120) {
                        $prodName = mb_substr($prodName, 0, 117).'...';
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
                            'weight_kg' => (is_numeric($existingProduct->weight_kg) && (float) $existingProduct->weight_kg > 0)
                                ? $existingProduct->weight_kg
                                : round(mt_rand(20, 350) / 100, 2),
                            'length_cm' => (is_numeric($existingProduct->length_cm) && (float) $existingProduct->length_cm > 0)
                                ? $existingProduct->length_cm
                                : 25.00,
                            'width_cm' => (is_numeric($existingProduct->width_cm) && (float) $existingProduct->width_cm > 0)
                                ? $existingProduct->width_cm
                                : 18.00,
                            'height_cm' => (is_numeric($existingProduct->height_cm) && (float) $existingProduct->height_cm > 0)
                                ? $existingProduct->height_cm
                                : 10.00,
                            'status' => 'active',
                        ]);

                        continue;
                    }

                    $skuPrefix = strtoupper(substr(str_replace('-', '', $categorySlug), 0, 4));
                    $sku = "BGO-{$skuPrefix}-".str_pad((string) ($pIdx + 1), 3, '0', STR_PAD_LEFT);
                    while (Product::where('sku', $sku)->exists()) {
                        $sku = "BGO-{$skuPrefix}-".mt_rand(100, 999);
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
                        'length_cm' => 25.00,
                        'width_cm' => 18.00,
                        'height_cm' => 10.00,
                        'status' => 'active',
                        // Ratings and sales come only from real orders and reviews.
                        'rating' => 0,
                        'sales_count' => 0,
                        'brand' => 'Authentic Marketplace Goods',
                        'condition' => 'new',
                    ]);
                }
            }
        });
    }
}
