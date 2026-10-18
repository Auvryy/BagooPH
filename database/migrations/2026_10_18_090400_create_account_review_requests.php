<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_review_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('source_token', 64);
            $table->jsonb('source_snapshot');
            $table->text('message');
            $table->enum('status', ['pending', 'accepted', 'declined', 'superseded'])->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX account_review_one_pending ON account_review_requests (user_id, subject_type, subject_id) WHERE status = 'pending'");
        Schema::create('account_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_request_id')->unique()->constrained('account_review_requests')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', ['reactivate', 'reopen_application', 'decline', 'close_stale']);
            $table->text('reason');
            $table->jsonb('before_state');
            $table->jsonb('after_state');
            $table->foreignId('restriction_decision_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('reviewed_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER account_review_request_identity BEFORE UPDATE ON account_review_requests
                WHEN NEW.user_id IS NOT OLD.user_id OR NEW.subject_type IS NOT OLD.subject_type
                OR NEW.subject_id IS NOT OLD.subject_id OR NEW.source_token IS NOT OLD.source_token
                OR NEW.source_snapshot IS NOT OLD.source_snapshot OR NEW.message IS NOT OLD.message
                OR NEW.created_at IS NOT OLD.created_at BEGIN SELECT RAISE(ABORT, 'Original review requests must be retained.'); END;
                CREATE TRIGGER account_review_request_retained BEFORE DELETE ON account_review_requests
                BEGIN SELECT RAISE(ABORT, 'Review requests must be retained.'); END;
                CREATE TRIGGER account_review_decision_no_update BEFORE UPDATE ON account_review_decisions
                BEGIN SELECT RAISE(ABORT, 'Review decisions must be retained.'); END;
                CREATE TRIGGER account_review_decision_no_delete BEFORE DELETE ON account_review_decisions
                BEGIN SELECT RAISE(ABORT, 'Review decisions must be retained.'); END;
                SQL);
            foreach (['shop' => 'shops', 'company' => 'logistics_companies'] as $type => $table) {
                DB::unprepared("CREATE TRIGGER account_review_{$type}_retained BEFORE DELETE ON {$table}
                    WHEN EXISTS (SELECT 1 FROM account_review_requests WHERE subject_type = '{$type}' AND subject_id = OLD.id)
                    BEGIN SELECT RAISE(ABORT, 'The reviewed resource must be retained.'); END");
            }
        } else {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION bagoo_account_review_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_TABLE_NAME = 'account_review_requests' AND TG_OP = 'UPDATE' THEN
                        IF NEW.user_id IS NOT DISTINCT FROM OLD.user_id AND NEW.subject_type IS NOT DISTINCT FROM OLD.subject_type
                            AND NEW.subject_id IS NOT DISTINCT FROM OLD.subject_id AND NEW.source_token IS NOT DISTINCT FROM OLD.source_token
                            AND NEW.source_snapshot IS NOT DISTINCT FROM OLD.source_snapshot AND NEW.message IS NOT DISTINCT FROM OLD.message
                            AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN RETURN NEW; END IF;
                    END IF;
                    RAISE EXCEPTION 'Original review requests and decisions must be retained.' USING ERRCODE = '23514';
                END $$;
                CREATE TRIGGER account_review_request_retained BEFORE UPDATE OR DELETE ON account_review_requests FOR EACH ROW EXECUTE FUNCTION bagoo_account_review_guard();
                CREATE TRIGGER account_review_decision_retained BEFORE UPDATE OR DELETE ON account_review_decisions FOR EACH ROW EXECUTE FUNCTION bagoo_account_review_guard();
                CREATE FUNCTION bagoo_account_review_resource_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM account_review_requests WHERE subject_type = TG_ARGV[0] AND subject_id = OLD.id) THEN
                        RAISE EXCEPTION 'The reviewed resource must be retained.' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END $$;
                CREATE TRIGGER account_review_shop_retained BEFORE DELETE ON shops FOR EACH ROW EXECUTE FUNCTION bagoo_account_review_resource_guard('shop');
                CREATE TRIGGER account_review_company_retained BEFORE DELETE ON logistics_companies FOR EACH ROW EXECUTE FUNCTION bagoo_account_review_resource_guard('company');
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['shop' => 'shops', 'company' => 'logistics_companies'] as $type => $table) {
            DB::statement('DROP TRIGGER IF EXISTS account_review_'.$type.'_retained'.(DB::getDriverName() === 'pgsql' ? ' ON '.$table : ''));
        }
        Schema::dropIfExists('account_review_decisions');
        Schema::dropIfExists('account_review_requests');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS bagoo_account_review_guard()');
            DB::statement('DROP FUNCTION IF EXISTS bagoo_account_review_resource_guard()');
        }
    }
};
