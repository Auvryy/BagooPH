<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('restriction_version')->default(0));
        Schema::create('governance_guards', function (Blueprint $table) {
            $table->string('name')->primary();
        });
        DB::table('governance_guards')->insert(['name' => 'platform-admin-continuity']);
        Schema::create('restriction_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role');
            $table->string('actor_name');
            $table->string('source_token', 64);
            $table->string('action', 20);
            $table->text('reason');
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamp('decided_at', 6);
            $table->unique(['subject_type', 'subject_id', 'source_token'], 'restriction_decision_source_unique');
            $table->index(['subject_type', 'subject_id', 'id']);
        });
        Schema::create('restriction_affected_work', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restriction_decision_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamp('recorded_at', 6);
            $table->unique(['restriction_decision_id', 'order_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('restriction_decisions')->exists()) {
            throw new LogicException('Recorded governance decisions must be retained. This migration cannot be rolled back while history exists.');
        }
        Schema::dropIfExists('restriction_affected_work');
        Schema::dropIfExists('restriction_decisions');
        Schema::dropIfExists('governance_guards');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('restriction_version'));
    }
};
