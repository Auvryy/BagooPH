<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('store_categories')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('normalized_name', 100);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->unique(['shop_id', 'normalized_name']);
            $table->index(['shop_id', 'parent_id', 'position']);
        });
        Schema::create('product_store_category', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_category_id')->constrained()->cascadeOnDelete();
            $table->unique(['product_id', 'store_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_store_category');
        Schema::dropIfExists('store_categories');
    }
};
