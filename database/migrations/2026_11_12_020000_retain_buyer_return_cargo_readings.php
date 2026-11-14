<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_return_cargo_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_return_parcel_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_return_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('route_position');
            $table->json('profile');
            $table->timestamp('created_at');
            $table->index(['buyer_return_parcel_id', 'route_position'], 'buyer_return_cargo_leg_index');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER buyer_return_cargo_no_'.strtolower($operation)." BEFORE {$operation} ON buyer_return_cargo_readings BEGIN SELECT RAISE(ABORT, 'Retain actual return handling evidence.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION retain_buyer_return_cargo() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Retain actual return handling evidence.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER buyer_return_cargo_retained BEFORE UPDATE OR DELETE ON buyer_return_cargo_readings FOR EACH ROW EXECUTE FUNCTION retain_buyer_return_cargo();");
        }
    }

    public function down(): void
    {
        if (DB::table('buyer_return_cargo_readings')->exists()) {
            throw new LogicException('Retain actual return handling evidence.');
        }
        Schema::dropIfExists('buyer_return_cargo_readings');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS retain_buyer_return_cargo();');
        }
    }
};
