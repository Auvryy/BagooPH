<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Local accounts already have a sign-in password; OAuth-only accounts establish theirs after email verification.
            $table->boolean('password_login_enabled')->default(true);
        });

        // Older OAuth registrations were assigned a random placeholder password with no separate state marker.
        DB::table('users')->whereNotNull('google_id')->update(['password_login_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_login_enabled');
        });
    }
};
