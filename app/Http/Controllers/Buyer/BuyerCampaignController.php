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
                'title' => 'Bagoo 11.11 Mega Sale Grand Kickoff',
                'tagline' => 'PHILIPPINES BIGGEST YEAR-END SHOPPING FRENZY',
                'description' => 'Score up to 90% Off on verified authentic brands, claim unlimited ₱0 Min Spend Free Shipping vouchers, and unlock hourly flash price drops directly from registered local merchants.',
                'theme' => [
                    'bg_gradient' => 'from-[#7F0019] via-[#4D000E] to-[#1A0005]',
                    'accent_color' => '#FFD700',
                    'primary_btn' => 'bg-[#E00D42] hover:bg-[#C20836] text-white',
                    'badge_bg' => 'bg-[#E00D42]',
                    'badge_text' => '11.11 MEGA DEAL',
                    'border_accent' => 'border-amber-500/30',
                ],
                'banner_img' => '/images/buyer/advertisements/mega-sale.webp',
                'badge' => '11.11 FESTIVAL',
                'ends_at' => now()->addHours(14)->addMinutes(32)->toIso8601String(),
                'vouchers' => [
                    [
                        'id' => 'v1',
                        'code' => '1111FREESHIP',
                        'title' => '₱0 Free Shipping',
                        'subtitle' => 'No minimum spend required',
                        'discount' => 'FREE SHIP',
                        'badge' => 'PLATFORM SPONSORED',
                        'claimed' => 74,
                    ],
                    [
                        'id' => 'v2',
                        'code' => 'MEGA11COINS',
                        'title' => '15% Coin Cashback',
                        'subtitle' => 'Valid across all 14 official categories',
                        'discount' => '15% BACK',
                        'badge' => 'COIN DROP',
                        'claimed' => 88,
                    ],
                    [
                        'id' => 'v3',
                        'code' => 'MEGA11KICK',
                        'title' => '₱111 Off Storewide',
                        'subtitle' => 'Min spend ₱699 on any verified merchant',
                        'discount' => '₱111 OFF',
                        'badge' => 'LIMITED DROP',
                        'claimed' => 62,
                    ],
                ],
            ],
            'christmas-gala' => [
                'slug' => 'christmas-gala',
                'title' => 'Paskong Pinoy Christmas Grand Gala',
                'tagline' => 'NATIONWIDE HOLIDAY GIFTING & MARKDOWNS',
                'description' => 'Celebrate the Christmas season with curated holiday gifts, bundled discounts, free festive delivery packaging, and up to 80% savings on top home decor, treats, apparel, and electronics.',
                'theme' => [
                    'bg_gradient' => 'from-[#0D3821] via-[#1A0A0E] to-[#120306]',
                    'accent_color' => '#F59E0B',
                    'primary_btn' => 'bg-emerald-600 hover:bg-emerald-700 text-white',
                    'badge_bg' => 'bg-emerald-600',
                    'badge_text' => 'CHRISTMAS SPECIAL',
                    'border_accent' => 'border-emerald-500/30',
                ],
                'banner_img' => '/images/buyer/advertisements/christmas.webp',
                'badge' => 'HOLIDAY GALA',
                'ends_at' => now()->addDays(2)->addHours(8)->toIso8601String(),
                'vouchers' => [
                    [
                        'id' => 'vc1',
                        'code' => 'PASKO2026',
                        'title' => '₱0 Holiday Express Delivery',
                        'subtitle' => 'Nationwide insured doorstep delivery',
                        'discount' => 'FREE SHIP',
                        'badge' => 'SANTA PASS',
                        'claimed' => 81,
                    ],
                    [
                        'id' => 'vc2',
                        'code' => 'SANTA150',
                        'title' => '₱150 Off Gift Baskets',
                        'subtitle' => 'Min spend ₱750 across food and lifestyle',
                        'discount' => '₱150 OFF',
                        'badge' => 'GIFT BUNDLE',
                        'claimed' => 69,
                    ],
                ],
            ],
            'halloween-spree' => [
                'slug' => 'halloween-spree',
                'title' => 'Bagoo Halloween Spooky Spree',
                'tagline' => 'MIDNIGHT FLASH STEALS & SPOOKY DISCOUNTS',
                'description' => 'Unleash terrifyingly good deals with midnight flash markdowns, exclusive novelty collections, costume specials, and treats without the tricks from registered Philippine merchants.',
                'theme' => [
                    'bg_gradient' => 'from-[#2B0E44] via-[#1F092E] to-[#0D0414]',
                    'accent_color' => '#FB923C',
                    'primary_btn' => 'bg-amber-600 hover:bg-amber-700 text-white',
                    'badge_bg' => 'bg-amber-600',
                    'badge_text' => 'SPOOKY STEAL',
                    'border_accent' => 'border-amber-500/30',
                ],
                'banner_img' => '/images/buyer/advertisements/halloween.webp',
                'badge' => 'MIDNIGHT SPREE',
                'ends_at' => now()->addHours(6)->addMinutes(45)->toIso8601String(),
                'vouchers' => [
                    [
                        'id' => 'vh1',
                        'code' => 'SPOOKYSHIP',
                        'title' => '₱0 Midnight Shipping',
                        'subtitle' => 'Zero minimum spend on midnight cart orders',
                        'discount' => 'FREE SHIP',
                        'badge' => 'MIDNIGHT PASS',
                        'claimed' => 85,
                    ],
                    [
                        'id' => 'vh2',
                        'code' => 'TREAT13',
                        'title' => '13% Spooky Discount',
                        'subtitle' => 'Min spend ₱399 on fashion, decor & gear',
                        'discount' => '13% OFF',
                        'badge' => 'TRICK OR TREAT',
                        'claimed' => 77,
                    ],
                ],
            ],
            'mega-carnival' => [
                'slug' => 'mega-carnival',
                'title' => 'Grand Celebration Mega Carnival',
                'tagline' => 'ANNIVERSARY GRAND FIESTA DISCOUNTS',
                'description' => 'Celebrate the Bagoo Marketplace Anniversary Gala with platform-wide flash promotions, exclusive store coupons, VIP buyer cashback, and verified merchant showcases.',
                'theme' => [
                    'bg_gradient' => 'from-[#4A0E17] via-[#24060B] to-[#120205]',
                    'accent_color' => '#FBBF24',
                    'primary_btn' => 'bg-[#E00D42] hover:bg-[#C20836] text-white',
                    'badge_bg' => 'bg-[#E00D42]',
                    'badge_text' => 'CARNIVAL PICK',
                    'border_accent' => 'border-amber-500/30',
                ],
                'banner_img' => '/images/buyer/advertisements/carnival.webp',
                'badge' => 'ANNIVERSARY GALA',
                'ends_at' => now()->addDays(3)->toIso8601String(),
                'vouchers' => [
                    [
                        'id' => 'vca1',
                        'code' => 'CARNIVALFREE',
                        'title' => '₱0 All-Day Free Shipping',
                        'subtitle' => 'Zero spend threshold nationwide',
                        'discount' => 'FREE SHIP',
                        'badge' => 'CELEBRATION PASS',
                        'claimed' => 89,
                    ],
                    [
                        'id' => 'vca2',
                        'code' => 'FIESTA90',
                        'title' => 'Up to ₱200 Off Orders',
                        'subtitle' => 'Min spend ₱800 on participating shops',
                        'discount' => '₱200 OFF',
                        'badge' => 'VIP REWARD',
                        'claimed' => 64,
                    ],
                ],
            ],
            'payday-savers' => [
                'slug' => 'payday-savers',
                'title' => 'Payday Super Savers Mega Sale',
                'tagline' => 'STRETCH YOUR SWELDO WITH DIRECT DISCOUNTS',
                'description' => 'Maximize your hard-earned pay with essential groceries, fashion upgrades, electronics, and household staples priced at direct merchant factory markdowns.',
                'theme' => [
                    'bg_gradient' => 'from-[#0F172A] via-[#091024] to-[#020617]',
                    'accent_color' => '#38BDF8',
                    'primary_btn' => 'bg-sky-600 hover:bg-sky-700 text-white',
                    'badge_bg' => 'bg-sky-600',
                    'badge_text' => 'SWELDO SAVER',
                    'border_accent' => 'border-sky-500/30',
                ],
                'banner_img' => '/images/buyer/advertisements/payday.webp',
                'badge' => 'PAYDAY PROMO',
                'ends_at' => now()->addDays(1)->addHours(11)->toIso8601String(),
                'vouchers' => [
                    [
                        'id' => 'vp1',
                        'code' => 'SWELDOFREE',
                        'title' => '₱0 Sweldo Express Courier',
                        'subtitle' => 'Free shipping on orders over ₱0',
                        'discount' => 'FREE SHIP',
                        'badge' => 'SWELDO PASS',
                        'claimed' => 79,
                    ],
                    [
                        'id' => 'vp2',
                        'code' => 'PAYDAY100',
                        'title' => '₱100 Off Sweldo Cart',
                        'subtitle' => 'Min spend ₱500 on all verified listings',
                        'discount' => '₱100 OFF',
                        'badge' => 'PAYDAY DEALS',
                        'claimed' => 84,
                    ],
                ],
            ],
            'electronics-frenzy' => [
                'slug' => 'electronics-frenzy',
                'title' => 'Electronics & Audio Lifestyle Frenzy',
                'tagline' => 'TOP-TIER GADGETS & SOUND AT DROP-DOWN PRICES',
                'description' => 'Upgrade your daily driver tech with genuine smart devices, studio audio monitors, EDC gear, and accessories with guaranteed merchant authenticity.',
                'theme' => [
                    'bg_gradient' => 'from-[#0B1528] via-[#050C1A] to-[#02050A]',
                    'accent_color' => '#34D399',
                    'primary_btn' => 'bg-emerald-600 hover:bg-emerald-700 text-white',
                    'badge_bg' => 'bg-emerald-600',
                    'badge_text' => 'TECH STEAL',
                    'border_accent' => 'border-emerald-500/30',
                ],
                'banner_img' => '/images/buyer/advertisements/frenzy.webp',
                'badge' => 'TECH FRENZY',
                'ends_at' => now()->addDays(4)->toIso8601String(),
                'vouchers' => [
                    [
                        'id' => 've1',
                        'code' => 'TECHSHIP',
                        'title' => 'Free Fragile Care Shipping',
                        'subtitle' => 'Bubble-wrapped insured dispatch on all gadgets',
                        'discount' => 'FREE SHIP',
                        'badge' => 'INSURED CARE',
                        'claimed' => 71,
                    ],
                ],
            ],
        ];

        $campaign = $campaigns[$event] ?? $campaigns['11-11-mega-sale'];

        // Pull active vouchers from database and link revision IDs for instant claiming
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

        // Fetch products and decorate with campaign markdowns
        $products = Product::with(['shop' => fn ($s) => $s->withReviewSummary(), 'category', 'subCategory'])
            ->availableForSale()
            ->withReviewSummary()
            ->where('status', 'active')
            ->orderBy('id', 'desc')
            ->take(28)
            ->get()
            ->map(function ($product, $idx) use ($campaign) {
                $multiplier = 1.25 + (($product->id * 3) % 25) / 100;
                $originalPrice = round($product->price * $multiplier, 2);
                $discountPct = round((1 - ($product->price / $originalPrice)) * 100);
                $claimed = 45 + (($product->id * 9) % 50);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'original_price' => $originalPrice,
                    'discount_pct' => $discountPct,
                    'cover_image' => $product->cover_image,
                    'slug' => $product->slug,
                    'shop' => $product->shop ? [
                        'id' => $product->shop->id,
                        'name' => $product->shop->name,
                        'city' => $product->shop->city,
                        'pickup_province' => $product->shop->pickup_province,
                        'rating' => $product->shop->rating,
                    ] : null,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'slug' => $product->category->slug,
                    ] : null,
                    'sub_category' => $product->subCategory ? [
                        'id' => $product->subCategory->id,
                        'name' => $product->subCategory->name,
                    ] : null,
                    'review_summary' => $product->reviewSummary,
                    'event_badge' => $campaign['theme']['badge_text'],
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
