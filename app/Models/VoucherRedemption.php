<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherRedemption extends VoucherEvidence
{
    protected $casts = ['terms' => 'array', 'discount_cents' => 'integer'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(VoucherRevision::class, 'voucher_revision_id');
    }
}
