<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_status')->default('pending_approval')->index()->after('status');
            $table->string('id_document_path')->nullable()->after('kyc_status');
            $table->string('business_permit_path')->nullable()->after('id_document_path');
            $table->string('driver_license_path')->nullable()->after('business_permit_path');
            $table->string('or_cr_path')->nullable()->after('driver_license_path');
            $table->text('kyc_feedback')->nullable()->after('or_cr_path');
            $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_feedback');
            $table->timestamp('kyc_reviewed_at')->nullable()->after('kyc_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'kyc_status',
                'id_document_path',
                'business_permit_path',
                'driver_license_path',
                'or_cr_path',
                'kyc_feedback',
                'kyc_submitted_at',
                'kyc_reviewed_at',
            ]);
        });
    }
};
