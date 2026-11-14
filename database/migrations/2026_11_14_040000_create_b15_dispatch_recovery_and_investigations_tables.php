<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_pickup_pauses', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('logistics_hub_id')->constrained('logistics_hubs')->cascadeOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->cascadeOnDelete();
            $table->string('barangay', 120)->nullable();
            $table->text('reason');
            $table->string('status', 24)->default('active'); // active, resumed
            $table->timestamp('paused_at');
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resumed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['logistics_hub_id', 'status']);
            $table->index(['logistics_company_id', 'status']);
        });

        Schema::create('dispatch_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_bayan_hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('logistics_company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('current_tier', 48)->default('open_pool');
            $table->string('status', 32)->default('monitoring'); // monitoring, escalated, resolved, auto_cancelled, manual_review
            $table->string('coverage_outcome', 48)->default('available');
            $table->boolean('is_urgent')->default(false);
            $table->string('dispatch_delay_label', 120)->nullable();
            $table->text('surge_deferred_note')->nullable();
            $table->boolean('penalty_exempt')->default(false);
            $table->timestamp('pickup_sla_started_at')->nullable();
            $table->timestamp('delayed_notice_sent_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('assigned_rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_action', 64)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_tier']);
            $table->index(['logistics_company_id', 'status']);
            $table->index(['origin_bayan_hub_id', 'status']);
        });

        Schema::create('dispatch_escalation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_escalation_id')->constrained('dispatch_escalations')->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->string('from_tier', 48)->nullable();
            $table->string('to_tier', 48)->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['dispatch_escalation_id', 'created_at']);
        });

        Schema::create('missing_parcel_investigations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_return_case_id')->nullable()->constrained('buyer_return_cases')->nullOnDelete();
            $table->foreignId('logistics_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('logistics_manifest_event_id')->nullable()->constrained('logistics_manifest_events')->nullOnDelete();
            $table->foreignId('sla_clock_id')->nullable()->constrained('sla_clocks')->nullOnDelete();
            $table->string('source_kind', 48);
            $table->string('status', 32)->default('investigating'); // investigating, recovered, confirmed_lost, closed_unfounded
            $table->boolean('proves_loss')->default(false);
            $table->boolean('liability_determined')->default(false);
            $table->string('compensation_status', 64)->default('pending_review');
            $table->string('insurer_funding_status', 64)->default('deferred_no_insurer_pool');
            $table->unsignedBigInteger('shipment_financial_plan_id')->nullable();
            $table->foreignId('investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('summary');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'logistics_company_id']);
            $table->index(['delivery_id', 'status']);
            $table->index(['source_kind', 'status']);
        });

        Schema::create('missing_parcel_investigation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('missing_parcel_investigation_id')->constrained('missing_parcel_investigations')->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['missing_parcel_investigation_id', 'created_at'], 'mpi_events_investigation_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missing_parcel_investigation_events');
        Schema::dropIfExists('missing_parcel_investigations');
        Schema::dropIfExists('dispatch_escalation_events');
        Schema::dropIfExists('dispatch_escalations');
        Schema::dropIfExists('hub_pickup_pauses');
    }
};
