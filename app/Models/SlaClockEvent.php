<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaClockEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sla_clock_id',
        'event_type',
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
            throw new DomainException('SLA clock events are append-only.');
        });

        static::deleting(function (): void {
            throw new DomainException('SLA clock events are append-only.');
        });
    }

    public function clock(): BelongsTo
    {
        return $this->belongsTo(SlaClock::class, 'sla_clock_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
