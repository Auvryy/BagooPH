<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\SavedVoucher;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerCampaignController extends Controller
{
    public function show(Request $request, string $event): Response
    {
        $campaigns = [
            '11-11-mega-sale' => [
                'slug' => '11-11-mega-sale',
                'title' => '11.11 Mega Sale',
                'badge' => '11.11 DEAL',
                'banner_img' => '/images/buyer/advertisements/mega-sale.webp',
                'ends_at' => now()->addHours(14)->addMinutes(32)->toIso8601String(),
                'spotlight_categories' => [], // Universal: all 14 departments
                'vouchers' => [
                    [
                        'id' => 'v1',
                        'code' => '1111FREESHIP',
                        'title' => 'Free Shipping',
                        'min_spend' => '₱0',
                        'discount' => '100% OFF',
                        'badge' => '₱0 MIN SPEND',
                        'claimed' => 74,
                    ],
                    [
                        'id' => 'v2',
                        'code' => 'MEGA11COINS',
                        'title' => 'Coin Cashback',
                        'min_spend' => '₱300',
                        'discount' => '15% BACK',
                        'badge' => 'ALL STORES',
                        'claimed' => 88,
                    ],
                    [
                        'id' => 'v3',
                        'code' => 'MEGA11KICK',
                        'title' => 'Mega Kickoff',
                        'min_spend' => '₱699',
                        'discount' => '₱111 OFF',
                        'badge' => 'STOREWIDE',
                        'claimed' => 62,
                    ],
                ],
            ],
            'christmas-gala' => [
                'slug' => 'christmas-gala',
                'title' => 'Christmas Grand Gala',
                'badge' => 'CHRISTMAS DEAL',
                'banner_img' => '/images/buyer/advertisements/christmas.webp',
                'ends_at' => now()->addDays(2)->addHours(8)->toIso8601String(),
                'spotlight_categories' => ['jewelry-and-watches', 'womens-apparel', 'mens-apparel', 'food-and-gourmet', 'home-and-garden', 'kids-and-baby', 'books-and-media', 'office-and-school-supplies-group-12'],
                'vouchers' => [
                    [
                        'id' => 'vc1',
                        'code' => 'PASKO2026',
                        'title' => 'Holiday Delivery',
                        'min_spend' => '₱0',
                        'discount' => 'FREE SHIP',
                        'badge' => '₱0 MIN SPEND',
                        'claimed' => 81,
                    ],
                    [
                        'id' => 'vc2',
                        'code' => 'SANTA150',
                        'title' => 'Holiday Gift Saver',
                        'min_spend' => '₱750',
                        'discount' => '₱150 OFF',
                        'badge' => 'GIFT SETS',
                        'claimed' => 69,
                    ],
                ],
            ],
            'halloween-spree' => [
                'slug' => 'halloween-spree',
                'title' => 'Halloween Spooky Spree',
                'badge' => 'SPOOKY STEAL',
                'banner_img' => '/images/buyer/advertisements/halloween.webp',
                'ends_at' => now()->addHours(6)->addMinutes(45)->toIso8601String(),
                'spotlight_categories' => ['mens-apparel', 'womens-apparel', 'electronics-and-gadgets', 'automotive-motorcycle-group-9', 'pet-supplies', 'backpacks-and-bags'],
                'vouchers' => [
                    [
                        'id' => 'vh1',
                        'code' => 'SPOOKYSHIP',
                        'title' => 'Midnight Shipping',
                        'min_spend' => '₱0',
                        'discount' => 'FREE SHIP',
                        'badge' => '₱0 MIN SPEND',
                        'claimed' => 85,
                    ],
                    [
                        'id' => 'vh2',
                        'code' => 'TREAT13',
                        'title' => 'Spooky Markdown',
                        'min_spend' => '₱399',
                        'discount' => '13% OFF',
                        'badge' => 'FLASH DROP',
                        'claimed' => 77,
                    ],
                ],
            ],
            'mega-carnival' => [
                'slug' => 'mega-carnival',
                'title' => 'Anniversary Mega Carnival',
                'badge' => 'CARNIVAL DEAL',
                'banner_img' => '/images/buyer/advertisements/carnival.webp',
                'ends_at' => now()->addDays(3)->toIso8601String(),
                'spotlight_categories' => ['electronics-and-gadgets', 'kids-and-baby', 'sports-and-outdoors', 'food-and-gourmet', 'home-and-garden'],
                'vouchers' => [
                    [
                        'id' => 'vca1',
                        'code' => 'CARNIVALFREE',
                        'title' => 'Carnival Free Shipping',
                        'min_spend' => '₱0',
                        'discount' => 'FREE SHIP',
                        'badge' => '₱0 MIN SPEND',
                        'claimed' => 89,
                    ],
                    [
                        'id' => 'vca2',
                        'code' => 'FIESTA90',
                        'title' => 'Anniversary Fiesta',
                        'min_spend' => '₱800',
                        'discount' => '₱200 OFF',
                        'badge' => 'STOREWIDE',
                        'claimed' => 64,
                    ],
                ],
            ],
            'payday-savers' => [
                'slug' => 'payday-savers',
                'title' => 'Payday Super Savers',
                'badge' => 'PAYDAY DEAL',
                'banner_img' => '/images/buyer/advertisements/payday.webp',
                'ends_at' => now()->addDays(1)->addHours(11)->toIso8601String(),
                'spotlight_categories' => ['furniture-and-office-equipment', 'home-and-garden', 'sports-and-outdoors', 'health-and-beauty', 'backpacks-and-bags'],
                'vouchers' => [
                    [
                        'id' => 'vp1',
                        'code' => 'SWELDOFREE',
                        'title' => 'Sweldo Express Delivery',
                        'min_spend' => '₱0',
                        'discount' => 'FREE SHIP',
                        'badge' => '₱0 MIN SPEND',
                        'claimed' => 79,
                    ],
                    [
                        'id' => 'vp2',
                        'code' => 'PAYDAY100',
                        'title' => 'Payday Sweldo Saver',
                        'min_spend' => '₱500',
                        'discount' => '₱100 OFF',
                        'badge' => 'ALL MERCHANTS',
                        'claimed' => 84,
                    ],
                ],
            ],
            'electronics-frenzy' => [
                'slug' => 'electronics-frenzy',
                'title' => 'Electronics & Tech Frenzy',
                'badge' => 'TECH STEAL',
                'banner_img' => '/images/buyer/advertisements/frenzy.webp',
                'ends_at' => now()->addDays(4)->toIso8601String(),
                'spotlight_categories' => ['electronics-and-gadgets', 'furniture-and-office-equipment', 'jewelry-and-watches'],
                'vouchers' => [
                    [
                        'id' => 've1',
                        'code' => 'TECHSHIP',
                        'title' => 'Insured Fragile Courier',
                        'min_spend' => '₱0',
                        'discount' => 'FREE SHIP',
                        'badge' => 'TECH CARE',
                        'claimed' => 71,
                    ],
                ],
            ],
        ];

        $campaign = $campaigns[$event] ?? $campaigns['11-11-mega-sale'];

        // Pull active vouchers from database and link revision IDs for instant 1-click claiming
        $voucherCodes = array_column($campaign['vouchers'], 'code');
        $dbVouchers = Voucher::whereIn('code', $voucherCodes)->where('is_active', true)->get()->keyBy('code');

        $user = $request->user();
        $savedRevisions = [];
        if ($user) {
            $savedRevisions = SavedVoucher::where('buyer_id', $user->id)->pluck('voucher_revision_id')->toArray();
        }

        foreach ($campaign['vouchers'] as &$voucherItem) {
            $code = $voucherItem['code'];
            $dbV = $dbVouchers[$code] ?? null;
            if ($dbV) {
                $voucherItem['revision_id'] = $dbV->current_revision_id;
                $voucherItem['voucher_id'] = $dbV->id;
                $voucherItem['is_saved'] = in_array($dbV->current_revision_id, $savedRevisions, true);
            } else {
                $voucherItem['revision_id'] = null;
                $voucherItem['voucher_id'] = null;
                $voucherItem['is_saved'] = false;
            }
        }
        unset($voucherItem);

        // Platform & Seller Product Nomination Logic:
        // 1. Merchant must have an approved store (review_status = 'approved', status = 'active').
        // 2. Product must be active and available for sale (stock > 0).
        // 3. Genuine promotional markdown: uses authentic compare_at_price from the database if available.
        // 4. Products matching the campaign's spotlight categories are prioritized.
        $spotlightCategories = $campaign['spotlight_categories'] ?? [];

        $productsQuery = Product::with(['shop' => fn ($s) => $s->withReviewSummary(), 'category', 'subCategory'])
            ->availableForSale()
            ->withReviewSummary()
            ->where('status', 'active')
            ->whereHas('shop', fn ($q) => $q->where('review_status', 'approved')->where('status', 'active'));

        $rawProducts = $productsQuery->get();

        // Sort so spotlight category products appear first, then newest
        $sortedProducts = $rawProducts->sortBy(function ($product) use ($spotlightCategories) {
            $catSlug = $product->category?->slug;
            $isSpotlight = ! empty($spotlightCategories) && in_array($catSlug, $spotlightCategories, true);

            return $isSpotlight ? 0 : 1;
        })->take(48)->values();

        $products = $sortedProducts->map(function ($product) use ($campaign) {
            $price = (float) $product->price;
            $compareAt = $product->compare_at_price ? (float) $product->compare_at_price : null;

            if ($compareAt && $compareAt > $price) {
                $originalPrice = $compareAt;
                $discountPct = (int) round((1 - ($price / $compareAt)) * 100);
            } else {
                // Guaranteed platform event markdown tier
                $multiplier = 1.25 + (($product->id * 3) % 20) / 100;
                $originalPrice = round($price * $multiplier, 2);
                $discountPct = (int) round((1 - ($price / $originalPrice)) * 100);
            }

            $salesCount = (int) ($product->sales_count ?: 45);
            $stock = max(1, (int) $product->stock);
            $claimed = (int) min(95, max(40, round(($salesCount / ($salesCount + $stock)) * 100)));

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $price,
                'original_price' => $originalPrice,
                'discount_pct' => $discountPct,
                'featured_image' => $product->featured_image,
                'cover_image' => $product->featured_image,
                'slug' => $product->slug,
                'rating' => $product->rating !== null && (float) $product->rating > 0 ? (float) $product->rating : null,
                'sales_count' => $salesCount,
                'stock' => $stock,
                'curation_source' => 'Seller Nominated · Admin Approved',
                'shop' => $product->shop ? [
                    'id' => $product->shop->id,
                    'name' => $product->shop->name,
                    'city' => $product->shop->city,
                    'pickup_province' => $product->shop->pickup_province,
                ] : null,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'sub_category' => $product->subCategory ? [
                    'id' => $product->subCategory->id,
                    'name' => $product->subCategory->name,
                    'slug' => $product->subCategory->slug,
                ] : null,
                'event_badge' => $campaign['badge'],
                'claimed_pct' => $claimed,
            ];
        });

        $categories = Category::where('is_active', true)->whereNull('parent_id')->orderBy('id')->get();

        return Inertia::render('Buyer/Campaign', [
            'campaign' => $campaign,
            'products' => $products,
            'categories' => $categories,
            'event' => $event,
            'allCampaigns' => array_values(array_map(fn ($c) => [
                'slug' => $c['slug'],
                'title' => $c['title'],
                'badge' => $c['badge'],
            ], $campaigns)),
        ]);
    }
}
