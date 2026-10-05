<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('kyc_decision_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('root_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('reviewer_role');
            $table->string('reviewer_name');
            $table->string('submission_token', 64);
            $table->string('decision');
            $table->text('reason')->nullable();
            $table->json('submission');
            $table->json('before_state');
            $table->json('after_state');
            $table->timestampTz('reviewed_at', 6);
            $table->unique(['shop_id', 'submission_token']);
            $table->index(['shop_id', 'reviewed_at']);
        });

        Schema::table('shops', function (Blueprint $table) {
            // Null means missing legacy provenance, never inferred approval.
            $table->string('review_status')->nullable()->index();
            $table->unsignedBigInteger('review_version')->default(0);
            $table->timestampTz('review_submitted_at', 6)->nullable();
            $table->timestampTz('reviewed_at', 6)->nullable();
            $table->text('review_feedback')->nullable();
            $table->string('business_permit_path')->nullable();
            $table->foreignId('review_decision_id')->nullable()->constrained('shop_review_decisions')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'shop_reviews_no_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON shop_review_decisions BEGIN SELECT RAISE(ABORT, 'Shop review decisions are immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_shop_review_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Shop review decisions are immutable.' USING ERRCODE = '23000';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER shop_reviews_immutable
                    BEFORE UPDATE OR DELETE ON shop_review_decisions
                    FOR EACH ROW EXECUTE FUNCTION prevent_shop_review_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_decision_id');
            $table->dropColumn(['review_status', 'review_version', 'review_submitted_at', 'reviewed_at', 'review_feedback', 'business_permit_path']);
        });
        Schema::dropIfExists('shop_review_decisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_shop_review_mutation()');
        }
    }
};
