<?php

namespace App\Services\Advertisements;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdvertisementScheduleLock
{
    public function lock(): bool
    {
        if (! Schema::hasTable('advertisement_schedule_locks')) {
            return false;
        }

        // The migration seeds this single mutex row. Recreate it idempotently if
        // a restored or repaired database retained the table but lost its row.
        DB::table('advertisement_schedule_locks')->insertOrIgnore(['id' => 1]);

        return DB::table('advertisement_schedule_locks')->where('id', 1)->lockForUpdate()->first() !== null;
    }
}
