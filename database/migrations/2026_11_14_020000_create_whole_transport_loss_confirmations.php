<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whole_transport_loss_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->string('scope_kind', 24);
            $table->foreignId('logistics_company_id')->constrained('logistics_companies')->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('logistics_transport_trips')->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('logistics_fleet')->restrictOnDelete();
            $table->unsignedInteger('manifest_version');
            $table->unsignedInteger('trip_version')->nullable();
            $table->text('reason');
            $table->string('proof_path');
            $table->string('proof_mime', 100);
            $table->unsignedInteger('proof_bytes');
            $table->string('proof_hash', 64);
            $table->string('request_token_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('whole_transport_loss_manifests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whole_transport_loss_confirmation_id');
            $table->foreign('whole_transport_loss_confirmation_id', 'wtl_manifest_confirmation_fk')
                ->references('id')->on('whole_transport_loss_confirmations')->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('logistics_transport_trips')->restrictOnDelete();
            $table->string('whole_transport_key', 64)->nullable()->unique();
            $table->unique(['whole_transport_loss_confirmation_id', 'manifest_id'], 'wtl_manifest_unique');
        });

        Schema::create('whole_transport_loss_bags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whole_transport_loss_confirmation_id');
            $table->foreign('whole_transport_loss_confirmation_id', 'wtl_bag_confirmation_fk')
                ->references('id')->on('whole_transport_loss_confirmations')->restrictOnDelete();
            $table->foreignUuid('mother_bag_id')->unique()->constrained('mother_bags')->restrictOnDelete();
            $table->foreignId('manifest_id')->nullable()->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('return_trip_id')->nullable()->constrained('logistics_transport_trips')->restrictOnDelete();
            $table->string('original_status', 24);
            $table->string('physical_tag', 80)->nullable();
            $table->string('seal_number', 80)->nullable();
            $table->string('active_physical_key', 64)->nullable();
        });

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            foreach (['whole_transport_loss_confirmations', 'whole_transport_loss_manifests', 'whole_transport_loss_bags'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER wtl_'.$table.'_no_'.strtolower($operation)." BEFORE {$operation} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Whole-transport-loss confirmation history is immutable.'); END");
                }
            }
            $bagInvariant = "(NEW.status = 'lost' AND (OLD.status <> 'in_transit' OR NEW.opened_at IS NOT NULL OR NEW.opened_by_id IS NOT NULL
                OR NEW.active_physical_key IS NOT OLD.active_physical_key
                OR NOT EXISTS (SELECT 1 FROM whole_transport_loss_bags WHERE mother_bag_id = OLD.id)))
                OR (OLD.status = 'lost' AND (NEW.status IS NOT OLD.status OR NEW.active_physical_key IS NOT OLD.active_physical_key
                    OR NEW.physical_tag IS NOT OLD.physical_tag OR NEW.seal_number IS NOT OLD.seal_number
                    OR NEW.opened_at IS NOT OLD.opened_at OR NEW.opened_by_id IS NOT OLD.opened_by_id
                    OR NEW.manifest_id IS NOT OLD.manifest_id OR NEW.return_trip_id IS NOT OLD.return_trip_id))";
            DB::unprepared("CREATE TRIGGER wtl_mother_bags_update BEFORE UPDATE ON mother_bags WHEN {$bagInvariant}
                BEGIN SELECT RAISE(ABORT, 'Lost physical bags must retain unarrived status and locked physical tags.'); END");
        } elseif ($driver === 'pgsql') {
            DB::unprepared("CREATE FUNCTION wtl_retain_immutable() RETURNS trigger AS \$\$
                BEGIN RAISE EXCEPTION 'Whole-transport-loss confirmation history is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;");
            foreach (['whole_transport_loss_confirmations', 'whole_transport_loss_manifests', 'whole_transport_loss_bags'] as $table) {
                DB::unprepared("CREATE TRIGGER wtl_{$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION wtl_retain_immutable();");
            }
            DB::unprepared("CREATE FUNCTION wtl_guard_lost_mother_bag() RETURNS trigger AS \$\$
                BEGIN
                    IF NEW.status = 'lost' AND OLD.status <> 'lost' THEN
                        IF OLD.status <> 'in_transit' OR NEW.opened_at IS NOT NULL OR NEW.opened_by_id IS NOT NULL
                            OR NEW.active_physical_key IS DISTINCT FROM OLD.active_physical_key
                            OR NOT EXISTS (SELECT 1 FROM whole_transport_loss_bags WHERE mother_bag_id = OLD.id) THEN
                            RAISE EXCEPTION 'Lost physical bags must originate from in-transit sacks with independent loss confirmation.' USING ERRCODE = '23000';
                        END IF;
                    ELSIF OLD.status = 'lost' AND (
                        NEW.status IS DISTINCT FROM OLD.status OR NEW.active_physical_key IS DISTINCT FROM OLD.active_physical_key
                        OR NEW.physical_tag IS DISTINCT FROM OLD.physical_tag OR NEW.seal_number IS DISTINCT FROM OLD.seal_number
                        OR NEW.opened_at IS DISTINCT FROM OLD.opened_at OR NEW.opened_by_id IS DISTINCT FROM OLD.opened_by_id
                        OR NEW.manifest_id IS DISTINCT FROM OLD.manifest_id OR NEW.return_trip_id IS DISTINCT FROM OLD.return_trip_id
                    ) THEN
                        RAISE EXCEPTION 'Lost physical bags must retain unarrived status and locked physical tags.' USING ERRCODE = '23000';
                    END IF;
                    RETURN NEW;
                END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER wtl_mother_bags_guard BEFORE UPDATE ON mother_bags FOR EACH ROW EXECUTE FUNCTION wtl_guard_lost_mother_bag();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Whole-transport-loss confirmation records are immutable and cannot be rolled back.');
    }
};
