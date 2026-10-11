<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EVIDENCE = ['voucher_revisions', 'voucher_redemptions', 'voucher_order_allocations', 'voucher_funding_entries'];

    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('issuer_type', 20)->default('legacy_unknown');
            $table->timestamp('archived_at')->nullable();
        });
        // A missing shop link cannot establish who agreed to pay an older discount.
        DB::table('vouchers')->update(['is_active' => false, 'archived_at' => now()]);

        Schema::create('voucher_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('issuer_type', 20);
            $table->foreignId('shop_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('funding_party', 20);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('discount_type', 20);
            foreach (['value_cents', 'minimum_cents', 'maximum_cents', 'budget_cents'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->unsignedInteger('percentage_bps')->nullable();
            $table->unsignedInteger('global_limit');
            $table->unsignedInteger('per_buyer_limit');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->unique(['voucher_id', 'revision_number']);
        });
        // Adding an SQLite foreign key rebuilds this referenced table and can clear
        // legacy orders' null-on-delete voucher links. Keep it additive there;
        // the pointer trigger and immutable revisions enforce the same retention.
        Schema::table('vouchers', fn (Blueprint $table) => $table->unsignedBigInteger('current_revision_id')->nullable()->index());
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('vouchers', fn (Blueprint $table) => $table->foreign('current_revision_id')->references('id')->on('voucher_revisions')->restrictOnDelete());
        }
        Schema::create('voucher_revision_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_revision_id')->unique()->constrained()->restrictOnDelete();
            foreach (['redemption_count', 'committed_cents', 'spent_cents', 'released_cents'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->timestamps();
        });
        Schema::create('saved_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('voucher_revision_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['buyer_id', 'voucher_revision_id']);
        });
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('voucher_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_submission_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('discount_cents');
            $table->json('terms');
            $table->timestamps();
            $table->unique(['voucher_revision_id', 'checkout_submission_id'], 'voucher_redemption_submission_unique');
            $table->index(['voucher_revision_id', 'buyer_id']);
        });
        Schema::create('voucher_order_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_redemption_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            foreach (['product_discount_cents', 'shipping_discount_cents', 'seller_expense_cents', 'platform_expense_cents'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->timestamps();
            $table->unique(['voucher_redemption_id', 'order_id']);
        });
        Schema::create('voucher_funding_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('voucher_order_allocation_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('source_entry_id')->nullable()->constrained('voucher_funding_entries')->restrictOnDelete();
            $table->foreignId('settlement_event_id')->nullable()->constrained('seller_settlement_events')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->timestamps();
            $table->unique(['voucher_order_allocation_id', 'event_type'], 'voucher_allocation_funding_type_unique');
            $table->unique('source_entry_id');
        });
        Schema::table('orders', fn (Blueprint $table) => $table->unsignedInteger('voucher_accounting_version')->nullable());
        Schema::table('checkout_submissions', fn (Blueprint $table) => $table->unsignedInteger('fingerprint_version')->default(1));
        Schema::table('seller_settlements', function (Blueprint $table) {
            foreach (['seller_promotion_cents', 'platform_promotion_cents', 'net_seller_cents'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->bigInteger('net_commission_cents')->nullable();
            $table->json('promotion_snapshot')->nullable();
        });

        foreach (self::EVIDENCE as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Voucher evidence is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()");
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER voucher_issuer_retained BEFORE UPDATE OF issuer_type, shop_id ON vouchers
                WHEN NEW.issuer_type IS NOT OLD.issuer_type OR NEW.shop_id IS NOT OLD.shop_id
                BEGIN SELECT RAISE(ABORT, 'Voucher issuer and scope are immutable.'); END;
                CREATE TRIGGER voucher_revision_pointer BEFORE UPDATE OF current_revision_id ON vouchers
                WHEN (OLD.current_revision_id IS NOT NULL AND NEW.current_revision_id IS NULL)
                    OR (NEW.current_revision_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM voucher_revisions r WHERE r.id = NEW.current_revision_id
                        AND r.voucher_id = NEW.id AND r.issuer_type = NEW.issuer_type AND r.shop_id IS NEW.shop_id
                    ))
                BEGIN SELECT RAISE(ABORT, 'Voucher revision must retain its original issuer.'); END;
                SQL);
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION retain_voucher_issuer() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.issuer_type IS DISTINCT FROM OLD.issuer_type OR NEW.shop_id IS DISTINCT FROM OLD.shop_id
                    THEN RAISE EXCEPTION 'Voucher issuer and scope are immutable.' USING ERRCODE = '23000'; END IF;
                    IF (OLD.current_revision_id IS NOT NULL AND NEW.current_revision_id IS NULL)
                        OR (NEW.current_revision_id IS NOT NULL AND NOT EXISTS (
                            SELECT 1 FROM voucher_revisions r WHERE r.id = NEW.current_revision_id
                            AND r.voucher_id = NEW.id AND r.issuer_type = NEW.issuer_type AND r.shop_id IS NOT DISTINCT FROM NEW.shop_id
                        ))
                    THEN RAISE EXCEPTION 'Voucher revision must retain its original issuer.' USING ERRCODE = '23000'; END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER voucher_issuer_retained BEFORE UPDATE OF issuer_type, shop_id, current_revision_id ON vouchers
                FOR EACH ROW EXECUTE FUNCTION retain_voucher_issuer();
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::EVIDENCE as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Recorded voucher terms and funding must be retained.');
            }
        }
        Schema::table('seller_settlements', fn (Blueprint $table) => $table->dropColumn(['seller_promotion_cents', 'platform_promotion_cents', 'net_seller_cents', 'net_commission_cents', 'promotion_snapshot']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('voucher_accounting_version'));
        Schema::table('checkout_submissions', fn (Blueprint $table) => $table->dropColumn('fingerprint_version'));
        Schema::dropIfExists('voucher_funding_entries');
        Schema::dropIfExists('voucher_order_allocations');
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('saved_vouchers');
        Schema::dropIfExists('voucher_revision_balances');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS voucher_issuer_retained ON vouchers; DROP FUNCTION IF EXISTS retain_voucher_issuer();');
            Schema::table('vouchers', fn (Blueprint $table) => $table->dropForeign(['current_revision_id']));
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS voucher_issuer_retained; DROP TRIGGER IF EXISTS voucher_revision_pointer;');
        }
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['current_revision_id']);
            $table->dropColumn('current_revision_id');
        });
        Schema::dropIfExists('voucher_revisions');
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn(['issuer_type', 'archived_at']));
    }
};
