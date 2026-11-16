<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform sale events (for example an 11.11 Mega Sale): an admin-scheduled window with a banner, claimable
 * vouchers from Bagoo and from shops, and seller product discounts that run for the event's exact window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_events', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 80);
            $table->string('badge', 24);
            $table->string('banner_path')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('sale_event_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->restrictOnDelete();
            // Null means anyone may claim; otherwise only the first N buyers can claim and use it.
            $table->unsignedInteger('claim_limit')->nullable();
            $table->unsignedInteger('claimed_count')->default(0);
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['sale_event_id', 'voucher_id']);
        });

        Schema::create('sale_event_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_event_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            // One claim per buyer per event voucher, even when two requests arrive together.
            $table->unique(['sale_event_voucher_id', 'buyer_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE product_sales ADD COLUMN sale_event_id INTEGER REFERENCES sale_events(id) ON DELETE RESTRICT');
        } else {
            Schema::table('product_sales', fn (Blueprint $table) => $table->foreignId('sale_event_id')->nullable()->constrained('sale_events')->restrictOnDelete());
        }
        Schema::table('product_sales', fn (Blueprint $table) => $table->index('sale_event_id'));
    }

    public function down(): void
    {
        Schema::table('product_sales', fn (Blueprint $table) => $table->dropIndex(['sale_event_id']));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE product_sales DROP COLUMN sale_event_id');
        } else {
            Schema::table('product_sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_event_id'));
        }
        Schema::dropIfExists('sale_event_claims');
        Schema::dropIfExists('sale_event_vouchers');
        Schema::dropIfExists('sale_events');
    }
};
