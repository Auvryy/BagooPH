<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Logistics\PickupClaimService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderWorkspaceService
{
    public const PREPARATION = ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging'];

    public const TRANSIT = ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'in_transit'];

    public const RETURN_CUSTODY = ['return_to_sender', 'return_in_transit', 'returned'];

    public function stages(string $role): array
    {
        $preparation = $role === 'seller'
            ? ['to_pack' => self::PREPARATION, 'to_pickup' => ['ready_for_pickup']]
            : ['to_ship' => [...self::PREPARATION, 'ready_for_pickup']];

        return [
            ...$preparation,
            'in_transit' => self::TRANSIT,
            'delivered' => ['delivered'],
            'completed' => ['completed'],
            'delivery_failed' => ['delivery_failed', 'failed'],
            'returned' => ['returned'],
            'cancelled' => ['cancelled', 'canceled'],
        ];
    }

    public function selection(Request $request, string $role, string $field = 'status'): string
    {
        $allowed = ['all', ...array_keys($this->stages($role))];
        if ($role === 'seller') {
            $allowed[] = 'return_custody';
        } else {
            $allowed[] = 'to_receive';
        }
        $data = $request->validate([
            $field => ['nullable', 'string', Rule::in($allowed)],
            'page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000'],
        ]);
        $selection = $data[$field] ?? 'all';

        return $selection === 'to_receive' ? 'in_transit' : $selection;
    }

    public function counts(Builder $orders, string $role): array
    {
        $totals = (clone $orders)->reorder()->select('orders.status')->selectRaw('COUNT(*) as stage_count')
            ->groupBy('orders.status')->pluck('stage_count', 'orders.status');
        $counts = ['all' => (int) $totals->sum()];
        foreach ($this->stages($role) as $stage => $statuses) {
            $counts[$stage] = (int) $totals->only($statuses)->sum();
        }
        if ($role === 'seller') {
            $counts['return_custody'] = $this->filter(clone $orders, 'seller', 'return_custody')->count();
        }

        return $counts;
    }

    public function filter(Builder $orders, string $role, string $stage): Builder
    {
        if ($stage === 'return_custody' && $role === 'seller') {
            return $orders->whereHas('delivery', fn (Builder $delivery) => $delivery->whereIn('status', self::RETURN_CUSTODY));
        }

        return $stage === 'all' ? $orders : $orders->whereIn('orders.status', $this->stages($role)[$stage]);
    }

    public function stable(Builder $orders): Builder
    {
        return $orders->reorder()->orderByDesc('orders.created_at')->orderByDesc('orders.id');
    }

    public function canConfirmReceipt(Order $order, User $buyer): bool
    {
        return $order->buyer_id === $buyer->id && app(BuyerAccessService::class)->canAccessExistingOrders($buyer)
            && $order->status === 'delivered' && ($order->delivery?->status === 'delivered'
                || ($order->delivery?->status === 'customer_collected'
                    && app(PickupClaimService::class)->hasCollectionEvidence($order->delivery)));
    }
}
