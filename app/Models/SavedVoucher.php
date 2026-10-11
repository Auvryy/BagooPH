<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedVoucher extends Model
{
    protected $guarded = ['id'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(VoucherRevision::class, 'voucher_revision_id');
    }
}
