<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnManifestParcel;
use App\Models\LogisticsManifestEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\Wallet\WalletLossReserve;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ShipmentLossQueue
{
    public function filter(Builder $query, string $status): void
    {
        if ($status === '') {
            return;
        }
        $active = fn ($rows) => $rows->whereNotNull('active_order_id')->orWhereNotNull('active_return_case_id');
        if ($status === 'voided') {
            $query->whereNull('active_order_id')->whereNull('active_return_case_id');

            return;
        }
        $query->where($active);
        $resolved = fn ($events) => $events->whereIn('event_type', ['loss-resolve-wallet', 'loss-resolve-cash']);
        $status === 'financially_resolved' ? $query->whereHas('events', $resolved) : $query->whereDoesntHave('events', $resolved);
    }

    public function row(User $actor, ShipmentFinancialPlan $plan): array
    {
        $state = $plan->snapshot['payment_method'] === 'wallet'
            ? app(WalletLossReserve::class)->summary($plan) : app(ShipmentLossCash::class)->state($plan);
        $active = (bool) ($plan->active_order_id || $plan->active_return_case_id);
        $seller = $actor->isSeller();

        return ['id' => $plan->id, 'reference' => $plan->reference, 'order_number' => $plan->order->order_number,
            'mode' => $plan->snapshot['payment_method'], 'status' => ! $active ? 'voided' : ($state['resolved'] ? 'financially_resolved' : 'awaiting_payment'),
            'amount_label' => $seller ? 'Separate seller protection' : 'Buyer refund',
            'amount_cents' => $plan->snapshot[$seller ? 'seller_protection_cents' : 'buyer_refund_cents'],
            'outstanding_cents' => $actor->isAdmin() || $actor->isLogistics() ? ($active ? $state['company_remaining_cents'] : 0) : null,
            'href' => '/shipment-finances/'.$plan->id];
    }

    public function candidates(User $admin, string $search)
    {
        $query = LogisticsManifestEvent::where('event_type', 'report-discrepancy')->where('payload->kind', 'missing')
            ->whereHas('manifest', fn ($manifests) => $manifests->where('status', 'dispatched')->whereNotNull('dispatched_at'))
            ->whereNotIn('id', ShipmentFinancialPlan::whereNotNull('loss_observation_id')
                ->where(fn ($plans) => $plans->whereNotNull('active_order_id')->orWhereNotNull('active_return_case_id'))->select('loss_observation_id'));
        if ($search !== '') {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $returnMembers = BuyerReturnManifestParcel::selectRaw('CAST(buyer_return_manifest_parcels.id AS TEXT)')
                ->whereColumn('buyer_return_manifest_parcels.manifest_id', 'logistics_manifest_events.manifest_id')
                ->whereHas('parcel.returnCase.order', fn ($orders) => $orders->whereRaw("order_number LIKE ? ESCAPE '!'", [$needle]));
            $member = DB::raw('CAST('.$query->getQuery()->getGrammar()->wrap('logistics_manifest_events.payload->return_member_id').' AS TEXT)');
            $query->where(fn ($events) => $events->whereRaw("reference LIKE ? ESCAPE '!'", [$needle])
                ->orWhereHas('manifest', fn ($manifests) => $manifests->whereRaw("reference LIKE ? ESCAPE '!'", [$needle]))
                ->orWhereHas('parcel.delivery.order', fn ($orders) => $orders->whereRaw("order_number LIKE ? ESCAPE '!'", [$needle]))
                ->orWhereIn($member, $returnMembers));
        }
        $observations = $query->orderByDesc('id')->paginate(12, ['*'], 'review_page')->withQueryString();
        $observations->setCollection($observations->getCollection()->map(function ($observation) use ($admin) {
            try {
                $snapshot = app(ShipmentLossReview::class)->preview($admin, $observation,
                    ['seller_protection' => '0.00', 'investigation_reference' => 'Awaiting independent investigation'])['snapshot'];
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 409], true)) {
                    throw $error;
                }

                return null;
            }
            $active = $snapshot['buyer_return_case_id']
                ? ShipmentFinancialPlan::where('active_return_case_id', $snapshot['buyer_return_case_id'])->exists()
                : ShipmentFinancialPlan::where('active_order_id', $snapshot['order_id'])->exists();
            if ($active) {
                return null;
            }

            return ['id' => $observation->id, 'reference' => $observation->reference,
                'manifest_reference' => $snapshot['source']['manifest_reference'], 'scope' => $snapshot['scope'],
                'href' => '/shipment-finances/missing/'.$observation->id.'/review'];
        })->filter()->values());

        return $observations;
    }
}
