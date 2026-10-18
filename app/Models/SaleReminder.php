<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReminder extends Model
{
    protected $fillable = ['buyer_id', 'product_sale_id', 'subscribed', 'notified_at'];

    protected $casts = ['subscribed' => 'boolean', 'notified_at' => 'immutable_datetime'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ProductSale::class, 'product_sale_id');
    }
}
