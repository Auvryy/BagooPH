<?php

namespace App\Console\Commands;

use App\Services\VerificationDocumentMigrationService;
use Illuminate\Console\Command;
use Throwable;

class PrivatizeVerificationDocuments extends Command
{
    protected $signature = 'verification:privatize {--dry-run : Check legacy files without changing storage or records}';

    protected $description = 'Move legacy verification documents out of public storage and update their references';

    public function handle(VerificationDocumentMigrationService $migration): int
    {
        try {
            $result = $migration->migrate((bool) $this->option('dry-run'));
        } catch (Throwable) {
            $this->error('Verification migration could not run. Check database and storage access. No document details were logged.');

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Eligible' : 'Privatized').' verification files: '.$result['processed']);
        if ($result['failed']) {
            $this->error('Unresolved verification files or references: '.$result['failed'].'. Resolve missing, unsafe, or conflicting files before reopening verification review.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
