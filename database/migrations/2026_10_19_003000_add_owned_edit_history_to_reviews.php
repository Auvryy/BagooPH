<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->string('edit_fingerprint', 64)->nullable();
            $table->json('original_content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', fn (Blueprint $table) => $table->dropColumn(['edited_at', 'edit_fingerprint', 'original_content']));
    }
};
