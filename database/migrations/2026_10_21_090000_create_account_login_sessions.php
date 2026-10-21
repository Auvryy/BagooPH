<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('session_version')->default(0);
        });

        Schema::create('account_login_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('method', 24);
            $table->string('session_key_hash', 64)->nullable()->unique();
            $table->unsignedBigInteger('native_token_id')->nullable()->unique();
            $table->unsignedBigInteger('session_version');
            $table->string('credential_fingerprint', 64);
            $table->string('device_label', 140);
            $table->string('device_type', 16);
            $table->text('network_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('signed_in_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 32)->nullable();
            $table->uuid('revocation_action_id')->nullable()->index();
            $table->timestamp('metadata_cleared_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'account_sessions_active');
            $table->index(['user_id', 'signed_in_at'], 'account_sessions_history');
        });

        Schema::create('account_session_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('actor_session_id');
            $table->string('request_token_hash', 64);
            $table->string('request_fingerprint', 64);
            $table->string('action', 16);
            $table->uuid('target_session_id')->nullable();
            $table->string('confirmation_method', 16);
            $table->unsignedInteger('affected_count');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'request_token_hash'], 'account_session_action_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_session_actions');
        Schema::dropIfExists('account_login_sessions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_version'));
    }
};
