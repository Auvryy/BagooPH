<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $triggers = $this->productTriggers();
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('legacy_category_id')->nullable()->after('category_id')->constrained('categories')->restrictOnDelete();
        });
        $this->restoreProductTriggers($triggers);

        DB::table('shops')->whereNotNull('root_category_id')->orderBy('id')->chunkById(200, function ($shops): void {
            foreach ($shops as $shop) {
                DB::table('products')->where('shop_id', $shop->id)
                    ->whereNotNull('category_id')->where('category_id', '!=', $shop->root_category_id)
                    ->update(['legacy_category_id' => DB::raw('category_id'), 'category_id' => $shop->root_category_id]);
            }
        });

        Schema::create('store_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('normalized_name', 50);
            $table->timestamps();
            $table->unique(['shop_id', 'normalized_name']);
        });

        Schema::create('product_store_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_tag_id')->constrained('store_tags')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'store_tag_id']);
            $table->index(['store_tag_id', 'product_id']);
        });
    }

    public function down(): void
    {
        DB::table('products')->whereNotNull('legacy_category_id')->orderBy('id')->chunkById(500, function ($products): void {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update(['category_id' => $product->legacy_category_id]);
            }
        });

        Schema::dropIfExists('product_store_tag');
        Schema::dropIfExists('store_tags');
        $triggers = $this->productTriggers();
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_category_id');
        });
        $this->restoreProductTriggers($triggers);
    }

    private function productTriggers(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'products'") : [];
    }

    private function restoreProductTriggers(array $triggers): void
    {
        foreach ($triggers as $trigger) {
            $existing = DB::table('sqlite_master')->select('tbl_name', 'sql')
                ->where('type', 'trigger')->where('name', $trigger->name)->first();
            if ($existing && ($existing->tbl_name !== $trigger->tbl_name || $existing->sql !== $trigger->sql)) {
                $quotedName = '"'.str_replace('"', '""', $trigger->name).'"';
                DB::unprepared("DROP TRIGGER IF EXISTS {$quotedName}");
                $existing = null;
            }
            if (! $existing) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
