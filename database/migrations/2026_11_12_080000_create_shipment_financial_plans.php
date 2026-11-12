<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_financial_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('order_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('undelivered_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('buyer_return_case_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 30);
            $table->json('snapshot');
            $table->text('reason');
            $table->string('proof_path');
            $table->string('proof_mime', 50);
            $table->unsignedBigInteger('proof_bytes');
            $table->char('proof_hash', 64);
            $table->char('request_token_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at');
        });
        Schema::create('shipment_financial_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('shipment_financial_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('source_event_id')->nullable()->constrained('shipment_financial_events')->restrictOnDelete();
            $table->foreignId('consumed_source_id')->nullable()->unique()->constrained('shipment_financial_events')->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('cash_reference', 120)->nullable()->unique();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 50)->nullable();
            $table->unsignedBigInteger('proof_bytes')->nullable();
            $table->char('proof_hash', 64)->nullable();
            $table->text('reason');
            $table->json('context');
            $table->char('request_token_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at');
            $table->unique(['shipment_financial_plan_id', 'sequence']);
        });
        foreach (['shipment_financial_plans', 'shipment_financial_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_retain_".strtolower($operation)." BEFORE {$operation} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Retain the original shipment financial review and actual money evidence.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$ BEGIN
                    RAISE EXCEPTION 'Retain the original shipment financial review and actual money evidence.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_retained BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain original shipment financial reviews and actual money evidence.');
    }
};
