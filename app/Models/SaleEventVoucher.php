<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleEventVoucher extends Model
{
    protected $fillable = ['sale_event_id', 'voucher_id', 'shop_id', 'claim_limit', 'claimed_count', 'added_by'];

    protected $casts = ['claim_limit' => 'integer', 'claimed_count' => 'integer'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(SaleEvent::class, 'sale_event_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(SaleEventClaim::class);
    }
}
