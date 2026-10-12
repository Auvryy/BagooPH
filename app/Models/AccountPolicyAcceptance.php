<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPolicyAcceptance extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'terms_version', 'privacy_version', 'source', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
