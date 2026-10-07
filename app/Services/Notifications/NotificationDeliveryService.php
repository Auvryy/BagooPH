<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationDeliveryService
{
    public function record(int $recipientId, string $type, string $sourceKey, array $data): NotificationDelivery
    {
        // This intent is part of the source transaction; only delivery runs after commit.
        $delivery = NotificationDelivery::firstOrCreate(['recipient_id' => $recipientId, 'source_key' => $sourceKey],
            ['type' => $type, 'data' => $data, 'available_at' => now()]);
        DB::afterCommit(fn () => $this->deliver($delivery->id));

        return $delivery;
    }

    public function deliver(string $id): bool
    {
        try {
            return DB::transaction(function () use ($id) {
                $delivery = NotificationDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($delivery->delivered_at) {
                    return true;
                }
                $existing = DB::table('notifications')->where('notifiable_type', User::class)
                    ->where('notifiable_id', $delivery->recipient_id)->where('source_key', $delivery->source_key)->first();
                if (! $existing) {
                    DB::table('notifications')->insert(['id' => $delivery->id, 'type' => $delivery->type,
                        'notifiable_type' => User::class, 'notifiable_id' => $delivery->recipient_id,
                        'source_key' => $delivery->source_key, 'data' => json_encode($delivery->data, JSON_THROW_ON_ERROR),
                        'read_at' => null, 'created_at' => $delivery->created_at, 'updated_at' => now()]);
                }
                $delivery->update(['delivered_at' => now(), 'last_attempt_at' => now(), 'attempts' => $delivery->attempts + 1]);

                return true;
            }, 3);
        } catch (Throwable $exception) {
            // Do not expose exception messages: database errors can contain private payloads.
            try {
                Log::warning('Notification delivery will retry.', ['delivery_id' => $id, 'exception_type' => $exception::class]);
            } catch (Throwable) {
                // Logging is also a side effect; its failure must not escape after commit.
            }
            try {
                DB::transaction(function () use ($id) {
                    $delivery = NotificationDelivery::whereKey($id)->lockForUpdate()->first();
                    if ($delivery && ! $delivery->delivered_at) {
                        $delivery->update(['attempts' => $delivery->attempts + 1, 'last_attempt_at' => now(),
                            'available_at' => now()->addSeconds(min(3600, 60 * (2 ** min($delivery->attempts, 6))))]);
                    }
                });
            } catch (Throwable) {
                // The durable intent remains pending even when retry bookkeeping is unavailable.
            }

            return false;
        }
    }

    public function deliverPending(int $limit = 100): array
    {
        $ids = NotificationDelivery::whereNull('delivered_at')->where('available_at', '<=', now())
            ->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('id');
        $delivered = 0;
        foreach ($ids as $id) {
            $delivered += (int) $this->deliver($id);
        }

        return ['attempted' => $ids->count(), 'delivered' => $delivered, 'failed' => $ids->count() - $delivered];
    }
}
