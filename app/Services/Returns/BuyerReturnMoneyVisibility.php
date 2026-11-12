<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\LogisticsCompany;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class BuyerReturnMoneyVisibility
{
    private const BUYER_EVENTS = ['approve-repayment', 'record-repayment', 'confirm-receipt', 'report-nonreceipt',
        'resolve-nonreceipt', 'close-zero-return', 'refund-wallet'];

    public function visible(User $actor, BuyerReturnCase $case, Collection $events): Collection
    {
        $companyOwner = $actor->isLogistics() && Schema::hasTable('buyer_return_repayment_plans')
            && BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)
                ->whereIn('snapshot->liable_company_id', LogisticsCompany::where('user_id', $actor->id)->pluck('id'))->exists();

        return $events->filter(function (BuyerReturnMoneyEvent $event) use ($actor, $case, $events, $companyOwner) {
            if ($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at) {
                return true;
            }
            if (in_array($event->event_type, self::BUYER_EVENTS, true)) {
                return ($actor->isBuyer() && $actor->id === $case->buyer_id) || ($actor->isSeller() && $actor->id === $case->seller_id)
                    || $companyOwner;
            }
            if ($actor->isBuyer()) {
                return false;
            }
            if ($event->actor_id === $actor->id || $event->event_type === 'wallet-funding-credit' && $event->recipient_id === $actor->id) {
                return true;
            }
            $source = $event->source_event_id ? $events->firstWhere('id', $event->source_event_id) : null;

            return $source?->actor_id === $actor->id && in_array($event->event_type, ['receive-recovery', 'receive-compensation'], true);
        })->values();
    }

    public function proof(User $actor, BuyerReturnCase $case, BuyerReturnMoneyEvent $event): bool
    {
        if ($event->buyer_return_case_id !== $case->id || ! $event->proof_path) {
            return false;
        }
        if ($actor->isAdmin()) {
            return $actor->canAccessPortal() && $actor->email_verified_at;
        }
        if ($actor->isBuyer()) {
            return $actor->id === $case->buyer_id && in_array($event->event_type, ['record-repayment', 'resolve-nonreceipt'], true);
        }
        if ($event->actor_id === $actor->id && in_array($event->event_type, ['offer-recovery', 'offer-compensation'], true)) {
            return true;
        }
        if (! in_array($event->event_type, ['receive-recovery', 'receive-compensation'], true)) {
            return false;
        }
        $source = BuyerReturnMoneyEvent::whereKey($event->source_event_id)->where('buyer_return_case_id', $case->id)->first();

        return $source?->actor_id === $actor->id;
    }
}
