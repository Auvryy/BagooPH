<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('delivery_checkpoint_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('pickup_cash_entry_id')->nullable()->unique()->constrained('cod_custody_entries')->restrictOnDelete();
            $table->string('source_kind', 30);
            $table->unsignedBigInteger('expected_cents');
            $table->unsignedBigInteger('product_subtotal_cents');
            $table->unsignedBigInteger('discount_cents');
            $table->unsignedBigInteger('shipping_cents');
            $table->unsignedInteger('version')->default(0);
            $table->json('state');
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['logistics_company_id', 'hub_id']);
        });
        Schema::create('cod_cash_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('cod_account_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('provenance', 40);
            $table->string('event_type', 40);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20)->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->unsignedBigInteger('expected_cents')->default(0);
            $table->unsignedBigInteger('received_cents')->default(0);
            $table->bigInteger('discrepancy_cents')->default(0);
            $table->foreignId('source_event_id')->nullable()->constrained('cod_cash_events')->restrictOnDelete();
            $table->string('evidence_reference', 120)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->json('private_evidence')->nullable();
            $table->json('source_state');
            $table->json('target_state');
            $table->uuid('request_token');
            $table->string('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['cod_account_id', 'sequence']);
            $table->unique(['cod_account_id', 'request_token']);
            $table->index(['to_user_id', 'event_type']);
        });
        $columns = ['reference', 'order_id', 'delivery_id', 'logistics_company_id', 'hub_id', 'collector_id',
            'delivery_checkpoint_id', 'pickup_cash_entry_id', 'source_kind', 'expected_cents', 'product_subtotal_cents',
            'discount_cents', 'shipping_cents', 'created_at'];
        $frozen = implode(' OR ', array_map(fn ($column) => "NEW.{$column} IS DISTINCT FROM OLD.{$column}", $columns));
        $frozen .= ' OR OLD.reconciled_at IS NOT NULL';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TRIGGER cod_accounts_identity_retained BEFORE UPDATE ON cod_accounts WHEN '.str_replace(' IS DISTINCT FROM ', ' IS NOT ', $frozen)."
                BEGIN SELECT RAISE(ABORT, 'COD source and reconciled records must be retained.'); END;
                CREATE TRIGGER cod_accounts_no_delete BEFORE DELETE ON cod_accounts
                BEGIN SELECT RAISE(ABORT, 'COD history must be retained.'); END;
                CREATE TRIGGER cod_cash_events_no_update BEFORE UPDATE ON cod_cash_events
                BEGIN SELECT RAISE(ABORT, 'Cash events are immutable.'); END;
                CREATE TRIGGER cod_cash_events_no_delete BEFORE DELETE ON cod_cash_events
                BEGIN SELECT RAISE(ABORT, 'Cash events are immutable.'); END;");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_cod_account() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'COD history must be retained.' USING ERRCODE = '23000'; END IF;
                    IF {$frozen} THEN RAISE EXCEPTION 'COD source and reconciled records must be retained.' USING ERRCODE = '23000'; END IF;
                    RETURN NEW;
                END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER cod_accounts_identity_retained BEFORE UPDATE OR DELETE ON cod_accounts FOR EACH ROW EXECUTE FUNCTION retain_cod_account();
                CREATE FUNCTION retain_cod_cash_event() RETURNS trigger AS \$\$
                BEGIN RAISE EXCEPTION 'Cash events are immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER cod_cash_events_immutable BEFORE UPDATE OR DELETE ON cod_cash_events FOR EACH ROW EXECUTE FUNCTION retain_cod_cash_event();");
        }
    }

    public function down(): void
    {
        if (DB::table('cod_accounts')->exists() || DB::table('cod_cash_events')->exists()) {
            throw new LogicException('Recorded cash custody and reconciliation must be retained.');
        }
        Schema::dropIfExists('cod_cash_events');
        Schema::dropIfExists('cod_accounts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_cod_account(); DROP FUNCTION IF EXISTS retain_cod_cash_event();');
        }
    }
};
