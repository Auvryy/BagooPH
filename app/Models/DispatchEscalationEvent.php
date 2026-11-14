<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchEscalationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dispatch_escalation_id',
        'event_type',
        'from_tier',
        'to_tier',
        'idempotency_key',
        'actor_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->created_at ??= now();
        });

        static::updating(function (): void {
            throw new DomainException('Dispatch escalation events are append-only.');
        });

        static::deleting(function (): void {
            throw new DomainException('Dispatch escalation events are append-only.');
        });
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(DispatchEscalation::class, 'dispatch_escalation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
