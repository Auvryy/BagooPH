<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('contact_settings_version')->default(0));
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER contact_settings_revision AFTER UPDATE OF phone ON users
                WHEN NEW.phone IS NOT OLD.phone BEGIN
                    UPDATE users SET contact_settings_version = OLD.contact_settings_version + 1 WHERE id = NEW.id;
                END;
                SQL);
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION bagoo_contact_settings_revision() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    NEW.contact_settings_version := OLD.contact_settings_version + CASE WHEN NEW.phone IS DISTINCT FROM OLD.phone THEN 1 ELSE 0 END;
                    RETURN NEW;
                END $$;
                CREATE TRIGGER contact_settings_revision BEFORE UPDATE OF phone, contact_settings_version ON users
                FOR EACH ROW EXECUTE FUNCTION bagoo_contact_settings_revision();
                SQL);
        } else {
            throw new RuntimeException('Contact revisions require PostgreSQL or isolated SQLite.');
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS contact_settings_revision'.(DB::getDriverName() === 'pgsql' ? ' ON users' : ''));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS bagoo_contact_settings_revision()');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('contact_settings_version'));
    }
};
