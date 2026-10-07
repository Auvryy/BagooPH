<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->foreignId('departure_checkpoint_id')->unique()->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('reason_code', 40);
            $table->string('notes', 500);
            $table->string('location_name');
            $table->string('barcode_scanned');
            $table->string('proof_path');
            $table->string('proof_hash', 64);
            $table->timestamp('attempted_at');
            $table->json('source_state');
            $table->string('request_token', 36);
            $table->string('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['delivery_id', 'attempt_number']);
            $table->unique(['delivery_id', 'request_token']);
        });
        Schema::create('delivery_recovery_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_attempt_id')->constrained('delivery_attempts')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('barcode_scanned')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->json('source_state');
            $table->json('target_state');
            $table->string('request_token', 36)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['delivery_attempt_id', 'event_type']);
            $table->unique(['delivery_id', 'request_token']);
        });
        foreach (['delivery_attempts', 'delivery_recovery_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($operation)." BEFORE {$operation} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Delivery recovery evidence is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$
                    BEGIN RAISE EXCEPTION 'Delivery recovery evidence is immutable.' USING ERRCODE = '23000'; END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                        FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('delivery_attempts')->exists() || DB::table('delivery_recovery_events')->exists()) {
            throw new LogicException('Delivery recovery evidence must be retained while records exist.');
        }
        Schema::dropIfExists('delivery_recovery_events');
        Schema::dropIfExists('delivery_attempts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_delivery_attempts(); DROP FUNCTION IF EXISTS retain_delivery_recovery_events()');
        }
    }
};
