<?php

use Illuminate\Support\Facades\DB;

// Standalone verification requires explicit permission for this disposable PostgreSQL database only.
function riderRaceConfigure(): void
{
    $database = getenv('RIDER_RACE_DATABASE');
    if (getenv('RIDER_RACE_APPROVED') !== 'disposable-only'
        || ! is_string($database) || ! preg_match('/\Abagoo_rider_race_[a-f0-9]{12}\z/', $database)
        || getenv('DB_HOST') !== 'pg' || getenv('DB_DATABASE') !== $database) {
        throw new RuntimeException('Refusing an unapproved or non-disposable race database.');
    }
    config(['database.default' => 'pgsql', 'database.connections.pgsql' => [
        'driver' => 'pgsql', 'host' => 'pg', 'port' => '5432', 'database' => $database,
        'username' => 'race', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable',
    ], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array']);
    DB::purge('pgsql');
    DB::statement("set statement_timeout to '15s'");
    DB::statement("set lock_timeout to '10s'");
    if (DB::selectOne('select current_database() as name')->name !== $database) {
        throw new RuntimeException('Disposable database identity did not match.');
    }
}
