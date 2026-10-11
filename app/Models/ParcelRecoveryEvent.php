<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class ParcelRecoveryEvent extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['source_state' => 'array', 'target_state' => 'array', 'payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference = 'PRE-'.strtoupper((string) Str::uuid());
            $event->actor_role = User::whereKey($event->actor_id)->value('role');
        });
        static::updating(fn () => throw new LogicException('Parcel recovery evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Parcel recovery evidence is immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
