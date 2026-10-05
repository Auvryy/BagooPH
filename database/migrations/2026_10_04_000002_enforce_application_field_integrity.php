<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->selectRaw('LOWER(email)')->groupByRaw('LOWER(email)')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Case-equivalent account emails require controlled review before this migration. No identities were changed.');
        }
        foreach (['users', 'shops', 'logistics_companies'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->text('address')->nullable()->change());
        }
        DB::statement('CREATE UNIQUE INDEX users_email_case_insensitive_unique ON users (LOWER(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_case_insensitive_unique');
        // Keep wider address columns: narrowing could truncate later valid applications.
    }
};
