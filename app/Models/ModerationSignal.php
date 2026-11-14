<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationSignal extends Model
{
    protected $fillable = [
        'reference',
        'signal_type',
        'severity',
        'status',
        'is_punitive',
        'subject_user_id',
        'order_id',
        'delivery_id',
        'review_id',
        'message_id',
        'matched_terms',
        'context_payload',
        'summary',
        'buyer_checkin_response',
        'buyer_checkin_notes',
        'checked_in_at',
        'reviewed_by_id',
        'review_notes',
        'reviewed_at',
    ];

    protected $casts = [
        'is_punitive' => 'boolean',
        'matched_terms' => 'array',
        'context_payload' => 'array',
        'checked_in_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
