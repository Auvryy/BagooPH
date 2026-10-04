<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('reviewer_role');
            $table->string('reviewer_name');
            $table->string('subject_role');
            $table->string('submission_token', 64);
            $table->string('decision');
            $table->text('reason')->nullable();
            $table->json('submission');
            $table->json('before_state');
            $table->json('after_state');
            $table->timestampTz('reviewed_at', 6);
            $table->unique(['user_id', 'submission_token']);
            $table->index(['user_id', 'reviewed_at']);
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'kyc_decisions_no_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON kyc_decisions BEGIN SELECT RAISE(ABORT, 'KYC decisions are immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_kyc_decision_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'KYC decisions are immutable.' USING ERRCODE = '23000';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER kyc_decisions_immutable
                    BEFORE UPDATE OR DELETE ON kyc_decisions
                    FOR EACH ROW EXECUTE FUNCTION prevent_kyc_decision_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_decisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_kyc_decision_mutation()');
        }
    }
};
