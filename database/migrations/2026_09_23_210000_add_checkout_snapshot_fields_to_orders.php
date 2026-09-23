<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('buyer_id')->constrained('vouchers')->nullOnDelete();
            $table->decimal('voucher_discount', 12, 2)->default(0)->after('subtotal');
            $table->string('shipping_province')->nullable()->after('shipping_city');
            $table->decimal('destination_latitude', 10, 7)->nullable()->after('destination_barangay');
            $table->decimal('destination_longitude', 10, 7)->nullable()->after('destination_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn([
                'voucher_discount',
                'shipping_province',
                'destination_latitude',
                'destination_longitude',
            ]);
        });
    }
};
