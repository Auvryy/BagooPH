<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Native nullable REFERENCES avoids rebuilding a table used by retained member triggers.
            DB::statement('ALTER TABLE mother_bags ADD COLUMN return_trip_id INTEGER NULL REFERENCES logistics_transport_trips(id) ON DELETE RESTRICT');
        } else {
            Schema::table('mother_bags', fn (Blueprint $table) => $table->foreignId('return_trip_id')->nullable()->constrained('logistics_transport_trips')->restrictOnDelete());
        }
        Schema::table('mother_bags', fn (Blueprint $table) => $table->index('return_trip_id'));
        Schema::create('mother_bag_return_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('mother_bag_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_return_parcel_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_return_parcel_id')->nullable()->unique()->constrained('buyer_return_parcels')->restrictOnDelete();
            $table->foreignId('buyer_return_manifest_parcel_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_return_cargo_reading_id')->constrained()->restrictOnDelete();
            $table->foreignId('loaded_by_id')->constrained('users')->restrictOnDelete();
            $table->string('barcode_scanned', 40);
            $table->json('source_state');
            $table->json('handling_snapshot');
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        $fields = ['id', 'mother_bag_id', 'buyer_return_parcel_id', 'buyer_return_manifest_parcel_id', 'buyer_return_cargo_reading_id',
            'loaded_by_id', 'barcode_scanned', 'source_state', 'handling_snapshot', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER return_sack_member_insert BEFORE INSERT ON mother_bag_return_parcels
                WHEN (SELECT status FROM mother_bags WHERE id = NEW.mother_bag_id) <> 'open'
                OR (SELECT return_trip_id FROM mother_bags WHERE id = NEW.mother_bag_id) IS NULL
                OR NEW.active_return_parcel_id IS NOT NEW.buyer_return_parcel_id
                BEGIN SELECT RAISE(ABORT, 'Retain actual return sack membership.'); END");
            DB::unprepared("CREATE TRIGGER return_sack_member_delete BEFORE DELETE ON mother_bag_return_parcels
                BEGIN SELECT RAISE(ABORT, 'Retain actual return sack membership.'); END");
            DB::unprepared("CREATE TRIGGER return_sack_member_update BEFORE UPDATE ON mother_bag_return_parcels
                WHEN {$changed} OR OLD.removed_at IS NOT NULL OR OLD.received_at IS NOT NULL OR NEW.active_return_parcel_id IS NOT NULL
                OR NOT (((SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id) = 'open' AND NEW.removed_at IS NOT NULL AND NEW.received_at IS NULL AND NEW.received_by_id IS NULL)
                    OR ((SELECT status FROM mother_bags WHERE id = OLD.mother_bag_id) = 'opened' AND NEW.removed_at IS NULL AND NEW.received_at IS NOT NULL AND NEW.received_by_id IS NOT NULL))
                BEGIN SELECT RAISE(ABORT, 'Retain actual return sack membership.'); END");
            DB::unprepared("CREATE TRIGGER return_sack_identity BEFORE UPDATE ON mother_bags
                WHEN OLD.return_trip_id IS NOT NULL AND NEW.return_trip_id IS NOT OLD.return_trip_id
                BEGIN SELECT RAISE(ABORT, 'Retain return sack trip identity.'); END");
            DB::unprepared("CREATE TRIGGER return_sack_delete BEFORE DELETE ON mother_bags WHEN OLD.return_trip_id IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Retain return sack trip identity.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field}::text IS DISTINCT FROM OLD.{$field}::text", $fields));
            DB::unprepared("CREATE FUNCTION retain_return_sack_members() RETURNS trigger AS \$\$ DECLARE bag_status text; BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain actual return sack membership.' USING ERRCODE = '23000'; END IF;
                SELECT status INTO bag_status FROM mother_bags WHERE id = NEW.mother_bag_id;
                IF TG_OP = 'INSERT' THEN
                    IF bag_status <> 'open' OR (SELECT return_trip_id FROM mother_bags WHERE id = NEW.mother_bag_id) IS NULL
                        OR NEW.active_return_parcel_id IS DISTINCT FROM NEW.buyer_return_parcel_id THEN
                        RAISE EXCEPTION 'Retain actual return sack membership.' USING ERRCODE = '23000'; END IF;
                ELSIF ({$changed}) OR OLD.removed_at IS NOT NULL OR OLD.received_at IS NOT NULL OR NEW.active_return_parcel_id IS NOT NULL
                    OR NOT ((bag_status = 'open' AND NEW.removed_at IS NOT NULL AND NEW.received_at IS NULL AND NEW.received_by_id IS NULL)
                        OR (bag_status = 'opened' AND NEW.removed_at IS NULL AND NEW.received_at IS NOT NULL AND NEW.received_by_id IS NOT NULL)) THEN
                    RAISE EXCEPTION 'Retain actual return sack membership.' USING ERRCODE = '23000';
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER return_sack_members_retained BEFORE INSERT OR UPDATE OR DELETE ON mother_bag_return_parcels FOR EACH ROW EXECUTE FUNCTION retain_return_sack_members();");
            DB::unprepared("CREATE FUNCTION retain_return_sack_identity() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' AND OLD.return_trip_id IS NULL THEN RETURN OLD; END IF;
                IF OLD.return_trip_id IS NOT NULL THEN
                    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain return sack trip identity.' USING ERRCODE = '23000'; END IF;
                    IF NEW.return_trip_id IS DISTINCT FROM OLD.return_trip_id THEN RAISE EXCEPTION 'Retain return sack trip identity.' USING ERRCODE = '23000'; END IF;
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER return_sack_identity_retained BEFORE UPDATE OR DELETE ON mother_bags FOR EACH ROW EXECUTE FUNCTION retain_return_sack_identity();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain actual return sack and seal evidence.');
    }
};
