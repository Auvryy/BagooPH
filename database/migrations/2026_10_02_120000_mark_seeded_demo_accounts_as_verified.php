<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mark reserved demo accounts as verified because .test addresses cannot receive mail.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereIn('email', [
                'admin@bagoo.test',
                'buyer@bagoo.test',
                'seller@bagoo.test',
                'rider@bagoo.test',
                'pickup.rider@bagoo.test',
                'logistics@bagoo.test',
                'logistics.admin@bagoo.test',
                'losbanos.hub@bagoo.test',
                'motherhub@bagoo.test',
            ])
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Verification is intentionally not revoked during rollback.
     */
    public function down(): void
    {
        // No-op: automatically revoking verified accounts would be unsafe.
    }
};
