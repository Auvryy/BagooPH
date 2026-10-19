<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_return_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_return_case_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_payment_event_id')->nullable()->constrained('seller_settlement_events')->restrictOnDelete();
            foreach (['gross_cents', 'commission_cents', 'shop_discount_cents', 'platform_discount_cents', 'buyer_cents', 'seller_cents', 'recovery_due_cents'] as $field) {
                $table->unsignedBigInteger($field);
            }
            $table->bigInteger('platform_cents');
            $table->json('unit_snapshot');
            $table->json('source_snapshot');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('buyer_return_money_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('buyer_return_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('event_type', 40);
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_event_id')->nullable()->constrained('buyer_return_money_events')->restrictOnDelete();
            $table->foreignId('consumed_source_id')->nullable()->unique()->constrained('buyer_return_money_events')->restrictOnDelete();
            $table->foreignId('repayment_case_id')->nullable()->unique()->constrained('buyer_return_cases')->restrictOnDelete();
            $table->string('cash_reference', 120)->nullable()->unique();
            $table->text('reason');
            $table->string('proof_path')->nullable();
            $table->char('proof_hash', 64)->nullable();
            $table->string('proof_mime', 40)->nullable();
            $table->unsignedInteger('proof_bytes')->nullable();
            $table->char('request_token_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->json('context');
            $table->timestamp('created_at');
            $table->unique(['buyer_return_case_id', 'sequence'], 'buyer_return_money_sequence_unique');
        });
        foreach (['buyer_return_adjustments', 'buyer_return_money_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $action) {
                    DB::unprepared("CREATE TRIGGER {$table}_retain_".strtolower($action)." BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT, 'Retain original return money evidence'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION {$table}_retain() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Retain original return money evidence'; END; $$");
                DB::unprepared("CREATE TRIGGER {$table}_retain BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_retain()");
            }
        }
    }

    public function down(): void
    {
        foreach (['buyer_return_money_events', 'buyer_return_adjustments'] as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Return money history must be retained.');
            }
        }
        foreach (['buyer_return_money_events', 'buyer_return_adjustments'] as $table) {
            Schema::dropIfExists($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS {$table}_retain()");
            }
        }
    }
};
