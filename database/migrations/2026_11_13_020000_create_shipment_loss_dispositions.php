<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_loss_dispositions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('shipment_financial_plan_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_return_case_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('loss_observation_id')->constrained('logistics_manifest_events')->restrictOnDelete();
            $table->foreignId('manifest_parcel_id')->nullable()->constrained('logistics_manifest_parcels')->restrictOnDelete();
            $table->unsignedBigInteger('buyer_return_manifest_parcel_id')->nullable();
            $table->foreign('buyer_return_manifest_parcel_id', 'loss_disposition_return_member_fk')->references('id')->on('buyer_return_manifest_parcels')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('financial_version');
            $table->string('state', 30);
            $table->json('financial_snapshot');
            $table->json('physical_snapshot');
            $table->text('reason');
            $table->string('proof_path');
            $table->string('proof_mime', 60);
            $table->unsignedInteger('proof_bytes');
            $table->string('proof_hash', 64);
            $table->string('request_token_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamp('created_at');
            $table->index(['manifest_id', 'manifest_parcel_id'], 'loss_disposition_manifest_member');
            $table->index(['manifest_id', 'buyer_return_manifest_parcel_id'], 'loss_disposition_return_member');
            $table->unique('manifest_parcel_id', 'loss_disposition_normal_member_unique');
            $table->unique('buyer_return_manifest_parcel_id', 'loss_disposition_return_member_unique');
        });
        $typed = '(manifest_parcel_id IS NOT NULL AND buyer_return_manifest_parcel_id IS NULL AND buyer_return_case_id IS NULL)
            OR (manifest_parcel_id IS NULL AND buyer_return_manifest_parcel_id IS NOT NULL AND buyer_return_case_id IS NOT NULL)';
        if (DB::getDriverName() === 'sqlite') {
            $typedInsert = '(NEW.manifest_parcel_id IS NOT NULL AND NEW.buyer_return_manifest_parcel_id IS NULL AND NEW.buyer_return_case_id IS NULL)
                OR (NEW.manifest_parcel_id IS NULL AND NEW.buyer_return_manifest_parcel_id IS NOT NULL AND NEW.buyer_return_case_id IS NOT NULL)';
            DB::unprepared("CREATE TRIGGER shipment_loss_dispositions_typed_insert BEFORE INSERT ON shipment_loss_dispositions
                WHEN NOT ({$typedInsert}) BEGIN SELECT RAISE(ABORT, 'Retain exactly one original typed missing-parcel identity.'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER shipment_loss_dispositions_retain_'.strtolower($operation)." BEFORE {$operation} ON shipment_loss_dispositions
                    BEGIN SELECT RAISE(ABORT, 'Retain the original independently recorded loss disposition.'); END");
            }
            DB::unprepared("CREATE TRIGGER shipment_loss_dispositions_preserve_claim BEFORE UPDATE OF active_order_id, active_return_case_id ON shipment_financial_plans
                WHEN (NEW.active_order_id IS NOT OLD.active_order_id OR NEW.active_return_case_id IS NOT OLD.active_return_case_id)
                    AND EXISTS (SELECT 1 FROM shipment_loss_dispositions WHERE shipment_financial_plan_id = OLD.id)
                BEGIN SELECT RAISE(ABORT, 'Retain the active authority of the original loss disposition.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE shipment_loss_dispositions ADD CONSTRAINT shipment_loss_dispositions_typed_member CHECK ({$typed});
                CREATE FUNCTION retain_shipment_loss_disposition() RETURNS trigger AS \$\$ BEGIN
                    RAISE EXCEPTION 'Retain the original independently recorded loss disposition.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER shipment_loss_dispositions_retained BEFORE UPDATE OR DELETE ON shipment_loss_dispositions
                    FOR EACH ROW EXECUTE FUNCTION retain_shipment_loss_disposition();
                CREATE FUNCTION retain_disposed_loss_claim() RETURNS trigger AS \$\$ BEGIN
                    IF (NEW.active_order_id IS DISTINCT FROM OLD.active_order_id OR NEW.active_return_case_id IS DISTINCT FROM OLD.active_return_case_id)
                        AND EXISTS (SELECT 1 FROM shipment_loss_dispositions WHERE shipment_financial_plan_id = OLD.id)
                    THEN RAISE EXCEPTION 'Retain the active authority of the original loss disposition.' USING ERRCODE = '23000'; END IF;
                    RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER shipment_loss_dispositions_preserve_claim BEFORE UPDATE OF active_order_id, active_return_case_id ON shipment_financial_plans
                    FOR EACH ROW EXECUTE FUNCTION retain_disposed_loss_claim();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain independently recorded loss dispositions and their private evidence.');
    }
};
