<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Preserve existing retention triggers while adding the nullable reference.
            DB::statement('ALTER TABLE reviews ADD COLUMN order_item_id INTEGER REFERENCES order_items(id) ON DELETE RESTRICT');
            Schema::table('reviews', function (Blueprint $table) {
                $table->unique('order_item_id');
                $table->string('submission_fingerprint', 64)->nullable();
            });
        } else {
            Schema::table('reviews', function (Blueprint $table) {
                $table->foreignId('order_item_id')->nullable()->unique()->constrained('order_items')->restrictOnDelete();
                $table->string('submission_fingerprint', 64)->nullable();
            });
        }
        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->unique()->constrained('reviews')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->text('text');
            $table->timestamps();
        });
        $this->backfillLegacyReviews();
    }

    public function backfillLegacyReviews(): void
    {
        DB::table('reviews')->whereNull('order_item_id')->orderBy('id')->chunkById(100, function ($reviews) {
            foreach ($reviews as $review) {
                if ($review->rating < 1 || $review->rating > 5 || ! $review->order_id) {
                    continue;
                }
                $owned = DB::table('orders')->where('id', $review->order_id)
                    ->where('buyer_id', $review->buyer_id)->where('status', 'completed')->exists();
                $items = DB::table('order_items')->where('order_id', $review->order_id)
                    ->where('product_id', $review->product_id)->limit(2)->pluck('id');
                $uniqueReview = DB::table('reviews')->where('buyer_id', $review->buyer_id)
                    ->where('order_id', $review->order_id)->where('product_id', $review->product_id)->count() === 1;
                if ($owned && $items->count() === 1 && $uniqueReview
                    && ! DB::table('reviews')->where('order_item_id', $items[0])->exists()) {
                    DB::table('reviews')->where('id', $review->id)->update(['order_item_id' => $items[0]]);
                }
            }
        });
        foreach (DB::table('products')->orderBy('id')->cursor() as $product) {
            $average = $this->verifiedReviews()->where('reviews.product_id', $product->id)->avg('reviews.rating');
            DB::table('products')->where('id', $product->id)->update(['rating' => round((float) ($average ?? 0), 2)]);
        }
        foreach (DB::table('shops')->orderBy('id')->cursor() as $shop) {
            $average = $this->verifiedReviews()->join('products', 'reviews.product_id', '=', 'products.id')
                ->where('products.shop_id', $shop->id)->avg('reviews.rating');
            DB::table('shops')->where('id', $shop->id)->update(['rating' => round((float) ($average ?? 0), 2)]);
        }
    }

    private function verifiedReviews(): Builder
    {
        return DB::table('reviews')->join('order_items', 'reviews.order_item_id', '=', 'order_items.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')->where('orders.status', 'completed')
            ->whereColumn('reviews.order_id', 'orders.id')->whereColumn('reviews.buyer_id', 'orders.buyer_id')
            ->whereColumn('reviews.product_id', 'order_items.product_id')->whereBetween('reviews.rating', [1, 5]);
    }

    public function down(): void
    {
        if (DB::table('review_replies')->exists() || DB::table('reviews')->whereNotNull('order_item_id')->exists()) {
            throw new LogicException('Recorded purchase reviews and seller replies must be retained.');
        }
        Schema::dropIfExists('review_replies');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX reviews_order_item_id_unique');
            DB::statement('ALTER TABLE reviews DROP COLUMN order_item_id');
            DB::statement('ALTER TABLE reviews DROP COLUMN submission_fingerprint');

            return;
        }
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropColumn('submission_fingerprint');
        });
    }
};
