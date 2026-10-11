<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoucherOrderAllocation extends VoucherEvidence
{
    protected $casts = ['product_discount_cents' => 'integer', 'shipping_discount_cents' => 'integer',
        'seller_expense_cents' => 'integer', 'platform_expense_cents' => 'integer'];

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(VoucherRedemption::class, 'voucher_redemption_id');
    }

    public function fundingEntries(): HasMany
    {
        return $this->hasMany(VoucherFundingEntry::class);
    }
}
