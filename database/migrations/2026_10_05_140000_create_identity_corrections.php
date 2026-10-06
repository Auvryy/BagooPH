<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('identity_version')->default(0));
        Schema::create('identity_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('source_token', 64);
            $table->string('request_token', 64);
            $table->text('reason');
            $table->json('source');
            $table->json('proposed');
            $table->json('evidence');
            $table->json('provenance');
            $table->timestampTz('requested_at', 6);
            $table->unique(['user_id', 'request_token']);
            $table->index(['user_id', 'id']);
        });
        Schema::create('identity_correction_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correction_request_id')->unique()->constrained('identity_correction_requests')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->string('review_token', 64);
            $table->string('action', 20);
            $table->text('reason');
            $table->json('before_state');
            $table->json('after_state');
            $table->timestampTz('decided_at', 6);
        });
        Schema::table('shop_review_decisions', fn (Blueprint $table) => $table->foreignId('identity_correction_request_id')->nullable()->constrained('identity_correction_requests')->restrictOnDelete());
        // SQLite rebuilds a table when changing a foreign key and drops its existing triggers.
        $this->restoreShopReviewGuards();
        foreach (['identity_correction_requests', 'identity_correction_decisions'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Identity correction history is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()");
            }
        }
    }

    private function restoreShopReviewGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER IF NOT EXISTS shop_reviews_no_'.strtolower($operation)." BEFORE {$operation} ON shop_review_decisions BEGIN SELECT RAISE(ABORT, 'Shop review decisions are immutable.'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('identity_correction_requests')->exists()) {
            throw new LogicException('Identity correction evidence must be retained.');
        }
        if (DB::getDriverName() === 'sqlite' && DB::table('shop_review_decisions')->exists()) {
            throw new LogicException('Recorded shop reviews must be retained; SQLite cannot rebuild their referenced table during rollback.');
        }
        Schema::table('shop_review_decisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('identity_correction_request_id'));
        $this->restoreShopReviewGuards();
        Schema::dropIfExists('identity_correction_decisions');
        Schema::dropIfExists('identity_correction_requests');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('identity_version'));
    }
};
