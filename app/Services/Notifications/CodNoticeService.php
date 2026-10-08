<?php

namespace App\Services\Notifications;

use App\Models\CodCashEvent;
use App\Models\LogisticsCompany;

class CodNoticeService
{
    public function __construct(private readonly NotificationDeliveryService $delivery) {}

    public function record(CodCashEvent $event): void
    {
        $account = $event->account;
        $title = match ($event->event_type) {
            'rider_collection', 'counter_collection' => 'COD collection was recorded',
            'handover_offered' => 'A cash handover needs confirmation',
            'handover_received' => $event->discrepancy_cents ? 'A cash handover has a difference to review' : 'A cash handover was received',
            'handover_cancelled' => 'A cash handover was cancelled',
            'adjustment' => 'A cash difference was reviewed',
            'platform_reconciled' => 'COD reached platform reconciliation',
            'recovery_authorized' => 'A cash recovery handover was authorized',
            default => 'An older cash record needs evidence review',
        };
        $ownerId = LogisticsCompany::whereKey($account->logistics_company_id)->value('user_id');
        foreach (array_unique(array_filter([$event->actor_id, $event->from_user_id, $event->to_user_id, $account->collector_id, $ownerId])) as $id) {
            $this->delivery->record((int) $id, 'cod-event', 'cod:'.$event->reference, [
                'title' => $title, 'body' => 'Open the cash record for its current responsibilities. Seller payouts are a separate step.',
                'target' => 'cod-cash', 'cod_account_id' => $account->id,
            ]);
        }
    }
}
