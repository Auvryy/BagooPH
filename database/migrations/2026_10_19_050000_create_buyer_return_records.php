<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_return_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_checkpoint_id')->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->string('status', 40)->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('reason', 40);
            $table->text('description');
            $table->json('route_snapshot');
            $table->timestamp('delivered_at');
            $table->timestamp('request_until');
            $table->timestamp('response_until');
            $table->timestamp('appeal_until')->nullable();
            $table->timestamp('dropoff_until')->nullable();
            $table->char('creation_token_hash', 64)->unique();
            $table->char('creation_fingerprint', 64);
            $table->timestamps();
            $table->index(['buyer_id', 'created_at']);
            $table->index(['seller_id', 'status']);
        });
        Schema::create('buyer_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_return_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->json('unit_positions');
            $table->json('purchase_snapshot');
            $table->unique(['buyer_return_case_id', 'order_item_id']);
        });
        Schema::create('buyer_return_unit_claims', function (Blueprint $table) {
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('unit_position');
            $table->foreignId('buyer_return_case_id')->constrained()->restrictOnDelete();
            $table->primary(['order_item_id', 'unit_position']);
        });
        Schema::create('buyer_return_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_return_case_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->char('content_hash', 64);
            $table->string('mime', 40);
            $table->unsignedInteger('bytes');
            $table->timestamp('created_at');
        });
        Schema::create('buyer_return_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('buyer_return_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->unsignedInteger('sequence');
            $table->string('event_type', 40);
            $table->string('source_status', 40)->nullable();
            $table->string('target_status', 40);
            $table->text('reason');
            $table->char('request_token_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->json('context');
            $table->timestamp('created_at');
            $table->unique(['buyer_return_case_id', 'sequence']);
        });
    }

    public function down(): void
    {
        foreach (['buyer_return_events', 'buyer_return_evidence', 'buyer_return_unit_claims', 'buyer_return_items', 'buyer_return_cases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
