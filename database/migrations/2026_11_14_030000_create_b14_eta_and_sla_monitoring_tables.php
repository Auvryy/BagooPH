<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('estimated_delivery_min_at')->nullable()->after('estimated_delivery_at');
            $table->timestamp('estimated_delivery_max_at')->nullable()->after('estimated_delivery_min_at');
            $table->json('eta_basis')->nullable()->after('estimated_delivery_max_at');
        });

        Schema::create('sla_pauses', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('scope_type', 32); // platform, province, company, hub
            $table->string('scope_value', 191)->nullable();
            $table->foreignId('logistics_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('logistics_hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->string('reason_code', 64);
            $table->string('title', 160);
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('active'); // active, ended
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->unsignedInteger('accumulated_paused_seconds')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scope_type', 'starts_at']);
            $table->index(['logistics_company_id', 'status']);
            $table->index(['logistics_hub_id', 'status']);
        });

        Schema::create('sla_clocks', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('clock_kind', 64);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('logistics_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->string('status', 24)->default('running'); // running, paused, stopped
            $table->string('pause_reason', 64)->nullable();
            $table->foreignId('active_pause_id')->nullable()->constrained('sla_pauses')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('warning_at')->nullable();
            $table->timestamp('breach_at')->nullable();
            $table->timestamp('critical_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_warned_at')->nullable();
            $table->timestamp('last_breached_at')->nullable();
            $table->timestamp('last_critical_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'clock_kind'], 'sla_clocks_subject_unique');
            $table->index(['status', 'clock_kind', 'breach_at']);
            $table->index(['status', 'warning_at']);
            $table->index(['delivery_id', 'clock_kind']);
        });

        Schema::create('sla_clock_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sla_clock_id')->constrained('sla_clocks')->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['sla_clock_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_clock_events');
        Schema::dropIfExists('sla_clocks');
        Schema::dropIfExists('sla_pauses');

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['estimated_delivery_min_at', 'estimated_delivery_max_at', 'eta_basis']);
        });
    }
};
