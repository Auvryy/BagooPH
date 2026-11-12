<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_return_repayment_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('buyer_return_case_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('buyer_return_adjustment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approval_event_id')->unique()->constrained('buyer_return_money_events')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('policy_version');
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER buyer_return_repayment_plans_retain_'.strtolower($operation)." BEFORE {$operation} ON buyer_return_repayment_plans
                    BEGIN SELECT RAISE(ABORT, 'Retain the original repayment plan and its funding basis.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_buyer_return_repayment_plan() RETURNS trigger AS \$\$ BEGIN
                RAISE EXCEPTION 'Retain the original repayment plan and its funding basis.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER buyer_return_repayment_plans_retained BEFORE UPDATE OR DELETE ON buyer_return_repayment_plans FOR EACH ROW EXECUTE FUNCTION retain_buyer_return_repayment_plan();");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain approved repayment plans and the original funding basis.');
    }
};
