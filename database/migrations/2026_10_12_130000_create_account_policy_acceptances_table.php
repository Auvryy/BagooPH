<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_policy_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('terms_version', 32);
            $table->string('privacy_version', 32);
            $table->string('source', 16);
            $table->timestamp('accepted_at');
            $table->unique(['user_id', 'terms_version', 'privacy_version'], 'account_policy_acceptance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_policy_acceptances');
    }
};
