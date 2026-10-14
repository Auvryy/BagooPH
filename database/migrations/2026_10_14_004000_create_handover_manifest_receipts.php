<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_manifest_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('handover_recovery_grant_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('handover_issue_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('manifest_parcel_id')->nullable()->constrained('logistics_manifest_parcels')->restrictOnDelete();
            $table->foreignId('manifest_event_id')->unique()->constrained('logistics_manifest_events')->restrictOnDelete();
            $table->foreignId('checkpoint_id')->unique()->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('received_by_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 30);
            $table->string('tracking_number_snapshot');
            $table->json('route_snapshot');
            $table->json('source_manifest_snapshot');
            $table->unsignedInteger('original_expected_count');
            $table->unsignedInteger('original_received_count');
            $table->timestampsTz();
            $table->unique(['manifest_id', 'delivery_id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $suffix = strtolower($operation);
                DB::unprepared("CREATE TRIGGER handover_manifest_receipts_no_$suffix BEFORE $operation ON handover_manifest_receipts BEGIN SELECT RAISE(ABORT, 'Corrective manifest receipts must be retained.'); END;");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER handover_manifest_receipts_retained BEFORE UPDATE OR DELETE ON handover_manifest_receipts FOR EACH ROW EXECUTE FUNCTION retain_handover_events();');
        }
    }

    public function down(): void
    {
        if (DB::table('handover_manifest_receipts')->exists()) {
            throw new LogicException('Corrective manifest receipts must be retained.');
        }
        Schema::dropIfExists('handover_manifest_receipts');
    }
};
