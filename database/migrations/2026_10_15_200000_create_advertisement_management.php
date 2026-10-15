<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisement_schedule_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('advertisement_schedule_locks')->insert(['id' => 1]);

        Schema::create('advertisement_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $table->string('checksum', 64);
            $table->string('source_path');
            $table->string('image_path');
            $table->string('mime', 30);
            $table->unsignedInteger('source_bytes');
            $table->unsignedInteger('image_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
        });
        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('import_key', 40)->nullable()->unique();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('advertisement_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('asset_id')->constrained('advertisement_assets')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('title', 80);
            $table->string('alt_text', 180);
            $table->string('summary', 600)->nullable();
            $table->string('destination_type', 20);
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->json('product_ids');
            $table->foreignId('offer_revision_id')->nullable()->constrained('voucher_revisions')->restrictOnDelete();
            $table->string('slot', 10)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('imported_candidate')->default(false);
            $table->timestamps();
            $table->unique(['advertisement_id', 'revision_number']);
        });
        Schema::create('advertisement_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->restrictOnDelete();
            $table->foreignId('revision_id')->unique()->constrained('advertisement_revisions')->restrictOnDelete();
            $table->foreignId('published_by_id')->constrained('users')->restrictOnDelete();
            $table->string('slot', 10);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->boolean('claims_reviewed');
            $table->timestamps();
            $table->index(['slot', 'starts_at', 'ends_at']);
        });
        Schema::create('advertisement_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->uuid('request_key')->unique();
            $table->string('fingerprint', 64);
            $table->unsignedInteger('source_version');
            $table->string('action', 30);
            $table->string('reason', 600)->nullable();
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamps();
            $table->index(['advertisement_id', 'id']);
        });

        foreach (['advertisement_assets', 'advertisement_revisions', 'advertisement_actions'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Advertisement history is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('advertisements')->exists()) {
            throw new LogicException('Retain advertisement history. Disable display instead of removing its records.');
        }
        foreach (['advertisement_actions', 'advertisement_publications', 'advertisement_revisions', 'advertisements', 'advertisement_assets', 'advertisement_schedule_locks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
