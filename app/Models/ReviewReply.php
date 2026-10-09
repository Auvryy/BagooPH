<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReply extends Model
{
    protected $fillable = ['review_id', 'seller_id', 'shop_id', 'text'];

    protected static function booted(): void
    {
        static::updating(function (ReviewReply $reply) {
            if ($reply->isDirty(['review_id', 'seller_id', 'shop_id', 'created_at'])) {
                throw new \LogicException('A saved reply must retain its original review, seller, shop and publication time.');
            }
        });
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
