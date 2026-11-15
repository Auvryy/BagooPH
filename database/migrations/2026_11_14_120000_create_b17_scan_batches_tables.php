<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_batch_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_reference')->unique();
            $table->foreignId('logistics_company_id')->constrained('logistics_companies')->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('source_channel', 16)->default('web');
            $table->boolean('direct_mode')->default(false);
            $table->string('pinned_action', 100)->nullable();
            $table->unsignedInteger('submitted_count')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('elapsed_ms')->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['hub_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        Schema::create('scan_request_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->uuid('request_token');
            $table->string('request_fingerprint', 64);
            $table->string('barcode_submitted', 255);
            $table->string('action_applied', 100);
            $table->string('status_before', 100);
            $table->string('status_after', 100);
            $table->string('checkpoint_reference', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['actor_id', 'request_token']);
            $table->index(['hub_id', 'delivery_id']);
        });

        Schema::create('scan_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_batch_request_id')->constrained('scan_batch_requests')->restrictOnDelete();
            $table->unsignedInteger('item_index');
            $table->uuid('request_token');
            $table->string('barcode_submitted', 255);
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->restrictOnDelete();
            $table->string('expected_action', 100)->nullable();
            $table->string('action_applied', 100)->nullable();
            $table->string('outcome', 32);
            $table->string('status_before', 100)->nullable();
            $table->string('status_after', 100)->nullable();
            $table->string('checkpoint_reference', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('client_scanned_at')->nullable();
            $table->timestamp('server_processed_at')->useCurrent();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['scan_batch_request_id', 'item_index']);
            $table->index(['request_token']);
            $table->index(['delivery_id', 'outcome']);
        });

        $this->retainBatchTables();
    }

    private function retainBatchTables(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER b17_scan_batch_requests_update BEFORE UPDATE ON scan_batch_requests WHEN OLD.finalized_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Finalized scan batch history is retained.'); END");
            DB::unprepared("CREATE TRIGGER b17_scan_batch_requests_delete BEFORE DELETE ON scan_batch_requests BEGIN SELECT RAISE(ABORT, 'Scan batch history is retained.'); END");
            DB::unprepared("CREATE TRIGGER b17_scan_request_ledgers_update BEFORE UPDATE ON scan_request_ledgers BEGIN SELECT RAISE(ABORT, 'Scan request ledger is retained.'); END");
            DB::unprepared("CREATE TRIGGER b17_scan_request_ledgers_delete BEFORE DELETE ON scan_request_ledgers BEGIN SELECT RAISE(ABORT, 'Scan request ledger is retained.'); END");
            DB::unprepared("CREATE TRIGGER b17_scan_batch_items_update BEFORE UPDATE ON scan_batch_items BEGIN SELECT RAISE(ABORT, 'Scan batch item history is retained.'); END");
            DB::unprepared("CREATE TRIGGER b17_scan_batch_items_delete BEFORE DELETE ON scan_batch_items BEGIN SELECT RAISE(ABORT, 'Scan batch item history is retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION b17_retain_batch_request() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' OR OLD.finalized_at IS NOT NULL THEN RAISE EXCEPTION 'Finalized scan batch history is retained.' USING ERRCODE = '23000'; END IF;
                RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER b17_scan_batch_requests_retained BEFORE UPDATE OR DELETE ON scan_batch_requests FOR EACH ROW EXECUTE FUNCTION b17_retain_batch_request();");

            DB::unprepared("CREATE FUNCTION b17_retain_immutable_scan_row() RETURNS trigger AS \$\$ BEGIN
                RAISE EXCEPTION 'Retained scan evidence cannot be modified or deleted.' USING ERRCODE = '23000';
                END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER b17_scan_request_ledgers_retained BEFORE UPDATE OR DELETE ON scan_request_ledgers FOR EACH ROW EXECUTE FUNCTION b17_retain_immutable_scan_row();
                CREATE TRIGGER b17_scan_batch_items_retained BEFORE UPDATE OR DELETE ON scan_batch_items FOR EACH ROW EXECUTE FUNCTION b17_retain_immutable_scan_row();");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Scan batch history must be retained. Restore a verified backup rather than dropping scan custody audit tables.');
    }
};
