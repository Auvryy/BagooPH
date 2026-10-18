<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->boolean('vacation_mode')->default(false));
        Schema::table('products', fn (Blueprint $table) => $table->unsignedInteger('purchase_limit')->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('purchase_limit'));
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('vacation_mode'));
    }
};
