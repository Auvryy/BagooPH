<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IDENTITY = ['reference', 'observation_event_id', 'original_manifest_parcel_id', 'delivery_id', 'source_checkpoint_id',
        'logistics_company_id', 'observed_hub_id', 'expected_hub_id', 'authorized_by_id', 'original_direction',
        'original_transit_status', 'route_snapshot', 'hub_ids', 'source_state', 'custody_snapshot', 'reason',
        'request_token', 'request_fingerprint', 'created_at'];

    public function up(): void
    {
        $manifestTriggers = $this->sqliteManifestTriggers();
        Schema::create('parcel_recovery_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('observation_event_id')->unique()->constrained('logistics_manifest_events')->restrictOnDelete();
            $table->foreignId('original_manifest_parcel_id')->constrained('logistics_manifest_parcels')->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_delivery_id')->nullable()->unique()->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('source_checkpoint_id')->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained('logistics_companies')->restrictOnDelete();
            $table->foreignId('observed_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('expected_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('authorized_by_id')->constrained('users')->restrictOnDelete();
            $table->string('original_direction', 20);
            $table->string('original_transit_status', 64);
            $table->json('route_snapshot');
            $table->json('hub_ids');
            $table->json('source_state');
            $table->json('custody_snapshot');
            $table->string('reason', 1000);
            $table->string('request_token', 36);
            $table->string('request_fingerprint', 64);
            $table->string('state', 20)->default('authorized');
            $table->unsignedInteger('leg_index')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['authorized_by_id', 'request_token']);
            $table->index(['logistics_company_id', 'state']);
        });
        Schema::create('parcel_recovery_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('parcel_recovery_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->foreignId('hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('manifest_event_id')->nullable()->constrained('logistics_manifest_events')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('barcode_scanned')->nullable();
            $table->string('reason', 1000);
            $table->json('source_state');
            $table->json('target_state');
            $table->json('payload');
            $table->string('request_token', 36)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['parcel_recovery_case_id', 'request_token']);
        });
        Schema::table('logistics_manifests', function (Blueprint $table) {
            $table->foreignId('parcel_recovery_case_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('recovery_leg_index')->nullable();
            $table->unique(['parcel_recovery_case_id', 'recovery_leg_index'], 'manifest_recovery_leg_unique');
        });
        $this->restoreSqliteManifestTriggers($manifestTriggers);
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER parcel_recovery_event_no_'.strtolower($operation)." BEFORE {$operation} ON parcel_recovery_events BEGIN SELECT RAISE(ABORT, 'Parcel recovery evidence is immutable.'); END");
            }
            DB::unprepared("CREATE TRIGGER parcel_recovery_no_delete BEFORE DELETE ON parcel_recovery_cases BEGIN SELECT RAISE(ABORT, 'Parcel recovery history must be retained.'); END");
            $conditions = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", self::IDENTITY));
            DB::unprepared("CREATE TRIGGER parcel_recovery_identity_retained BEFORE UPDATE ON parcel_recovery_cases WHEN {$conditions} BEGIN SELECT RAISE(ABORT, 'Parcel recovery identity must be retained.'); END");
            DB::unprepared("CREATE TRIGGER manifest_recovery_identity_retained BEFORE UPDATE ON logistics_manifests WHEN NEW.parcel_recovery_case_id IS NOT OLD.parcel_recovery_case_id OR NEW.recovery_leg_index IS NOT OLD.recovery_leg_index BEGIN SELECT RAISE(ABORT, 'Recovery manifest identity must be retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER parcel_recovery_event_immutable BEFORE UPDATE OR DELETE ON parcel_recovery_events FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()');
            $conditions = implode(' OR ', array_map(function ($field) {
                $cast = in_array($field, ['route_snapshot', 'hub_ids', 'source_state', 'custody_snapshot'], true) ? '::text' : '';

                return "NEW.{$field}{$cast} IS DISTINCT FROM OLD.{$field}{$cast}";
            }, self::IDENTITY));
            DB::unprepared("CREATE FUNCTION retain_parcel_recovery_identity() RETURNS trigger AS \$\$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Parcel recovery history must be retained.' USING ERRCODE = '23000'; END IF; IF {$conditions} THEN RAISE EXCEPTION 'Parcel recovery identity must be retained.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER parcel_recovery_identity_retained BEFORE UPDATE OR DELETE ON parcel_recovery_cases FOR EACH ROW EXECUTE FUNCTION retain_parcel_recovery_identity();
                CREATE FUNCTION retain_manifest_recovery_identity() RETURNS trigger AS \$\$ BEGIN IF NEW.parcel_recovery_case_id IS DISTINCT FROM OLD.parcel_recovery_case_id OR NEW.recovery_leg_index IS DISTINCT FROM OLD.recovery_leg_index THEN RAISE EXCEPTION 'Recovery manifest identity must be retained.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER manifest_recovery_identity_retained BEFORE UPDATE ON logistics_manifests FOR EACH ROW EXECUTE FUNCTION retain_manifest_recovery_identity();");
        }
    }

    public function down(): void
    {
        if (DB::table('parcel_recovery_cases')->exists()) {
            throw new LogicException('Recorded parcel recovery and its evidence must be retained.');
        }
        $pgsql = DB::getDriverName() === 'pgsql';
        foreach (['parcel_recovery_event_no_update', 'parcel_recovery_event_no_delete', 'parcel_recovery_no_delete', 'parcel_recovery_identity_retained', 'parcel_recovery_event_immutable', 'manifest_recovery_identity_retained'] as $trigger) {
            $table = str_starts_with($trigger, 'manifest_') ? 'logistics_manifests' : (str_starts_with($trigger, 'parcel_recovery_event_') ? 'parcel_recovery_events' : 'parcel_recovery_cases');
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger.($pgsql ? ' ON '.$table : ''));
        }
        if ($pgsql) {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_manifest_recovery_identity(); DROP FUNCTION IF EXISTS retain_parcel_recovery_identity();');
        }
        $manifestTriggers = $this->sqliteManifestTriggers();
        Schema::table('logistics_manifests', function (Blueprint $table) {
            $table->dropUnique('manifest_recovery_leg_unique');
            $table->dropConstrainedForeignId('parcel_recovery_case_id');
            $table->dropColumn('recovery_leg_index');
        });
        $this->restoreSqliteManifestTriggers($manifestTriggers);
        Schema::dropIfExists('parcel_recovery_events');
        Schema::dropIfExists('parcel_recovery_cases');
    }

    private function sqliteManifestTriggers(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'logistics_manifests'") : [];
    }

    private function restoreSqliteManifestTriggers(array $triggers): void
    {
        foreach ($triggers as $trigger) {
            if (! DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$trigger->name])) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
