<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_return_recoveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('buyer_return_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_return_parcel_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_return_parcel_id')->nullable()->unique()->constrained('buyer_return_parcels')->restrictOnDelete();
            $table->foreignId('original_member_id')->constrained('buyer_return_manifest_parcels')->restrictOnDelete();
            $table->foreignId('original_bag_member_id')->nullable()->constrained('mother_bag_return_parcels')->restrictOnDelete();
            $table->foreignId('observation_event_id')->unique()->constrained('logistics_manifest_events')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('observed_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('expected_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('observed_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('authorization_reason')->nullable();
            $table->json('source_state');
            $table->json('hub_ids');
            $table->string('state', 20)->default('observed');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('leg_index')->default(0);
            $table->timestamps();
            $table->index(['logistics_company_id', 'state']);
        });
        Schema::create('buyer_return_recovery_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('recovery_id')->constrained('buyer_return_recoveries')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->unsignedInteger('sequence');
            $table->string('event_type', 30);
            $table->uuid('request_token')->nullable()->unique();
            $table->char('fingerprint', 64)->nullable();
            $table->text('reason');
            $table->json('context');
            $table->timestamp('created_at');
            $table->unique(['recovery_id', 'sequence']);
        });
        Schema::create('buyer_return_recovery_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->unique()->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('recovery_id')->constrained('buyer_return_recoveries')->restrictOnDelete();
            $table->unsignedInteger('leg_index');
            $table->timestamp('created_at');
            $table->unique(['recovery_id', 'leg_index']);
        });
        Schema::create('buyer_return_bag_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_bag_member_id')->unique()->constrained('mother_bag_return_parcels')->restrictOnDelete();
            $table->foreignId('recovery_id')->unique()->constrained('buyer_return_recoveries')->restrictOnDelete();
            $table->foreignId('intake_event_id')->constrained('buyer_return_recovery_events')->restrictOnDelete();
            $table->foreignId('return_event_id')->constrained('buyer_return_events')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE buyer_return_cargo_readings ADD COLUMN recovery_id INTEGER NULL REFERENCES buyer_return_recoveries(id) ON DELETE RESTRICT');
        } else {
            Schema::table('buyer_return_cargo_readings', fn (Blueprint $table) => $table->foreignId('recovery_id')->nullable()->constrained('buyer_return_recoveries')->restrictOnDelete());
        }
        Schema::table('buyer_return_cargo_readings', fn (Blueprint $table) => $table->index(['recovery_id', 'hub_id']));
        foreach (['buyer_return_recovery_events', 'buyer_return_recovery_routes', 'buyer_return_bag_extractions'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_retain_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Retain actual return recovery evidence.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Retain actual return recovery evidence.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_retained BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
        $this->authorityGuard();
        $this->extractionGuard();
    }

    private function authorityGuard(): void
    {
        $fields = ['id', 'reference', 'buyer_return_case_id', 'buyer_return_parcel_id', 'original_member_id', 'original_bag_member_id',
            'observation_event_id', 'logistics_company_id', 'observed_hub_id', 'expected_hub_id', 'observed_by_id', 'source_state', 'hub_ids', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER return_recovery_authority_update BEFORE UPDATE ON buyer_return_recoveries
                WHEN {$changed} OR (OLD.authorized_by_id IS NOT NULL AND (NEW.authorized_by_id IS NOT OLD.authorized_by_id OR NEW.authorization_reason IS NOT OLD.authorization_reason))
                BEGIN SELECT RAISE(ABORT, 'Retain original return recovery authority.'); END");
            DB::unprepared("CREATE TRIGGER return_recovery_authority_delete BEFORE DELETE ON buyer_return_recoveries BEGIN SELECT RAISE(ABORT, 'Retain original return recovery authority.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field}::text IS DISTINCT FROM OLD.{$field}::text", $fields));
            DB::unprepared("CREATE FUNCTION retain_return_recovery_authority() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain original return recovery authority.' USING ERRCODE = '23000'; END IF;
                IF {$changed} OR (OLD.authorized_by_id IS NOT NULL AND (NEW.authorized_by_id IS DISTINCT FROM OLD.authorized_by_id OR NEW.authorization_reason IS DISTINCT FROM OLD.authorization_reason)) THEN
                    RAISE EXCEPTION 'Retain original return recovery authority.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER return_recovery_authority_retained BEFORE UPDATE OR DELETE ON buyer_return_recoveries FOR EACH ROW EXECUTE FUNCTION retain_return_recovery_authority();");
        }
    }

    private function extractionGuard(): void
    {
        $fields = ['id', 'mother_bag_id', 'buyer_return_parcel_id', 'buyer_return_manifest_parcel_id', 'buyer_return_cargo_reading_id',
            'loaded_by_id', 'barcode_scanned', 'source_state', 'handling_snapshot', 'created_at'];
        $extraction = "EXISTS (SELECT 1 FROM buyer_return_bag_extractions x JOIN buyer_return_recoveries r ON r.id = x.recovery_id
            JOIN buyer_return_recovery_events e ON e.id = x.intake_event_id WHERE x.original_bag_member_id = OLD.id
            AND r.buyer_return_parcel_id = OLD.buyer_return_parcel_id AND e.recovery_id = r.id AND e.event_type = 'intake')";
        $normal = "(((SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id) = 'open' AND NEW.removed_at IS NOT NULL AND NEW.received_at IS NULL AND NEW.received_by_id IS NULL)
            OR ((SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id) = 'opened' AND NEW.removed_at IS NULL AND NEW.received_at IS NOT NULL AND NEW.received_by_id IS NOT NULL))";
        $completed = "EXISTS (SELECT 1 FROM buyer_return_bag_extractions x JOIN buyer_return_recoveries r ON r.id = x.recovery_id
            JOIN buyer_return_manifest_parcels p ON p.id = r.original_member_id WHERE x.original_bag_member_id = OLD.id AND r.state = 'completed'
            AND r.active_return_parcel_id IS NULL AND p.received_at IS NOT NULL AND p.received_by_id = NEW.received_by_id)";
        $recovered = "({$extraction} AND NEW.removed_at IS NULL AND ((NEW.received_at IS NULL AND NEW.received_by_id IS NULL) OR (NEW.received_at IS NOT NULL AND NEW.received_by_id IS NOT NULL AND {$completed})))";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER return_sack_member_update');
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER return_sack_member_update BEFORE UPDATE ON mother_bag_return_parcels
                WHEN {$changed} OR OLD.removed_at IS NOT NULL OR OLD.received_at IS NOT NULL OR NEW.active_return_parcel_id IS NOT NULL
                OR NOT ({$normal} OR {$recovered}) BEGIN SELECT RAISE(ABORT, 'Retain actual return sack membership.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field}::text IS DISTINCT FROM OLD.{$field}::text", $fields));
            DB::unprepared("CREATE OR REPLACE FUNCTION retain_return_sack_members() RETURNS trigger AS \$\$ DECLARE bag_status text; BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain actual return sack membership.' USING ERRCODE = '23000'; END IF;
                SELECT status INTO bag_status FROM mother_bags WHERE id = NEW.mother_bag_id;
                IF TG_OP = 'INSERT' THEN
                    IF bag_status <> 'open' OR (SELECT return_trip_id FROM mother_bags WHERE id = NEW.mother_bag_id) IS NULL
                        OR NEW.active_return_parcel_id IS DISTINCT FROM NEW.buyer_return_parcel_id THEN
                        RAISE EXCEPTION 'Retain actual return sack membership.' USING ERRCODE = '23000'; END IF;
                ELSIF ({$changed}) OR OLD.removed_at IS NOT NULL OR OLD.received_at IS NOT NULL OR NEW.active_return_parcel_id IS NOT NULL
                    OR NOT ({$normal} OR {$recovered}) THEN
                    RAISE EXCEPTION 'Retain actual return sack membership.' USING ERRCODE = '23000';
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain actual return recovery, extraction and receipt evidence.');
    }
};
