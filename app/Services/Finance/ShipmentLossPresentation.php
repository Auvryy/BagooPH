<?php

namespace App\Services\Finance;

use App\Models\LogisticsCompany;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletLossReserve;

class ShipmentLossPresentation
{
    public function present(User $actor, ShipmentFinancialPlan $original): array
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        $access = app(ShipmentLossAccess::class);
        $plan = $access->query($actor)->whereKey($original->id)->with('order')->firstOrFail();
        $admin = $actor->isAdmin();
        $buyer = $actor->isBuyer();
        $seller = $actor->isSeller();
        $company = $actor->isLogistics();
        $wallet = $plan->snapshot['payment_method'] === 'wallet';
        $state = $wallet ? app(WalletLossReserve::class)->summary($plan) : app(ShipmentLossCash::class)->state($plan);
        $active = (bool) ($plan->active_order_id || $plan->active_return_case_id);
        $resolved = $state['resolved'];
        $amounts = [];
        if ($admin || $buyer || $company) {
            $amounts['Buyer refund'] = $plan->snapshot['buyer_refund_cents'];
        }
        if ($admin || $seller || $company) {
            $amounts['Separate seller protection'] = $plan->snapshot['seller_protection_cents'];
        }
        if ($admin || $company) {
            $amounts['Company funding outstanding'] = $active ? $state['company_remaining_cents'] : 0;
            $amounts['Advance outstanding'] = $active ? $state['advance_outstanding_cents'] : 0;
        }
        $events = $plan->events()->orderBy('sequence')->get();
        $history = $events->filter(function ($event) use ($admin, $buyer, $seller, $company, $actor, $access, $plan) {
            if ($admin || $access->proof($actor, $plan, $event)) {
                return true;
            }
            if (in_array($event->event_type, ['approved', 'voided', 'loss-reconciliation-approved', 'loss-reconciliation-invalidated', 'loss-quarantine-released'], true)) {
                return true;
            }

            return ($buyer && in_array($event->event_type, ['loss-resolve-wallet', 'loss-assess-zero-buyer', 'loss-resolve-cash', 'loss-return-buyer-compensation'], true))
                || ($seller && in_array($event->event_type, ['loss-resolve-wallet', 'loss-return-seller-compensation'], true))
                || ($company && in_array($event->event_type, ['loss-contribute-company', 'loss-credit-requested', 'loss-credit-approved', 'loss-credit-rejected', 'loss-return-unused-credit', 'loss-return-company-source', 'loss-restore-company-compensation', 'loss-confirm-company-cash-return', 'loss-report-company-cash-nonreceipt'], true));
        })->map(function ($event) use ($access, $actor, $plan, $admin, $seller) {
            $proof = $access->proof($actor, $plan, $event);
            // The Wallet settlement's headline amount is the buyer refund, never the seller's protection.
            $amount = $seller && $event->event_type === 'loss-resolve-wallet' ? $plan->snapshot['seller_protection_cents'] : $event->amount_cents;
            $reconciliationProof = $admin && $event->event_type === 'loss-reconciliation-approved'
                ? app(ShipmentLossReconciliationService::class)->active($plan)?->id
                : null;

            return $event->only(['id', 'reference', 'event_type', 'created_at']) + ['amount_cents' => $amount,
                'reason' => $admin ? $event->reason : null, 'cash_reference' => $proof ? $event->cash_reference : null,
                'proof_url' => $proof ? '/shipment-finances/'.$plan->id.'/events/'.$event->id.'/proof'
                    : ($reconciliationProof ? '/shipment-finances/'.$plan->id.'/reconciliation/'.$reconciliationProof.'/proof' : null)];
        })->values()->all();

        return ['id' => $plan->id, 'reference' => $plan->reference, 'order' => $plan->order->only(['id', 'order_number']),
            'mode' => $wallet ? 'wallet' : 'cod', 'status' => ! $active ? 'voided' : ($resolved ? 'financially_resolved' : 'awaiting_payment'),
            'scope' => $plan->snapshot['scope'], 'company_name' => LogisticsCompany::findOrFail($plan->logistics_company_id)->name,
            'amounts' => $amounts, 'review_reason' => $admin ? $plan->reason : null,
            'review_proof_url' => $admin ? '/shipment-finances/'.$plan->id.'/proof' : null,
            'source' => $admin ? array_intersect_key($plan->snapshot['source'], array_flip(['observation_reference', 'manifest_reference', 'barcode'])) : null,
            'history' => $history, 'controls' => app(ShipmentLossControls::class)->present($actor, $plan, $state, $events)];
    }
}
