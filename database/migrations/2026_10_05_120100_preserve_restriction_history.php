<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SUBJECTS = ['account' => 'users', 'shop' => 'shops', 'company' => 'logistics_companies',
        'hub' => 'logistics_hubs', 'handler' => 'hub_handlers', 'fleet' => 'logistics_fleet'];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['restriction_decisions', 'restriction_affected_work'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    $name = $table.'_no_'.strtolower($operation);
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Restriction history is immutable.'); END");
                }
            }
            foreach (self::SUBJECTS as $type => $table) {
                DB::unprepared("CREATE TRIGGER restriction_{$type}_retained BEFORE DELETE ON {$table}
                    WHEN EXISTS (SELECT 1 FROM restriction_decisions WHERE subject_type = '{$type}' AND subject_id = OLD.id)
                    BEGIN SELECT RAISE(ABORT, 'Restriction subjects must be retained.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_restriction_history_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Restriction history is immutable.' USING ERRCODE = '23000';
                END;
                $$ LANGUAGE plpgsql;
                CREATE FUNCTION retain_restriction_subject() RETURNS trigger AS $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM restriction_decisions WHERE subject_type = TG_ARGV[0] AND subject_id = OLD.id) THEN
                        RAISE EXCEPTION 'Restriction subjects must be retained.' USING ERRCODE = '23000';
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
                SQL);
            foreach (['restriction_decisions', 'restriction_affected_work'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()");
            }
            foreach (self::SUBJECTS as $type => $table) {
                DB::unprepared("CREATE TRIGGER restriction_{$type}_retained BEFORE DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION retain_restriction_subject('{$type}')");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('restriction_decisions')->exists()) {
            throw new LogicException('Restriction history guards must be retained with recorded decisions.');
        }
        $driver = DB::getDriverName();
        foreach (self::SUBJECTS as $type => $table) {
            DB::unprepared('DROP TRIGGER IF EXISTS restriction_'.$type.'_retained'.($driver === 'pgsql' ? ' ON '.$table : ''));
        }
        foreach (['restriction_decisions', 'restriction_affected_work'] as $table) {
            if ($driver === 'sqlite') {
                foreach (['update', 'delete'] as $operation) {
                    DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_no_'.$operation);
                }
            } elseif ($driver === 'pgsql') {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable ON '.$table);
            }
        }
        if ($driver === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_restriction_history_mutation(); DROP FUNCTION IF EXISTS retain_restriction_subject()');
        }
    }
};
