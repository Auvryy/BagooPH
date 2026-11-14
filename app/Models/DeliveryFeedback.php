<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryFeedback extends Model
{
    protected $table = 'delivery_feedbacks';

    protected $fillable = [
        'delivery_id',
        'order_id',
        'buyer_id',
        'logistics_company_id',
        'rider_id',
        'rating',
        'timeliness_rating',
        'handling_rating',
        'communication_rating',
        'comment',
        'tags',
        'submitted_at',
        'edited_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'timeliness_rating' => 'integer',
        'handling_rating' => 'integer',
        'communication_rating' => 'integer',
        'tags' => 'array',
        'submitted_at' => 'immutable_datetime',
        'edited_at' => 'immutable_datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function logisticsCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }
}
