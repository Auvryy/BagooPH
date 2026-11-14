<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShipmentLossDisposition extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id', 'reference'];

    protected $hidden = ['proof_path', 'proof_hash', 'request_token_hash', 'request_fingerprint', 'financial_snapshot', 'physical_snapshot'];

    protected $casts = ['financial_snapshot' => 'array', 'physical_snapshot' => 'array', 'financial_version' => 'integer',
        'proof_bytes' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $plan = ShipmentFinancialPlan::find($record->shipment_financial_plan_id);
            $return = $plan?->buyer_return_case_id !== null;
            $member = $return ? BuyerReturnManifestParcel::find($record->buyer_return_manifest_parcel_id)
                : LogisticsManifestParcel::find($record->manifest_parcel_id);
            abort_unless(DB::transactionLevel() > 0 && $plan && $plan->kind === 'transit_loss'
                && ($plan->active_order_id || $plan->active_return_case_id)
                && $record->order_id === $plan->order_id && $record->delivery_id === $plan->delivery_id
                && $record->buyer_return_case_id === $plan->buyer_return_case_id && $record->logistics_company_id === $plan->logistics_company_id
                && $record->manifest_id === ($plan->snapshot['source']['manifest_id'] ?? null)
                && $record->loss_observation_id === $plan->loss_observation_id
                && $member && $member->id === ($plan->snapshot['source']['member_id'] ?? null) && $member->manifest_id === $record->manifest_id
                && ($return ? $record->manifest_parcel_id === null && $member->parcel->buyer_return_case_id === $plan->buyer_return_case_id
                    : $record->buyer_return_manifest_parcel_id === null && $member->delivery_id === $plan->delivery_id)
                && $record->financial_snapshot === $plan->snapshot && $record->physical_snapshot === ($plan->snapshot['source']['physical_state'] ?? null)
                && $record->financial_version === (int) $plan->events()->max('sequence')
                && $record->state === 'confirmed_lost' && $record->proof_bytes > 0 && $record->proof_bytes <= 5242880
                && str_starts_with((string) $record->proof_path, 'shipment-loss-dispositions/'), 409,
                'Retain the exact active reviewed loss and its original typed manifest member without a physical receipt.');
            $record->reference = (string) Str::uuid();
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ShipmentFinancialPlan::class, 'shipment_financial_plan_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifestEvent::class, 'loss_observation_id');
    }
}
