<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReviewDecision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['before_state', 'after_state'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'reviewed_at' => 'immutable_datetime', 'reviewer_id' => 'integer'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
