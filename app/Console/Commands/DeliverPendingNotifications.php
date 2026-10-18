<?php

namespace App\Console\Commands;

use App\Services\Notifications\AccountStatusDeliveryService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Console\Command;

class DeliverPendingNotifications extends Command
{
    protected $signature = 'notifications:deliver-pending {--limit=100 : Maximum due deliveries to attempt}';

    protected $description = 'Retry due persistent in-app notifications and account status emails';

    public function handle(NotificationDeliveryService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $result = $service->deliverPending($limit);
        $this->info("Attempted {$result['attempted']}; delivered {$result['delivered']}; pending retry {$result['failed']}; expired or cancelled {$result['discarded']}.");
        $email = app(AccountStatusDeliveryService::class)->deliverPending($limit);
        $this->info("Account emails: attempted {$email['attempted']}; sent {$email['delivered']}; pending retry {$email['failed']}.");

        return $result['failed'] || $email['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
