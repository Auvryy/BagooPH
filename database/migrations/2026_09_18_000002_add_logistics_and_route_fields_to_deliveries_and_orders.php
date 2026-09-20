<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Orders table extensions (Delivery mode, Hub pickup option, Destination barangay)
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_type')->default('doorstep')->after('status'); // doorstep, hub_self_pickup
            $table->foreignId('pickup_hub_id')->nullable()->after('delivery_type')->constrained('logistics_hubs')->nullOnDelete();
            $table->string('destination_barangay')->nullable()->after('shipping_city');
        });

        // 2. Deliveries table extensions (Multi-hop routing facilities, manifests, failure tracking, POD geofence)
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('logistics_company_id')->nullable()->after('courier_id')->constrained('logistics_companies')->nullOnDelete();
            $table->foreignId('origin_bayan_hub_id')->nullable()->after('logistics_company_id')->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('origin_mother_hub_id')->nullable()->after('origin_bayan_hub_id')->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('destination_mother_hub_id')->nullable()->after('origin_mother_hub_id')->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('destination_bayan_hub_id')->nullable()->after('destination_mother_hub_id')->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('current_hub_id')->nullable()->after('destination_bayan_hub_id')->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('assigned_rider_id')->nullable()->after('current_hub_id')->constrained('users')->nullOnDelete();

            $table->string('delivery_type')->default('doorstep')->after('status'); // doorstep, hub_self_pickup
            $table->string('shuttle_manifest_number')->nullable()->after('delivery_type');
            $table->string('truck_manifest_number')->nullable()->after('shuttle_manifest_number');
            $table->string('destination_bin')->nullable()->after('truck_manifest_number');

            $table->string('failure_reason')->nullable()->after('destination_bin');
            $table->integer('failure_attempts')->default(0)->after('failure_reason');

            $table->decimal('pod_latitude', 10, 7)->nullable()->after('proof_image');
            $table->decimal('pod_longitude', 10, 7)->nullable()->after('pod_latitude');
            $table->boolean('pod_geofence_verified')->default(false)->after('pod_longitude');
            $table->string('pod_signature')->nullable()->after('pod_geofence_verified');
        });

        // 3. Delivery Checkpoints extensions (Physical Hub Station, Facility code, GPS telemetry)
        Schema::table('delivery_checkpoints', function (Blueprint $table) {
            $table->foreignId('hub_id')->nullable()->after('delivery_id')->constrained('logistics_hubs')->nullOnDelete();
            $table->string('facility_code', 30)->nullable()->after('hub_id');
            $table->decimal('latitude', 10, 7)->nullable()->after('location_name');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('manifest_number')->nullable()->after('barcode_scanned');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_checkpoints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hub_id');
            $table->dropColumn(['facility_code', 'latitude', 'longitude', 'manifest_number']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logistics_company_id');
            $table->dropConstrainedForeignId('origin_bayan_hub_id');
            $table->dropConstrainedForeignId('origin_mother_hub_id');
            $table->dropConstrainedForeignId('destination_mother_hub_id');
            $table->dropConstrainedForeignId('destination_bayan_hub_id');
            $table->dropConstrainedForeignId('current_hub_id');
            $table->dropConstrainedForeignId('assigned_rider_id');
            $table->dropColumn([
                'delivery_type',
                'shuttle_manifest_number',
                'truck_manifest_number',
                'destination_bin',
                'failure_reason',
                'failure_attempts',
                'pod_latitude',
                'pod_longitude',
                'pod_geofence_verified',
                'pod_signature',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pickup_hub_id');
            $table->dropColumn(['delivery_type', 'destination_barangay']);
        });
    }
};
