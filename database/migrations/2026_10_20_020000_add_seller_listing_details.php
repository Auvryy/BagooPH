<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertSqliteAlterIsSafe();
        $triggers = $this->retentionTriggers();
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 100)->nullable();
            $table->string('condition', 10)->nullable();
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable()->default(0.50)->change();
        });
        $this->restoreTriggers($triggers);
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('declared_weight_kg', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        $this->assertSqliteAlterIsSafe();
        $triggers = $this->retentionTriggers();
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('declared_weight_kg'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['brand', 'condition', 'length_cm', 'width_cm', 'height_cm']));
        $this->restoreTriggers($triggers);
        // Missing draft/legacy weights stay unknown; rollback must not invent a declared weight.
    }

    private function assertSqliteAlterIsSafe(): void
    {
        // SQLite cannot disable cascading foreign keys inside an existing transaction.
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0 && DB::table('products')->exists()) {
            throw new RuntimeException('Run listing schema changes outside an existing SQLite transaction to preserve product and order references.');
        }
    }

    private function retentionTriggers(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ('products', 'order_items')") : [];
    }

    private function restoreTriggers(array $triggers): void
    {
        // SQLite may rebuild altered tables; preserve existing retention guards verbatim.
        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
