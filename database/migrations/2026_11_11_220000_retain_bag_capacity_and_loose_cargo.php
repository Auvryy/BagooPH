<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mother_bags', function (Blueprint $table): void {
            $table->unsignedSmallInteger('packing_policy_version')->nullable();
            $table->string('physical_tag', 80)->nullable();
            $table->string('active_physical_key', 64)->nullable()->unique();
            $table->string('automatic_lane', 64)->nullable()->unique();
        });
        Schema::table('mother_bag_parcels', fn (Blueprint $table) => $table->json('handling_snapshot')->nullable());
        Schema::table('logistics_manifest_parcels', function (Blueprint $table): void {
            $table->string('cargo_mode', 16)->nullable();
            $table->json('handling_snapshot')->nullable();
        });
        Schema::create('bag_packing_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_token');
            $table->string('fingerprint', 64);
            $table->foreignUuid('mother_bag_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['actor_id', 'request_token']);
        });
        $this->retain('bag_packing_requests', []);
        $this->retain('mother_bags', ['packing_policy_version', 'physical_tag']);
        $this->retain('logistics_manifest_parcels', ['cargo_mode', 'handling_snapshot']);
        // Receipt fields may advance after opening; the sealed packing identity cannot.
        $fields = ['mother_bag_id', 'delivery_id', 'loaded_by_id', 'barcode_scanned', 'source_state', 'handling_snapshot', 'removed_at', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($f) => "NEW.$f IS NOT OLD.$f", $fields));
            $status = '(SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id)';
            $receiptGuard = "(NEW.active_delivery_id IS NOT OLD.active_delivery_id OR NEW.received_at IS NOT OLD.received_at) AND ($status NOT IN ('open', 'opened') OR OLD.received_at IS NOT NULL OR ($status = 'opened' AND (NEW.active_delivery_id IS NOT NULL OR NEW.received_at IS NULL)))";
            DB::unprepared("CREATE TRIGGER b08_bag_member_update BEFORE UPDATE ON mother_bag_parcels WHEN (NEW.active_delivery_id IS NOT NULL AND NEW.active_delivery_id <> NEW.delivery_id) OR ($receiptGuard) OR NEW.mother_bag_id IS NOT OLD.mother_bag_id OR (($changed) AND (SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id) <> 'open') BEGIN SELECT RAISE(ABORT, 'Sealed bag membership is retained.'); END");
            DB::unprepared("CREATE TRIGGER b08_bag_member_insert BEFORE INSERT ON mother_bag_parcels WHEN (SELECT status FROM mother_bags WHERE id = NEW.mother_bag_id) <> 'open' BEGIN SELECT RAISE(ABORT, 'Sealed bag membership is retained.'); END");
            DB::unprepared("CREATE TRIGGER b08_bag_member_delete BEFORE DELETE ON mother_bag_parcels BEGIN SELECT RAISE(ABORT, 'Bag membership history is retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($f) => "NEW.$f::text IS DISTINCT FROM OLD.$f::text", $fields));
            $status = '(SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id)';
            $receiptGuard = "(NEW.active_delivery_id IS DISTINCT FROM OLD.active_delivery_id OR NEW.received_at IS DISTINCT FROM OLD.received_at) AND ($status NOT IN ('open', 'opened') OR OLD.received_at IS NOT NULL OR ($status = 'opened' AND (NEW.active_delivery_id IS NOT NULL OR NEW.received_at IS NULL)))";
            DB::unprepared("CREATE FUNCTION b08_bag_member_guard() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Bag membership history is retained.' USING ERRCODE = '23000'; END IF;
                IF NEW.active_delivery_id IS NOT NULL AND NEW.active_delivery_id <> NEW.delivery_id THEN RAISE EXCEPTION 'Original bag membership is retained.' USING ERRCODE = '23000'; END IF;
                IF TG_OP = 'INSERT' THEN
                    IF (SELECT status FROM mother_bags WHERE id = NEW.mother_bag_id) <> 'open' THEN RAISE EXCEPTION 'Sealed bag membership is retained.' USING ERRCODE = '23000'; END IF;
                ELSIF ($receiptGuard) OR NEW.mother_bag_id IS DISTINCT FROM OLD.mother_bag_id OR (($changed) AND (SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id) <> 'open') THEN RAISE EXCEPTION 'Sealed bag membership is retained.' USING ERRCODE = '23000'; END IF;
                RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER b08_bag_member_guard BEFORE INSERT OR UPDATE OR DELETE ON mother_bag_parcels FOR EACH ROW EXECUTE FUNCTION b08_bag_member_guard();");
        }
    }

    private function retain(string $table, array $fields): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $fields ? ' WHEN '.implode(' OR ', array_map(fn ($f) => "NEW.$f IS NOT OLD.$f", $fields)) : '';
            DB::unprepared("CREATE TRIGGER b08_{$table}_update BEFORE UPDATE ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Original packing evidence is retained.'); END");
            if (! $fields) {
                DB::unprepared("CREATE TRIGGER b08_{$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Original packing evidence is retained.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = $fields ? implode(' OR ', array_map(fn ($f) => "NEW.$f::text IS DISTINCT FROM OLD.$f::text", $fields)) : 'TRUE';
            DB::unprepared("CREATE FUNCTION b08_retain_{$table}() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' OR ($changed) THEN RAISE EXCEPTION 'Original packing evidence is retained.' USING ERRCODE = '23000'; END IF;
                RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER b08_{$table}_retained BEFORE UPDATE".(! $fields ? ' OR DELETE' : '')." ON {$table} FOR EACH ROW EXECUTE FUNCTION b08_retain_{$table}();");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Packing history must be retained. Restore a verified backup rather than dropping physical cargo evidence.');
    }
};
