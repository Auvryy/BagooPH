<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_companies', function (Blueprint $table) {
            $table->boolean('rider_intake_enabled')->default(false);
            $table->unsignedBigInteger('rider_intake_version')->default(0);
        });

        Schema::create('rider_company_intake_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_token');
            $table->char('fingerprint', 64);
            $table->boolean('before_enabled');
            $table->boolean('after_enabled');
            $table->unsignedBigInteger('before_version');
            $table->unsignedBigInteger('after_version');
            $table->timestamp('recorded_at', 6);
            $table->unique(['actor_id', 'request_token'], 'rci_actor_token');
            $table->unique(['logistics_company_id', 'after_version'], 'rci_company_version');
        });

        Schema::create('rider_company_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_token');
            $table->char('submission_fingerprint', 64);
            $table->unsignedBigInteger('intake_version');
            $table->jsonb('company_snapshot');
            $table->jsonb('profile_snapshot');
            $table->text('note')->nullable();
            $table->boolean('sharing_acknowledged');
            $table->string('status', 20);
            $table->unsignedBigInteger('version');
            $table->timestamp('submitted_at', 6);
            $table->timestamp('withdrawn_at', 6)->nullable();
            $table->unique(['rider_id', 'request_token'], 'rcr_rider_token');
            $table->index(['rider_id', 'id']);
            $table->index(['logistics_company_id', 'status', 'id'], 'rcr_company_status');
        });

        Schema::create('rider_company_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_company_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_token');
            $table->char('fingerprint', 64);
            $table->string('action', 20);
            $table->unsignedBigInteger('request_version');
            $table->jsonb('result');
            $table->timestamp('recorded_at', 6);
            $table->unique(['actor_id', 'request_token'], 'rce_actor_token');
            $table->unique(['rider_company_request_id', 'request_version'], 'rce_request_version');
        });

        DB::statement("CREATE UNIQUE INDEX rcr_one_pending_rider ON rider_company_requests (rider_id) WHERE status = 'pending'");

        foreach (['rider_company_intake_events', 'rider_company_request_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Rider company history is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION {$table}_immutable() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Rider company history is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_immutable();");
            }
        }

        $unchanged = implode(' AND ', array_map(fn ($column) => DB::getDriverName() === 'pgsql'
            ? "NEW.{$column} IS NOT DISTINCT FROM OLD.{$column}"
            : "NEW.{$column} IS OLD.{$column}", ['id', 'reference', 'logistics_company_id', 'rider_id', 'request_token', 'submission_fingerprint', 'intake_version', 'company_snapshot', 'profile_snapshot', 'note', 'sharing_acknowledged', 'submitted_at']));
        // The withdrawal receipt is inserted first in the same transaction as its projection.
        $withdrawal = "OLD.status = 'pending' AND NEW.status = 'withdrawn' AND NEW.version = OLD.version + 1 AND NEW.withdrawn_at IS NOT NULL AND NEW.withdrawn_at >= OLD.submitted_at AND EXISTS (SELECT 1 FROM rider_company_request_events e WHERE e.rider_company_request_id = OLD.id AND e.actor_id = OLD.rider_id AND e.action = 'withdrawn' AND e.request_version = NEW.version AND e.recorded_at = NEW.withdrawn_at)";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER rcr_guard_update BEFORE UPDATE ON rider_company_requests WHEN NOT ({$unchanged} AND {$withdrawal}) BEGIN SELECT RAISE(ABORT, 'Rider company requests retain their original submission and require an evidenced withdrawal.'); END");
            DB::unprepared("CREATE TRIGGER rcr_guard_delete BEFORE DELETE ON rider_company_requests BEGIN SELECT RAISE(ABORT, 'Rider company requests retain their history.'); END");
            DB::unprepared("CREATE TRIGGER rcr_guard_insert BEFORE INSERT ON rider_company_requests WHEN NEW.status != 'pending' OR NEW.version != 1 OR NEW.withdrawn_at IS NOT NULL OR NEW.sharing_acknowledged != 1 OR NEW.intake_version < 1 BEGIN SELECT RAISE(ABORT, 'A rider company request must begin as an acknowledged pending submission.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION rcr_guard_mutation() RETURNS trigger AS \$\$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Rider company requests retain their history.' USING ERRCODE = '23000'; ELSIF TG_OP = 'INSERT' THEN IF NEW.status != 'pending' OR NEW.version != 1 OR NEW.withdrawn_at IS NOT NULL OR NOT NEW.sharing_acknowledged OR NEW.intake_version < 1 THEN RAISE EXCEPTION 'A rider company request must begin as an acknowledged pending submission.' USING ERRCODE = '23000'; END IF; ELSIF NOT ({$unchanged} AND {$withdrawal}) THEN RAISE EXCEPTION 'Rider company requests retain their original submission and require an evidenced withdrawal.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER rcr_guard_mutation BEFORE INSERT OR UPDATE OR DELETE ON rider_company_requests FOR EACH ROW EXECUTE FUNCTION rcr_guard_mutation();");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('logistics_companies', 'rider_intake_version')
            && DB::table('logistics_companies')->where(fn ($query) => $query->where('rider_intake_enabled', true)->orWhere('rider_intake_version', '!=', 0))->exists()) {
            throw new LogicException('Retain rider company intake decisions before rolling back this migration.');
        }
        foreach (['rider_company_request_events', 'rider_company_requests', 'rider_company_intake_events'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException('Retain rider company submissions and decisions before rolling back this migration.');
            }
        }
        Schema::dropIfExists('rider_company_request_events');
        Schema::dropIfExists('rider_company_requests');
        Schema::dropIfExists('rider_company_intake_events');
        if (DB::getDriverName() === 'pgsql') {
            foreach (['rider_company_intake_events_immutable', 'rider_company_request_events_immutable', 'rcr_guard_mutation'] as $function) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
            }
        }
        Schema::table('logistics_companies', fn (Blueprint $table) => $table->dropColumn(['rider_intake_enabled', 'rider_intake_version']));
    }
};
