<?php

namespace App\Services\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SellerOrderFilters
{
    public function selection(Request $request): array
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);

        return ['search' => trim($data['search'] ?? ''), 'from' => $data['from'] ?? '', 'to' => $data['to'] ?? ''];
    }

    public function apply(Builder $orders, array $filters, int $shopId): Builder
    {
        if ($filters['search'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
            $orders->where(fn (Builder $query) => $query
                ->whereRaw("LOWER(orders.order_number) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(orders.recipient_name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('delivery', fn ($delivery) => $delivery->whereRaw("LOWER(tracking_number) LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('items', fn ($items) => $items->where('shop_id', $shopId)->whereHas('product',
                    fn ($product) => $product->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern]))));
        }
        if ($filters['from'] !== '') {
            $orders->where('orders.created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($filters['to'] !== '') {
            $orders->where('orders.created_at', '<', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->startOfDay()->addDay()->utc());
        }

        return $orders;
    }
}
