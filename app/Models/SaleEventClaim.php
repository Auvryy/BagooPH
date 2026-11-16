<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleEventClaim extends Model
{
    protected $fillable = ['sale_event_voucher_id', 'buyer_id'];

    public function eventVoucher(): BelongsTo
    {
        return $this->belongsTo(SaleEventVoucher::class, 'sale_event_voucher_id');
    }
}
