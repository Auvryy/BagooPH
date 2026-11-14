<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScaleDiscrepancyLog extends Model
{
    protected $fillable = [
        'reference',
        'order_id',
        'delivery_id',
        'shop_id',
        'seller_id',
        'hub_id',
        'recorded_by_id',
        'declared_weight_grams',
        'measured_weight_grams',
        'overage_grams',
        'declared_shipping_cents',
        'recalculated_shipping_cents',
        'tariff_delta_cents',
        'status',
        'financial_penalty_id',
        'notes',
        'recorded_at',
    ];

    protected $casts = [
        'declared_weight_grams' => 'integer',
        'measured_weight_grams' => 'integer',
        'overage_grams' => 'integer',
        'declared_shipping_cents' => 'integer',
        'recalculated_shipping_cents' => 'integer',
        'tariff_delta_cents' => 'integer',
        'recorded_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function penalty(): BelongsTo
    {
        return $this->belongsTo(FinancialPenalty::class, 'financial_penalty_id');
    }
}
