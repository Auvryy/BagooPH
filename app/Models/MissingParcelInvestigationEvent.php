<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissingParcelInvestigationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'missing_parcel_investigation_id',
        'event_type',
        'actor_id',
        'notes',
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
            throw new DomainException('Investigation timeline events are append-only.');
        });

        static::deleting(function (): void {
            throw new DomainException('Investigation timeline events are append-only.');
        });
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(MissingParcelInvestigation::class, 'missing_parcel_investigation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
