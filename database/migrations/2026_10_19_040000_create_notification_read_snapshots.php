<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_read_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
        });
        Schema::create('notification_read_snapshot_items', function (Blueprint $table) {
            $table->foreignUuid('snapshot_id')->constrained('notification_read_snapshots')->cascadeOnDelete();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->primary(['snapshot_id', 'notification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_read_snapshot_items');
        Schema::dropIfExists('notification_read_snapshots');
    }
};
