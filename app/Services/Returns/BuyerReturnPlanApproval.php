<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\CodAccount;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Finance\SellerSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BuyerReturnPlanApproval
{
    public function preview(User $actor, BuyerReturnCase $case): array
    {
        $this->admin($actor, $case);
        if (Schema::hasTable('shipment_financial_plans')) {
            abort_if(ShipmentFinancialPlan::where('active_return_case_id', $case->id)->exists(), 409, 'This original claim has an active loss review. Resolve that financial authority before approving another repayment.');
        }
        $snapshot = app(BuyerReturnRefundPlan::class)->postDelivery($case);

        return ['snapshot' => $snapshot, 'review_token' => $this->reviewToken($snapshot)];
    }

    private function admin(User $actor, BuyerReturnCase $case): User
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        abort_unless($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at
            && ! in_array($actor->id, [$case->buyer_id, $case->seller_id], true), 403);
        app(BuyerReturnService::class)->owned($actor, $case);

        return $actor;
    }

    private function reviewToken(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function approve(User $actor, BuyerReturnCase $snapshot, array $input): BuyerReturnRepaymentPlan
    {
        abort_unless(Schema::hasTable('buyer_return_repayment_plans'), 409, 'Apply the additive repayment-plan migration before approving successor terms.');
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'review_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'amount' => ['prohibited'], 'amount_cents' => ['prohibited'],
            'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'status' => ['prohibited']])->validate();
        $data['expected_version'] = (int) $data['expected_version'];
        $data['request_token'] = strtolower($data['request_token']);
        $token = hash('sha256', $data['request_token']);
        $fingerprint = hash('sha256', json_encode([$snapshot->id, $actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $snapshot, $data, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($snapshot->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', [$actor->id, $snapshot->buyer_id, $snapshot->seller_id])->orderBy('id')->lockForUpdate()->get();
            CodAccount::where('order_id', $order->id)->lockForUpdate()->first();
            SellerSettlement::where('order_id', $order->id)->lockForUpdate()->first();
            $case = BuyerReturnCase::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $admin = $this->admin($actor, $case);
            if ($prior = BuyerReturnMoneyEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->buyer_return_case_id === $case->id && $prior->actor_id === $admin->id && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                    'This request already approved a different financial review.');

                return BuyerReturnRepaymentPlan::where('approval_event_id', $prior->id)->firstOrFail();
            }
            abort_if(BuyerReturnEvent::where('request_token_hash', $token)->exists(), 409, 'This token belongs to another return action.');
            abort_unless($case->version === $data['expected_version'] && in_array($case->status, ['seller_received', 'condition_review'], true)
                && ! BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->exists(), 409, 'This return changed. Review its current financial basis.');
            $plan = $this->preview($admin, $case)['snapshot'];
            abort_unless(hash_equals($this->reviewToken($plan), $data['review_token']), 409, 'The original financial source changed. Review its exact current plan.');
            $basis = app(BuyerReturnAmounts::class)->successorForCase($case->load('order', 'items'));
            $reference = (string) Str::uuid();
            $payment = $order->payment_method === 'cod' ? app(SellerSettlementService::class)->verifiedPayment($order->sellerSettlement) : null;
            $amounts = array_intersect_key($basis, array_flip(['gross_cents', 'commission_cents', 'shop_discount_cents', 'platform_discount_cents',
                'buyer_cents', 'seller_cents', 'platform_cents', 'unit_snapshot']));
            $adjustment = BuyerReturnAdjustment::create($amounts + ['buyer_return_case_id' => $case->id, 'order_id' => $order->id,
                'source_snapshot' => $basis['source_snapshot'] + ['refund_policy_version' => 2, 'repayment_plan_reference' => $reference],
                'seller_payment_event_id' => $payment?->id, 'recovery_due_cents' => $plan['seller_recovery_due_cents'],
                'actor_id' => $admin->id, 'created_at' => now()]);
            $before = $case->status;
            $case->update(['status' => 'repayment_ready', 'version' => $case->version + 1]);
            $event = BuyerReturnMoneyEvent::create(['reference' => 'BRM-'.Str::upper((string) Str::uuid()), 'buyer_return_case_id' => $case->id,
                'order_id' => $order->id, 'actor_id' => $admin->id, 'actor_role' => 'admin', 'event_type' => 'approve-repayment',
                'sequence' => $case->version, 'amount_cents' => 0, 'recipient_id' => $case->buyer_id, 'reason' => $data['reason'],
                'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                'context' => ['adjustment_id' => $adjustment->id, 'repayment_plan_reference' => $reference, 'no_money_moved' => true], 'created_at' => now()]);
            $approved = BuyerReturnRepaymentPlan::create(['reference' => $reference, 'buyer_return_case_id' => $case->id,
                'buyer_return_adjustment_id' => $adjustment->id, 'approval_event_id' => $event->id, 'order_id' => $order->id,
                'actor_id' => $admin->id, 'policy_version' => 2, 'snapshot' => $plan, 'created_at' => now()]);
            app(BuyerReturnService::class)->append($case, $admin, 'money-approve-repayment', $before, $data['reason'], $token, $fingerprint,
                ['money_event_id' => $event->id, 'repayment_plan_reference' => $reference, 'no_money_moved' => true]);

            return $approved;
        }, 3);
    }
}
