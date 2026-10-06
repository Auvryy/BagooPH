<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('closed_at', 6)->nullable());
        Schema::create('account_closures', function (Blueprint $table) {
            $table->id();
            // Provenance survives safe deletion; these identifiers deliberately have no live-user foreign key.
            $table->unsignedBigInteger('subject_id')->unique();
            $table->string('subject_role');
            $table->string('subject_name');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_role');
            $table->string('actor_name');
            $table->string('source_token', 64);
            $table->string('outcome', 20);
            $table->text('reason');
            $table->json('before_state');
            $table->timestamp('closed_at', 6);
            $table->index(['actor_id', 'id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared('CREATE TRIGGER account_closures_no_'.strtolower($action)." BEFORE {$action} ON account_closures BEGIN SELECT RAISE(ABORT, 'Account closure decisions are immutable'); END");
            }
            DB::unprepared("CREATE TRIGGER closed_accounts_no_reopen BEFORE UPDATE ON users WHEN OLD.closed_at IS NOT NULL AND (NEW.closed_at IS NULL OR NEW.closed_at <> OLD.closed_at OR NEW.status <> 'inactive') BEGIN SELECT RAISE(ABORT, 'Closed accounts must remain inactive and retained'); END");
            DB::unprepared("CREATE TRIGGER closed_accounts_no_delete BEFORE DELETE ON users WHEN OLD.closed_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Closed accounts must remain inactive and retained'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER account_closures_immutable BEFORE UPDATE OR DELETE ON account_closures FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()');
            DB::unprepared("CREATE FUNCTION preserve_closed_accounts() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF OLD.closed_at IS NOT NULL THEN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Closed accounts must remain inactive and retained'; ELSIF NEW.closed_at IS DISTINCT FROM OLD.closed_at OR NEW.status IS DISTINCT FROM 'inactive' THEN RAISE EXCEPTION 'Closed accounts must remain inactive and retained'; END IF; END IF; IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER closed_accounts_retained BEFORE UPDATE OR DELETE ON users FOR EACH ROW EXECUTE FUNCTION preserve_closed_accounts()');
        }
    }

    public function down(): void
    {
        if (DB::table('account_closures')->exists()) {
            throw new LogicException('Recorded account closures must be retained.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS closed_accounts_retained ON users');
            DB::unprepared('DROP FUNCTION IF EXISTS preserve_closed_accounts()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS closed_accounts_no_reopen');
            DB::unprepared('DROP TRIGGER IF EXISTS closed_accounts_no_delete');
        }
        Schema::dropIfExists('account_closures');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('closed_at'));
    }
};
