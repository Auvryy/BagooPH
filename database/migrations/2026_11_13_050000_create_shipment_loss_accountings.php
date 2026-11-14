<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_loss_accountings', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('shipment_financial_plan_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('shipment_loss_disposition_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('financial_version');
            $table->text('reason');
            $table->string('request_token_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamp('created_at');
        });
        Schema::create('shipment_loss_accounting_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_loss_accounting_id')->constrained()->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->string('relation_kind', 20);
            $table->foreignId('manifest_parcel_id')->nullable()->constrained('logistics_manifest_parcels')->restrictOnDelete();
            $table->unsignedBigInteger('buyer_return_manifest_parcel_id')->nullable();
            $table->foreign('buyer_return_manifest_parcel_id', 'loss_accounting_return_member_fk')->references('id')->on('buyer_return_manifest_parcels')->restrictOnDelete();
            $table->unique(['shipment_loss_accounting_id', 'manifest_id'], 'loss_accounting_manifest_unique');
        });
        foreach (['shipment_loss_accountings', 'shipment_loss_accounting_manifests'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_retain_".strtolower($operation)." BEFORE {$operation} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Retain original loss accounting and transport identities.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$ BEGIN
                    RAISE EXCEPTION 'Retain original loss accounting and transport identities.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_retained BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
        $typed = "(relation_kind = 'observed' AND manifest_parcel_id IS NULL AND buyer_return_manifest_parcel_id IS NULL)
            OR (relation_kind IN ('source', 'original') AND ((manifest_parcel_id IS NOT NULL AND buyer_return_manifest_parcel_id IS NULL)
                OR (manifest_parcel_id IS NULL AND buyer_return_manifest_parcel_id IS NOT NULL)))";
        if (DB::getDriverName() === 'sqlite') {
            $typed = preg_replace('/\b(relation_kind|manifest_parcel_id|buyer_return_manifest_parcel_id)\b/', 'NEW.$1', $typed);
            DB::unprepared("CREATE TRIGGER shipment_loss_accounting_manifests_typed BEFORE INSERT ON shipment_loss_accounting_manifests
                WHEN NOT ({$typed}) BEGIN SELECT RAISE(ABORT, 'Retain the exact typed loss accounting relation.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE shipment_loss_accounting_manifests ADD CONSTRAINT loss_accounting_typed_member CHECK ({$typed});");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain independently supported loss accounting.');
    }
};
