<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('seller_amount', 12, 2);
            $table->decimal('platform_commission', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(60.00);
            $table->string('status')->default('settled'); // pending, settled, refunded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledgers');
    }
};
