<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 50)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('attachment_hash', 64)->nullable();
            $table->uuid('request_key')->nullable();
            $table->unique(['sender_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['sender_id', 'request_key']);
            $table->dropColumn(['attachment_path', 'attachment_mime', 'attachment_size', 'attachment_hash', 'request_key']);
        });
    }
};
