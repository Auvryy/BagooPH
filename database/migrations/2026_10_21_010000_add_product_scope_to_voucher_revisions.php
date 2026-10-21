<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_revisions', function (Blueprint $table) {
            $table->string('product_scope', 16)->default('all');
            $table->json('product_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_revisions', function (Blueprint $table) {
            $table->dropColumn(['product_scope', 'product_ids']);
        });
    }
};
