<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('cod_account_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_event_id')->unique()->constrained('cod_cash_events')->restrictOnDelete();
            $table->foreignId('buyer_checkpoint_id')->unique()->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->foreignId('legacy_ledger_id')->nullable()->constrained('commission_ledgers')->restrictOnDelete();
            foreach (['product_cents', 'seller_cents', 'commission_cents', 'shipping_cents', 'discount_cents'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['seller_id', 'created_at', 'id']);
        });
        Schema::create('seller_settlement_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('seller_settlement_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 40);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('payment_reference', 120)->nullable();
            $table->string('reason', 1000);
            $table->string('proof_path')->nullable();
            $table->string('proof_hash', 64)->nullable();
            $table->foreignId('source_event_id')->nullable()->constrained('seller_settlement_events')->restrictOnDelete();
            $table->json('context');
            $table->uuid('request_token');
            $table->string('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['seller_settlement_id', 'sequence']);
            $table->unique(['seller_settlement_id', 'request_token']);
            $table->index(['event_type', 'created_at', 'id']);
        });
        foreach (['seller_settlements', 'seller_settlement_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
                    BEGIN SELECT RAISE(ABORT, 'Financial history is immutable.'); END;
                    CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                    BEGIN SELECT RAISE(ABORT, 'Financial history must be retained.'); END;");
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION retain_{$table}() RETURNS trigger AS \$\$
                    BEGIN RAISE EXCEPTION 'Financial history is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION retain_{$table}();");
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER settlement_ledger_no_update BEFORE UPDATE ON commission_ledgers
                WHEN EXISTS (SELECT 1 FROM seller_settlements WHERE order_id = OLD.order_id)
                BEGIN SELECT RAISE(ABORT, 'Original settlement ledgers must be retained.'); END;
                CREATE TRIGGER settlement_ledger_no_delete BEFORE DELETE ON commission_ledgers
                WHEN EXISTS (SELECT 1 FROM seller_settlements WHERE order_id = OLD.order_id)
                BEGIN SELECT RAISE(ABORT, 'Original settlement ledgers must be retained.'); END;");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_settlement_ledger() RETURNS trigger AS \$\$
                BEGIN
                    IF EXISTS (SELECT 1 FROM seller_settlements WHERE order_id = OLD.order_id) THEN
                        RAISE EXCEPTION 'Original settlement ledgers must be retained.' USING ERRCODE = '23000';
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER settlement_ledger_retained BEFORE UPDATE OR DELETE ON commission_ledgers
                FOR EACH ROW EXECUTE FUNCTION retain_settlement_ledger();");
        }
    }

    public function down(): void
    {
        if (DB::table('seller_settlements')->exists() || DB::table('seller_settlement_events')->exists()) {
            throw new LogicException('Recorded settlement history must be retained.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS settlement_ledger_no_update; DROP TRIGGER IF EXISTS settlement_ledger_no_delete;');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS settlement_ledger_retained ON commission_ledgers; DROP FUNCTION IF EXISTS retain_settlement_ledger();');
        }
        Schema::dropIfExists('seller_settlement_events');
        Schema::dropIfExists('seller_settlements');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_seller_settlements(); DROP FUNCTION IF EXISTS retain_seller_settlement_events();');
        }
    }
};
