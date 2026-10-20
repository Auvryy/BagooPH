<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_key', 80)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->string('business_key', 100)->unique();
            $table->string('kind', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('buyer_return_case_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('request_token_hash', 64)->nullable()->unique();
            $table->string('fingerprint', 64);
            $table->string('reason', 1000);
            $table->json('context');
            $table->timestamp('created_at');
        });
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('delta_cents');
            $table->bigInteger('balance_after_cents');
            $table->timestamp('created_at');
            $table->unique(['wallet_transaction_id', 'wallet_account_id']);
        });
        Schema::create('wallet_top_up_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pending_buyer_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_reason', 500)->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('request_token_hash', 64)->unique();
            $table->string('fingerprint', 64);
            $table->string('review_token_hash', 64)->nullable()->unique();
            $table->string('review_fingerprint', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('source_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('settlement_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('wallet_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('request_token_hash', 64)->unique();
            $table->string('action', 20);
            $table->string('outcome', 20);
            $table->string('message', 500);
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
        foreach (['wallet_transactions', 'wallet_entries', 'wallet_payment_attempts'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_retain_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Wallet history is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION {$table}_retain() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Wallet history is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER {$table}_retain BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_retain();");
            }
        }
        $check = "balance_cents >= 0 OR kind IN ('demo_issuance', 'promotion')";
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wallet_accounts ADD CONSTRAINT wallet_nonnegative CHECK ({$check})");
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER wallet_nonnegative_'.strtolower($operation)." BEFORE {$operation} ON wallet_accounts WHEN NOT (NEW.balance_cents >= 0 OR NEW.kind IN ('demo_issuance', 'promotion')) BEGIN SELECT RAISE(ABORT, 'Wallet funds are insufficient.'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['wallet_payment_attempts', 'wallet_payments', 'wallet_top_up_requests', 'wallet_entries', 'wallet_transactions', 'wallet_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['wallet_transactions', 'wallet_entries', 'wallet_payment_attempts'] as $table) {
                DB::statement("DROP FUNCTION IF EXISTS {$table}_retain()");
            }
        }
    }
};
