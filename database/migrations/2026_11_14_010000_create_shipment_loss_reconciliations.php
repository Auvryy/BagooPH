<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_loss_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('shipment_financial_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_plan_id')->nullable()->unique();
            $table->foreignId('shipment_loss_finding_id')->constrained('shipment_loss_findings')->restrictOnDelete();
            $table->string('outcome_path', 40);
            $table->string('status', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('financial_version');
            $table->string('source_fingerprint', 64);
            $table->json('plan_snapshot');
            $table->json('finding_snapshot');
            $table->json('forward_route_hub_ids')->nullable();
            $table->json('return_route_hub_ids')->nullable();
            $table->unsignedInteger('return_route_position')->nullable();
            $table->text('reason');
            $table->string('proof_path');
            $table->string('proof_mime', 60);
            $table->unsignedInteger('proof_bytes');
            $table->string('proof_hash', 64);
            $table->string('request_token_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamp('invalidated_at')->nullable();
            $table->foreignId('invalidated_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('invalidation_reason')->nullable();
            $table->string('invalidation_token_hash', 64)->nullable()->unique();
            $table->string('invalidation_fingerprint', 64)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('release_barcode_scanned', 100)->nullable();
            $table->text('release_reason')->nullable();
            $table->json('release_custody_after')->nullable();
            $table->string('release_token_hash', 64)->nullable()->unique();
            $table->string('release_fingerprint', 64)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('shipment_loss_return_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->unique()->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('reconciliation_id')->constrained('shipment_loss_reconciliations')->restrictOnDelete();
            $table->unsignedInteger('leg_index');
            $table->timestamp('created_at');
            $table->unique(['reconciliation_id', 'leg_index'], 'loss_return_route_leg_unique');
        });

        $this->reconciliationGuards();
        $this->returnRouteGuards();
        $this->forwardBagGuards();
        $this->returnSackGuards();
    }

    private function reconciliationGuards(): void
    {
        $immutable = ['id', 'reference', 'shipment_financial_plan_id', 'shipment_loss_finding_id', 'outcome_path', 'actor_id',
            'financial_version', 'source_fingerprint', 'plan_snapshot', 'finding_snapshot', 'forward_route_hub_ids',
            'return_route_hub_ids', 'return_route_position', 'reason', 'proof_path', 'proof_mime', 'proof_bytes', 'proof_hash',
            'request_token_hash', 'request_fingerprint', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TRIGGER shipment_loss_reconciliations_retain_delete BEFORE DELETE ON shipment_loss_reconciliations
                BEGIN SELECT RAISE(ABORT, \'Retain the found-parcel reconciliation audit history.\'); END');
            $frozen = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $immutable));
            $invalidate = "(OLD.status = 'approved' AND NEW.status = 'invalidated' AND NEW.active_plan_id IS NULL
                AND NEW.invalidated_at IS NOT NULL AND NEW.invalidated_by_id IS NOT NULL AND NEW.invalidation_reason IS NOT NULL
                AND NEW.invalidation_token_hash IS NOT NULL AND NEW.invalidation_fingerprint IS NOT NULL
                AND NEW.released_at IS NULL AND NEW.released_by_id IS NULL AND NEW.release_barcode_scanned IS NULL
                AND NEW.release_reason IS NULL AND NEW.release_custody_after IS NULL AND NEW.release_token_hash IS NULL AND NEW.release_fingerprint IS NULL)";
            $release = "(OLD.status = 'approved' AND NEW.status = 'released' AND NEW.active_plan_id = OLD.shipment_financial_plan_id
                AND NEW.released_at IS NOT NULL AND NEW.released_by_id IS NOT NULL AND NEW.release_barcode_scanned IS NOT NULL
                AND NEW.release_reason IS NOT NULL AND NEW.release_custody_after IS NOT NULL AND NEW.release_token_hash IS NOT NULL AND NEW.release_fingerprint IS NOT NULL
                AND NEW.invalidated_at IS NULL AND NEW.invalidated_by_id IS NULL AND NEW.invalidation_reason IS NULL
                AND NEW.invalidation_token_hash IS NULL AND NEW.invalidation_fingerprint IS NULL)";
            DB::unprepared("CREATE TRIGGER shipment_loss_reconciliations_retain_update BEFORE UPDATE ON shipment_loss_reconciliations
                WHEN ({$frozen}) OR NOT ({$invalidate} OR {$release})
                BEGIN SELECT RAISE(ABORT, 'Retain the approved found-parcel reconciliation or transition it once.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $json = ['plan_snapshot', 'finding_snapshot', 'forward_route_hub_ids', 'return_route_hub_ids'];
            $frozen = implode(' OR ', array_map(fn ($field) => 'NEW.'.$field.(in_array($field, $json, true) ? '::text' : '').' IS DISTINCT FROM OLD.'.$field.(in_array($field, $json, true) ? '::text' : ''), $immutable));
            $invalidate = "(OLD.status = 'approved' AND NEW.status = 'invalidated' AND NEW.active_plan_id IS NULL
                AND NEW.invalidated_at IS NOT NULL AND NEW.invalidated_by_id IS NOT NULL AND NEW.invalidation_reason IS NOT NULL
                AND NEW.invalidation_token_hash IS NOT NULL AND NEW.invalidation_fingerprint IS NOT NULL
                AND NEW.released_at IS NULL AND NEW.released_by_id IS NULL AND NEW.release_barcode_scanned IS NULL
                AND NEW.release_reason IS NULL AND NEW.release_custody_after IS NULL AND NEW.release_token_hash IS NULL AND NEW.release_fingerprint IS NULL)";
            $release = "(OLD.status = 'approved' AND NEW.status = 'released' AND NEW.active_plan_id = OLD.shipment_financial_plan_id
                AND NEW.released_at IS NOT NULL AND NEW.released_by_id IS NOT NULL AND NEW.release_barcode_scanned IS NOT NULL
                AND NEW.release_reason IS NOT NULL AND NEW.release_custody_after IS NOT NULL AND NEW.release_token_hash IS NOT NULL AND NEW.release_fingerprint IS NOT NULL
                AND NEW.invalidated_at IS NULL AND NEW.invalidated_by_id IS NULL AND NEW.invalidation_reason IS NULL
                AND NEW.invalidation_token_hash IS NULL AND NEW.invalidation_fingerprint IS NULL)";
            DB::unprepared("CREATE FUNCTION retain_shipment_loss_reconciliation() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain the found-parcel reconciliation audit history.' USING ERRCODE = '23000'; END IF;
                IF ({$frozen}) OR NOT ({$invalidate} OR {$release}) THEN
                    RAISE EXCEPTION 'Retain the approved found-parcel reconciliation or transition it once.' USING ERRCODE = '23000';
                END IF;
                RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER shipment_loss_reconciliations_retained BEFORE UPDATE OR DELETE ON shipment_loss_reconciliations
                    FOR EACH ROW EXECUTE FUNCTION retain_shipment_loss_reconciliation();");
        }
    }

    private function returnRouteGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER shipment_loss_return_routes_retain_'.strtolower($operation)." BEFORE {$operation} ON shipment_loss_return_routes
                    BEGIN SELECT RAISE(ABORT, 'Retain the reconciled return transport route.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_shipment_loss_return_route() RETURNS trigger AS \$\$ BEGIN
                RAISE EXCEPTION 'Retain the reconciled return transport route.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER shipment_loss_return_routes_retained BEFORE UPDATE OR DELETE ON shipment_loss_return_routes
                    FOR EACH ROW EXECUTE FUNCTION retain_shipment_loss_return_route();");
        }
    }

    private function forwardBagGuards(): void
    {
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
        $reconciled = "(OLD.active_delivery_id = OLD.delivery_id AND NEW.active_delivery_id IS NULL
            AND OLD.received_at IS NULL AND NEW.received_at IS NULL
            AND EXISTS (SELECT 1 FROM shipment_loss_reconciliations slr
                JOIN shipment_loss_findings slf ON slf.id = slr.shipment_loss_finding_id
                JOIN logistics_manifest_parcels p ON p.id = slf.manifest_parcel_id
                JOIN mother_bags b ON b.id = OLD.mother_bag_id
                WHERE slr.status = 'released' AND slr.active_plan_id = slr.shipment_financial_plan_id
                    AND slr.released_by_id IS NOT NULL AND p.delivery_id = OLD.delivery_id
                    AND p.manifest_id = b.manifest_id))";
        $fields = ['id', 'mother_bag_id', 'delivery_id', 'loaded_by_id', 'barcode_scanned', 'source_state', 'handling_snapshot', 'removed_at', 'created_at'];
        $status = '(SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id)';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER b08_bag_member_update');
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            $receiptGuard = "(NEW.active_delivery_id IS NOT OLD.active_delivery_id OR NEW.received_at IS NOT OLD.received_at)
                AND ({$status} NOT IN ('open', 'opened') OR OLD.received_at IS NOT NULL
                    OR ({$status} = 'opened' AND (NEW.active_delivery_id IS NOT NULL OR NEW.received_at IS NULL)))";
            DB::unprepared("CREATE TRIGGER b08_bag_member_update BEFORE UPDATE ON mother_bag_parcels
                WHEN (NEW.active_delivery_id IS NOT NULL AND NEW.active_delivery_id <> NEW.delivery_id)
                    OR (({$receiptGuard}) AND NOT ({$extract} OR {$received} OR {$reconciled}))
                    OR NEW.id IS NOT OLD.id OR NEW.mother_bag_id IS NOT OLD.mother_bag_id
                    OR (({$changed}) AND {$status} <> 'open')
                BEGIN SELECT RAISE(ABORT, 'Sealed bag membership is retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
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
                ELSIF (({$receiptGuard}) AND NOT ({$extract} OR {$received} OR {$reconciled}))
                    OR NEW.id IS DISTINCT FROM OLD.id OR NEW.mother_bag_id IS DISTINCT FROM OLD.mother_bag_id
                    OR (({$changed}) AND {$status} <> 'open') THEN
                    RAISE EXCEPTION 'Sealed bag membership is retained.' USING ERRCODE = '23000';
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;");
        }
    }

    private function returnSackGuards(): void
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
        $reconciled = "(NEW.removed_at IS NULL AND NEW.received_at IS NULL AND NEW.received_by_id IS NULL
            AND EXISTS (SELECT 1 FROM shipment_loss_reconciliations slr
                JOIN shipment_loss_findings slf ON slf.id = slr.shipment_loss_finding_id
                WHERE slr.status = 'released' AND slr.active_plan_id = slr.shipment_financial_plan_id
                    AND slr.released_by_id IS NOT NULL AND slf.buyer_return_manifest_parcel_id = OLD.buyer_return_manifest_parcel_id))";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER return_sack_member_update');
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER return_sack_member_update BEFORE UPDATE ON mother_bag_return_parcels
                WHEN {$changed} OR OLD.removed_at IS NOT NULL OR OLD.received_at IS NOT NULL OR NEW.active_return_parcel_id IS NOT NULL
                OR NOT ({$normal} OR {$recovered} OR {$reconciled}) BEGIN SELECT RAISE(ABORT, 'Retain actual return sack membership.'); END");
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
                    OR NOT ({$normal} OR {$recovered} OR {$reconciled}) THEN
                    RAISE EXCEPTION 'Retain actual return sack membership.' USING ERRCODE = '23000';
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain found-parcel reconciliation and physical release evidence.');
    }
};
