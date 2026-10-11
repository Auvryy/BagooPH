<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherCampaignAction extends VoucherEvidence
{
    protected $casts = ['before_state' => 'array', 'after_state' => 'array', 'budget_increase_cents' => 'integer', 'source_version' => 'integer'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
