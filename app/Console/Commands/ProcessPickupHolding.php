<?php

namespace App\Console\Commands;

use App\Services\Logistics\PickupClaimService;
use Illuminate\Console\Command;

class ProcessPickupHolding extends Command
{
    protected $signature = 'pickup:process-due';

    protected $description = 'Record due hub-pickup reminders and seven-day expiry.';

    public function handle(PickupClaimService $service): int
    {
        $this->info($service->processDue().' pickup holding events recorded.');

        return self::SUCCESS;
    }
}
