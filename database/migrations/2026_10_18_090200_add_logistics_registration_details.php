<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_companies', function (Blueprint $table): void {
            $table->string('tin', 12)->nullable()->unique();
            $table->json('service_areas')->default('[]');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE logistics_companies ADD CONSTRAINT company_tin_format CHECK (tin IS NULL OR tin ~ '^[0-9]{12}$')");
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER company_tin_format_'.strtolower($operation).' BEFORE '.$operation." ON logistics_companies
                    WHEN NEW.tin IS NOT NULL AND (LENGTH(NEW.tin) <> 12 OR NEW.tin GLOB '*[^0-9]*') BEGIN
                    SELECT RAISE(ABORT, 'Use a canonical 12-digit company TIN.'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE logistics_companies DROP CONSTRAINT company_tin_format');
        } else {
            DB::statement('DROP TRIGGER company_tin_format_insert');
            DB::statement('DROP TRIGGER company_tin_format_update');
        }
        Schema::table('logistics_companies', fn (Blueprint $table) => $table->dropUnique(['tin']));
        DB::statement('ALTER TABLE logistics_companies DROP COLUMN tin');
        DB::statement('ALTER TABLE logistics_companies DROP COLUMN service_areas');
    }
};
