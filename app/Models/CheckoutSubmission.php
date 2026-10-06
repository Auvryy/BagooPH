<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class CheckoutSubmission extends Model
{
    public $timestamps = false;

    protected $fillable = ['buyer_id', 'cart_id', 'token_hash', 'request_hash', 'order_count', 'created_at'];

    protected $hidden = ['token_hash', 'request_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'order_count' => 'integer', 'buyer_id' => 'integer', 'cart_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Checkout results are immutable.'));
        static::deleting(fn () => throw new LogicException('Checkout results are immutable.'));
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'checkout_submission_orders')->orderBy('orders.id');
    }
}
