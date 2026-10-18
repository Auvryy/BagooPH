<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_sale_id')->constrained()->restrictOnDelete();
            $table->boolean('subscribed')->default(true);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['buyer_id', 'product_sale_id']);
            $table->index(['subscribed', 'notified_at', 'id']);
        });
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->timestamp('discarded_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropColumn('discarded_at'));
        Schema::dropIfExists('sale_reminders');
    }
};
