<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->unsignedInteger('campaign_version')->default(0);
            $table->timestamp('suspended_at')->nullable();
        });
        Schema::create('voucher_campaign_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('based_on_revision_id')->nullable()->constrained('voucher_revisions')->restrictOnDelete();
            $table->foreignId('saved_by_id')->constrained('users')->restrictOnDelete();
            $table->json('terms');
            $table->timestamps();
        });
        Schema::create('voucher_campaign_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_revision_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name', 255);
            $table->string('action', 30);
            $table->uuid('request_key')->unique();
            $table->string('fingerprint', 64);
            $table->unsignedInteger('source_version');
            $table->unsignedBigInteger('budget_increase_cents')->default(0);
            $table->string('reason', 1000)->nullable();
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamps();
            $table->index(['voucher_id', 'id']);
            $table->index(['voucher_revision_id', 'action']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER voucher_campaign_actions_no_'.strtolower($operation)." BEFORE {$operation} ON voucher_campaign_actions BEGIN SELECT RAISE(ABORT, 'Voucher campaign history is immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER voucher_campaign_actions_immutable BEFORE UPDATE OR DELETE ON voucher_campaign_actions FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::table('voucher_campaign_actions')->exists()) {
            throw new LogicException('Retain published campaign and budget decisions. Pause new offers instead.');
        }
        Schema::dropIfExists('voucher_campaign_actions');
        Schema::dropIfExists('voucher_campaign_drafts');
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn(['campaign_version', 'suspended_at']));
    }
};
