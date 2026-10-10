<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BuyAgainSubmission extends Model
{
    public $timestamps = false;

    protected $fillable = ['buyer_id', 'order_id', 'cart_id', 'token_hash', 'request_hash', 'result', 'created_at'];

    protected $hidden = ['token_hash', 'request_hash'];

    protected function casts(): array
    {
        return ['buyer_id' => 'integer', 'order_id' => 'integer', 'cart_id' => 'integer', 'result' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Buy again results are immutable.'));
        static::deleting(fn () => throw new LogicException('Buy again results are immutable.'));
    }
}
