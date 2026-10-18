<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('name', 100);
            $table->decimal('price', 12, 2);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('used_quantity')->default(0);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'kind', 'starts_at', 'ends_at']);
        });
        if (DB::getDriverName() === 'sqlite') {
            // Native nullable ADD COLUMN preserves the existing cross-table retention triggers.
            DB::statement('ALTER TABLE order_items ADD COLUMN product_sale_id INTEGER REFERENCES product_sales(id) ON DELETE RESTRICT');
        } else {
            Schema::table('order_items', fn (Blueprint $table) => $table->foreignId('product_sale_id')->nullable()->constrained('product_sales')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        if (DB::table('product_sales')->exists()) {
            throw new LogicException('Recorded sale schedules and order allocations must be retained.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE order_items DROP COLUMN product_sale_id');
        } else {
            Schema::table('order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_sale_id'));
        }
        Schema::dropIfExists('product_sales');
    }
};
