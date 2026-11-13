<?php

namespace App\Services\Finance;

use App\Models\LogisticsCompany;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\BuyerAccessService;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ShipmentLossAccess
{
    public function query(User $actor): Builder
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        abort_unless($actor->email_verified_at, 403);
        abort_unless(Schema::hasTable('shipment_financial_plans'), 409, 'Shipment financial reviews are currently unavailable.');
        $query = ShipmentFinancialPlan::where('kind', 'transit_loss');
        if ($actor->isAdmin()) {
            abort_unless($actor->canAccessPortal(), 403);

            return $query;
        }
        if ($actor->isBuyer()) {
            app(BuyerAccessService::class)->requireOrderHistory($actor);

            return $query->where('buyer_id', $actor->id);
        }
        abort_unless($actor->isKycApproved(), 403);
        if ($actor->isSeller()) {
            return $query->where('seller_id', $actor->id);
        }
        abort_unless($actor->isLogistics(), 403);

        return $query->whereIn('logistics_company_id', LogisticsCompany::where('user_id', $actor->id)->select('id'));
    }

    public function proof(User $actor, ShipmentFinancialPlan $plan, ?ShipmentFinancialEvent $event = null): bool
    {
        if (! $this->query($actor)->whereKey($plan->id)->exists()
            || ($event && ($event->shipment_financial_plan_id !== $plan->id || ! $event->proof_path))) {
            return false;
        }
        $actor = app(BuyerReturnService::class)->current($actor);
        if ($actor->isAdmin()) {
            return true;
        }
        if (! $event) {
            return false;
        }
        if ($actor->isBuyer() && $actor->id === $plan->buyer_id) {
            return in_array($event->event_type, ['loss-record-buyer-payment', 'loss-confirm-buyer-receipt',
                'loss-report-buyer-nonreceipt', 'loss-resolve-buyer-nonreceipt'], true);
        }
        if ($actor->isSeller() && $actor->id === $plan->seller_id) {
            return in_array($event->event_type, ['loss-record-seller-protection', 'loss-confirm-seller-receipt',
                'loss-report-seller-nonreceipt', 'loss-resolve-seller-nonreceipt'], true);
        }
        if ($actor->isLogistics()) {
            if ($event->event_type === 'loss-offer-company') {
                return $event->actor_id === $actor->id;
            }
            $source = $event->source_event_id ? $plan->events()->find($event->source_event_id) : null;

            return $event->event_type === 'loss-receive-company' && $source?->event_type === 'loss-offer-company' && $source->actor_id === $actor->id;
        }

        return false;
    }
}
