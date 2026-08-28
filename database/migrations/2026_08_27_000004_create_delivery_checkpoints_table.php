<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('checkpoint_type'); // seller_pack, courier_pickup, hub_intake, barangay_sort, doorstep_handover
            $table->string('location_name')->nullable();
            $table->string('barcode_scanned')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('scanned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proof_image')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_checkpoints');
    }
};
