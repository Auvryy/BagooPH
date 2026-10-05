<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['shops', 'logistics_companies', 'logistics_hubs', 'hub_handlers', 'logistics_fleet'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('restriction_version')->default(0));
        }
    }

    public function down(): void
    {
        if (DB::table('restriction_decisions')->whereIn('subject_type', ['shop', 'company', 'hub', 'handler', 'fleet'])->exists()) {
            throw new LogicException('Resource restriction versions must be retained with recorded decisions.');
        }
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('restriction_version'));
        }
    }
};
