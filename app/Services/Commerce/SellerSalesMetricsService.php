<?php

namespace App\Services\Commerce;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SellerSalesMetricsService
{
    /**
     * @return Builder<OrderItem>
     */
    public function openItems(int $shopId): Builder
    {
        return OrderItem::query()
            ->where('shop_id', $shopId)
            ->whereHas('order', fn (Builder $query) => $query
                ->whereIn('status', OrderStatus::openCommerceStatuses()));
    }

    /**
     * @return Builder<OrderItem>
     */
    public function completedItems(int $shopId): Builder
    {
        return OrderItem::query()
            ->where('shop_id', $shopId)
            ->whereHas('order', fn (Builder $query) => $query
                ->whereIn('status', OrderStatus::completedCommerceStatuses()));
    }

    /**
     * @return Builder<OrderItem>
     */
    public function completedItemsBetween(int $shopId, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->completedItems($shopId)
            ->whereHas('order', fn (Builder $query) => $query
                ->whereBetween('completed_at', [$from, $to]));
    }

    /**
     * @return array{
     *     completedGrossSales: float,
     *     completedUnits: int,
     *     completedOrderCount: int,
     *     averageCompletedOrderValue: float,
     *     estimatedSellerShare: float,
     *     openOrderValue: float,
     *     openUnits: int,
     *     openOrderCount: int
     * }
     */
    public function dashboardSummary(int $shopId): array
    {
        $completedItems = $this->completedItems($shopId);
        $openItems = $this->openItems($shopId);

        $completedGrossSales = round((float) (clone $completedItems)->sum('subtotal'), 2);
        $completedUnits = (int) (clone $completedItems)->sum('quantity');
        $completedOrderCount = (int) (clone $completedItems)->distinct()->count('order_id');
        $openOrderValue = round((float) (clone $openItems)->sum('subtotal'), 2);

        return [
            'completedGrossSales' => $completedGrossSales,
            'completedUnits' => $completedUnits,
            'completedOrderCount' => $completedOrderCount,
            'averageCompletedOrderValue' => $completedOrderCount > 0
                ? round($completedGrossSales / $completedOrderCount, 2)
                : 0.0,
            'estimatedSellerShare' => round($completedGrossSales * 0.90, 2),
            'openOrderValue' => $openOrderValue,
            'openUnits' => (int) (clone $openItems)->sum('quantity'),
            'openOrderCount' => (int) (clone $openItems)->distinct()->count('order_id'),
        ];
    }

    /**
     * @return list<array{date: string, revenue: float, units: int}>
     */
    public function sevenDayCompletedSales(int $shopId): array
    {
        $days = [];
        $today = CarbonImmutable::today();

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);
            $items = $this->completedItemsBetween($shopId, $day->startOfDay(), $day->endOfDay());

            $days[] = [
                'date' => $day->format('M j'),
                'revenue' => round((float) (clone $items)->sum('subtotal'), 2),
                'units' => (int) (clone $items)->sum('quantity'),
            ];
        }

        return $days;
    }

    /**
     * Add seller-facing lifecycle totals without changing the public
     * marketplace sales counter.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withProductLifecycleTotals(Builder $query): Builder
    {
        return $query
            ->withSum([
                'orderItems as completed_units' => fn (Builder $items) => $items
                    ->whereHas('order', fn (Builder $orders) => $orders
                        ->whereIn('status', OrderStatus::completedCommerceStatuses())),
            ], 'quantity')
            ->withSum([
                'orderItems as open_order_units' => fn (Builder $items) => $items
                    ->whereHas('order', fn (Builder $orders) => $orders
                        ->whereIn('status', OrderStatus::openCommerceStatuses())),
            ], 'quantity');
    }
}
