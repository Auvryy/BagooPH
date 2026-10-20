<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_voucher_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_order_allocation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_entry_id')->unique()->constrained('voucher_funding_entries')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 500);
            $table->timestamp('created_at');
        });
        Schema::create('wallet_voucher_restorations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_redemption_id')->unique()->constrained()->restrictOnDelete();
            $table->json('payment_references');
            $table->timestamp('created_at');
        });
        foreach (['wallet_voucher_releases', 'wallet_voucher_restorations'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_retain_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Wallet reservation history is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION {$table}_retain() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Wallet reservation history is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER {$table}_retain BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_retain();");
            }
        }
    }

    public function down(): void
    {
        foreach (['wallet_voucher_restorations', 'wallet_voucher_releases'] as $table) {
            Schema::dropIfExists($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP FUNCTION IF EXISTS {$table}_retain()");
            }
        }
    }
};
