<?php

namespace App\Console\Commands;

use App\Services\Monitoring\StateMonitoringService;
use Illuminate\Console\Command;

class ScanSlaMonitoring extends Command
{
    protected $signature = 'monitoring:scan-sla
                            {--dry-run : Preview planned warnings, breaches, escalations, and closures without writing}
                            {--limit=100 : Maximum number of SLA clocks to evaluate per tick}';

    protected $description = 'Evaluate state-aware SLA clocks, ETA drift, pickup escalations, and return deadlines.';

    public function handle(StateMonitoringService $monitoring): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(500, (int) $this->option('limit')));
        $result = $monitoring->scan($dryRun, $limit);

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
