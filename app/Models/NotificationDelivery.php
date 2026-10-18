<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class NotificationDelivery extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'available_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime', 'discarded_at' => 'immutable_datetime', 'attempts' => 'integer',
            'email_required' => 'boolean', 'email_delivered_at' => 'immutable_datetime',
            'email_attempts' => 'integer', 'email_last_attempt_at' => 'immutable_datetime', 'email_available_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $delivery) {
            if ($delivery->isDirty(['id', 'recipient_id', 'type', 'source_key', 'data', 'created_at'])) {
                throw new LogicException('Notification event identity must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Notification delivery history must be retained.'));
    }
}
