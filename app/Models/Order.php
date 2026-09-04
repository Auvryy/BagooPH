<?php

namespace App\Models;

use App\Models\Builders\OrderBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'buyer_id',
        'subtotal',
        'shipping_fee',
        'total_amount',
        'payment_method',
        'payment_status',
        'status',
        'recipient_name',
        'recipient_phone',
        'shipping_address',
        'shipping_city',
        'shipping_postal_code',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function newEloquentBuilder($query): OrderBuilder
    {
        return new OrderBuilder($query);
    }

    public function setStatusAttribute($value): void
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        $this->attributes['status'] = $value !== null ? strtolower(trim((string) $value)) : null;
    }

    public function getUserIdAttribute(): ?int
    {
        return $this->buyer_id !== null ? (int) $this->buyer_id : null;
    }

    public function setUserIdAttribute($value): void
    {
        $this->attributes['buyer_id'] = $value;
    }

    public function isPlaced(): bool
    {
        return in_array($this->status, ['placed', 'pending'], true);
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isPreparing(): bool
    {
        return in_array($this->status, ['preparing', 'processing', 'packaging'], true);
    }

    public function isReadyForPickup(): bool
    {
        return $this->status === 'ready_for_pickup';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    public function commissionLedger(): HasOne
    {
        return $this->hasOne(CommissionLedger::class);
    }
}
