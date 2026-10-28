<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnAdjustment;
use App\Models\CodCashEvent;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsEarning;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsRateService;
use App\Services\Vouchers\VoucherFundingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LogisticsEarningsService
{
    public function originReceipt(Delivery $parcel, DeliveryCheckpoint $checkpoint): void
    {
        $order = $parcel->order;
        if ($order->logistics_flow_version < 2 || ! $parcel->courier_id || $checkpoint->checkpoint_type !== 'arrived_at_origin_hub') {
            return;
        }
        $split = app(LogisticsRateService::class)->shippingSplit(CodMoney::cents($order->shipping_fee), $order->shipping_quote['earnings_model'] ?? 'dedicated_fleet');
        if ($split['pickup'] > 0) {
            $this->accrue($parcel, $checkpoint, 'pickup', $parcel->courier_id, $split['pickup'], $split['pickup'], true);
        }
    }

    public function completed(Delivery $parcel, DeliveryCheckpoint $checkpoint): void
    {
        $order = $parcel->order;
        $split = app(LogisticsRateService::class)->shippingSplit(CodMoney::cents($order->shipping_fee), $order->shipping_quote['earnings_model'] ?? 'dedicated_fleet');
        $product = SellerSettlementService::split(CodMoney::cents($order->subtotal));
        $promotions = app(VoucherFundingService::class)->amounts($order);
        if ($promotions['promotion_blockers']) {
            throw new \DomainException('The original promotion funding needs review before automatic earnings release.');
        }
        $seller = $order->items()->with('shop')->get()->pluck('shop.user_id')->unique();
        if ($seller->count() !== 1) {
            throw new \DomainException('Automatic earnings require one original seller for this shop order.');
        }
        $this->accrue($parcel, $checkpoint, 'seller', $seller->first(), $product['seller_cents'], $product['seller_cents'] - $promotions['seller_promotion_cents'], true);
        $this->accrue($parcel, $checkpoint, 'platform', null, $product['commission_cents'], $product['commission_cents'] - $promotions['platform_promotion_cents'], true);
        $this->accrue($parcel, $checkpoint, 'company', null, $split['company'], $split['company'], true);
        if ($parcel->assigned_rider_id && $split['delivery'] > 0) {
            $this->accrue($parcel, $checkpoint, 'delivery', $parcel->assigned_rider_id, $split['delivery'], $split['delivery'], $order->payment_method !== 'cod');
        }
    }

    private function accrue(Delivery $parcel, DeliveryCheckpoint $checkpoint, string $role, ?int $userId, int $gross, int $net, bool $released): void
    {
        $record = LogisticsEarning::firstOrCreate(['source_key' => 'parcel:'.$parcel->id.':'.$role], [
            'order_id' => $parcel->order_id, 'delivery_id' => $parcel->id, 'logistics_company_id' => $parcel->logistics_company_id,
            'user_id' => $userId, 'checkpoint_id' => $checkpoint->id, 'role' => $role, 'pool' => in_array($role, ['seller', 'platform'], true) ? 'products' : 'shipping',
            'amount_cents' => $gross, 'net_amount_cents' => $net, 'earned_at' => $checkpoint->created_at, 'released_at' => $released ? now() : null,
            'snapshot' => ['tracking_number' => $parcel->tracking_number, 'source_reference' => $checkpoint->record_reference,
                'payment_method' => $parcel->order->payment_method, 'shipping_quote' => $parcel->order->shipping_quote,
                'original_shipping_cents' => CodMoney::cents($parcel->order->shipping_fee), 'virtual_only' => $parcel->order->payment_method === 'wallet'],
        ]);
        if ($record->wasRecentlyCreated) {
            $this->event($record, $checkpoint->scanned_by_id, 'earned', $checkpoint->record_reference);
            if ($released) {
                $this->event($record, $checkpoint->scanned_by_id, 'released', $checkpoint->record_reference);
            }
        }
    }

    public function cashReceived(CodCashEvent $event): void
    {
        if (! in_array($event->event_type, ['handover_received', 'adjustment'], true)) {
            return;
        }
        $account = $event->account;
        $riderId = $account->collector_id;
        $state = $event->target_state;
        $acknowledged = (int) $account->events()->where('event_type', 'handover_received')->where('from_user_id', $riderId)
            ->where('from_stage', 'rider')->where('to_stage', 'hub')->sum('amount_cents');
        if (! $riderId || $acknowledged < $account->expected_cents || ($state['balances']['rider:'.$riderId] ?? 0) !== 0
            || ($state['excess']['rider:'.$riderId] ?? 0) !== 0 || ($state['pending']['holder_id'] ?? null) === $riderId
            || collect($state['discrepancies'])->contains(fn ($item) => ($item['holder_id'] ?? null) === $riderId && ! $item['resolved_by'])) {
            return;
        }
        foreach (LogisticsEarning::where('delivery_id', $account->delivery_id)->where('user_id', $riderId)
            ->where('role', 'delivery')->whereNull('released_at')->whereNull('reversed_at')->lockForUpdate()->get() as $record) {
            $record->update(['released_at' => $event->created_at]);
            $this->event($record, $event->actor_id, 'released', $event->reference);
        }
    }

    public function scoped(User $actor): Builder
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->closed_at === null && ($actor->canAccessPortal() || (($actor->isCourier() || $actor->isSeller()) && $actor->isKycApproved() && in_array($actor->status, ['inactive', 'suspended'], true))), 403);
        $query = LogisticsEarning::query();
        if ($actor->isAdmin()) {
            return $query;
        }
        if ($actor->isCourier() || $actor->isSeller()) {
            return $query->where('user_id', $actor->id);
        }
        abort_unless($actor->isLogistics(), 403);

        return $query->where('role', 'company')->whereIn('logistics_company_id', app(LogisticsEligibilityService::class)->managedCompanies($actor)->select('id'));
    }

    public function summary(User $actor, ?int $deliveryId = null, ?string $role = null): array
    {
        if (! Schema::hasTable('logistics_earnings')) {
            return ['available' => false, 'confirmed_cents' => null];
        }
        $query = $this->scoped($actor)->whereNull('reversed_at')->when($deliveryId, fn ($q) => $q->where('delivery_id', $deliveryId))
            ->when($role, fn ($q) => $q->where('role', $role));
        $totals = DB::query()->fromSub($this->withReturns($query), 'earnings')->selectRaw(
            'COALESCE(SUM(CASE WHEN released_at IS NOT NULL THEN net_amount_cents - returned_cents ELSE 0 END), 0) AS released,
             COALESCE(SUM(CASE WHEN released_at IS NULL THEN net_amount_cents - returned_cents ELSE 0 END), 0) AS pending'
        )->first();

        return ['available' => true, 'confirmed_cents' => (string) $totals->released,
            'pending_cents' => (string) $totals->pending, 'basis' => 'original_delivery_earnings_ledger'];
    }

    public function withReturns(Builder $query): Builder
    {
        return $query->select('logistics_earnings.*')->addSelect(['returned_cents' => BuyerReturnAdjustment::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN logistics_earnings.role = 'seller' THEN seller_cents ELSE platform_cents END), 0)")
            ->whereColumn('buyer_return_adjustments.order_id', 'logistics_earnings.order_id')
            ->whereRaw("logistics_earnings.role IN ('seller', 'platform')")]);
    }

    public function net(LogisticsEarning $record): int
    {
        if ($record->getAttribute('returned_cents') === null) {
            $record = $this->withReturns(LogisticsEarning::whereKey($record->id))->firstOrFail();
        }

        return $record->net_amount_cents - (int) $record->returned_cents;
    }

    private function event(LogisticsEarning $record, int $actorId, string $type, string $reference): void
    {
        DB::table('logistics_earning_events')->insertOrIgnore(['logistics_earning_id' => $record->id, 'actor_id' => $actorId, 'event_type' => $type, 'source_reference' => $reference]);
    }
}
