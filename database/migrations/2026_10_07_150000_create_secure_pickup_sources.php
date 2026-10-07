<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_hubs', fn (Blueprint $table) => $table->string('operating_hours')->nullable());
        Schema::create('pickup_claims', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('delivery_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('ready_checkpoint_id')->unique()->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('prepared_by_id')->constrained('users')->restrictOnDelete();
            $table->string('tracking_number_snapshot');
            $table->string('status', 16)->default('ready');
            $table->string('code_hash')->nullable();
            $table->timestamp('code_issued_at')->nullable();
            $table->timestamp('ready_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedSmallInteger('failed_verifications')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
        Schema::create('pickup_claim_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('pickup_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20)->nullable();
            $table->string('provenance', 30);
            $table->string('event_type', 40);
            $table->string('barcode_scanned')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->json('source_state');
            $table->json('target_state');
            $table->string('request_token', 36)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['pickup_claim_id', 'request_token']);
        });
        Schema::create('cod_custody_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->foreignId('holder_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pickup_claim_event_id')->unique()->constrained()->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('tender_cents');
            $table->unsignedBigInteger('change_cents');
            $table->timestamps();
            $table->unique(['delivery_id', 'entry_type']);
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->string('source_key', 100);
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['notifiable_type', 'notifiable_id', 'source_key'], 'notification_source_unique');
        });
        foreach (['pickup_claim_events', 'cod_custody_entries'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $op) {
                    DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($op)." BEFORE {$op} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Pickup and cash evidence is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$
                    BEGIN RAISE EXCEPTION 'Pickup and cash evidence is immutable.' USING ERRCODE = '23000'; END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
        $frozen = ['reference', 'delivery_id', 'ready_checkpoint_id', 'hub_id', 'buyer_id', 'prepared_by_id', 'tracking_number_snapshot', 'ready_at', 'expires_at', 'created_at'];
        $claims = implode(' OR ', array_map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}", $frozen));
        $claims .= ' OR (OLD.code_hash IS NOT NULL AND (NEW.code_hash IS DISTINCT FROM OLD.code_hash OR NEW.code_issued_at IS DISTINCT FROM OLD.code_issued_at)) OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at)';
        $notice = implode(' OR ', array_map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}", ['id', 'type', 'notifiable_type', 'notifiable_id', 'source_key', 'data', 'created_at']));
        foreach (['pickup_claims' => $claims, 'notifications' => $notice] as $table => $condition) {
            if (DB::getDriverName() === 'sqlite') {
                $condition = str_replace(' IS DISTINCT FROM ', ' IS NOT ', $condition);
                DB::unprepared("CREATE TRIGGER {$table}_identity_retained BEFORE UPDATE ON {$table} WHEN {$condition}
                    BEGIN SELECT RAISE(ABORT, 'Pickup and notice source identity must be retained.'); END;
                    CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                    BEGIN SELECT RAISE(ABORT, 'Pickup and notice history must be retained.'); END;");
            } elseif (DB::getDriverName() === 'pgsql') {
                // JSON equality is deliberately compared as text; the source writer stores canonical JSON once.
                $condition = str_replace('NEW.data IS DISTINCT FROM OLD.data', 'NEW.data::text IS DISTINCT FROM OLD.data::text', $condition);
                DB::unprepared("CREATE FUNCTION retain_{$table}_identity() RETURNS trigger AS \$\$
                    BEGIN
                        IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Pickup and notice history must be retained.' USING ERRCODE = '23000'; END IF;
                        IF {$condition} THEN RAISE EXCEPTION 'Pickup and notice source identity must be retained.' USING ERRCODE = '23000'; END IF;
                        RETURN NEW;
                    END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_identity_retained BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION retain_{$table}_identity();");
            }
        }
    }

    public function down(): void
    {
        foreach (['pickup_claims', 'pickup_claim_events', 'cod_custody_entries', 'notifications'] as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Recorded pickup, cash and notice history must be retained.');
            }
        }
        foreach (['cod_custody_entries', 'pickup_claim_events', 'pickup_claims', 'notifications'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('logistics_hubs', fn (Blueprint $table) => $table->dropColumn('operating_hours'));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_cod_custody_entries(); DROP FUNCTION IF EXISTS retain_pickup_claim_events(); DROP FUNCTION IF EXISTS retain_pickup_claims_identity(); DROP FUNCTION IF EXISTS retain_notifications_identity();');
        }
    }
};
