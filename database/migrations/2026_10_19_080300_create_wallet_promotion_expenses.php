<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_promotion_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_order_allocation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('source_entry_id')->unique()->constrained('voucher_funding_entries')->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER wallet_promotion_retain_'.strtolower($operation)." BEFORE {$operation} ON wallet_promotion_expenses BEGIN SELECT RAISE(ABORT, 'Wallet promotion evidence is immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION wallet_promotion_retain() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Wallet promotion evidence is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER wallet_promotion_retain BEFORE UPDATE OR DELETE ON wallet_promotion_expenses FOR EACH ROW EXECUTE FUNCTION wallet_promotion_retain();");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_promotion_expenses');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS wallet_promotion_retain()');
        }
    }
};
