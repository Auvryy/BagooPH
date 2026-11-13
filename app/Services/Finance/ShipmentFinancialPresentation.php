<?php

namespace App\Services\Finance;

use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletLedger;
use Illuminate\Support\Arr;

class ShipmentFinancialPresentation
{
    public function status(array $state): string
    {
        return ! $state['assessed'] ? 'awaiting_execution' : ($state['remaining_cents'] > 0 ? 'shipping_due' : 'resolved');
    }

    public function filterStatus($query, string $status): void
    {
        if ($status === '') {
            return;
        }
        $executed = fn ($events) => $events->whereIn('event_type', ['wallet-refund', 'zero-refund-assessed']);
        if ($status === 'awaiting_execution') {
            $query->whereDoesntHave('events', $executed);

            return;
        }
        $query->whereHas('events', $executed);
        $debt = 'CAST('.$query->getQuery()->getGrammar()->wrap('snapshot->buyer_liability_cents').' AS BIGINT)';
        $comparison = $status === 'resolved' ? '<=' : '>';
        $query->whereRaw($debt.' '.$comparison.' (SELECT COALESCE(SUM(amount_cents), 0) FROM shipment_financial_events
            WHERE shipment_financial_plan_id = shipment_financial_plans.id AND event_type IN (?, ?))', ['receive-shipping-payment', 'pay-wallet-shipping']);
    }

    public function row(ShipmentFinancialPlan $plan): array
    {
        $state = app(ShipmentRefusalLiability::class)->state($plan);

        return ['id' => $plan->id, 'reference' => $plan->reference, 'status' => $this->status($state), 'kind' => 'shipment',
            'order_number' => $plan->order->order_number, 'mode' => $plan->snapshot['payment_method'],
            'refund_cents' => $plan->snapshot['buyer_refund_cents'], 'outstanding_cents' => $state['remaining_cents'], 'href' => '/shipment-finances/'.$plan->id];
    }

    public function present(User $actor, ShipmentFinancialPlan $original): array
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        $access = app(ShipmentFinancialAccess::class);
        $plan = $access->query($actor)->whereKey($original->id)->with('order')->firstOrFail();
        $state = app(ShipmentRefusalLiability::class)->state($plan);
        $admin = $actor->isAdmin();
        $buyer = $actor->isBuyer() && $actor->id === $plan->buyer_id;
        $events = $plan->events()->get();
        $pending = $events->where('event_type', 'offer-shipping-payment')->filter(fn ($event) => ! $events->contains('consumed_source_id', $event->id));
        $actions = [];
        $wallet = $plan->snapshot['payment_method'] === 'wallet';
        if ($admin && ! $state['assessed']) {
            $actions[] = $wallet ? 'execute-wallet-refusal' : 'assess-cod-refusal';
        }
        if ($admin && $state['assessed'] && $pending->contains('recipient_id', $actor->id)) {
            $actions[] = 'receive-shipping-payment';
        }
        if ($buyer && $state['assessed'] && $state['remaining_cents'] > ($wallet ? 0 : $state['pending_offer_cents'])) {
            $actions[] = $wallet ? 'pay-wallet-shipping' : 'offer-shipping-payment';
        }
        $history = $events->filter(fn ($event) => $admin || $buyer || in_array($event->event_type, ['approved', 'wallet-refund', 'zero-refund-assessed'], true));

        return ['id' => $plan->id, 'reference' => $plan->reference, 'kind' => $plan->kind, 'status' => $this->status($state),
            'version' => $state['version'], 'mode' => $plan->snapshot['payment_method'], 'order' => $plan->order->only(['id', 'order_number']),
            'amounts' => Arr::only($plan->snapshot, ['actual_buyer_paid_cents', 'original_merchandise_cents', 'buyer_outbound_shipping_cents',
                'reverse_fee_cents', 'reverse_fee_withheld_cents', 'buyer_refund_cents', 'buyer_liability_cents']),
            'state' => $state, 'actions' => $actions, 'review_reason' => $admin || $buyer ? $plan->reason : null,
            'cost_reference' => $admin || $buyer ? $plan->snapshot['cost_reference'] : null,
            'review_proof_url' => $admin ? '/shipment-finances/'.$plan->id.'/proof' : null,
            'wallet_balance_cents' => $buyer && $wallet ? app(WalletLedger::class)->balance($actor) : null,
            'receivers' => in_array('offer-shipping-payment', $actions, true) ? User::where('role', 'admin')->whereNotNull('email_verified_at')->orderBy('id')->get()
                ->filter(fn ($user) => $user->canAccessPortal())->map(fn ($user) => $user->only(['id', 'name']))->values()->all() : [],
            'pending_offers' => $admin ? $pending->where('recipient_id', $actor->id)->map(fn ($event) => $event->only(['id', 'cash_reference', 'amount_cents']))->values()->all() : [],
            'history' => $history->map(function ($event) use ($access, $actor, $plan, $admin, $buyer) {
                $proof = $access->proof($actor, $plan, $event);

                return $event->only(['id', 'reference', 'event_type', 'amount_cents', 'created_at']) + [
                    'reason' => $admin || $buyer ? $event->reason : null,
                    'cash_reference' => $proof ? $event->cash_reference : null,
                    'proof_url' => $proof ? '/shipment-finances/'.$plan->id.'/events/'.$event->id.'/proof' : null,
                    'wallet_reference' => $admin || $buyer ? $event->context['wallet_reference'] ?? null : null,
                    'no_buyer_payment' => $event->context['no_buyer_payment'] ?? false];
            })->values()->all()];
    }
}
