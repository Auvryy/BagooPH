<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShipmentLossAccounting extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id', 'reference'];

    protected $hidden = ['request_token_hash', 'request_fingerprint'];

    protected $casts = ['financial_version' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $disposition = ShipmentLossDisposition::find($record->shipment_loss_disposition_id);
            $plan = ShipmentFinancialPlan::find($record->shipment_financial_plan_id);
            abort_unless(DB::transactionLevel() > 0 && $disposition && $plan?->kind === 'transit_loss'
                && $disposition->shipment_financial_plan_id === $plan->id
                && $record->financial_version === (int) $plan->events()->max('sequence'), 409,
                'Retain the original independently reviewed disposition before accounting for a lost parcel.');
            $record->reference = (string) Str::uuid();
        });
    }
}
