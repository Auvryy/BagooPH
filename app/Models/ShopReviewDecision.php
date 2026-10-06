<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ShopReviewDecision extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'shop_id', 'seller_id', 'reviewer_id', 'kyc_decision_id', 'root_category_id', 'identity_correction_request_id',
        'reviewer_role', 'reviewer_name', 'submission_token', 'decision', 'reason',
        'submission', 'before_state', 'after_state', 'reviewed_at',
    ];

    protected $hidden = ['submission_token', 'submission', 'before_state', 'after_state'];

    protected function casts(): array
    {
        return ['submission' => 'array', 'before_state' => 'array', 'after_state' => 'array', 'reviewed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Shop review decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Shop review decisions are immutable.'));
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
