<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER shipment_financial_plans_retain_update');
        } elseif ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER shipment_financial_plans_retained ON shipment_financial_plans');
        }
        Schema::table('shipment_financial_plans', function (Blueprint $table) {
            $table->dropUnique(['undelivered_order_id']);
            $table->dropUnique(['buyer_return_case_id']);
            $table->index('undelivered_order_id');
            $table->index('buyer_return_case_id');
            $table->foreignId('active_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('active_return_case_id')->nullable()->unique()->constrained('buyer_return_cases')->restrictOnDelete();
            $table->foreignId('loss_observation_id')->nullable()->index()->constrained('logistics_manifest_events')->restrictOnDelete();
        });
        DB::table('shipment_financial_plans')->update(['active_order_id' => DB::raw('undelivered_order_id'), 'active_return_case_id' => DB::raw('buyer_return_case_id')]);
        foreach (['wallet_refund_reserve_entries', 'wallet_refund_credit_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('shipment_financial_plan_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        if ($driver === 'sqlite') {
            // Adding foreign keys rebuilds these SQLite tables and removes their original triggers.
            foreach (['wallet_refund_reserve_entries' => ['UPDATE', 'DELETE'], 'shipment_financial_plans' => ['DELETE']] as $name => $operations) {
                foreach ($operations as $operation) {
                    $trigger = $name.'_retain_'.strtolower($operation);
                    DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$name}
                        BEGIN SELECT RAISE(ABORT, 'Retain the original financial authority and actual reserve evidence.'); END");
                }
            }
            $fields = ['id', 'reference', 'authority_reference', 'repayment_plan_id', 'actor_id', 'amount_cents', 'reason', 'request_token_hash', 'request_fingerprint', 'created_at'];
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_refund_credit_request_update');
            DB::unprepared("CREATE TRIGGER wallet_refund_credit_request_update BEFORE UPDATE ON wallet_refund_credit_requests
                WHEN OLD.status <> 'pending' OR {$changed}
                BEGIN SELECT RAISE(ABORT, 'Retain the original demonstration funding request and final review.'); END");
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_refund_credit_request_delete');
            DB::unprepared("CREATE TRIGGER wallet_refund_credit_request_delete BEFORE DELETE ON wallet_refund_credit_requests
                BEGIN SELECT RAISE(ABORT, 'Retain original demonstration funding requests.'); END");
            DB::unprepared("CREATE TRIGGER wallet_refund_credit_request_shipment_authority_update BEFORE UPDATE ON wallet_refund_credit_requests
                WHEN NEW.shipment_financial_plan_id IS NOT OLD.shipment_financial_plan_id
                BEGIN SELECT RAISE(ABORT, 'Retain the original shipment funding-credit authority.'); END");
        } elseif ($driver === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_refund_credit_shipment_authority() RETURNS trigger AS \$\$ BEGIN
                IF NEW.shipment_financial_plan_id IS DISTINCT FROM OLD.shipment_financial_plan_id THEN
                    RAISE EXCEPTION 'Retain the original shipment funding-credit authority.' USING ERRCODE = '23000';
                END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER wallet_refund_credit_request_shipment_authority_update BEFORE UPDATE ON wallet_refund_credit_requests
                    FOR EACH ROW EXECUTE FUNCTION retain_refund_credit_shipment_authority();");
        }
        $fields = array_values(array_diff(Schema::getColumnListing('shipment_financial_plans'), ['active_order_id', 'active_return_case_id']));
        $voided = "EXISTS (SELECT 1 FROM shipment_financial_events WHERE shipment_financial_plan_id = OLD.id AND event_type = 'voided' AND amount_cents = 0)
            AND NOT EXISTS (SELECT 1 FROM wallet_refund_reserve_entries WHERE shipment_financial_plan_id = OLD.id)
            AND NOT EXISTS (SELECT 1 FROM shipment_financial_events WHERE shipment_financial_plan_id = OLD.id AND (amount_cents > 0 OR wallet_transaction_id IS NOT NULL))";
        if ($driver === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT OLD.{$field}", $fields));
            DB::unprepared("CREATE TRIGGER shipment_financial_plans_retain_update BEFORE UPDATE ON shipment_financial_plans
                WHEN ({$changed}) OR ((NEW.active_order_id IS NOT OLD.active_order_id OR NEW.active_return_case_id IS NOT OLD.active_return_case_id)
                    AND NOT (OLD.kind = 'transit_loss' AND NEW.active_order_id IS NULL AND NEW.active_return_case_id IS NULL AND {$voided}))
                BEGIN SELECT RAISE(ABORT, 'Retain the original shipment review; only an evidenced unfunded void may release its active claim.'); END");
        } elseif ($driver === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($field) => "NEW.{$field}::text IS DISTINCT FROM OLD.{$field}::text", $fields));
            DB::unprepared("CREATE OR REPLACE FUNCTION retain_shipment_financial_plans() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain original shipment financial reviews.' USING ERRCODE = '23000'; END IF;
                IF ({$changed}) OR ((NEW.active_order_id IS DISTINCT FROM OLD.active_order_id OR NEW.active_return_case_id IS DISTINCT FROM OLD.active_return_case_id)
                    AND NOT (OLD.kind = 'transit_loss' AND NEW.active_order_id IS NULL AND NEW.active_return_case_id IS NULL AND {$voided}))
                THEN RAISE EXCEPTION 'Retain the original shipment review; only an evidenced unfunded void may release its active claim.' USING ERRCODE = '23000'; END IF;
                RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER shipment_financial_plans_retained BEFORE UPDATE OR DELETE ON shipment_financial_plans FOR EACH ROW EXECUTE FUNCTION retain_shipment_financial_plans();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain reviewed loss authorities, their funding and original active-claim history.');
    }
};
