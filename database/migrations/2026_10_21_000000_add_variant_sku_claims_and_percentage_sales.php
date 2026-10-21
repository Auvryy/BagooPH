<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sku_claims', function (Blueprint $table) {
            $table->string('sku_key', 191)->primary();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });
        foreach (DB::table('products')->select(['id', 'sku', 'variants'])->orderBy('id')->cursor() as $product) {
            $skus = [$product->sku];
            foreach (json_decode($product->variants ?? 'null', true)['combinations'] ?? [] as $row) {
                if (! empty($row['sku'])) {
                    $skus[] = $row['sku'];
                }
            }
            foreach ($skus as $sku) {
                if (trim((string) $sku) !== '') {
                    DB::table('product_sku_claims')->insert(['sku_key' => Str::upper(trim($sku)), 'product_id' => $product->id]);
                }
            }
        }
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('low_stock_active')->default(false);
            $table->unsignedBigInteger('low_stock_cycle')->default(0);
        });
        Schema::table('product_sales', function (Blueprint $table) {
            $table->string('discount_type', 12)->default('fixed');
            $table->unsignedSmallInteger('percentage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_sales', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'percentage']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['low_stock_active', 'low_stock_cycle']));
        Schema::dropIfExists('product_sku_claims');
    }
};
