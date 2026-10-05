<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_placement_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->timestamp('recorded_at', 6);
            $table->index(['logistics_company_id', 'user_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'logistics_placements_no_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON logistics_placement_records BEGIN SELECT RAISE(ABORT, 'Logistics placement records are immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_logistics_placement_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Logistics placement records are immutable.' USING ERRCODE = '23000';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER logistics_placements_immutable
                    BEFORE UPDATE OR DELETE ON logistics_placement_records
                    FOR EACH ROW EXECUTE FUNCTION prevent_logistics_placement_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_placement_records');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_logistics_placement_mutation()');
        }
    }
};
