<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cart_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('request_hash', 64);
            $table->unsignedInteger('order_count');
            $table->timestamp('created_at');
        });
        Schema::create('checkout_submission_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->index(['checkout_submission_id', 'order_id']);
        });

        foreach (['checkout_submissions', 'checkout_submission_orders'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Checkout results are immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()");
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER checkout_order_scope BEFORE INSERT ON checkout_submission_orders
                WHEN NOT EXISTS (
                    SELECT 1 FROM checkout_submissions s JOIN orders o ON o.buyer_id = s.buyer_id
                    WHERE s.id = NEW.checkout_submission_id AND o.id = NEW.order_id
                ) OR (SELECT COUNT(*) FROM checkout_submission_orders WHERE checkout_submission_id = NEW.checkout_submission_id)
                    >= (SELECT order_count FROM checkout_submissions WHERE id = NEW.checkout_submission_id)
                BEGIN SELECT RAISE(ABORT, 'Original checkout orders must be retained in buyer scope.'); END;
                CREATE TRIGGER checkout_buyer_retained BEFORE UPDATE OF buyer_id ON orders
                WHEN NEW.buyer_id != OLD.buyer_id AND EXISTS (SELECT 1 FROM checkout_submission_orders WHERE order_id = OLD.id)
                BEGIN SELECT RAISE(ABORT, 'Original checkout ownership must be retained.'); END;
                SQL);
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION enforce_checkout_order_scope() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM checkout_submissions s JOIN orders o ON o.buyer_id = s.buyer_id
                        WHERE s.id = NEW.checkout_submission_id AND o.id = NEW.order_id
                    ) OR (SELECT COUNT(*) FROM checkout_submission_orders WHERE checkout_submission_id = NEW.checkout_submission_id)
                        >= (SELECT order_count FROM checkout_submissions WHERE id = NEW.checkout_submission_id)
                    THEN RAISE EXCEPTION 'Original checkout orders must be retained in buyer scope.' USING ERRCODE = '23000'; END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER checkout_order_scope BEFORE INSERT ON checkout_submission_orders
                FOR EACH ROW EXECUTE FUNCTION enforce_checkout_order_scope();
                CREATE FUNCTION retain_checkout_buyer() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.buyer_id != OLD.buyer_id AND EXISTS (SELECT 1 FROM checkout_submission_orders WHERE order_id = OLD.id)
                    THEN RAISE EXCEPTION 'Original checkout ownership must be retained.' USING ERRCODE = '23000'; END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER checkout_buyer_retained BEFORE UPDATE OF buyer_id ON orders
                FOR EACH ROW EXECUTE FUNCTION retain_checkout_buyer();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('checkout_submissions')->exists() || DB::table('checkout_submission_orders')->exists()) {
            throw new LogicException('Recorded checkout results must be retained.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS checkout_buyer_retained ON orders; DROP FUNCTION IF EXISTS retain_checkout_buyer()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS checkout_buyer_retained');
        }
        Schema::dropIfExists('checkout_submission_orders');
        Schema::dropIfExists('checkout_submissions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_checkout_order_scope()');
        }
    }
};
