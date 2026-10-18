<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['buyer_id', 'shop_id']);
            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_follows');
    }
};
