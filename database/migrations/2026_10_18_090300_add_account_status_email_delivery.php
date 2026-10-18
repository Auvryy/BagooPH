<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restriction_decisions', fn (Blueprint $table) => $table->text('owner_feedback')->nullable());
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->boolean('email_required')->default(false);
            $table->timestamp('email_delivered_at')->nullable();
            $table->unsignedInteger('email_attempts')->default(0);
            $table->timestamp('email_last_attempt_at')->nullable();
            $table->timestamp('email_available_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('restriction_decisions', fn (Blueprint $table) => $table->dropColumn('owner_feedback'));
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropColumn([
            'email_required', 'email_delivered_at', 'email_attempts', 'email_last_attempt_at', 'email_available_at',
        ]));
    }
};
