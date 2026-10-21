<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;

class AccountLoginSession extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['session_key_hash', 'native_token_id', 'credential_fingerprint', 'session_version', 'network_address', 'user_agent'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'session_version' => 'integer', 'native_token_id' => 'integer',
            'network_address' => 'encrypted', 'user_agent' => 'encrypted',
            'signed_in_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
            'metadata_cleared_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $session) {
            if ($session->isDirty(['user_id', 'channel', 'method', 'native_token_id', 'signed_in_at'])) {
                throw new LogicException('Original sign-in identity must be retained.');
            }
            if ($session->getRawOriginal('revoked_at') !== null && $session->isDirty(['revoked_at', 'revocation_reason', 'revocation_action_id'])) {
                throw new LogicException('An ended sign-in cannot be reopened or replaced.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nativeToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'native_token_id');
    }
}
