<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'seller_id',
        'courier_id',
        'gross_amount',
        'seller_amount',
        'platform_commission',
        'delivery_fee',
        'status',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'seller_amount' => 'decimal:2',
        'platform_commission' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }
}
