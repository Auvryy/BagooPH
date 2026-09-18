<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Shops table extensions (Single Root Category Enclosure & Multi-Shop Toggling)
        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('root_category_id')->nullable()->after('rating')->constrained('categories')->nullOnDelete();
            $table->boolean('is_default')->default(true)->after('root_category_id');
        });

        // 2. Addresses table extensions (Interactive Map Coordinates & Admin Verification)
        Schema::table('addresses', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('landmark')->nullable()->after('longitude');
            $table->string('verification_status')->default('verified')->after('landmark'); // verified, pending_review, unverified
            $table->text('verification_notes')->nullable()->after('verification_status');
        });

        // 3. Courier Profiles extensions (Company Affiliation, Primary Hub, Assigned Barangay, Fleet Vehicle)
        Schema::table('courier_profiles', function (Blueprint $table) {
            $table->foreignId('logistics_company_id')->nullable()->after('user_id')->constrained('logistics_companies')->nullOnDelete();
            $table->foreignId('assigned_hub_id')->nullable()->after('logistics_company_id')->constrained('logistics_hubs')->nullOnDelete();
            $table->string('assigned_barangay')->nullable()->after('assigned_hub_id');
            $table->foreignId('vehicle_id')->nullable()->after('assigned_barangay')->constrained('logistics_fleet')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courier_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logistics_company_id');
            $table->dropConstrainedForeignId('assigned_hub_id');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn('assigned_barangay');
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn([
                'latitude',
                'longitude',
                'landmark',
                'verification_status',
                'verification_notes',
            ]);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('root_category_id');
            $table->dropColumn('is_default');
        });
    }
};
