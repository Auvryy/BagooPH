<?php

namespace App\Services\Commerce;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuyerProductViewTracker
{
    public const MAX_COUNTED_PRODUCTS_PER_SESSION_DAY = 50;

    public function record(Product $product, Request $request): void
    {
        if (! Schema::hasTable('product_daily_views')) {
            return;
        }

        $day = CarbonImmutable::now('Asia/Manila')->toDateString();
        $state = $request->session()->get('buyer.counted_product_views', []);
        $viewed = is_array($state) && ($state['day'] ?? null) === $day && is_array($state['ids'] ?? null)
            ? $state['ids'] : [];
        $viewed = array_values(array_unique(array_filter($viewed,
            fn ($id) => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0)));

        if (in_array($product->id, array_map('intval', $viewed), true)
            || count($viewed) >= self::MAX_COUNTED_PRODUCTS_PER_SESSION_DAY) {
            return;
        }

        $inserted = DB::table('product_daily_views')->insertOrIgnore([
            'product_id' => $product->id,
            'day' => $day,
            'views' => 1,
        ]);
        if (! $inserted) {
            DB::table('product_daily_views')->where('product_id', $product->id)->where('day', $day)->increment('views');
        }

        $viewed[] = $product->id;
        $request->session()->put('buyer.counted_product_views', ['day' => $day, 'ids' => $viewed]);
    }

    public function prune(int $retentionDays = 14): int
    {
        if (! Schema::hasTable('product_daily_views')) {
            return 0;
        }

        $cutoff = CarbonImmutable::now('Asia/Manila')->subDays(max(7, $retentionDays) - 1)->toDateString();

        return DB::table('product_daily_views')->where('day', '<', $cutoff)->delete();
    }
}
