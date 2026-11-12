<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_refund_credit_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->uuid('authority_reference');
            $table->foreignId('repayment_plan_id')->nullable()->constrained('buyer_return_repayment_plans')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('active_key', 64)->nullable()->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->text('reason');
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(1);
            $table->char('request_token_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->char('review_token_hash', 64)->nullable()->unique();
            $table->char('review_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['authority_reference', 'status']);
        });
        $fields = ['id', 'reference', 'authority_reference', 'repayment_plan_id', 'actor_id', 'amount_cents', 'reason', 'request_token_hash', 'request_fingerprint', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER wallet_refund_credit_request_update BEFORE UPDATE ON wallet_refund_credit_requests
                WHEN OLD.status <> 'pending' OR {$changed} BEGIN SELECT RAISE(ABORT, 'Retain the original demonstration funding request and final review.'); END");
            DB::unprepared("CREATE TRIGGER wallet_refund_credit_request_delete BEFORE DELETE ON wallet_refund_credit_requests BEGIN SELECT RAISE(ABORT, 'Retain original demonstration funding requests.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field}::text IS DISTINCT FROM OLD.{$field}::text", $fields));
            DB::unprepared("CREATE FUNCTION retain_wallet_refund_credit_request() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain original demonstration funding requests.' USING ERRCODE = '23000'; END IF;
                IF OLD.status <> 'pending' OR {$changed} THEN RAISE EXCEPTION 'Retain the original demonstration funding request and final review.' USING ERRCODE = '23000'; END IF;
                RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER wallet_refund_credit_request_retained BEFORE UPDATE OR DELETE ON wallet_refund_credit_requests FOR EACH ROW EXECUTE FUNCTION retain_wallet_refund_credit_request();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain original virtual funding credit requests and reviews.');
    }
};
