<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

class ShipmentFinancialPlan extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['proof_path', 'proof_hash', 'request_token_hash', 'request_fingerprint'];

    protected $casts = ['snapshot' => 'array', 'proof_bytes' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $plan) {
            abort_unless(in_array($plan->kind, ['buyer_refusal', 'transit_loss'], true)
                && (($plan->undelivered_order_id === $plan->order_id && $plan->buyer_return_case_id === null)
                    || ($plan->kind === 'transit_loss' && $plan->undelivered_order_id === null && $plan->buyer_return_case_id !== null)), 409);
            $plan->reference = (string) Str::uuid();
            $plan->active_order_id = $plan->undelivered_order_id;
            $plan->active_return_case_id = $plan->buyer_return_case_id;
        });
        static::updating(function (self $plan) {
            if (DB::transactionLevel() === 0 || $plan->kind !== 'transit_loss'
                || array_diff(array_keys($plan->getDirty()), ['active_order_id', 'active_return_case_id'])
                || $plan->active_order_id !== null || $plan->active_return_case_id !== null
                || ! $plan->events()->where('event_type', 'voided')->where('amount_cents', 0)->exists()
                || (Schema::hasTable('shipment_loss_dispositions') && ShipmentLossDisposition::where('shipment_financial_plan_id', $plan->id)->exists())
                || WalletRefundReserveEntry::where('shipment_financial_plan_id', $plan->id)->exists()
                || $plan->events()->where(fn ($query) => $query->where('amount_cents', '>', 0)->orWhereNotNull('wallet_transaction_id'))->exists()) {
                throw new LogicException('Retain the original shipment review; only an evidenced unfunded void may release its active claim.');
            }
        });
        static::deleting(fn () => throw new LogicException('Retain original shipment financial reviews.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentFinancialEvent::class)->orderBy('sequence');
    }
}
