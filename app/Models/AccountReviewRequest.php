<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccountReviewRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['source_token', 'source_snapshot'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'resolved_at' => 'immutable_datetime', 'user_id' => 'integer', 'subject_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decision(): HasOne
    {
        return $this->hasOne(AccountReviewDecision::class, 'review_request_id');
    }
}
