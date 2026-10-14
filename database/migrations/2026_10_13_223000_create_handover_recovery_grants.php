<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_recovery_grants', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('handover_issue_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('holder_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by_id')->constrained('users')->restrictOnDelete();
            $table->string('mode', 32);
            $table->string('notes', 1000);
            $table->json('source_snapshot');
            $table->json('authority_snapshot');
            $table->uuid('request_token');
            $table->string('request_fingerprint', 64);
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['authorized_by_id', 'request_token']);
            $table->index(['handover_issue_id', 'expires_at']);
        });
        Schema::table('handover_issue_events', function (Blueprint $table) {
            $table->foreignId('handover_recovery_grant_id')->nullable()->constrained()->restrictOnDelete();
        });
        $this->protectEvents();
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $suffix = strtolower($operation);
                DB::unprepared("CREATE TRIGGER handover_grants_no_$suffix BEFORE $operation ON handover_recovery_grants BEGIN SELECT RAISE(ABORT, 'Recovery authorizations must be retained.'); END;");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER handover_grants_retained BEFORE UPDATE OR DELETE ON handover_recovery_grants FOR EACH ROW EXECUTE FUNCTION retain_handover_events();');
        }
    }

    public function down(): void
    {
        if (DB::table('handover_recovery_grants')->exists()) {
            throw new LogicException('Recorded recovery authorizations must be retained.');
        }
        Schema::table('handover_issue_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('handover_recovery_grant_id'));
        $this->protectEvents();
        Schema::dropIfExists('handover_recovery_grants');
    }

    private function protectEvents(): void
    {
        // SQLite rebuilds this table for foreign keys and drops its old triggers.
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("DROP TRIGGER IF EXISTS handover_events_no_update;
                DROP TRIGGER IF EXISTS handover_issue_events_no_delete;
                CREATE TRIGGER handover_events_no_update BEFORE UPDATE ON handover_issue_events BEGIN SELECT RAISE(ABORT, 'Handover history is immutable.'); END;
                CREATE TRIGGER handover_issue_events_no_delete BEFORE DELETE ON handover_issue_events BEGIN SELECT RAISE(ABORT, 'Handover evidence must be retained.'); END;");
        }
    }
};
