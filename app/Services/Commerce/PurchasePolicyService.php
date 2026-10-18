<?php

namespace App\Services\Commerce;

use App\Models\OrderItem;
use App\Models\Product;
use RuntimeException;

class PurchasePolicyService
{
    public function assertShopAccepting(Product $product): void
    {
        if ($product->shop?->vacation_mode) {
            throw new RuntimeException("{$product->shop->name} is on vacation. You can keep this item in your Bag and buy it when the shop returns.");
        }
    }

    public function remaining(Product $product, ?int $buyerId): ?int
    {
        if (! $product->purchase_limit) {
            return null;
        }
        $purchased = $buyerId ? OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($order) => $order->where('buyer_id', $buyerId)->where('status', '!=', 'cancelled'))->sum('quantity') : 0;

        return max(0, $product->purchase_limit - (int) $purchased);
    }

    public function assertQuantity(Product $product, ?int $buyerId, int $quantity): void
    {
        $remaining = $this->remaining($product, $buyerId);
        if ($remaining !== null && $quantity > $remaining) {
            throw new RuntimeException("'{$product->name}' has a limit of {$product->purchase_limit} per buyer across orders. You can buy {$remaining} more. Cancelled orders do not count.");
        }
    }
}
