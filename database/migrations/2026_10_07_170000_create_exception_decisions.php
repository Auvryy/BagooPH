<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('restriction_affected_work_id')->nullable()->constrained('restriction_affected_work')->restrictOnDelete();
            $table->foreignId('delivery_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('logistics_manifest_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('pickup_claim_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('logistics_company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('actor_name');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('responsible_name')->nullable();
            $table->string('action', 16);
            $table->string('reason', 1000);
            $table->json('source_state');
            $table->json('supporting_evidence');
            $table->string('request_token', 36);
            $table->string('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['actor_id', 'request_token']);
            foreach (['restriction_affected_work_id', 'delivery_attempt_id', 'logistics_manifest_event_id', 'pickup_claim_id'] as $source) {
                $table->index([$source, 'id']);
            }
        });
        $oneSource = '(CASE WHEN restriction_affected_work_id IS NULL THEN 0 ELSE 1 END + CASE WHEN delivery_attempt_id IS NULL THEN 0 ELSE 1 END + CASE WHEN logistics_manifest_event_id IS NULL THEN 0 ELSE 1 END + CASE WHEN pickup_claim_id IS NULL THEN 0 ELSE 1 END) = 1';
        if (DB::getDriverName() === 'sqlite') {
            $sqliteSource = preg_replace('/\b(restriction_affected_work_id|delivery_attempt_id|logistics_manifest_event_id|pickup_claim_id)\b/', 'NEW.$1', $oneSource);
            DB::unprepared("CREATE TRIGGER exception_decisions_source BEFORE INSERT ON exception_decisions WHEN NOT ({$sqliteSource}) BEGIN SELECT RAISE(ABORT, 'Choose exactly one actual exception source.'); END");
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared('CREATE TRIGGER exception_decisions_no_'.strtolower($op)." BEFORE {$op} ON exception_decisions BEGIN SELECT RAISE(ABORT, 'Exception decisions are immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE exception_decisions ADD CONSTRAINT exception_decisions_source CHECK ({$oneSource})");
            DB::unprepared("CREATE FUNCTION retain_exception_decisions() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Exception decisions are immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER exception_decisions_immutable BEFORE UPDATE OR DELETE ON exception_decisions FOR EACH ROW EXECUTE FUNCTION retain_exception_decisions();");
        }
    }

    public function down(): void
    {
        if (DB::table('exception_decisions')->exists()) {
            throw new LogicException('Recorded exception decisions must be retained.');
        }
        Schema::dropIfExists('exception_decisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_exception_decisions();');
        }
    }
};
