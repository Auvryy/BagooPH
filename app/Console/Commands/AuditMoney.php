<?php

namespace App\Console\Commands;

use App\Services\Finance\MoneyAuditService;
use Illuminate\Console\Command;

class AuditMoney extends Command
{
    protected $signature = 'bagoo:audit-money';

    protected $description = 'Check that every order total, seller and platform share, shipping share and cash record adds up (read-only).';

    public function handle(MoneyAuditService $audit): int
    {
        $result = $audit->run();
        $this->info("Orders checked: {$result['orders']} · clean: {$result['checked']} · issues: ".count($result['issues']));
        if ($result['issues'] !== []) {
            $this->table(['Order', 'Check', 'What is wrong'], array_map(fn ($issue) => array_values($issue), $result['issues']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
