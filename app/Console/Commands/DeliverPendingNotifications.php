<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Console\Command;

class DeliverPendingNotifications extends Command
{
    protected $signature = 'notifications:deliver-pending {--limit=100 : Maximum due deliveries to attempt}';

    protected $description = 'Retry due persistent in-app notification deliveries';

    public function handle(NotificationDeliveryService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $result = $service->deliverPending($limit);
        $this->info("Attempted {$result['attempted']}; delivered {$result['delivered']}; pending retry {$result['failed']}.");

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
