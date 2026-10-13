<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FACTS = ['id', 'reference', 'delivery_id', 'logistics_company_id', 'reporter_id', 'reporter_role', 'kind',
        'reported_holder', 'reported_location', 'notes', 'source_snapshot', 'evidence_path', 'evidence_hash',
        'evidence_extension', 'evidence_mime', 'request_token', 'request_fingerprint', 'created_at'];

    public function up(): void
    {
        Schema::create('handover_issues', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_delivery_id')->nullable()->unique()->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('reporter_role', 20);
            $table->string('kind', 40);
            $table->string('reported_holder', 100);
            $table->string('reported_location', 255);
            $table->string('notes', 1000);
            $table->json('source_snapshot');
            $table->string('evidence_path');
            $table->string('evidence_hash', 64);
            $table->string('evidence_extension', 5);
            $table->string('evidence_mime', 80);
            $table->string('request_token', 36);
            $table->string('request_fingerprint', 64);
            $table->string('state', 30)->default('pending_review');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['reporter_id', 'request_token']);
            $table->index(['logistics_company_id', 'state']);
        });
        Schema::create('handover_issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('handover_issue_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('event_type', 30);
            $table->string('notes', 1000);
            $table->json('parcel_snapshot');
            $table->string('request_token', 36);
            $table->string('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['handover_issue_id', 'actor_id', 'request_token'], 'handover_event_retry_unique');
        });

        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.$field IS NOT OLD.$field", self::FACTS));
            DB::unprepared("CREATE TRIGGER handover_facts_retained BEFORE UPDATE ON handover_issues WHEN $changed BEGIN SELECT RAISE(ABORT, 'Original handover report must be retained.'); END;");
            foreach (['handover_issues', 'handover_issue_events'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT, 'Handover evidence must be retained.'); END;");
            }
            DB::unprepared("CREATE TRIGGER handover_events_no_update BEFORE UPDATE ON handover_issue_events BEGIN SELECT RAISE(ABORT, 'Handover history is immutable.'); END;");
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($field) => $field === 'source_snapshot'
                ? "NEW.$field::text IS DISTINCT FROM OLD.$field::text" : "NEW.$field IS DISTINCT FROM OLD.$field", self::FACTS));
            DB::unprepared("CREATE FUNCTION retain_handover_facts() RETURNS trigger AS \$\$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Handover evidence must be retained.' USING ERRCODE = '23000'; END IF; IF $changed THEN RAISE EXCEPTION 'Original handover report must be retained.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER handover_facts_retained BEFORE UPDATE OR DELETE ON handover_issues FOR EACH ROW EXECUTE FUNCTION retain_handover_facts();
                CREATE FUNCTION retain_handover_events() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Handover history is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER handover_events_retained BEFORE UPDATE OR DELETE ON handover_issue_events FOR EACH ROW EXECUTE FUNCTION retain_handover_events();");
        }
    }

    public function down(): void
    {
        if (DB::table('handover_issues')->exists()) {
            throw new LogicException('Recorded handover reports and evidence must be retained.');
        }
        Schema::dropIfExists('handover_issue_events');
        Schema::dropIfExists('handover_issues');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_handover_facts(); DROP FUNCTION IF EXISTS retain_handover_events();');
        }
    }
};
