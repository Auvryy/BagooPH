<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_loss_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('shipment_financial_plan_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('manifest_parcel_id')->nullable()->constrained('logistics_manifest_parcels')->restrictOnDelete();
            $table->unsignedBigInteger('buyer_return_manifest_parcel_id')->nullable();
            $table->foreign('buyer_return_manifest_parcel_id', 'loss_finding_return_member_fk')->references('id')->on('buyer_return_manifest_parcels')->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('financial_version');
            $table->string('barcode_scanned', 100);
            $table->json('source_state');
            $table->json('custody_after');
            $table->text('reason');
            $table->string('proof_path');
            $table->string('proof_mime', 60);
            $table->unsignedInteger('proof_bytes');
            $table->string('proof_hash', 64);
            $table->string('request_token_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamp('created_at');
        });
        $typed = '(manifest_parcel_id IS NOT NULL AND buyer_return_manifest_parcel_id IS NULL)
            OR (manifest_parcel_id IS NULL AND buyer_return_manifest_parcel_id IS NOT NULL)';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER shipment_loss_findings_typed_insert BEFORE INSERT ON shipment_loss_findings
                WHEN NOT ((NEW.manifest_parcel_id IS NOT NULL AND NEW.buyer_return_manifest_parcel_id IS NULL)
                    OR (NEW.manifest_parcel_id IS NULL AND NEW.buyer_return_manifest_parcel_id IS NOT NULL))
                BEGIN SELECT RAISE(ABORT, 'Retain exactly one original found parcel identity.'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER shipment_loss_findings_retain_'.strtolower($operation)." BEFORE {$operation} ON shipment_loss_findings
                    BEGIN SELECT RAISE(ABORT, 'Retain the actual found parcel and quarantine evidence.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE shipment_loss_findings ADD CONSTRAINT shipment_loss_findings_typed_member CHECK ({$typed});
                CREATE FUNCTION retain_shipment_loss_finding() RETURNS trigger AS \$\$ BEGIN
                    RAISE EXCEPTION 'Retain the actual found parcel and quarantine evidence.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER shipment_loss_findings_retained BEFORE UPDATE OR DELETE ON shipment_loss_findings
                    FOR EACH ROW EXECUTE FUNCTION retain_shipment_loss_finding();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain the original found-parcel intake and financial hold.');
    }
};
