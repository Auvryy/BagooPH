<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class WalletRefundCreditRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['active_key', 'request_token_hash', 'request_fingerprint', 'review_token_hash', 'review_fingerprint'];

    protected $casts = ['amount_cents' => 'integer', 'version' => 'integer', 'reviewed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->reference = (string) Str::uuid();
            if ($request->shipment_financial_plan_id !== null) {
                $plan = ShipmentFinancialPlan::find($request->shipment_financial_plan_id);
                abort_unless(DB::transactionLevel() > 0 && $plan?->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet'
                    && ($plan->active_order_id || $plan->active_return_case_id) && $request->repayment_plan_id === null
                    && $request->authority_reference === $plan->reference && $request->amount_cents > 0 && $request->version === 1 && $request->status === 'pending'
                    && $request->active_key === hash('sha256', $plan->reference.':'.$request->actor_id)
                    && LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $request->actor_id)->exists(), 409,
                    'Retain the original active loss and named company behind its demonstration funding request.');
            }
        });
        static::updating(function (self $request) {
            $mutable = ['status', 'version', 'active_key', 'reviewer_id', 'review_reason', 'reviewed_at', 'wallet_transaction_id', 'review_token_hash', 'review_fingerprint', 'updated_at'];
            if ($request->getRawOriginal('status') !== 'pending' || array_diff(array_keys($request->getDirty()), $mutable)) {
                throw new LogicException('Retain the original demonstration funding request and final review.');
            }
        });
        static::deleting(fn () => throw new LogicException('Retain original demonstration funding requests.'));
    }
}
