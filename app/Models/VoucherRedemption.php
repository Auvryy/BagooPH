<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoucherRedemption extends VoucherEvidence
{
    protected $casts = ['terms' => 'array', 'discount_cents' => 'integer'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(VoucherRevision::class, 'voucher_revision_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VoucherOrderAllocation::class, 'voucher_redemption_id');
    }
}
