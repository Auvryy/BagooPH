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
                ->whereBetween('completed_at', [$from->setTimezone(config('app.timezone')), $to->setTimezone(config('app.timezone'))]));
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
        return $this->completedSales($shopId, 7);
    }

    /** @return list<array{date: string, revenue: float, units: int}> */
    public function completedSales(int $shopId, int $period = 30): array
    {
        return $this->dailyActivity($shopId, $period);
    }

    public function deliveredAwaitingReceipt(int $shopId, int $period = 30): array
    {
        return $this->dailyActivity($shopId, $period, awaitingReceipt: true);
    }

    private function dailyActivity(int $shopId, int $period, bool $awaitingReceipt = false): array
    {
        $days = [];
        $today = CarbonImmutable::today('Asia/Manila');
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;
        $from = $today->subDays($period - 1);
        $to = $today->endOfDay();
        $query = $awaitingReceipt
            ? OrderItem::where('shop_id', $shopId)->whereHas('order', fn ($order) => $order->where('status', OrderStatus::DELIVERED->value)
                ->whereHas('delivery', fn ($delivery) => $delivery->whereBetween('delivered_at', [$from->setTimezone(config('app.timezone')), $to->setTimezone(config('app.timezone'))])))
            : $this->completedItemsBetween($shopId, $from, $to);
        $rows = $query->with(['order:id,completed_at', 'order.delivery:id,order_id,delivered_at'])->get(['id', 'order_id', 'quantity', 'subtotal'])
            ->groupBy(fn ($item) => CarbonImmutable::instance($awaitingReceipt ? $item->order->delivery->delivered_at : $item->order->completed_at)
                ->setTimezone('Asia/Manila')->format('Y-m-d'));

        for ($daysAgo = $period - 1; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);
            $items = $rows->get($day->format('Y-m-d'), collect());

            $days[] = [
                'date' => $day->format('M j'),
                'revenue' => round((float) $items->sum('subtotal'), 2),
                'units' => (int) $items->sum('quantity'),
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
