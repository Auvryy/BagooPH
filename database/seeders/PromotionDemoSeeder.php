<?php

namespace Database\Seeders;

use App\Models\Advertisement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\SellerPromotionService;
use App\Services\ShopEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Marketplace banners through the real review flow: a seller submits a promotion, the admin approves and
 * places it, and the admin publishes a Bagoo promotion of their own.
 */
class PromotionDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->where('email', 'admin@bagoo.test')->first();
        $shop = Shop::eligible()->whereHas('user', fn ($q) => $q->where('email', 'seller.food@bagoo.ph'))->first()
            ?? Shop::eligible()->whereHas('products', fn ($q) => $q->where('status', 'active'))->orderBy('id')->first();
        if (! $admin?->isAdmin() || ! $shop || ! config('advertisements.enabled')) {
            $this->command?->warn('Promotion demo skipped: the demo admin or an approved shop is not ready.');

            return;
        }
        $zone = 'Asia/Manila';
        $starts = CarbonImmutable::now($zone)->startOfMinute()->format('Y-m-d\TH:i');
        $ends = CarbonImmutable::now($zone)->addDays(14)->setTime(23, 59)->format('Y-m-d\TH:i');
        $manager = app(AdvertisementManager::class);
        $command = fn (Advertisement $ad, array $data) => ['source_version' => $ad->fresh()->version, 'request_key' => (string) Str::uuid(), ...$data];
        $image = fn (string $name) => new UploadedFile(public_path("images/buyer/advertisements/{$name}.webp"), "{$name}.webp", 'image/webp', null, true);

        $product = Product::where('shop_id', $shop->id)->where('status', 'active')->orderByDesc('sales_count')->get()
            ->first(fn (Product $item) => app(ShopEligibilityService::class)->productIsEligible($item));
        if ($product && ! Advertisement::where('shop_id', $shop->id)->exists()) {
            $sellers = app(SellerPromotionService::class);
            $ad = $sellers->saveDraft($shop->user, ['title' => "{$shop->name} best sellers", 'alt_text' => "Best-selling products from {$shop->name}",
                'summary' => '', 'purpose' => 'feature', 'destination_type' => 'product', 'destination_id' => $product->id, 'offer_revision_id' => null,
                'starts_at' => $starts, 'ends_at' => $ends, 'image' => $image('collection'), 'request_key' => (string) Str::uuid(), 'source_version' => 0]);
            $sellers->act($shop->user, $ad, $command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id]));
            $submission = $ad->submissions()->sole();
            $sellers->decide($admin, $ad, $command($ad, ['decision' => 'approved', 'submission_id' => $submission->id, 'revision_id' => $submission->revision_id, 'claims_reviewed' => true]));
            $manager->act($admin, $ad, $command($ad, ['action' => 'publish', 'submission_id' => $submission->id, 'slot' => 'side_1', 'publish_now' => true, 'claims_reviewed' => true]));
        }

        $picks = Product::where('status', 'active')->orderByDesc('sales_count')->limit(30)->get()
            ->filter(fn (Product $item) => app(ShopEligibilityService::class)->productIsEligible($item))->take(8)->pluck('id')->all();
        if ($picks && ! Advertisement::whereNull('shop_id')->whereHas('revisions', fn ($q) => $q->where('title', 'Bagoo top picks'))->exists()) {
            $ad = $manager->saveDraft($admin, ['title' => 'Bagoo top picks', 'alt_text' => 'A collection of top-rated products on Bagoo', 'purpose' => 'feature',
                'destination_type' => 'collection', 'destination_id' => null, 'product_ids' => $picks, 'slot' => 'side_2',
                'starts_at' => $starts, 'ends_at' => $ends, 'image' => $image('frenzy'), 'source_version' => 0, 'request_key' => (string) Str::uuid()]);
            $manager->act($admin, $ad, $command($ad, ['action' => 'publish', 'publish_now' => true, 'claims_reviewed' => true]));
        }
    }
}
