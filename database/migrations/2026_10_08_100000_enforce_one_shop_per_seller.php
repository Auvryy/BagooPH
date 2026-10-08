<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('shops')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('One shop per seller requires reviewing existing duplicate ownership before deployment. No shops or orders were removed.');
        }

        Schema::table('shops', fn (Blueprint $table) => $table->unique('user_id', 'shops_one_per_seller_unique'));
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropUnique('shops_one_per_seller_unique'));
    }
};
