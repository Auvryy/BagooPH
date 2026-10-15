<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->constrained('shops')->restrictOnDelete();
        });
        Schema::create('advertisement_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->restrictOnDelete();
            $table->foreignId('revision_id')->unique()->constrained('advertisement_revisions')->restrictOnDelete();
            $table->foreignId('submitted_by_id')->constrained('users')->restrictOnDelete();
            $table->json('shop_scope');
            $table->timestamps();
        });
        Schema::create('advertisement_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('advertisement_submissions')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->string('actor_role', 20);
            $table->string('decision', 24);
            $table->string('reason', 600)->nullable();
            $table->timestamps();
            $table->index(['submission_id', 'id']);
        });
        Schema::table('advertisement_publications', function (Blueprint $table) {
            $table->dropUnique(['revision_id']);
            $table->foreignId('replaces_publication_id')->nullable()->unique()->constrained('advertisement_publications')->restrictOnDelete();
        });
        foreach (['advertisement_submissions', 'advertisement_reviews'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Advertisement review history is immutable.'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_restriction_history_mutation()");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('advertisements')->whereNotNull('shop_id')->exists()) {
            throw new LogicException('Retain seller promotion history. Disable display instead of removing these records.');
        }
        Schema::dropIfExists('advertisement_reviews');
        Schema::dropIfExists('advertisement_submissions');
        Schema::table('advertisement_publications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_publication_id');
            $table->unique('revision_id');
        });
        Schema::table('advertisements', fn (Blueprint $table) => $table->dropConstrainedForeignId('shop_id'));
    }
};
