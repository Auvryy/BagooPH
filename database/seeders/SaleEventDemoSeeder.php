<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\SaleEvent;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Promotions\SaleEventService;
use App\Services\ShopEligibilityService;
use App\Services\Vouchers\VoucherCampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A live and an upcoming sale event with real vouchers (some limited) and real product discounts,
 * all created through the same services the admin and seller pages use.
 */
class SaleEventDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->where('email', 'admin@bagoo.test')->first();
        if (! $admin?->isAdmin() || ! $admin->canAccessPortal()) {
            $this->command?->warn('Sale event demo skipped: the demo admin is not ready.');

            return;
        }
        $events = app(SaleEventService::class);
        $zone = SaleEventService::ZONE;
        $start = CarbonImmutable::now($zone)->startOfMinute();
        $end = $start->addDays(3)->setTime(23, 59);

        $live = SaleEvent::where('slug', 'payday-mega-sale')->first() ?? $events->save($admin, [
            'title' => 'Payday Mega Sale', 'badge' => 'Payday deal',
            'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $end->format('Y-m-d\TH:i'),
        ], null);
        $live->update(['slug' => 'payday-mega-sale', 'banner_path' => $live->banner_path ?? '/images/buyer/advertisements/payday.webp']);

        $christmasStart = CarbonImmutable::create($start->year, 12, 12, 0, 0, 0, $zone);
        if (! SaleEvent::where('slug', '12-12-christmas-sale')->exists() && $christmasStart->isFuture()) {
            $upcoming = $events->save($admin, ['title' => '12.12 Christmas Sale', 'badge' => '12.12 deal',
                'starts_at' => $christmasStart->format('Y-m-d\TH:i'), 'ends_at' => $christmasStart->setTime(23, 59)->format('Y-m-d\TH:i')], null);
            $upcoming->update(['slug' => '12-12-christmas-sale', 'banner_path' => '/images/buyer/advertisements/christmas.webp']);
        }

        $vouchers = [
            ['code' => 'PAYDAYSHIP50', 'name' => 'Payday shipping savings', 'description' => 'Up to ₱50 off the shipping fee.', 'discount_type' => 'free_shipping',
                'discount_value' => '50.00', 'max_discount' => '50.00', 'min_spend' => '0.00', 'claim_limit' => null],
            ['code' => 'PAYDAY100', 'name' => 'Payday ₱100 off', 'description' => '₱100 off orders from ₱699. First 100 buyers only.', 'discount_type' => 'fixed',
                'discount_value' => '100.00', 'max_discount' => '100.00', 'min_spend' => '699.00', 'claim_limit' => 100],
            ['code' => 'PAYDAY15', 'name' => 'Payday 15% off', 'description' => '15% off orders from ₱300, up to ₱150.', 'discount_type' => 'percent',
                'discount_value' => '15.00', 'max_discount' => '150.00', 'min_spend' => '300.00', 'claim_limit' => 500],
        ];
        foreach ($vouchers as $terms) {
            $this->attach($live, $admin, $terms, null, $end);
        }

        // Each demo shop joins with a few products and one limited shop voucher.
        $shops = Shop::eligible()->whereHas('products', fn ($q) => $q->where('status', 'active'))->orderBy('id')->limit(6)->get();
        foreach ($shops as $index => $shop) {
            $products = Product::where('shop_id', $shop->id)->where('status', 'active')->orderByDesc('sales_count')->orderBy('id')->limit(4)->get()
                ->filter(fn (Product $product) => app(ShopEligibilityService::class)->productIsEligible($product))
                ->reject(fn (Product $product) => $product->sales()->whereNull('ended_at')->where('ends_at', '>', now())->exists());
            if ($products->isNotEmpty()) {
                DB::transaction(fn () => $events->addProducts($shop, $live, $products->pluck('id')->all(), [15, 20, 25, 30, 35, 40][$index % 6]));
            }
            $this->attach($live, $shop->user, ['code' => 'SHOP'.$shop->id.'PAYDAY', 'name' => 'Payday shop savings', 'description' => '₱50 off orders from ₱300 in this shop.',
                'discount_type' => 'fixed', 'discount_value' => '50.00', 'max_discount' => '50.00', 'min_spend' => '300.00', 'claim_limit' => 50], $shop, $end);
        }
    }

    private function attach(SaleEvent $event, User $actor, array $terms, ?Shop $shop, CarbonImmutable $end): void
    {
        $limit = $terms['claim_limit'];
        unset($terms['claim_limit']);
        $voucher = Voucher::where('code', $terms['code'])->first();
        if (! $voucher) {
            $service = app(VoucherCampaignService::class);
            $voucher = $service->saveDraft($actor, [...$terms, 'budget' => '20000.00', 'usage_limit' => 1000, 'per_buyer_limit' => 1,
                'starts_at' => now()->startOfMinute()->toIso8601String(), 'ends_at' => $end->utc()->toIso8601String(),
                'request_key' => (string) Str::uuid(), 'source_version' => 0], shop: $shop);
            $service->act($actor, $voucher, ['action' => 'publish', 'request_key' => (string) Str::uuid(), 'source_version' => $voucher->campaign_version], $shop);
        }
        if (! $event->vouchers()->where('voucher_id', $voucher->id)->exists()) {
            app(SaleEventService::class)->addVoucher($actor, $event, ['voucher_id' => $voucher->id, 'claim_limit' => $limit], $shop);
        }
    }
}
