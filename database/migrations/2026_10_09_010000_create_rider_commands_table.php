<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_commands', function (Blueprint $table) {
            $table->id();
            // PostgreSQL checks the actor after domain user locks, avoiding an earlier FK key-share lock inversion.
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->deferrable()->initiallyImmediate(false);
            $table->uuid('idempotency_key');
            $table->string('action', 60);
            $table->string('resource', 120);
            $table->char('fingerprint', 64);
            $table->json('result');
            $table->timestampTz('recorded_at');
            $table->timestampTz('expires_at');
            $table->unique(['actor_id', 'idempotency_key']);
            $table->index(['actor_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('rider_commands') && DB::table('rider_commands')->exists()) {
            throw new RuntimeException('Retained rider command results must not be discarded by rollback.');
        }
        Schema::dropIfExists('rider_commands');
    }
};
