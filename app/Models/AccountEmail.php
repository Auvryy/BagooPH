<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountEmail extends Model
{
    protected $fillable = ['user_id', 'email', 'verified_at'];

    protected function casts(): array
    {
        return ['is_original' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
