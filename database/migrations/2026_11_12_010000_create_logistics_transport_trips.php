<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_transport_trips', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('destination_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('logistics_fleet')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->uuid('creation_token');
            $table->string('creation_fingerprint', 64);
            $table->string('status', 20)->default('ready');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['created_by_id', 'creation_token']);
        });
        Schema::create('logistics_trip_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_transport_trips')->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('active_manifest_id')->nullable()->unique()->constrained('logistics_manifests')->restrictOnDelete();
            $table->json('source_snapshot');
            $table->timestamp('created_at');
            $table->unique(['trip_id', 'manifest_id']);
        });
        Schema::create('logistics_trip_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_transport_trips')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 20);
            $table->unsignedInteger('sequence');
            $table->uuid('request_token');
            $table->string('fingerprint', 64);
            $table->json('context');
            $table->timestamp('created_at');
            $table->unique(['trip_id', 'sequence']);
            $table->unique(['trip_id', 'request_token']);
        });
        $this->retain('logistics_trip_events', []);
        $this->retain('logistics_trip_manifests', ['active_manifest_id']);
    }

    public function down(): void
    {
        if (DB::table('logistics_transport_trips')->exists()) {
            throw new LogicException('Retain actual trip membership and departure evidence.');
        }
        Schema::dropIfExists('logistics_trip_events');
        Schema::dropIfExists('logistics_trip_manifests');
        Schema::dropIfExists('logistics_transport_trips');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_logistics_trip_events(); DROP FUNCTION IF EXISTS retain_logistics_trip_manifests();');
        }
    }

    private function retain(string $table, array $mutable): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Original trip evidence must be retained.'); END");
            $fields = array_diff(Schema::getColumnListing($table), $mutable);
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER {$table}_retain_update BEFORE UPDATE ON {$table} WHEN {$changed} BEGIN SELECT RAISE(ABORT, 'Original trip evidence must be retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $without = implode('', array_map(fn ($field) => " - '{$field}'", $mutable));
            DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Original trip evidence must be retained.' USING ERRCODE = '23000'; END IF;
                IF (to_jsonb(NEW){$without}) IS DISTINCT FROM (to_jsonb(OLD){$without}) THEN
                    RAISE EXCEPTION 'Original trip evidence must be retained.' USING ERRCODE = '23000';
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER {$table}_retained BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
        }
    }
};
