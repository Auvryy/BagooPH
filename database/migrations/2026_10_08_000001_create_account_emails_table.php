<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->selectRaw('LOWER(TRIM(email))')->groupByRaw('LOWER(TRIM(email))')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Existing accounts have overlapping email addresses. Resolve their ownership before applying this migration.');
        }
        Schema::create('account_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email')->unique();
            $table->boolean('is_original')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX account_emails_original_owner_unique ON account_emails (user_id) WHERE is_original = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1'));
        DB::statement('INSERT INTO account_emails (user_id, email, is_original, verified_at, created_at, updated_at) SELECT id, LOWER(TRIM(email)), '.(DB::getDriverName() === 'pgsql' ? 'true' : '1').', email_verified_at, created_at, updated_at FROM users');
        if (DB::getDriverName() === 'sqlite') {
            // A table rebuild would lose SQLite expression indexes on the retained users table.
            DB::statement('ALTER TABLE users ADD COLUMN preferred_contact_email_id INTEGER REFERENCES account_emails(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE email_otps ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE CASCADE');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('preferred_contact_email_id')->nullable()->constrained('account_emails')->nullOnDelete();
            });
            Schema::table('email_otps', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER account_email_register AFTER INSERT ON users BEGIN
                    INSERT INTO account_emails (user_id, email, is_original, verified_at, created_at, updated_at)
                    VALUES (NEW.id, LOWER(TRIM(NEW.email)), 1, NEW.email_verified_at, NEW.created_at, NEW.updated_at);
                END;
                CREATE TRIGGER account_email_verify AFTER UPDATE OF email_verified_at ON users BEGIN
                    UPDATE account_emails SET verified_at = NEW.email_verified_at, updated_at = NEW.updated_at WHERE user_id = NEW.id AND is_original = 1;
                END;
                CREATE TRIGGER account_email_keep_original BEFORE UPDATE OF email ON users WHEN NEW.email != OLD.email BEGIN
                    SELECT RAISE(ABORT, 'Original sign-in email cannot be replaced.');
                END;
                CREATE TRIGGER account_email_keep_ownership BEFORE UPDATE OF user_id, email, is_original ON account_emails
                WHEN NEW.user_id != OLD.user_id OR NEW.email != OLD.email OR NEW.is_original != OLD.is_original BEGIN
                    SELECT RAISE(ABORT, 'Email ownership cannot be replaced.');
                END;
                CREATE TRIGGER account_email_keep_registration BEFORE DELETE ON account_emails
                WHEN OLD.is_original = 1 AND EXISTS (SELECT 1 FROM users WHERE id = OLD.user_id) BEGIN
                    SELECT RAISE(ABORT, 'Original sign-in email cannot be removed.');
                END;
                CREATE TRIGGER account_email_normalize BEFORE INSERT ON account_emails WHEN NEW.email != LOWER(TRIM(NEW.email)) BEGIN
                    SELECT RAISE(ABORT, 'Use a normalized email address.');
                END;
                SQL);
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION bagoo_account_email_sync() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        INSERT INTO account_emails (user_id, email, is_original, verified_at, created_at, updated_at)
                        VALUES (NEW.id, LOWER(TRIM(NEW.email)), true, NEW.email_verified_at, NEW.created_at, NEW.updated_at);
                    ELSE
                        UPDATE account_emails SET verified_at = NEW.email_verified_at, updated_at = NEW.updated_at WHERE user_id = NEW.id AND is_original = true;
                    END IF;
                    RETURN NEW;
                END $$;
                CREATE TRIGGER account_email_register AFTER INSERT ON users FOR EACH ROW EXECUTE FUNCTION bagoo_account_email_sync();
                CREATE TRIGGER account_email_verify AFTER UPDATE OF email_verified_at ON users FOR EACH ROW EXECUTE FUNCTION bagoo_account_email_sync();
                CREATE FUNCTION bagoo_account_email_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_TABLE_NAME = 'users' AND NEW.email IS DISTINCT FROM OLD.email THEN
                        RAISE EXCEPTION 'Original sign-in email cannot be replaced.' USING ERRCODE = '23514';
                    ELSIF TG_TABLE_NAME = 'account_emails' THEN
                        IF TG_OP = 'INSERT' THEN
                            IF NEW.email IS DISTINCT FROM LOWER(TRIM(NEW.email)) THEN
                                RAISE EXCEPTION 'Use a normalized email address.' USING ERRCODE = '23514';
                            END IF;
                        ELSIF TG_OP = 'DELETE' THEN
                            IF OLD.is_original AND EXISTS (SELECT 1 FROM users WHERE id = OLD.user_id) THEN
                                RAISE EXCEPTION 'Original sign-in email cannot be removed.' USING ERRCODE = '23514';
                            END IF;
                            RETURN OLD;
                        ELSIF NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.email IS DISTINCT FROM OLD.email OR NEW.is_original IS DISTINCT FROM OLD.is_original THEN
                            RAISE EXCEPTION 'Email ownership cannot be replaced.' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    RETURN NEW;
                END $$;
                CREATE TRIGGER account_email_keep_original BEFORE UPDATE OF email ON users FOR EACH ROW EXECUTE FUNCTION bagoo_account_email_guard();
                CREATE TRIGGER account_email_keep_ownership BEFORE UPDATE OF user_id, email, is_original ON account_emails FOR EACH ROW EXECUTE FUNCTION bagoo_account_email_guard();
                CREATE TRIGGER account_email_keep_registration BEFORE DELETE ON account_emails FOR EACH ROW EXECUTE FUNCTION bagoo_account_email_guard();
                CREATE TRIGGER account_email_normalize BEFORE INSERT ON account_emails FOR EACH ROW EXECUTE FUNCTION bagoo_account_email_guard();
                SQL);
        } else {
            throw new RuntimeException('Account email ownership requires PostgreSQL or the isolated SQLite test database.');
        }
    }

    public function down(): void
    {
        foreach (['account_email_register', 'account_email_verify', 'account_email_keep_original'] as $trigger) {
            DB::statement('DROP TRIGGER IF EXISTS '.$trigger.(DB::getDriverName() === 'pgsql' ? ' ON users' : ''));
        }
        foreach (['account_email_keep_ownership', 'account_email_keep_registration', 'account_email_normalize'] as $trigger) {
            DB::statement('DROP TRIGGER IF EXISTS '.$trigger.(DB::getDriverName() === 'pgsql' ? ' ON account_emails' : ''));
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS bagoo_account_email_sync()');
            DB::statement('DROP FUNCTION IF EXISTS bagoo_account_email_guard()');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE email_otps DROP COLUMN user_id');
            DB::statement('ALTER TABLE users DROP COLUMN preferred_contact_email_id');
        } else {
            Schema::table('email_otps', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
            Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('preferred_contact_email_id'));
        }
        Schema::dropIfExists('account_emails');
    }
};
