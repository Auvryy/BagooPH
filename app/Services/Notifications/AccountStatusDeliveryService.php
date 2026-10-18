<?php

namespace App\Services\Notifications;

use App\Mail\AccountStatusMail;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\AccountIdentityService;
use App\Services\SecretMailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AccountStatusDeliveryService
{
    public function requireEmail(NotificationDelivery $delivery): void
    {
        $delivery->refresh();
        if (! $delivery->email_required) {
            $delivery->forceFill(['email_required' => true, 'email_available_at' => now()])->save();
        }
        DB::afterCommit(fn () => $this->deliver($delivery->id));
    }

    public function deliver(string $id): bool
    {
        try {
            return DB::transaction(function () use ($id): bool {
                $delivery = NotificationDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $delivery->email_required || $delivery->email_delivered_at !== null) {
                    return true;
                }
                $recipient = User::findOrFail($delivery->recipient_id);
                app(SecretMailService::class)->assertSafeTransport();
                $mail = new AccountStatusMail($recipient->name, AccountIdentityService::label($recipient->role),
                    $delivery->data['title'], $delivery->data['body']);
                Mail::to($recipient->routeNotificationForMail($mail))->send($mail);
                $delivery->forceFill(['email_delivered_at' => now(), 'email_last_attempt_at' => now(),
                    'email_attempts' => $delivery->email_attempts + 1, 'email_available_at' => null])->save();

                return true;
            }, 3);
        } catch (Throwable $exception) {
            try {
                Log::warning('Account status email will retry.', ['delivery_id' => $id, 'exception_type' => $exception::class]);
                DB::transaction(function () use ($id): void {
                    $delivery = NotificationDelivery::whereKey($id)->lockForUpdate()->first();
                    if ($delivery && ! $delivery->email_delivered_at) {
                        $delivery->forceFill(['email_attempts' => $delivery->email_attempts + 1, 'email_last_attempt_at' => now(),
                            'email_available_at' => now()->addSeconds(min(3600, 60 * (2 ** min($delivery->email_attempts, 6))))])->save();
                    }
                });
            } catch (Throwable) {
                // The delivery intent remains available even when retry bookkeeping fails.
            }

            return false;
        }
    }

    public function deliverPending(int $limit = 100): array
    {
        $ids = NotificationDelivery::where('email_required', true)->whereNull('email_delivered_at')
            ->where(fn ($query) => $query->whereNull('email_available_at')->orWhere('email_available_at', '<=', now()))
            ->orderBy('email_available_at')->orderBy('id')->limit($limit)->pluck('id');
        $delivered = 0;
        foreach ($ids as $id) {
            $delivered += (int) $this->deliver($id);
        }

        return ['attempted' => $ids->count(), 'delivered' => $delivered, 'failed' => $ids->count() - $delivered];
    }
}
