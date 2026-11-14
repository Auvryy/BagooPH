<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewDispute extends Model
{
    protected $fillable = [
        'reference',
        'review_id',
        'shop_id',
        'seller_id',
        'buyer_id',
        'reason_code',
        'seller_evidence',
        'status',
        'adjudicated_by_id',
        'adjudication_notes',
        'adjudicated_at',
        'buyer_appeal_reason',
        'buyer_appealed_at',
        'appeal_reviewed_by_id',
        'appeal_notes',
        'appeal_reviewed_at',
    ];

    protected $casts = [
        'adjudicated_at' => 'immutable_datetime',
        'buyer_appealed_at' => 'immutable_datetime',
        'appeal_reviewed_at' => 'immutable_datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function adjudicatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjudicated_by_id');
    }

    public function appealReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_reviewed_by_id');
    }
}
