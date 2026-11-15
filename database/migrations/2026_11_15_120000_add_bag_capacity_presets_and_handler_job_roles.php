<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hub_handlers', 'job_role')) {
            Schema::table('hub_handlers', function (Blueprint $table): void {
                $table->string('job_role', 24)->default('all');
            });
        }

        Schema::table('mother_bags', function (Blueprint $table): void {
            if (! Schema::hasColumn('mother_bags', 'capacity_preset')) {
                $table->string('capacity_preset', 32)->nullable();
            }
            if (! Schema::hasColumn('mother_bags', 'max_count')) {
                $table->unsignedInteger('max_count')->nullable();
            }
            if (! Schema::hasColumn('mother_bags', 'max_weight_kg')) {
                $table->decimal('max_weight_kg', 8, 3)->nullable();
            }
            if (! Schema::hasColumn('mother_bags', 'max_volume_cm3')) {
                $table->unsignedBigInteger('max_volume_cm3')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mother_bags', function (Blueprint $table): void {
            foreach (['capacity_preset', 'max_count', 'max_weight_kg', 'max_volume_cm3'] as $col) {
                if (Schema::hasColumn('mother_bags', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasColumn('hub_handlers', 'job_role')) {
            Schema::table('hub_handlers', fn (Blueprint $table) => $table->dropColumn('job_role'));
        }
    }
};
