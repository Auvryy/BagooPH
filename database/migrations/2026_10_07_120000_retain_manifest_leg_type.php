<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER manifest_leg_type_retained BEFORE UPDATE ON logistics_manifests
                WHEN NEW.type IS NOT OLD.type
                BEGIN SELECT RAISE(ABORT, 'Manifest leg type must be retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION retain_manifest_leg_type() RETURNS trigger AS $$
                BEGIN
                    IF NEW.type IS DISTINCT FROM OLD.type THEN
                        RAISE EXCEPTION 'Manifest leg type must be retained.' USING ERRCODE = '23000';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER manifest_leg_type_retained BEFORE UPDATE ON logistics_manifests
                    FOR EACH ROW EXECUTE FUNCTION retain_manifest_leg_type();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('logistics_manifests')->exists()) {
            throw new LogicException('Manifest evidence and its guards must be retained while manifests exist.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS manifest_leg_type_retained'.(DB::getDriverName() === 'pgsql' ? ' ON logistics_manifests' : ''));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_manifest_leg_type()');
        }
    }
};
