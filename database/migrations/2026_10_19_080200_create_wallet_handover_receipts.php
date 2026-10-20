<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_handover_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_checkpoint_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('request_token_hash', 64)->unique();
            $table->string('fingerprint', 64);
            $table->string('proof_hash', 64);
            $table->string('recipient_name', 255);
            $table->string('recipient_relationship', 30);
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER wallet_handover_retain_'.strtolower($operation)." BEFORE {$operation} ON wallet_handover_receipts BEGIN SELECT RAISE(ABORT, 'Wallet handover evidence is immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION wallet_handover_retain() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Wallet handover evidence is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER wallet_handover_retain BEFORE UPDATE OR DELETE ON wallet_handover_receipts FOR EACH ROW EXECUTE FUNCTION wallet_handover_retain();");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_handover_receipts');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS wallet_handover_retain()');
        }
    }
};
