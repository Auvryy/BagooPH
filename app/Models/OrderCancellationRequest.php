<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class OrderCancellationRequest extends Model
{
    protected $guarded = ['id', 'reference'];

    protected $hidden = ['request_token_hash', 'request_fingerprint'];

    protected $casts = ['source_state' => 'array', 'version' => 'integer', 'respond_by' => 'datetime', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->reference = 'OCR-'.Str::upper((string) Str::uuid()));
        static::updating(function (self $request) {
            if (array_diff(array_keys($request->getDirty()), ['status', 'version', 'active_order_id', 'closed_at', 'updated_at'])) {
                throw new LogicException('Original cancellation requests must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Cancellation requests must be retained.'));
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderCancellationEvent::class, 'cancellation_request_id')->orderBy('version');
    }
}
