<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', fn (Blueprint $table) => $table->text('street')->change());
    }

    public function down(): void
    {
        // Retain the wider column so valid saved addresses are never truncated.
    }
};
