<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_transport_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('phone', 24)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['logistics_company_id', 'hub_id', 'status']);
        });
        $fleetDefinitions = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('tbl_name', 'logistics_fleet')->whereIn('type', ['index', 'trigger'])->whereNotNull('sql')->get()
            : collect();
        Schema::table('logistics_fleet', fn (Blueprint $table) => $table->foreignId('transport_driver_id')->nullable()->unique()->constrained('logistics_transport_drivers')->restrictOnDelete());
        foreach ($fleetDefinitions as $definition) {
            DB::unprepared('DROP '.strtoupper($definition->type).' IF EXISTS "'.$definition->name.'"');
            DB::unprepared($definition->sql);
        }

        foreach (['logistics_manifests', 'logistics_transport_trips', 'whole_transport_loss_confirmations'] as $tableName) {
            $definitions = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('tbl_name', $tableName)->whereIn('type', ['index', 'trigger'])->whereNotNull('sql')->get()
                : collect();
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('transport_driver_id')->nullable()->constrained('logistics_transport_drivers')->restrictOnDelete();
                $table->string('transport_driver_name', 100)->nullable();
                $table->unsignedBigInteger('driver_id')->nullable()->change();
            });
            foreach ($definitions as $definition) {
                DB::unprepared('DROP '.strtoupper($definition->type).' IF EXISTS "'.$definition->name.'"');
                DB::unprepared($definition->sql);
            }
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$tableName}_transport_identity BEFORE UPDATE ON {$tableName} WHEN NEW.transport_driver_id IS NOT OLD.transport_driver_id OR NEW.transport_driver_name IS NOT OLD.transport_driver_name BEGIN SELECT RAISE(ABORT, 'Original transport driver identity must be retained.'); END");
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$tableName}_driver_".strtolower($operation)." BEFORE {$operation} ON {$tableName} WHEN (NEW.driver_id IS NULL AND NEW.transport_driver_id IS NULL) OR (NEW.driver_id IS NOT NULL AND NEW.transport_driver_id IS NOT NULL) OR (NEW.transport_driver_id IS NOT NULL AND NEW.transport_driver_name IS NULL) BEGIN SELECT RAISE(ABORT, 'Choose one retained driver identity.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_driver_identity CHECK ((driver_id IS NULL) <> (transport_driver_id IS NULL) AND (transport_driver_id IS NULL OR transport_driver_name IS NOT NULL))");
                DB::unprepared("CREATE FUNCTION {$tableName}_transport_identity() RETURNS trigger AS \$\$ BEGIN IF ROW(NEW.transport_driver_id, NEW.transport_driver_name) IS DISTINCT FROM ROW(OLD.transport_driver_id, OLD.transport_driver_name) THEN RAISE EXCEPTION 'Original transport driver identity must be retained.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER {$tableName}_transport_identity BEFORE UPDATE ON {$tableName} FOR EACH ROW EXECUTE FUNCTION {$tableName}_transport_identity();");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain named transport drivers and their manifest history; use a reviewed forward migration.');
    }
};
