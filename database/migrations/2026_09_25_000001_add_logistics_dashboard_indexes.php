<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->index(
                ['logistics_company_id', 'current_hub_id', 'status'],
                'deliveries_company_current_hub_status_idx'
            );
            $table->index(
                ['logistics_company_id', 'origin_bayan_hub_id', 'status', 'current_hub_id'],
                'deliveries_company_origin_intake_idx'
            );
        });

        Schema::table('delivery_checkpoints', function (Blueprint $table) {
            $table->index(
                ['hub_id', 'checkpoint_type', 'created_at'],
                'checkpoints_hub_type_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('delivery_checkpoints', function (Blueprint $table) {
            $table->dropIndex('checkpoints_hub_type_created_idx');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex('deliveries_company_current_hub_status_idx');
            $table->dropIndex('deliveries_company_origin_intake_idx');
        });
    }
};
