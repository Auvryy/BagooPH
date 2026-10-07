<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->string('source_key', 100);
            $table->json('data');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['recipient_id', 'source_key']);
            $table->index(['delivered_at', 'available_at']);
        });

        $condition = implode(' OR ', array_map(fn ($column) => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'recipient_id', 'type', 'source_key', 'data', 'created_at']));
        if (DB::getDriverName() === 'sqlite') {
            $condition = str_replace(' IS DISTINCT FROM ', ' IS NOT ', $condition);
            DB::unprepared("CREATE TRIGGER notification_deliveries_identity BEFORE UPDATE ON notification_deliveries WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Notification event identity must be retained.'); END;
                CREATE TRIGGER notification_deliveries_no_delete BEFORE DELETE ON notification_deliveries
                BEGIN SELECT RAISE(ABORT, 'Notification delivery history must be retained.'); END;");
        } elseif (DB::getDriverName() === 'pgsql') {
            $condition = str_replace('NEW.data IS DISTINCT FROM OLD.data', 'NEW.data::text IS DISTINCT FROM OLD.data::text', $condition);
            DB::unprepared("CREATE FUNCTION retain_notification_delivery() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Notification delivery history must be retained.' USING ERRCODE = '23000'; END IF;
                    IF {$condition} THEN RAISE EXCEPTION 'Notification event identity must be retained.' USING ERRCODE = '23000'; END IF;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER notification_deliveries_identity BEFORE UPDATE OR DELETE ON notification_deliveries FOR EACH ROW EXECUTE FUNCTION retain_notification_delivery();");
        }
    }

    public function down(): void
    {
        if (DB::table('notification_deliveries')->exists()) {
            throw new LogicException('Recorded notification delivery history must be retained.');
        }
        Schema::dropIfExists('notification_deliveries');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_notification_delivery();');
        }
    }
};
