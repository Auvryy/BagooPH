<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\User;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletTransaction;
use App\Services\Finance\ShipmentFinancialLinks;
use App\Services\Wallet\WalletFundingCredits;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletRefundCreditService;
use App\Services\Wallet\WalletReturnReserve;
use App\Services\Wallet\WalletSuccessorReturnMoneyService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BuyerReturnMoneyPresentation
{
    public function present(User $actor, BuyerReturnCase $snapshot): ?array
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        if (! $actor->email_verified_at) {
            return null;
        }
        if (! app(BuyerReturnFinancialScope::class)->query($actor)->whereKey($snapshot->id)->exists()) {
            return null;
        }
        $case = $snapshot->fresh(['order', 'items', 'parcel']);
        $wallet = $case->order->payment_method === 'wallet';
        if ($loss = app(ShipmentFinancialLinks::class)->activeReturn($actor, $case)) {
            return ['policy_version' => 2, 'mode' => $wallet ? 'wallet' : 'cod', 'actions' => [], 'plan' => null,
                'adjustment' => null, 'history' => [], 'blockers' => [], 'shipment_finance' => $loss];
        }
        $admin = $actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at;
        $plan = Schema::hasTable('buyer_return_repayment_plans') ? BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->first() : null;
        $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();
        $events = BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->orderBy('sequence')->get();
        $visibility = app(BuyerReturnMoneyVisibility::class);
        $history = $visibility->visible($actor, $case, $events);
        $actions = $wallet ? app(WalletSuccessorReturnMoneyService::class)->actions($actor, $case) : app(BuyerReturnCashExecution::class)->actions($actor, $case);
        $data = ['policy_version' => 2, 'mode' => $wallet ? 'wallet' : 'cod', 'actions' => $actions, 'plan' => null, 'blockers' => [],
            'adjustment' => $adjustment?->only(['gross_cents', 'commission_cents', 'shop_discount_cents', 'platform_discount_cents', 'buyer_cents', 'seller_cents', 'platform_cents']),
            'history' => $history->map(fn ($event) => $event->only(['id', 'reference', 'event_type', 'amount_cents', 'actor_role', 'reason', 'source_event_id', 'created_at']) + [
                'cash_reference' => $visibility->proof($actor, $case, $event) ? $event->cash_reference : null,
                'proof_url' => $visibility->proof($actor, $case, $event) ? '/buyer-returns/'.$case->id.'/money/'.$event->id.'/proof' : null,
                'wallet_reference' => $event->context['wallet_reference'] ?? null,
                'recipient_kind' => $event->context['recipient_kind'] ?? null])->values()->all()];
        if ($plan) {
            $data['plan'] = $this->summary($plan->snapshot) + ['reference' => $plan->reference, 'is_preview' => false];
            if ($actor->isBuyer()) {
                unset($data['plan']['seller_recovery_due_cents'], $data['plan']['company_funding_due_cents']);
            }
        } elseif ($admin && in_array('approve-repayment', $actions, true)) {
            try {
                $preview = app(BuyerReturnPlanApproval::class)->preview($actor, $case);
                $data['plan'] = $this->summary($preview['snapshot']) + ['reference' => null, 'is_preview' => true];
                $data['review_token'] = $preview['review_token'];
                $data['original_payment'] = $preview['snapshot']['source_snapshot']['payment'];
                $data['original_seller_payment'] = $preview['snapshot']['source_snapshot']['seller_payment'];
            } catch (HttpExceptionInterface $error) {
                $data['blockers'][] = $error->getMessage();
                $data['actions'] = [];
            }
        }
        if (! $actor->isBuyer() && $plan) {
            $data += app(BuyerReturnLiabilityBalance::class)->forCase($case);
            if ($wallet) {
                $data['reserve'] = app(WalletReturnReserve::class)->summary($plan);
                if (! $admin) {
                    $data['owned_wallet_balance_cents'] = app(WalletLedger::class)->balance($actor);
                    try {
                        $data['owned_wallet_available_cents'] = app(WalletFundingCredits::class)->available($actor, $plan->reference);
                    } catch (HttpExceptionInterface $error) {
                        if ($error->getStatusCode() !== 409) {
                            throw $error;
                        }
                        $data['owned_wallet_available_cents'] = 0;
                        $data['blockers'][] = $error->getMessage();
                    }
                }
                if (Schema::hasTable('wallet_refund_credit_requests')) {
                    $requests = WalletRefundCreditRequest::where('repayment_plan_id', $plan->id)
                        ->when(! $admin, fn ($query) => $query->where('actor_id', $actor->id))->orderByDesc('id')->get();
                    $data['funding_credit_requests'] = $requests->map(fn ($request) => $request->only(['id', 'reference', 'amount_cents', 'reason', 'status', 'version', 'review_reason', 'created_at']) + [
                        'actor_name' => User::whereKey($request->actor_id)->value('name'), 'can_review' => $admin && $request->status === 'pending',
                        'wallet_reference' => $request->wallet_transaction_id ? WalletTransaction::whereKey($request->wallet_transaction_id)->value('reference') : null])->all();
                    if (! $admin && ($plan->snapshot['fault'] === 'seller' && $actor->id === $case->seller_id || $actor->isLogistics())) {
                        try {
                            $data['funding_credit_max_cents'] = app(WalletRefundCreditService::class)->maximum($actor, $case, $plan);
                        } catch (HttpExceptionInterface $error) {
                            $data['blockers'][] = $error->getMessage();
                            $data['funding_credit_max_cents'] = 0;
                        }
                    }
                }
            } else {
                $data['receivers'] = User::where('role', 'admin')->whereNotNull('email_verified_at')->orderBy('id')->get()
                    ->filter(fn ($user) => $user->canAccessPortal() && $user->id !== $actor->id)->map(fn ($user) => $user->only(['id', 'name']))->values()->all();
                if ($admin) {
                    $data['backing'] = app(BuyerReturnCashExecution::class)->backing($case->order);
                    $data['funding'] = app(BuyerReturnCashExecution::class)->funding($case);
                    $data['pending_offers'] = $events->filter(fn ($event) => in_array($event->event_type, ['offer-recovery', 'offer-compensation', 'offer-funding'], true)
                        && $event->recipient_id === $actor->id && $event->actor_id !== $actor->id && ! $events->contains('consumed_source_id', $event->id))
                        ->map(fn ($event) => $event->only(['id', 'event_type', 'amount_cents', 'cash_reference']))->values()->all();
                }
            }
        }

        return $data;
    }

    private function summary(array $snapshot): array
    {
        return Arr::only($snapshot, ['policy_version', 'fault', 'original_buyer_merchandise_cents', 'outbound_shipping_refund_cents', 'buyer_refund_cents',
            'seller_recovery_due_cents', 'company_funding_due_cents', 'buyer_return_charge_cents', 'protected_shipping_cents']);
    }
}
