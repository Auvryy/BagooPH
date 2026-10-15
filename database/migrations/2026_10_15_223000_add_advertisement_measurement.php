<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained('advertisement_publications')->restrictOnDelete();
            $table->string('event_type', 10);
            $table->string('dedupe_hash', 64);
            $table->timestampTz('recorded_at');
            $table->unique(['dedupe_hash', 'event_type']);
            $table->index(['recorded_at', 'id']);
        });
        Schema::create('advertisement_daily_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained('advertisement_publications')->restrictOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unique(['publication_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        if (DB::table('advertisement_daily_statistics')->exists()) {
            throw new LogicException('Retain recorded advertisement totals. Disable measurement instead of removing the tables.');
        }
        Schema::dropIfExists('advertisement_events');
        Schema::dropIfExists('advertisement_daily_statistics');
    }
};
