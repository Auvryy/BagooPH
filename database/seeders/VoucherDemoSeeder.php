<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VoucherDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->where('email', 'admin@bagoo.test')->first();
        $shop = Shop::eligible()->whereHas('user', fn ($q) => $q->where('email', 'seller@bagoo.test'))->first();
        if (! $admin?->isAdmin() || ! $admin->canAccessPortal() || ! $shop) {
            return;
        }
        $campaigns = [
            ['code' => 'DEMO_SHOP100', 'name' => 'Shop savings', 'description' => 'Save ₱100 on selected products from this shop.',
                'discount_type' => 'fixed', 'discount_value' => '100.00', 'max_discount' => '100.00', 'actor' => $shop->user, 'shop' => $shop],
            ['code' => 'DEMO_BAGOO10', 'name' => 'Bagoo product savings', 'description' => 'Save 10% after shop discounts, up to ₱100.',
                'discount_type' => 'percent', 'discount_value' => '10.00', 'max_discount' => '100.00', 'actor' => $admin, 'shop' => null],
            ['code' => 'DEMO_SHIP30', 'name' => 'Bagoo shipping savings', 'description' => 'Reduce an actual shipping fee by up to ₱30.',
                'discount_type' => 'free_shipping', 'discount_value' => '30.00', 'max_discount' => '30.00', 'actor' => $admin, 'shop' => null],
        ];
        foreach ($campaigns as $campaign) {
            // Reseeding never revives a paused/ended offer, resets a quota or rewrites recorded terms.
            if (Voucher::where('code', $campaign['code'])->exists()) {
                continue;
            }
            $actor = $campaign['actor'];
            $owner = $campaign['shop'];
            unset($campaign['actor'], $campaign['shop']);
            $service = app(VoucherCampaignService::class);
            $voucher = $service->saveDraft($actor, [...$campaign, 'min_spend' => '500.00', 'budget' => '10000.00',
                'usage_limit' => 100, 'per_buyer_limit' => 2, 'starts_at' => now()->subMinute()->toIso8601String(),
                'ends_at' => now()->addDays(14)->toIso8601String(), 'request_key' => (string) Str::uuid(), 'source_version' => 0], shop: $owner);
            $service->act($actor, $voucher, ['action' => 'publish', 'request_key' => (string) Str::uuid(), 'source_version' => $voucher->campaign_version], $owner);
        }
    }
}
