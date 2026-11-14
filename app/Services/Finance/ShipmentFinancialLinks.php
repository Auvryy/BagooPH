<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/** Bounded original-review previews with access to every earlier scoped record. */
class ShipmentFinancialLinks
{
    public const PREVIEW_LIMIT = 5;

    public function forOrder(User $actor, Order $order): array
    {
        $actor = User::findOrFail($actor->id);
        $result = ['records' => [], 'histories' => [], 'primary' => null];
        if (! $actor->email_verified_at || ! Schema::hasTable('shipment_financial_plans')) {
            return $result;
        }
        $rows = collect();
        foreach (['transit_loss' => 'losses', 'buyer_refusal' => 'shipments'] as $kind => $view) {
            $query = ($kind === 'transit_loss' ? app(ShipmentLossAccess::class) : app(ShipmentFinancialAccess::class))
                ->query($actor)->where('order_id', $order->id);
            $count = (clone $query)->count();
            if (! $count) {
                continue;
            }
            $rows = $rows->concat($query->orderByRaw('CASE WHEN active_order_id IS NOT NULL OR active_return_case_id IS NOT NULL THEN 0 ELSE 1 END')
                ->orderByDesc('id')->limit(self::PREVIEW_LIMIT)
                ->get(['id', 'reference', 'kind', 'buyer_return_case_id', 'active_order_id', 'active_return_case_id']));
            $result['histories'][] = ['kind' => $kind, 'count' => $count,
                'href' => '/return-liabilities?'.http_build_query(['view' => $view, 'order_id' => $order->id])];
        }
        $references = BuyerReturnCase::whereIn('id', $rows->pluck('buyer_return_case_id')->filter())->pluck('reference', 'id');
        $result['records'] = $rows->map(fn ($plan) => [
            'id' => $plan->id, 'reference' => $plan->reference, 'kind' => $plan->kind,
            'active' => (bool) ($plan->active_order_id || $plan->active_return_case_id),
            'return_reference' => $plan->buyer_return_case_id ? $references->get($plan->buyer_return_case_id) : null,
            'href' => '/shipment-finances/'.$plan->id])->sortBy([['active', 'desc'], ['id', 'desc']])->values()->all();
        $primary = collect($result['records'])->firstWhere('active', true) ?? ($result['records'][0] ?? null);
        $result['primary'] = $primary ? ['id' => $primary['id'], 'reference' => $primary['reference']] : null;

        return $result;
    }

    public function activeReturn(User $actor, BuyerReturnCase $case): ?array
    {
        if (! $actor->email_verified_at || ! Schema::hasTable('shipment_financial_plans')) {
            return null;
        }
        $plan = app(ShipmentLossAccess::class)->query($actor)->where('buyer_return_case_id', $case->id)
            ->where('active_return_case_id', $case->id)->first(['id', 'reference']);
        if (! $plan) {
            return null;
        }
        $reconciliation = app(ShipmentLossReconciliationService::class)->active($plan);
        if ($reconciliation && $reconciliation->status === 'released' && $reconciliation->outcome_path === 'post_delivery_partial_return') {
            return null;
        }

        return ['id' => $plan->id, 'reference' => $plan->reference, 'return_reference' => $case->reference,
            'href' => '/shipment-finances/'.$plan->id];
    }
}
