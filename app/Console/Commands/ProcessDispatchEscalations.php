<?php

namespace App\Console\Commands;

use App\Services\Logistics\DispatchRecoveryService;
use Illuminate\Console\Command;

class ProcessDispatchEscalations extends Command
{
    protected $signature = 'dispatch:process-escalations
                            {--dry-run : Preview escalation actions without mutating deliveries or sending notifications}
                            {--limit=100 : Maximum ready parcels to evaluate per run}';

    protected $description = 'Evaluate the 4-step first-mile pickup escalation ladder and 72-hour penalty-exempt fallbacks.';

    public function handle(DispatchRecoveryService $recovery): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(500, (int) $this->option('limit')));
        $result = $recovery->processEscalations($dryRun, $limit);

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
