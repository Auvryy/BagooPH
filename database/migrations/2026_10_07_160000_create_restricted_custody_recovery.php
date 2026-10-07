<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_recovery_grants', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('restriction_affected_work_id')->constrained('restriction_affected_work')->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_checkpoint_id')->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('actor_name');
            $table->string('phase', 16);
            $table->string('reason', 1000);
            $table->json('source_state');
            $table->json('custody_snapshot');
            $table->json('restriction_snapshot');
            $table->string('request_token', 36);
            $table->string('request_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['authorized_by_id', 'request_token']);
        });
        Schema::create('custody_recovery_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('custody_recovery_grant_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('actor_name');
            $table->string('event_type', 20);
            $table->string('barcode_scanned');
            $table->string('notes', 1000);
            $table->json('source_state');
            $table->json('target_state');
            $table->string('request_token', 36);
            $table->string('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['custody_recovery_grant_id', 'event_type']);
            $table->unique(['custody_recovery_grant_id', 'request_token']);
        });
        foreach (['custody_recovery_grants', 'custody_recovery_receipts'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $op) {
                    DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($op)." BEFORE {$op} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Recovery authority and receipts are immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$
                    BEGIN RAISE EXCEPTION 'Recovery authority and receipts are immutable.' USING ERRCODE = '23000'; END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('custody_recovery_grants')->exists()) {
            throw new LogicException('Recorded recovery authority must be retained.');
        }
        Schema::dropIfExists('custody_recovery_receipts');
        Schema::dropIfExists('custody_recovery_grants');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_custody_recovery_receipts(); DROP FUNCTION IF EXISTS retain_custody_recovery_grants();');
        }
    }
};
