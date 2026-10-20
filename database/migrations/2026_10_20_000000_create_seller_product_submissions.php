<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_product_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->uuid('request_token');
            $table->char('payload_hash', 64);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['shop_id', 'request_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_product_submissions');
    }
};
