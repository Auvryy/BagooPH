<?php

use App\Services\AccountSessions\AccountSessionService;
use App\Services\Advertisements\AdvertisementMeasurementService;
use App\Services\Commerce\SaleReminderService;
use App\Services\Logistics\RiderCompanyOfferService;
use App\Services\Wallet\WalletPaymentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pickup:process-due')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:deliver-pending')->everyMinute()->withoutOverlapping();

Artisan::command('advertisements:prune-events', function () {
    $count = app(AdvertisementMeasurementService::class)->prune();
    $this->info("Removed {$count} expired advertisement event records; daily totals are retained.");
})->purpose('Remove anonymous advertisement event records after seven days');

Schedule::command('advertisements:prune-events')->daily()->withoutOverlapping();

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

Artisan::command('sales:send-reminders {--limit=100}', function () {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Use a limit from 1 to 1000.');

        return 2;
    }
    $count = app(SaleReminderService::class)->dispatchDue($limit);
    $this->info("Recorded {$count} due sale reminders.");
})->purpose('Send owned reminders shortly before upcoming flash slots');

Schedule::command('sales:send-reminders')->everyMinute()->withoutOverlapping();

Artisan::command('wallet:expire-payments {--limit=100}', function () {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Use a limit from 1 to 1000.');

        return 2;
    }
    $count = app(WalletPaymentService::class)->expireDue($limit);
    $this->info("Expired {$count} unpaid wallet orders and released their original reservations.");
})->purpose('Release unpaid virtual wallet orders after their one-hour payment deadline');

Schedule::command('wallet:expire-payments')->everyMinute()->withoutOverlapping();

Artisan::command('account-security:minimise-metadata', function () {
    $count = app(AccountSessionService::class)->minimiseOldMetadata();
    $this->info("Cleared private device/network metadata from {$count} older ended sessions; revocation protection is retained.");
})->purpose('Minimise old sign-in metadata without reopening revoked sessions');

Schedule::command('account-security:minimise-metadata')->daily()->withoutOverlapping();
