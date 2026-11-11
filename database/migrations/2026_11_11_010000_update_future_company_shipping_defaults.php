<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite default changes rebuild the table and break retained-evidence triggers.
            // Explicit model defaults cover application inserts on the isolated test database.
            return;
        }
        // Existing published company tariffs and purchased orders retain their original amounts.
        Schema::table('logistics_companies', function (Blueprint $table): void {
            $table->unsignedInteger('base_shipping_cents')->default(5000)->change();
            $table->unsignedInteger('additional_kg_cents')->default(2000)->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('logistics_companies', function (Blueprint $table): void {
            $table->unsignedInteger('base_shipping_cents')->default(6000)->change();
            $table->unsignedInteger('additional_kg_cents')->default(0)->change();
        });
    }
};
