<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Support\Facades\DB;

class LowStockNoticeService
{
    public const THRESHOLD = 5;

    public function saved(Product $product, ?int $previousStock, bool $created): void
    {
        $low = $product->stock <= self::THRESHOLD;
        $crossed = ! $created && $product->status === 'active' && ! $product->compliance_restricted
            && $previousStock > self::THRESHOLD && $low;
        $cycle = (int) $product->low_stock_cycle + ($crossed ? 1 : 0);
        if ($crossed || (bool) $product->low_stock_active !== $low) {
            DB::table('products')->where('id', $product->id)->update(['low_stock_active' => $low, 'low_stock_cycle' => $cycle]);
            $product->forceFill(['low_stock_active' => $low, 'low_stock_cycle' => $cycle])->syncOriginalAttributes(['low_stock_active', 'low_stock_cycle']);
        }
        if ($crossed && ($sellerId = $product->shop()->value('user_id'))) {
            app(NotificationDeliveryService::class)->record($sellerId, 'inventory-event', 'inventory:'.$product->id.':'.$cycle, [
                'title' => $product->stock === 0 ? 'A product is out of stock' : 'A product is running low',
                'body' => $product->name.' has '.$product->stock.' units left. Review its option stock before restocking.',
                'target' => 'seller-products', 'product_id' => $product->id,
            ]);
        }
    }
}
