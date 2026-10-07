<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_manifests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('logistics_company_id')->constrained('logistics_companies')->restrictOnDelete();
            $table->foreignId('source_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('destination_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('logistics_fleet')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('creation_token', 36);
            $table->string('creation_fingerprint', 64);
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('dispatcher_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('receiver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by_id', 'creation_token']);
            $table->index(['logistics_company_id', 'status']);
        });
        Schema::create('logistics_manifest_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('active_delivery_id')->nullable()->unique()->constrained('deliveries')->restrictOnDelete();
            $table->string('tracking_number_snapshot');
            $table->json('route_snapshot');
            $table->json('source_state');
            $table->boolean('included')->default(true);
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('receipt_condition', 20)->nullable();
            $table->timestamps();
            $table->unique(['manifest_id', 'delivery_id']);
        });
        Schema::create('logistics_manifest_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('manifest_parcel_id')->nullable()->constrained('logistics_manifest_parcels')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->foreignId('hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('barcode_scanned')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->json('source_state');
            $table->json('target_state');
            $table->json('payload');
            $table->string('request_token', 36)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['manifest_id', 'request_token']);
            $table->index(['manifest_parcel_id', 'event_type']);
        });
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER manifest_event_no_'.strtolower($operation)." BEFORE {$operation} ON logistics_manifest_events
                    BEGIN SELECT RAISE(ABORT, 'Manifest evidence is immutable.'); END");
            }
            DB::unprepared("CREATE TRIGGER manifest_member_no_delete BEFORE DELETE ON logistics_manifest_parcels
                BEGIN SELECT RAISE(ABORT, 'Manifest membership history must be retained.'); END");
            DB::unprepared("CREATE TRIGGER manifest_identity_retained BEFORE UPDATE ON logistics_manifests
                WHEN NEW.reference IS NOT OLD.reference OR NEW.logistics_company_id IS NOT OLD.logistics_company_id
                    OR NEW.source_hub_id IS NOT OLD.source_hub_id OR NEW.destination_hub_id IS NOT OLD.destination_hub_id
                    OR NEW.vehicle_id IS NOT OLD.vehicle_id OR NEW.driver_id IS NOT OLD.driver_id
                    OR NEW.created_by_id IS NOT OLD.created_by_id OR NEW.creation_token IS NOT OLD.creation_token
                    OR NEW.creation_fingerprint IS NOT OLD.creation_fingerprint OR NEW.created_at IS NOT OLD.created_at
                BEGIN SELECT RAISE(ABORT, 'Manifest identity must be retained.'); END");
            DB::unprepared("CREATE TRIGGER manifest_member_identity_retained BEFORE UPDATE ON logistics_manifest_parcels
                WHEN NEW.manifest_id IS NOT OLD.manifest_id OR NEW.delivery_id IS NOT OLD.delivery_id
                    OR NEW.tracking_number_snapshot IS NOT OLD.tracking_number_snapshot OR NEW.route_snapshot IS NOT OLD.route_snapshot
                    OR NEW.source_state IS NOT OLD.source_state OR NEW.created_at IS NOT OLD.created_at
                BEGIN SELECT RAISE(ABORT, 'Manifest membership identity must be retained.'); END");
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER manifest_active_parcel_'.strtolower($operation)." BEFORE {$operation} ON logistics_manifest_parcels
                    WHEN NEW.active_delivery_id IS NOT NULL AND (NEW.active_delivery_id != NEW.delivery_id OR NEW.included = 0)
                    BEGIN SELECT RAISE(ABORT, 'Manifest active parcel identity is invalid.'); END");
            }
        } elseif ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION retain_manifest_evidence() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'Manifest evidence is immutable.' USING ERRCODE = '23000'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER manifest_event_immutable BEFORE UPDATE OR DELETE ON logistics_manifest_events
                    FOR EACH ROW EXECUTE FUNCTION retain_manifest_evidence();
                CREATE FUNCTION retain_manifest_membership() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Manifest membership history must be retained.' USING ERRCODE = '23000';
                    END IF;
                    IF TG_OP = 'UPDATE' AND ROW(NEW.manifest_id, NEW.delivery_id, NEW.tracking_number_snapshot, NEW.route_snapshot::text, NEW.source_state::text, NEW.created_at)
                        IS DISTINCT FROM ROW(OLD.manifest_id, OLD.delivery_id, OLD.tracking_number_snapshot, OLD.route_snapshot::text, OLD.source_state::text, OLD.created_at) THEN
                        RAISE EXCEPTION 'Manifest membership identity must be retained.' USING ERRCODE = '23000';
                    END IF;
                    IF NEW.active_delivery_id IS NOT NULL AND (NEW.active_delivery_id <> NEW.delivery_id OR NOT NEW.included) THEN
                        RAISE EXCEPTION 'Manifest active parcel identity is invalid.' USING ERRCODE = '23000';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER manifest_member_retained BEFORE INSERT OR UPDATE OR DELETE ON logistics_manifest_parcels
                    FOR EACH ROW EXECUTE FUNCTION retain_manifest_membership();
                CREATE FUNCTION retain_manifest_identity() RETURNS trigger AS $$
                BEGIN
                    IF ROW(NEW.reference, NEW.logistics_company_id, NEW.source_hub_id, NEW.destination_hub_id, NEW.vehicle_id, NEW.driver_id,
                        NEW.created_by_id, NEW.creation_token, NEW.creation_fingerprint, NEW.created_at)
                        IS DISTINCT FROM ROW(OLD.reference, OLD.logistics_company_id, OLD.source_hub_id, OLD.destination_hub_id, OLD.vehicle_id, OLD.driver_id,
                        OLD.created_by_id, OLD.creation_token, OLD.creation_fingerprint, OLD.created_at) THEN
                        RAISE EXCEPTION 'Manifest identity must be retained.' USING ERRCODE = '23000';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER manifest_identity_retained BEFORE UPDATE ON logistics_manifests
                    FOR EACH ROW EXECUTE FUNCTION retain_manifest_identity();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('logistics_manifests')->exists()) {
            throw new LogicException('Manifest evidence and its guards must be retained while manifests exist.');
        }
        Schema::dropIfExists('logistics_manifest_events');
        Schema::dropIfExists('logistics_manifest_parcels');
        Schema::dropIfExists('logistics_manifests');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_manifest_evidence(); DROP FUNCTION IF EXISTS retain_manifest_membership(); DROP FUNCTION IF EXISTS retain_manifest_identity()');
        }
    }
};
