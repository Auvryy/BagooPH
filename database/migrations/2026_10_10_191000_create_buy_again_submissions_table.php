<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buy_again_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('cart_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('request_hash', 64);
            $table->json('result');
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER buy_again_no_'.strtolower($operation)." BEFORE {$operation} ON buy_again_submissions BEGIN SELECT RAISE(ABORT, 'Buy again results are immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER buy_again_immutable BEFORE UPDATE OR DELETE ON buy_again_submissions FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::table('buy_again_submissions')->exists()) {
            throw new LogicException('Recorded Buy again results must be retained.');
        }
        Schema::dropIfExists('buy_again_submissions');
    }
};
