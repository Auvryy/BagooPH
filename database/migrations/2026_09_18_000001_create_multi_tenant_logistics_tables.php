<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Logistics Companies (Multi-Tenant Master Entity)
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 20)->unique();
            $table->string('logo')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('address')->nullable();
            $table->string('status')->default('pending'); // pending, active, suspended
            $table->json('accreditation_details')->nullable(); // DTI, SEC, LTFRB, Insurance, etc.
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // 2. Logistics Hubs (Two-Tier Facility Network: Regional Mother Hubs & Local Bayan Hubs)
        Schema::create('logistics_hubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained('logistics_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->string('tier'); // regional_mother_hub, local_bayan_hub
            $table->string('province');
            $table->string('city_municipality');
            $table->string('barangay')->nullable();
            $table->string('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('capacity')->default(5000);
            $table->json('coverage_barangays')->nullable(); // Array of barangay names served by this Bayan hub
            $table->boolean('allows_self_pickup')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Logistics Fleet (Motorcycles, Tricycles, L300 Feeder Vans, Wing Trucks)
        Schema::create('logistics_fleet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained('logistics_companies')->cascadeOnDelete();
            $table->foreignId('hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->string('plate_number')->unique();
            $table->string('vehicle_type'); // motorcycle, tricycle, l300_van, wing_truck
            $table->string('model')->nullable();
            $table->decimal('capacity_kg', 8, 2)->default(100.00);
            $table->foreignId('assigned_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active'); // active, maintenance, idle
            $table->timestamps();
        });

        // 4. Hub Handlers / Personnel (Scoped to a physical hub station)
        Schema::create('hub_handlers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->cascadeOnDelete();
            $table->string('role_title')->default('Hub Handler'); // Intake Scanner, Sorter, Dispatcher
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'hub_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_handlers');
        Schema::dropIfExists('logistics_fleet');
        Schema::dropIfExists('logistics_hubs');
        Schema::dropIfExists('logistics_companies');
    }
};
