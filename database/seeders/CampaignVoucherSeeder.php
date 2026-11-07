<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CampaignVoucherSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        if (! $admin) {
            return;
        }

        $campaigns = [
            // 11.11 Mega Sale
            [
                'code' => '1111FREESHIP',
                'name' => '11.11 Free Shipping ₱0 Min Spend',
                'description' => 'Platform sponsored free doorstep shipping voucher across all participating stores.',
                'discount_type' => 'free_shipping',
                'discount_value' => '100.00',
                'min_spend' => '0.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 2000,
                'per_buyer_limit' => 5,
            ],
            [
                'code' => 'MEGA11COINS',
                'name' => '11.11 15% Coin Cashback',
                'description' => '15% platform cashback rewards on all 14 curated marketplace departments.',
                'discount_type' => 'percent',
                'discount_value' => '15.00',
                'min_spend' => '300.00',
                'max_discount' => '150.00',
                'budget' => '100000.00',
                'usage_limit' => 1500,
                'per_buyer_limit' => 3,
            ],
            [
                'code' => 'MEGA11KICK',
                'name' => '11.11 Mega Kickoff ₱111 Off',
                'description' => '₱111 instant storewide checkout discount on orders over ₱699.',
                'discount_type' => 'fixed',
                'discount_value' => '111.00',
                'min_spend' => '699.00',
                'max_discount' => '111.00',
                'budget' => '100000.00',
                'usage_limit' => 1000,
                'per_buyer_limit' => 2,
            ],

            // Christmas Grand Gala
            [
                'code' => 'PASKO2026',
                'name' => 'Pasko Holiday Free Shipping',
                'description' => '₱0 min spend nationwide holiday express delivery on gifts and lifestyle items.',
                'discount_type' => 'free_shipping',
                'discount_value' => '100.00',
                'min_spend' => '0.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 2000,
                'per_buyer_limit' => 5,
            ],
            [
                'code' => 'SANTA150',
                'name' => 'Santa ₱150 Gift Baskets Deal',
                'description' => '₱150 holiday discount on holiday gift hampers, lifestyle sets and treats.',
                'discount_type' => 'fixed',
                'discount_value' => '150.00',
                'min_spend' => '750.00',
                'max_discount' => '150.00',
                'budget' => '100000.00',
                'usage_limit' => 1000,
                'per_buyer_limit' => 2,
            ],

            // Halloween Spooky Spree
            [
                'code' => 'SPOOKYSHIP',
                'name' => 'Midnight Spooky Free Shipping',
                'description' => '₱0 min spend flash shipping pass for midnight Halloween cart checkouts.',
                'discount_type' => 'free_shipping',
                'discount_value' => '100.00',
                'min_spend' => '0.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 1500,
                'per_buyer_limit' => 3,
            ],
            [
                'code' => 'TREAT13',
                'name' => 'Spooky Treat 13% Off',
                'description' => '13% festive markdown across fashion, toys, decor, and tactical gear.',
                'discount_type' => 'percent',
                'discount_value' => '13.00',
                'min_spend' => '399.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 1000,
                'per_buyer_limit' => 2,
            ],

            // Anniversary Mega Carnival
            [
                'code' => 'CARNIVALFREE',
                'name' => 'Anniversary Carnival Free Shipping',
                'description' => 'All-day free shipping celebration pass with zero threshold.',
                'discount_type' => 'free_shipping',
                'discount_value' => '100.00',
                'min_spend' => '0.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 2000,
                'per_buyer_limit' => 5,
            ],
            [
                'code' => 'FIESTA90',
                'name' => 'Anniversary Fiesta ₱200 Off',
                'description' => '₱200 discount for orders over ₱800 during the Grand Anniversary Carnival.',
                'discount_type' => 'fixed',
                'discount_value' => '200.00',
                'min_spend' => '800.00',
                'max_discount' => '200.00',
                'budget' => '100000.00',
                'usage_limit' => 1000,
                'per_buyer_limit' => 2,
            ],

            // Payday Super Savers
            [
                'code' => 'SWELDOFREE',
                'name' => 'Payday Sweldo Free Delivery',
                'description' => 'Direct merchant free doorstep shipping for payday essentials.',
                'discount_type' => 'free_shipping',
                'discount_value' => '100.00',
                'min_spend' => '0.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 2000,
                'per_buyer_limit' => 5,
            ],
            [
                'code' => 'PAYDAY100',
                'name' => 'Payday ₱100 Sweldo Saver',
                'description' => '₱100 off when you spend ₱500 or more across any merchant.',
                'discount_type' => 'fixed',
                'discount_value' => '100.00',
                'min_spend' => '500.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 1500,
                'per_buyer_limit' => 3,
            ],

            // Electronics Frenzy
            [
                'code' => 'TECHSHIP',
                'name' => 'Fragile Care Tech Free Shipping',
                'description' => 'Bubble-wrapped insured dispatch for electronics, gadgets, and audio.',
                'discount_type' => 'free_shipping',
                'discount_value' => '100.00',
                'min_spend' => '0.00',
                'max_discount' => '100.00',
                'budget' => '100000.00',
                'usage_limit' => 1500,
                'per_buyer_limit' => 3,
            ],
        ];

        $service = app(VoucherCampaignService::class);

        foreach ($campaigns as $data) {
            if (Voucher::where('code', $data['code'])->exists()) {
                continue;
            }

            $voucher = $service->saveDraft($admin, [
                ...$data,
                'starts_at' => now()->startOfMinute()->toIso8601String(),
                'ends_at' => now()->addDays(60)->startOfMinute()->toIso8601String(),
                'request_key' => (string) Str::uuid(),
                'source_version' => 0,
            ], shop: null);

            $service->act($admin, $voucher, [
                'action' => 'publish',
                'request_key' => (string) Str::uuid(),
                'source_version' => $voucher->campaign_version,
            ], null);
        }
    }
}
