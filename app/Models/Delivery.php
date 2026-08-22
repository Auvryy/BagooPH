<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'courier_id',
        'tracking_number',
        'logistics_partner',
        'status',
        'pickup_store_name',
        'pickup_address',
        'pickup_phone',
        'delivery_address',
        'delivery_recipient_name',
        'delivery_phone',
        'estimated_delivery_at',
        'assigned_at',
        'picked_up_at',
        'delivered_at',
        'proof_image',
        'courier_notes',
    ];

    protected $casts = [
        'estimated_delivery_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }
}
