<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_manifests', fn (Blueprint $table) => $table->string('direction', 16)->default('outbound'));
        Schema::create('delivery_return_routes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('delivery_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('source_checkpoint_id')->unique()->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('tracking_number_snapshot');
            $table->json('forward_route');
            $table->json('hub_ids');
            $table->timestamps();
        });
        Schema::create('delivery_return_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_return_route_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('barcode_scanned');
            $table->string('notes', 1000)->nullable();
            $table->json('source_state');
            $table->json('target_state');
            $table->string('request_token', 36)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['delivery_return_route_id', 'event_type'], 'return_route_event_unique');
            $table->unique(['delivery_id', 'request_token']);
        });
        foreach (['delivery_return_routes', 'delivery_return_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($operation)." BEFORE {$operation} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Return custody evidence is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$
                    BEGIN RAISE EXCEPTION 'Return custody evidence is immutable.' USING ERRCODE = '23000'; END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                        FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER manifest_direction_retained BEFORE UPDATE ON logistics_manifests
                WHEN NEW.direction IS NOT OLD.direction
                BEGIN SELECT RAISE(ABORT, 'Manifest direction must be retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION retain_manifest_direction() RETURNS trigger AS $$
                BEGIN
                    IF NEW.direction IS DISTINCT FROM OLD.direction THEN
                        RAISE EXCEPTION 'Manifest direction must be retained.' USING ERRCODE = '23000';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER manifest_direction_retained BEFORE UPDATE ON logistics_manifests
                    FOR EACH ROW EXECUTE FUNCTION retain_manifest_direction();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('delivery_return_routes')->exists() || DB::table('delivery_return_events')->exists()
            || DB::table('logistics_manifests')->exists()) {
            throw new LogicException('Return custody history and manifest direction must be retained while records exist.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS manifest_direction_retained'.(DB::getDriverName() === 'pgsql' ? ' ON logistics_manifests' : ''));
        Schema::dropIfExists('delivery_return_events');
        Schema::dropIfExists('delivery_return_routes');
        Schema::table('logistics_manifests', fn (Blueprint $table) => $table->dropColumn('direction'));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_delivery_return_routes(); DROP FUNCTION IF EXISTS retain_delivery_return_events(); DROP FUNCTION IF EXISTS retain_manifest_direction();');
        }
    }
};
