<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image_path')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'sort_order']);
        });

        $triggers = $this->productTriggers();
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('sub_category_id')->nullable()->after('category_id')->constrained('sub_categories')->nullOnDelete();
            $table->index('sub_category_id');
        });
        $this->restoreProductTriggers($triggers);
    }

    public function down(): void
    {
        $triggers = $this->productTriggers();
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sub_category_id');
        });
        $this->restoreProductTriggers($triggers);

        Schema::dropIfExists('sub_categories');
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
