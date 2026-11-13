<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_recovery_bag_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_bag_member_id')->unique()->constrained('mother_bag_parcels')->restrictOnDelete();
            $table->foreignId('recovery_id')->unique()->constrained('parcel_recovery_cases')->restrictOnDelete();
            $table->foreignId('intake_event_id')->constrained('parcel_recovery_events')->restrictOnDelete();
            $table->foreignId('checkpoint_id')->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        $source = "SELECT 1 FROM parcel_recovery_bag_extractions x
            JOIN parcel_recovery_cases r ON r.id = x.recovery_id
            JOIN parcel_recovery_events e ON e.id = x.intake_event_id
            JOIN delivery_checkpoints c ON c.id = x.checkpoint_id
            JOIN logistics_manifest_parcels p ON p.id = r.original_manifest_parcel_id
            JOIN mother_bags b ON b.id = OLD.mother_bag_id
            WHERE x.original_bag_member_id = OLD.id AND r.delivery_id = OLD.delivery_id
                AND p.delivery_id = OLD.delivery_id AND p.manifest_id = b.manifest_id
                AND e.parcel_recovery_case_id = r.id AND e.event_type = 'intake'
                AND e.actor_id = x.actor_id AND e.barcode_scanned = OLD.barcode_scanned
                AND c.delivery_id = OLD.delivery_id AND c.checkpoint_type = 'parcel_recovery_intake'
                AND c.scanned_by_id = x.actor_id AND c.barcode_scanned = OLD.barcode_scanned
                AND c.hub_id = e.hub_id AND e.hub_id = r.observed_hub_id";
        $extract = "(OLD.active_delivery_id = OLD.delivery_id AND NEW.active_delivery_id IS NULL
            AND OLD.received_at IS NULL AND NEW.received_at IS NULL AND EXISTS ({$source}))";
        $received = "(OLD.active_delivery_id IS NULL AND NEW.active_delivery_id IS NULL
            AND OLD.received_at IS NULL AND NEW.received_at IS NOT NULL
            AND EXISTS ({$source} AND r.state = 'completed' AND r.active_delivery_id IS NULL
                AND p.received_at = NEW.received_at AND p.received_by_id IS NOT NULL
                AND EXISTS (SELECT 1 FROM logistics_manifest_events receipt WHERE receipt.manifest_id = p.manifest_id
                    AND receipt.manifest_parcel_id = p.id AND receipt.event_type = 'parcel_received'
                    AND receipt.actor_id = p.received_by_id AND receipt.hub_id = r.expected_hub_id
                    AND receipt.barcode_scanned = OLD.barcode_scanned)))";
        $fields = ['id', 'mother_bag_id', 'delivery_id', 'loaded_by_id', 'barcode_scanned', 'source_state', 'handling_snapshot', 'removed_at', 'created_at'];
        $status = '(SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id)';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER parcel_recovery_bag_extractions_retain_'.strtolower($operation)." BEFORE {$operation} ON parcel_recovery_bag_extractions
                    BEGIN SELECT RAISE(ABORT, 'Retain the actual parcel extraction and recovery evidence.'); END");
            }
            DB::unprepared('DROP TRIGGER b08_bag_member_update');
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            $receiptGuard = "(NEW.active_delivery_id IS NOT OLD.active_delivery_id OR NEW.received_at IS NOT OLD.received_at)
                AND ({$status} NOT IN ('open', 'opened') OR OLD.received_at IS NOT NULL
                    OR ({$status} = 'opened' AND (NEW.active_delivery_id IS NOT NULL OR NEW.received_at IS NULL)))";
            DB::unprepared("CREATE TRIGGER b08_bag_member_update BEFORE UPDATE ON mother_bag_parcels
                WHEN (NEW.active_delivery_id IS NOT NULL AND NEW.active_delivery_id <> NEW.delivery_id)
                    OR (({$receiptGuard}) AND NOT ({$extract} OR {$received}))
                    OR NEW.id IS NOT OLD.id OR NEW.mother_bag_id IS NOT OLD.mother_bag_id
                    OR (({$changed}) AND {$status} <> 'open')
                BEGIN SELECT RAISE(ABORT, 'Sealed bag membership is retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_parcel_recovery_bag_extraction() RETURNS trigger AS \$\$ BEGIN
                    RAISE EXCEPTION 'Retain the actual parcel extraction and recovery evidence.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER parcel_recovery_bag_extractions_retained BEFORE UPDATE OR DELETE ON parcel_recovery_bag_extractions
                    FOR EACH ROW EXECUTE FUNCTION retain_parcel_recovery_bag_extraction();");
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field}::text IS DISTINCT FROM OLD.{$field}::text", $fields));
            $receiptGuard = "(NEW.active_delivery_id IS DISTINCT FROM OLD.active_delivery_id OR NEW.received_at IS DISTINCT FROM OLD.received_at)
                AND ({$status} NOT IN ('open', 'opened') OR OLD.received_at IS NOT NULL
                    OR ({$status} = 'opened' AND (NEW.active_delivery_id IS NOT NULL OR NEW.received_at IS NULL)))";
            DB::unprepared("CREATE OR REPLACE FUNCTION b08_bag_member_guard() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Bag membership history is retained.' USING ERRCODE = '23000'; END IF;
                IF NEW.active_delivery_id IS NOT NULL AND NEW.active_delivery_id <> NEW.delivery_id THEN
                    RAISE EXCEPTION 'Original bag membership is retained.' USING ERRCODE = '23000'; END IF;
                IF TG_OP = 'INSERT' THEN
                    IF (SELECT status FROM mother_bags WHERE id = NEW.mother_bag_id) <> 'open' THEN
                        RAISE EXCEPTION 'Sealed bag membership is retained.' USING ERRCODE = '23000'; END IF;
                ELSIF (({$receiptGuard}) AND NOT ({$extract} OR {$received}))
                    OR NEW.id IS DISTINCT FROM OLD.id OR NEW.mother_bag_id IS DISTINCT FROM OLD.mother_bag_id
                    OR (({$changed}) AND {$status} <> 'open') THEN
                    RAISE EXCEPTION 'Sealed bag membership is retained.' USING ERRCODE = '23000';
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain actual parcel extraction and original sealed bag evidence.');
    }
};
