<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PARENTS = [
        'deliveries' => 'c.delivery_id = OLD.id',
        'orders' => 'd.order_id = OLD.id',
        'order_items' => 'd.order_id = OLD.order_id',
        'products' => 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = d.order_id AND oi.product_id = OLD.id)',
        'users' => 'c.scanned_by_id = OLD.id',
        'logistics_companies' => 'd.logistics_company_id = OLD.id',
        'shops' => 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = d.order_id AND oi.shop_id = OLD.id)',
        'logistics_hubs' => '(c.hub_id = OLD.id OR d.current_hub_id = OLD.id OR d.origin_bayan_hub_id = OLD.id OR d.origin_mother_hub_id = OLD.id OR d.destination_mother_hub_id = OLD.id OR d.destination_bayan_hub_id = OLD.id)',
    ];

    public function up(): void
    {
        Schema::table('delivery_checkpoints', function (Blueprint $table) {
            // Historical missing facts remain null; no physical event is reconstructed.
            $table->string('record_reference', 64)->nullable()->unique();
            $table->string('actor_role', 20)->nullable();
            $table->string('scan_provenance', 30)->nullable();
            $table->json('source_state')->nullable();
            $table->json('target_state')->nullable();
            $table->json('custody_before')->nullable();
            $table->json('custody_after')->nullable();
            $table->foreignId('source_checkpoint_id')->nullable()->constrained('delivery_checkpoints')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER custody_checkpoint_no_'.strtolower($operation)." BEFORE {$operation} ON delivery_checkpoints
                    BEGIN SELECT RAISE(ABORT, 'Custody evidence is immutable.'); END");
            }
            foreach (self::PARENTS as $table => $condition) {
                DB::unprepared("CREATE TRIGGER custody_{$table}_retained BEFORE DELETE ON {$table}
                    WHEN EXISTS (SELECT 1 FROM delivery_checkpoints c JOIN deliveries d ON d.id = c.delivery_id WHERE {$condition})
                    BEGIN SELECT RAISE(ABORT, 'Custody references must be retained.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_custody_checkpoint_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Custody evidence is immutable.' USING ERRCODE = '23000';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER custody_checkpoint_immutable BEFORE UPDATE OR DELETE ON delivery_checkpoints
                    FOR EACH ROW EXECUTE FUNCTION prevent_custody_checkpoint_mutation();
                SQL);
            foreach (self::PARENTS as $table => $condition) {
                DB::unprepared("CREATE FUNCTION retain_custody_{$table}() RETURNS trigger AS \$\$
                    BEGIN
                        IF EXISTS (SELECT 1 FROM delivery_checkpoints c JOIN deliveries d ON d.id = c.delivery_id WHERE {$condition}) THEN
                            RAISE EXCEPTION 'Custody references must be retained.' USING ERRCODE = '23000';
                        END IF;
                        RETURN OLD;
                    END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER custody_{$table}_retained BEFORE DELETE ON {$table}
                        FOR EACH ROW EXECUTE FUNCTION retain_custody_{$table}()");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('delivery_checkpoints')->exists()) {
            throw new LogicException('Custody evidence and its guards must be retained while checkpoints exist.');
        }
        $driver = DB::getDriverName();
        foreach (self::PARENTS as $table => $condition) {
            DB::unprepared("DROP TRIGGER IF EXISTS custody_{$table}_retained".($driver === 'pgsql' ? " ON {$table}" : ''));
            if ($driver === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS retain_custody_{$table}()");
            }
        }
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS custody_checkpoint_no_update; DROP TRIGGER IF EXISTS custody_checkpoint_no_delete');
        } elseif ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS custody_checkpoint_immutable ON delivery_checkpoints; DROP FUNCTION IF EXISTS prevent_custody_checkpoint_mutation()');
        }
        Schema::table('delivery_checkpoints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_checkpoint_id');
            $table->dropUnique(['record_reference']);
            $table->dropColumn(['record_reference', 'actor_role', 'scan_provenance', 'source_state', 'target_state', 'custody_before', 'custody_after']);
        });
    }
};
