<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_return_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_return_case_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approval_event_id')->constrained('buyer_return_events')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->string('barcode', 40)->unique();
            $table->json('route_snapshot');
            $table->json('hub_ids');
            $table->unsignedInteger('route_position')->default(0);
            $table->foreignId('current_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('status', 30)->default('awaiting_dropoff');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->json('custody');
            $table->timestamps();
            $table->index(['logistics_company_id', 'status']);
        });
        Schema::create('buyer_return_manifest_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->unique()->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('buyer_return_case_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('leg_index');
            $table->timestamp('created_at');
            $table->unique(['buyer_return_case_id', 'leg_index'], 'buyer_return_manifest_leg_unique');
        });
        Schema::create('buyer_return_manifest_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->unique()->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('buyer_return_parcel_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_return_parcel_id')->nullable()->unique()->constrained('buyer_return_parcels')->restrictOnDelete();
            $table->string('barcode_snapshot', 40);
            $table->unsignedInteger('leg_index');
            $table->json('source_state');
            $table->boolean('included')->default(true);
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('receipt_condition', 20)->nullable();
            $table->timestamps();
        });
        $this->preservingTriggers('buyer_return_evidence', fn () => Schema::table('buyer_return_evidence', function (Blueprint $table) {
            $table->string('purpose', 30)->default('request');
            $table->foreignId('buyer_return_event_id')->nullable()->constrained()->restrictOnDelete();
        }));
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER buyer_return_manifest_routes_no_'.strtolower($operation)." BEFORE {$operation} ON buyer_return_manifest_routes
                    BEGIN SELECT RAISE(ABORT, 'Buyer-return manifest identity must be retained.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION retain_buyer_return_manifest_routes() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Buyer-return manifest identity must be retained.' USING ERRCODE = '23000';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER buyer_return_manifest_routes_immutable BEFORE UPDATE OR DELETE ON buyer_return_manifest_routes
                    FOR EACH ROW EXECUTE FUNCTION retain_buyer_return_manifest_routes();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('buyer_return_parcels')->exists() || DB::table('buyer_return_manifest_routes')->exists()) {
            throw new LogicException('Buyer-return custody history must be retained while parcels exist.');
        }
        $this->preservingTriggers('buyer_return_evidence', fn () => Schema::table('buyer_return_evidence', function (Blueprint $table) {
            $table->dropConstrainedForeignId('buyer_return_event_id');
            $table->dropColumn('purpose');
        }));
        Schema::dropIfExists('buyer_return_manifest_parcels');
        Schema::dropIfExists('buyer_return_manifest_routes');
        Schema::dropIfExists('buyer_return_parcels');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_buyer_return_manifest_routes()');
        }
    }

    private function preservingTriggers(string $table, Closure $alter): void
    {
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->whereNotNull('sql')->get(['name', 'sql']) : collect();
        $alter();
        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
