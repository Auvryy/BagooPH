<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_shifts', fn (Blueprint $table) => $table->unsignedBigInteger('cash_event_floor')->default(0));
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER era_shift_cash_floor BEFORE UPDATE ON rider_shifts WHEN NEW.cash_event_floor IS NOT OLD.cash_event_floor BEGIN SELECT RAISE(ABORT, 'Original shift cash boundary is retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION era_retain_shift_cash_floor() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.cash_event_floor IS DISTINCT FROM OLD.cash_event_floor THEN RAISE EXCEPTION 'Original shift cash boundary is retained.'; END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER era_shift_cash_floor BEFORE UPDATE ON rider_shifts FOR EACH ROW EXECUTE FUNCTION era_retain_shift_cash_floor()");
        }
    }

    public function down(): void
    {
        throw new LogicException('Original shift cash boundaries must be retained. Roll forward with a reviewed repair.');
    }
};
