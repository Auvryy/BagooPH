<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\LogisticsCompany;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class BuyerReturnFinancialScope
{
    public function query(User $actor): Builder
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        abort_unless($actor->email_verified_at, 403);
        $query = BuyerReturnCase::whereHas('order', fn ($order) => $order->where('shipping_quote->refund_policy_version', 2));
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
        $companies = LogisticsCompany::where('user_id', $actor->id)->pluck('id');

        return $query->where(function (Builder $rows) use ($companies) {
            $rows->whereRaw('1 = 0');
            if (Schema::hasTable('buyer_return_repayment_plans')) {
                $rows->orWhereHas('repaymentPlan', fn ($plan) => $plan->whereIn('snapshot->liable_company_id', $companies));
            }
            if (Schema::hasTable('shipment_financial_plans')) {
                $rows->orWhereIn('id', ShipmentFinancialPlan::where('kind', 'transit_loss')->whereNotNull('active_return_case_id')
                    ->whereIn('logistics_company_id', $companies)->select('active_return_case_id'));
            }
        });
    }
}
