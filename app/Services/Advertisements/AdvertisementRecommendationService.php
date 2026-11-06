<?php

namespace App\Services\Advertisements;

use App\Models\Category;
use App\Models\Product;
use App\Services\Commerce\BuyerDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdvertisementRecommendationService
{
    public const MAX_RECENT_PRODUCT_VIEWS = 50;

    public function rememberRecentProductView(mixed $recentProductViews, int $productId): array
    {
        $seen = [$productId => true];
        $recent = [$productId];
        foreach (is_array($recentProductViews) ? $recentProductViews : [] as $id) {
            if (! (is_int($id) || (is_string($id) && ctype_digit($id)))) {
                continue;
            }
            $id = (int) $id;
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $recent[] = $id;
            if (count($recent) === self::MAX_RECENT_PRODUCT_VIEWS) {
                break;
            }
        }

        return $recent;
    }

    public function rank(array $entries, array $recentProductViews): array
    {
        $recentProductViews = array_slice(array_values(array_filter($recentProductViews,
            fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))), 0, self::MAX_RECENT_PRODUCT_VIEWS);
        $productWeights = [];
        foreach ($recentProductViews as $index => $id) {
            $id = (int) $id;
            if ($id > 0) {
                $productWeights[$id] = ($productWeights[$id] ?? 0) + (self::MAX_RECENT_PRODUCT_VIEWS - $index);
            }
        }
        $recentProducts = Product::availableForSale()->where('status', 'active')->whereIn('id', array_keys($productWeights))
            ->get(['id', 'category_id'])->keyBy('id');
        $categoryWeights = [];
        foreach ($productWeights as $id => $weight) {
            $categoryId = $recentProducts->get($id)?->category_id;
            if ($categoryId) {
                $categoryWeights[$categoryId] = ($categoryWeights[$categoryId] ?? 0) + $weight;
            }
        }

        $destinations = [];
        $allProductIds = [];
        $allCategoryIds = [];
        foreach ($entries as $index => $entry) {
            $publication = $entry['publication'];
            $products = app(AdvertisementDestinationService::class)->products($publication->revision);
            $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
            $categoryIds = $products->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
            if ($publication->revision->destination_type === 'category' && $category = Category::find($publication->revision->destination_id)) {
                $categoryIds = array_values(array_unique([...$categoryIds, ...app(BuyerDiscoveryService::class)->categoryIds($category->slug)]));
            }
            $destinations[$index] = ['products' => $products, 'product_ids' => $productIds, 'category_ids' => $categoryIds];
            $allProductIds = [...$allProductIds, ...$productIds];
            $allCategoryIds = [...$allCategoryIds, ...$categoryIds];
        }

        $publicationIds = array_values(array_map(fn ($entry) => $entry['publication']->id, $entries));
        $recentClicks = [];
        if ($publicationIds && Schema::hasTable('advertisement_daily_statistics')) {
            $cutoff = CarbonImmutable::now('Asia/Manila')->subDays(6)->toDateString();
            $recentClicks = DB::table('advertisement_daily_statistics')->whereIn('publication_id', $publicationIds)->where('day', '>=', $cutoff)
                ->selectRaw('publication_id, SUM(clicks) as clicks')->groupBy('publication_id')->pluck('clicks', 'publication_id')->all();
        }

        $cutoff = CarbonImmutable::now('Asia/Manila')->subDays(6)->toDateString();
        $productViewCounts = [];
        $categoryViewCounts = [];
        if (Schema::hasTable('product_daily_views')) {
            $allProductIds = array_values(array_unique($allProductIds));
            $allCategoryIds = array_values(array_unique($allCategoryIds));
            if ($allProductIds) {
                $productViewCounts = DB::table('product_daily_views')->whereIn('product_id', $allProductIds)->where('day', '>=', $cutoff)
                    ->selectRaw('product_id, SUM(views) as views')->groupBy('product_id')->pluck('views', 'product_id')->all();
            }
            if ($allCategoryIds) {
                $categoryViewCounts = DB::table('product_daily_views')->join('products', 'products.id', '=', 'product_daily_views.product_id')
                    ->whereIn('products.category_id', $allCategoryIds)->where('product_daily_views.day', '>=', $cutoff)
                    ->selectRaw('products.category_id as category_id, SUM(product_daily_views.views) as views')->groupBy('products.category_id')->pluck('views', 'category_id')->all();
            }
        }

        foreach ($entries as $index => &$entry) {
            $publication = $entry['publication'];
            $products = $destinations[$index]['products'];
            $productIds = $destinations[$index]['product_ids'];
            $categoryIds = $destinations[$index]['category_ids'];
            $exactActivity = 0;
            foreach ($productIds as $productId) {
                $exactActivity += $productWeights[$productId] ?? 0;
            }
            $categoryActivity = 0;
            foreach ($categoryIds as $categoryId) {
                $categoryActivity += $categoryWeights[$categoryId] ?? 0;
            }
            $productViews = 0;
            foreach ($productIds as $productId) {
                $productViews += (int) ($productViewCounts[$productId] ?? 0);
            }
            $categoryViews = 0;
            foreach ($categoryIds as $categoryId) {
                $categoryViews += (int) ($categoryViewCounts[$categoryId] ?? 0);
            }
            $productViewSignal = (int) floor(log(1 + $productViews, 2) * 100);
            $categoryViewSignal = (int) floor(log(1 + $categoryViews, 2) * 60);
            $clickSignal = min(500, (int) ($recentClicks[$publication->id] ?? 0)) * 2;
            $salesSignal = min(1000, (int) $products->sum('sales_count'));
            $entry['score'] = ($exactActivity * 1000) + ($categoryActivity * 100)
                + $productViewSignal + $categoryViewSignal + $clickSignal + $salesSignal;
        }
        unset($entry);

        usort($entries, fn ($a, $b) => ($b['score'] <=> $a['score'])
            ?: strcmp($a['banner']['slot'], $b['banner']['slot'])
            ?: ($a['publication']->id <=> $b['publication']->id));

        return array_map(fn ($entry) => $entry['banner'], $entries);
    }
}
