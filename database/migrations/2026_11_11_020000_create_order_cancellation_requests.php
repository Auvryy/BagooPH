<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->string('reason', 100);
            $table->text('notes')->nullable();
            $table->json('source_state');
            $table->char('request_token_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->timestamp('respond_by')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_cancellation_events', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('cancellation_request_id')->constrained('order_cancellation_requests')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event_type', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20)->nullable();
            $table->text('reason');
            $table->json('source_state');
            $table->json('target_state');
            $table->char('request_token_hash', 64)->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('created_at');
            $table->unique(['cancellation_request_id', 'version'], 'order_cancellation_event_version_unique');
        });
        $original = ['reference', 'order_id', 'buyer_id', 'seller_id', 'reason', 'notes', 'source_state', 'request_token_hash', 'request_fingerprint', 'respond_by', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($field) => "OLD.{$field} IS NOT NEW.{$field}", $original));
            DB::unprepared("CREATE TRIGGER cancellation_request_original_update BEFORE UPDATE ON order_cancellation_requests WHEN {$changed} BEGIN SELECT RAISE(ABORT, 'Original cancellation request must be retained'); END");
            DB::unprepared("CREATE TRIGGER cancellation_request_original_delete BEFORE DELETE ON order_cancellation_requests BEGIN SELECT RAISE(ABORT, 'Original cancellation request must be retained'); END");
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared('CREATE TRIGGER cancellation_event_'.strtolower($action)." BEFORE {$action} ON order_cancellation_events BEGIN SELECT RAISE(ABORT, 'Cancellation evidence must be retained'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn ($field) => $field === 'source_state'
                ? 'OLD.source_state::text IS DISTINCT FROM NEW.source_state::text' : "OLD.{$field} IS DISTINCT FROM NEW.{$field}", $original));
            DB::unprepared("CREATE FUNCTION retain_order_cancellation_request() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Original cancellation request must be retained'; END IF; IF {$changed} THEN RAISE EXCEPTION 'Original cancellation request must be retained'; END IF; RETURN NEW; END; $$");
            DB::unprepared('CREATE TRIGGER cancellation_request_original BEFORE UPDATE OR DELETE ON order_cancellation_requests FOR EACH ROW EXECUTE FUNCTION retain_order_cancellation_request()');
            DB::unprepared("CREATE FUNCTION retain_order_cancellation_event() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Cancellation evidence must be retained'; END; $$");
            DB::unprepared('CREATE TRIGGER cancellation_event_original BEFORE UPDATE OR DELETE ON order_cancellation_events FOR EACH ROW EXECUTE FUNCTION retain_order_cancellation_event()');
        }
    }

    public function down(): void
    {
        if (DB::table('order_cancellation_requests')->exists() || DB::table('order_cancellation_events')->exists()) {
            throw new LogicException('Cancellation requests and their decisions must be retained.');
        }
        Schema::dropIfExists('order_cancellation_events');
        Schema::dropIfExists('order_cancellation_requests');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_order_cancellation_event()');
            DB::unprepared('DROP FUNCTION IF EXISTS retain_order_cancellation_request()');
        }
    }
};
