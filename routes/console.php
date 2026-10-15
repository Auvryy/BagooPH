<?php

use App\Services\Logistics\RiderCompanyOfferService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pickup:process-due')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:deliver-pending')->everyMinute()->withoutOverlapping();

Artisan::command('rider-companies:expire-offers {--limit=100}', function () {
    $limit = $this->option('limit');
    if (! is_string($limit) || ! preg_match('/\A[1-9][0-9]{0,3}\z/', $limit) || (int) $limit > 1000) {
        $this->error('Use a limit from 1 to 1000.');

        return 2;
    }
    $count = app(RiderCompanyOfferService::class)->expireDue((int) $limit);
    $this->info("Expired {$count} rider assignment offers.");
})->purpose('Retain expired rider company offer decisions without changing custody');

Schedule::command('rider-companies:expire-offers')->everyMinute()->withoutOverlapping();
