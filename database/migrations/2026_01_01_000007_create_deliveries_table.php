<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tracking_number')->unique();
            $table->string('logistics_partner')->default('Bagoo Express'); // Extensible for 3rd party logistics
            $table->string('status')->default('unassigned'); // unassigned, assigned, picked_up, in_transit, out_for_delivery, delivered, failed
            
            // Origin & Destination Info
            $table->string('pickup_store_name')->nullable();
            $table->text('pickup_address');
            $table->string('pickup_phone')->nullable();
            
            $table->text('delivery_address');
            $table->string('delivery_recipient_name');
            $table->string('delivery_phone');
            
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            
            $table->string('proof_image')->nullable();
            $table->text('courier_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
