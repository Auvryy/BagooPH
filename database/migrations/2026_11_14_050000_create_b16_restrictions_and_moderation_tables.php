<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enforcement_sanctions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('subject_type', 32); // buyer, seller, shop, company, courier
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 48); // warning, cod_restricted, review_restricted, catalog_visibility_restricted, payout_hold, pickup_zone_restricted
            $table->string('status', 32)->default('active'); // active, resolved, overturned, expired
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->text('evidence_summary')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->unsignedBigInteger('source_financial_penalty_id')->nullable();
            $table->unsignedBigInteger('source_signal_id')->nullable();
            $table->boolean('auto_release_on_debt_clear')->default(false);
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_by_role', 32)->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status']);
            $table->index(['subject_user_id', 'status']);
            $table->index(['scope', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['source_financial_penalty_id', 'status']);
        });

        Schema::create('enforcement_appeals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('enforcement_sanction_id')->constrained('enforcement_sanctions')->cascadeOnDelete();
            $table->foreignId('appellant_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->text('evidence_notes')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('status', 32)->default('pending'); // pending, under_review, overturned, upheld
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['enforcement_sanction_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('financial_penalties', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('subject_type', 32); // seller, company, courier, buyer
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 48); // sla_pickup_breach, scale_weight_overage, off_platform_bypass, policy_breach, recovery_debt
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('settled_cents')->default(0);
            $table->unsignedBigInteger('outstanding_cents')->default(0);
            $table->string('status', 32)->default('pending_review'); // pending_review, approved_charged, unpaid_debt_hold, waived, rejected
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->text('evidence_summary');
            $table->json('evidence_refs')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('sla_clock_id')->nullable()->constrained('sla_clocks')->nullOnDelete();
            $table->unsignedBigInteger('scale_discrepancy_log_id')->nullable();
            $table->foreignId('proposed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('waived_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->text('decision_notes')->nullable();
            $table->timestamp('proposed_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status']);
            $table->index(['subject_user_id', 'status']);
            $table->index(['status', 'category']);
        });

        Schema::create('moderation_signals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('signal_type', 64); // off_platform_bypass_keyword, anti_scam_stalled_order, review_velocity_burst, review_courier_keyword, scale_discrepancy, cod_refusal_pattern
            $table->string('severity', 24)->default('advisory'); // advisory, review_required, high_priority
            $table->string('status', 32)->default('open'); // open, checked_in, confirmed_violation, dismissed_benign
            $table->boolean('is_punitive')->default(false);
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('review_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->json('matched_terms')->nullable();
            $table->json('context_payload')->nullable();
            $table->text('summary');
            $table->string('buyer_checkin_response', 48)->nullable();
            $table->text('buyer_checkin_notes')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['signal_type', 'status']);
            $table->index(['subject_user_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('scale_discrepancy_logs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('declared_weight_grams');
            $table->unsignedInteger('measured_weight_grams');
            $table->integer('overage_grams');
            $table->unsignedInteger('declared_shipping_cents')->default(0);
            $table->unsignedInteger('recalculated_shipping_cents')->default(0);
            $table->integer('tariff_delta_cents')->default(0);
            $table->string('status', 32)->default('logged'); // logged, flagged_for_penalty, penalized, cleared
            $table->unsignedBigInteger('financial_penalty_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index(['delivery_id']);
        });

        Schema::create('delivery_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('logistics_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('timeliness_rating')->nullable();
            $table->unsignedTinyInteger('handling_rating')->nullable();
            $table->unsignedTinyInteger('communication_rating')->nullable();
            $table->text('comment')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['logistics_company_id', 'rating']);
            $table->index(['rider_id', 'rating']);
            $table->index(['order_id', 'buyer_id']);
        });

        Schema::create('review_disputes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason_code', 64); // courier_delivery_complaint, abusive_or_troll, unverified_or_false_claim, competitor_sabotage
            $table->text('seller_evidence');
            $table->string('status', 40)->default('pending'); // pending, upheld_excluded_courier, upheld_removed, rejected, buyer_appealed, appeal_overturned, appeal_upheld
            $table->foreignId('adjudicated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('adjudication_notes')->nullable();
            $table->timestamp('adjudicated_at')->nullable();
            $table->text('buyer_appeal_reason')->nullable();
            $table->timestamp('buyer_appealed_at')->nullable();
            $table->foreignId('appeal_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('appeal_notes')->nullable();
            $table->timestamp('appeal_reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['review_id', 'status']);
            $table->index(['seller_id', 'status']);
        });

        if (! Schema::hasColumn('reviews', 'moderation_status')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->string('moderation_status', 32)->default('published');
                $table->text('moderation_reason')->nullable();
                $table->json('detected_flags')->nullable();
                $table->unsignedBigInteger('moderated_by_id')->nullable();
                $table->timestamp('moderated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reviews', 'moderation_status')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->dropColumn(['moderation_status', 'moderation_reason', 'detected_flags', 'moderated_by_id', 'moderated_at']);
            });
        }

        Schema::dropIfExists('review_disputes');
        Schema::dropIfExists('delivery_feedbacks');
        Schema::dropIfExists('scale_discrepancy_logs');
        Schema::dropIfExists('moderation_signals');
        Schema::dropIfExists('financial_penalties');
        Schema::dropIfExists('enforcement_appeals');
        Schema::dropIfExists('enforcement_sanctions');
    }
};
