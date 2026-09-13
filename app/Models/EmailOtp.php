<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'code_hash',
        'purpose',
        'token',
        'attempts',
        'verified_at',
        'expires_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * Check if the OTP is currently active and eligible for verification.
     */
    public function isValid(): bool
    {
        return $this->verified_at === null
            && $this->attempts < 5
            && $this->expires_at->isFuture();
    }

    /**
     * Scope query to active, unexpired, and unverified records.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('verified_at')
            ->where('attempts', '<', 5)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope query for a specific email and purpose.
     */
    public function scopeForEmail(Builder $query, string $email, string $purpose = 'registration'): Builder
    {
        return $query->where('email', strtolower(trim($email)))
            ->where('purpose', $purpose);
    }
}
