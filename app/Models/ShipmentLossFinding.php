<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ShipmentLossFinding extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['proof_path', 'proof_hash', 'source_state', 'custody_after', 'request_token_hash', 'request_fingerprint'];

    protected $casts = ['financial_version' => 'integer', 'source_state' => 'array', 'custody_after' => 'array',
        'proof_bytes' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $plan = ShipmentFinancialPlan::find($record->shipment_financial_plan_id);
            $return = $plan?->buyer_return_case_id !== null;
            $member = $return ? BuyerReturnManifestParcel::find($record->buyer_return_manifest_parcel_id)
                : LogisticsManifestParcel::find($record->manifest_parcel_id);
            $hub = LogisticsHub::find($record->hub_id);
            abort_unless(DB::transactionLevel() > 0 && $plan?->kind === 'transit_loss'
                && ($plan->active_order_id || $plan->active_return_case_id) && $member && $hub
                && $record->manifest_id === $member->manifest_id && $record->manifest_id === $plan->snapshot['source']['manifest_id']
                && $member->id === $plan->snapshot['source']['member_id'] && $record->barcode_scanned === $plan->snapshot['source']['barcode']
                && ($return ? $record->manifest_parcel_id === null : $record->buyer_return_manifest_parcel_id === null)
                && $hub->logistics_company_id === $plan->logistics_company_id
                && $record->source_state === $plan->snapshot['source']['physical_state']
                && $record->financial_version === (int) $plan->events()->max('sequence')
                && ($record->custody_after['kind'] ?? null) === 'loss_quarantine'
                && ($record->custody_after['hub_id'] ?? null) === $hub->id
                && ($record->custody_after['user_id'] ?? null) === $record->actor_id
                && ($record->custody_after['finding_reference'] ?? null) === $record->reference
                && $record->proof_bytes > 0 && $record->proof_bytes <= 5242880
                && str_starts_with((string) $record->proof_path, 'shipment-loss-findings/'), 409,
                'Retain the exact original loss, scanned typed parcel and actual quarantine holder.');
        });
    }
}
