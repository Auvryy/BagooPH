<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $postgres = DB::getDriverName() === 'pgsql';
            if (! $postgres && DB::getDriverName() !== 'sqlite') {
                throw new RuntimeException('Account identity migration requires PostgreSQL or isolated SQLite.');
            }
            if ($postgres) {
                DB::statement('LOCK TABLE users, account_emails IN ACCESS EXCLUSIVE MODE');
            }
            Schema::table('account_emails', fn (Blueprint $table) => $table->string('role')->default('buyer'));
            Schema::table('email_otps', fn (Blueprint $table) => $table->string('role')->default('buyer'));
            DB::statement('UPDATE account_emails SET role = (SELECT role FROM users WHERE users.id = account_emails.user_id)');
            foreach (['users_email_unique', 'users_google_id_unique'] as $name) {
                DB::statement($postgres ? 'ALTER TABLE users DROP CONSTRAINT IF EXISTS '.$name : 'DROP INDEX IF EXISTS '.$name);
            }
            DB::statement($postgres ? 'ALTER TABLE account_emails DROP CONSTRAINT IF EXISTS account_emails_email_unique' : 'DROP INDEX IF EXISTS account_emails_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_case_insensitive_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_role_unique ON users (LOWER(TRIM(email)), role)');
            DB::statement('CREATE UNIQUE INDEX users_google_id_role_unique ON users (google_id, role)');
            DB::statement('CREATE UNIQUE INDEX account_emails_email_role_unique ON account_emails (email, role)');

            if ($postgres) {
                DB::unprepared(<<<'SQL'
                    CREATE OR REPLACE FUNCTION bagoo_account_email_sync() RETURNS trigger LANGUAGE plpgsql AS $$
                    BEGIN
                        IF TG_OP = 'INSERT' THEN
                            INSERT INTO account_emails (user_id, email, role, is_original, verified_at, created_at, updated_at)
                            VALUES (NEW.id, LOWER(TRIM(NEW.email)), NEW.role, true, NEW.email_verified_at, NEW.created_at, NEW.updated_at);
                        ELSE
                            UPDATE account_emails SET verified_at = NEW.email_verified_at, updated_at = NEW.updated_at WHERE user_id = NEW.id AND is_original = true;
                        END IF;
                        RETURN NEW;
                    END $$;
                    CREATE FUNCTION bagoo_account_role_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                    BEGIN
                        IF TG_TABLE_NAME = 'users' THEN
                            IF NEW.role IS DISTINCT FROM OLD.role THEN
                                RAISE EXCEPTION 'An account role cannot be changed.' USING ERRCODE = '23514';
                            END IF;
                        ELSE
                            IF NEW.role IS DISTINCT FROM (SELECT role FROM users WHERE id = NEW.user_id)
                                OR (TG_OP = 'UPDATE' AND NEW.role IS DISTINCT FROM OLD.role) THEN
                                RAISE EXCEPTION 'The email must keep its account role.' USING ERRCODE = '23514';
                            END IF;
                        END IF;
                        RETURN NEW;
                    END $$;
                    CREATE TRIGGER account_role_retained BEFORE UPDATE OF role ON users FOR EACH ROW EXECUTE FUNCTION bagoo_account_role_guard();
                    CREATE TRIGGER account_email_role_insert BEFORE INSERT ON account_emails FOR EACH ROW EXECUTE FUNCTION bagoo_account_role_guard();
                    CREATE TRIGGER account_email_role_update BEFORE UPDATE OF user_id, role ON account_emails FOR EACH ROW EXECUTE FUNCTION bagoo_account_role_guard();
                    SQL);
            } else {
                DB::unprepared(<<<'SQL'
                    DROP TRIGGER account_email_register;
                    CREATE TRIGGER account_email_register AFTER INSERT ON users BEGIN
                        INSERT INTO account_emails (user_id, email, role, is_original, verified_at, created_at, updated_at)
                        VALUES (NEW.id, LOWER(TRIM(NEW.email)), NEW.role, 1, NEW.email_verified_at, NEW.created_at, NEW.updated_at);
                    END;
                    CREATE TRIGGER account_role_retained BEFORE UPDATE OF role ON users WHEN NEW.role IS NOT OLD.role BEGIN
                        SELECT RAISE(ABORT, 'An account role cannot be changed.');
                    END;
                    CREATE TRIGGER account_email_role_insert BEFORE INSERT ON account_emails
                    WHEN NEW.role IS NOT (SELECT role FROM users WHERE id = NEW.user_id) BEGIN
                        SELECT RAISE(ABORT, 'The email must keep its account role.');
                    END;
                    CREATE TRIGGER account_email_role_update BEFORE UPDATE OF user_id, role ON account_emails
                    WHEN NEW.role IS NOT OLD.role OR NEW.role IS NOT (SELECT role FROM users WHERE id = NEW.user_id) BEGIN
                        SELECT RAISE(ABORT, 'The email must keep its account role.');
                    END;
                    SQL);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Role-scoped accounts cannot be collapsed automatically. Roll forward; global email ownership requires a separate reviewed migration.');
    }
};
