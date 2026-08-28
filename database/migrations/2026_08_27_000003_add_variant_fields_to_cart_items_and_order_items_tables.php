<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->string('color')->nullable()->after('unit_price');
            $table->string('size')->nullable()->after('color');
            $table->string('sku_snapshot')->nullable()->after('size');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('color')->nullable()->after('subtotal');
            $table->string('size')->nullable()->after('color');
            $table->string('sku_snapshot')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['color', 'size', 'sku_snapshot']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['color', 'size', 'sku_snapshot']);
        });
    }
};
