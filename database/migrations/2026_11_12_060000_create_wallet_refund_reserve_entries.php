<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_refund_reserve_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->uuid('authority_reference');
            $table->foreignId('buyer_return_repayment_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source_kind', 40);
            $table->bigInteger('delta_cents');
            $table->json('context');
            $table->timestamp('created_at');
            $table->index(['order_id', 'source_kind']);
            $table->index(['authority_reference', 'source_kind']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER wallet_refund_reserve_entries_retain_'.strtolower($operation)." BEFORE {$operation} ON wallet_refund_reserve_entries
                    BEGIN SELECT RAISE(ABORT, 'Retain the actual virtual refund reserve and original source.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_wallet_refund_reserve_entry() RETURNS trigger AS \$\$ BEGIN
                RAISE EXCEPTION 'Retain the actual virtual refund reserve and original source.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER wallet_refund_reserve_entries_retained BEFORE UPDATE OR DELETE ON wallet_refund_reserve_entries FOR EACH ROW EXECUTE FUNCTION retain_wallet_refund_reserve_entry();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain actual virtual refund reserve history.');
    }
};
