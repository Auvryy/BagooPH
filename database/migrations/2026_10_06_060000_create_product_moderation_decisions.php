<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('compliance_restricted')->default(false);
            $table->unsignedBigInteger('moderation_version')->default(0);
        });
        Schema::create('product_moderation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role');
            $table->string('actor_name');
            $table->string('source_token', 64);
            $table->string('action', 20);
            $table->text('reason');
            $table->json('before_state');
            $table->json('after_state');
            $table->foreignId('prior_decision_id')->nullable()->constrained('product_moderation_decisions')->restrictOnDelete();
            $table->timestamp('decided_at', 6);
            $table->unique(['product_id', 'source_token']);
            $table->index(['product_id', 'id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER product_moderation_no_'.strtolower($operation)." BEFORE {$operation} ON product_moderation_decisions BEGIN SELECT RAISE(ABORT, 'Product moderation history is immutable.'); END");
            }
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER referenced_products_retained BEFORE DELETE ON products
                WHEN EXISTS (SELECT 1 FROM order_items WHERE product_id = OLD.id)
                  OR EXISTS (SELECT 1 FROM cart_items WHERE product_id = OLD.id)
                  OR EXISTS (SELECT 1 FROM reviews WHERE product_id = OLD.id)
                  OR EXISTS (SELECT 1 FROM product_moderation_decisions WHERE product_id = OLD.id)
                BEGIN SELECT RAISE(ABORT, 'Referenced products must be retained.'); END
                SQL);
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER product_moderation_immutable BEFORE UPDATE OR DELETE ON product_moderation_decisions FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION retain_referenced_product() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM order_items WHERE product_id = OLD.id)
                      OR EXISTS (SELECT 1 FROM cart_items WHERE product_id = OLD.id)
                      OR EXISTS (SELECT 1 FROM reviews WHERE product_id = OLD.id)
                      OR EXISTS (SELECT 1 FROM product_moderation_decisions WHERE product_id = OLD.id) THEN
                        RAISE EXCEPTION 'Referenced products must be retained.' USING ERRCODE = '23000';
                    END IF;
                    RETURN OLD;
                END;
                $$;
                CREATE TRIGGER referenced_products_retained BEFORE DELETE ON products FOR EACH ROW EXECUTE FUNCTION retain_referenced_product();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('product_moderation_decisions')->exists()) {
            throw new LogicException('Recorded product moderation must be retained.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS referenced_products_retained ON products');
            DB::unprepared('DROP FUNCTION IF EXISTS retain_referenced_product()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS referenced_products_retained');
        }
        Schema::dropIfExists('product_moderation_decisions');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['compliance_restricted', 'moderation_version']));
    }
};
