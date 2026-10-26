<?php

use App\Models\LogisticsCompany;
use Database\Seeders\LogisticsTransportSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Upgrade only the reserved, already approved demo network. Fresh
            // installations and companies owned by real users are left alone.
            $company = LogisticsCompany::eligible()
                ->where('code', 'BGX')
                ->where('name', 'Bagoo Express Dispatch Fleet')
                ->whereHas('user', fn ($owner) => $owner->whereIn('email', [
                    'motherhub@bagoo.test', 'logistics.admin@bagoo.test',
                ]))
                ->lockForUpdate()->first();

            if ($company) {
                app(LogisticsTransportSeeder::class)->run();
            }
        });
    }

    public function down(): void
    {
        // Retain accounts and vehicles that may now support recorded custody.
    }
};
