<?php

namespace App\Models;

use App\Models\Concerns\RetainsBuyerReturnEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerReturnRepaymentPlan extends Model
{
    use RetainsBuyerReturnEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['policy_version' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(BuyerReturnAdjustment::class, 'buyer_return_adjustment_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(BuyerReturnMoneyEvent::class, 'approval_event_id');
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(BuyerReturnCase::class, 'buyer_return_case_id');
    }
}
