<?php

namespace Tests\Feature\Seller;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ApplicationIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class SingleShopOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_a_second_shop_without_losing_the_original_or_its_order(): void
    {
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $item = OrderItem::factory()->create(['shop_id' => $shop->id, 'product_id' => $product->id]);
        $before = $shop->fresh()->getAttributes();

        try {
            DB::table('shops')->insert(['user_id' => $shop->user_id, 'name' => 'Another shop', 'slug' => 'another-shop',
                'name_key' => ApplicationIdentityService::shopNameKey('Another shop')]);
            $this->fail('Even a direct database insert must respect the one-shop rule.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE', $exception->getMessage());
        }

        $this->assertDatabaseCount('shops', 1);
        $this->assertSame($before, $shop->fresh()->getAttributes());
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'shop_id' => $shop->id]);
    }

    public function test_ownership_updates_cannot_give_a_seller_a_second_shop(): void
    {
        $first = Shop::factory()->create();
        $second = Shop::factory()->create();
        $before = $second->fresh()->getAttributes();

        try {
            DB::table('shops')->where('id', $second->id)->update(['user_id' => $first->user_id]);
            $this->fail('Ownership reassignment cannot bypass the database limit.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE', $exception->getMessage());
        }
        $this->assertSame($before, $second->fresh()->getAttributes());
        $this->assertDatabaseCount('shops', 2);
    }

    public function test_migration_reports_legacy_duplicates_without_deleting_or_merging_records(): void
    {
        $migration = require database_path('migrations/2026_10_08_100000_enforce_one_shop_per_seller.php');
        $migration->down();
        $first = Shop::factory()->create();
        $second = Shop::factory()->create(['user_id' => $first->user_id]);
        $product = Product::factory()->create(['shop_id' => $second->id]);
        $item = OrderItem::factory()->create(['shop_id' => $second->id, 'product_id' => $product->id]);
        $before = Shop::orderBy('id')->get()->map->getAttributes()->all();

        try {
            $migration->up();
            $this->fail('Ambiguous existing ownership requires a reviewed resolution.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reviewing existing duplicate ownership', $exception->getMessage());
        }
        $this->assertSame($before, Shop::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'shop_id' => $second->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'shop_id' => $second->id]);
        $this->actingAs($first->user)->get('/seller/products')->assertConflict();
        $this->get('/seller/shops')->assertConflict();
    }

    public function test_sole_shop_is_shared_without_a_picker_on_root_and_seller_hosts(): void
    {
        $shop = Shop::factory()->approved()->create();
        foreach ([['/seller/shops', '/seller/settings'], ['http://seller.localhost/shops', 'http://seller.localhost/settings']] as [$oldUrl, $settings]) {
            $this->actingAs($shop->user)->get($oldUrl)->assertRedirect($settings);
            $this->get($settings)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Settings')->where('shop.id', $shop->id)->where('shopReview.eligible', true)
                ->where('shopReview.review_status', 'approved')->has('shopReview.decision_history', 1)
                ->where('auth.user.shop.id', $shop->id)->missing('shops')->missing('auth.user.sellerShops'));
        }
    }

    public function test_settings_retains_review_and_resubmission_feedback_without_opening_new_work(): void
    {
        foreach ([null, 'pending_approval', 'rejected'] as $review) {
            $shop = Shop::factory()->approved()->create();
            $shop->update(['review_status' => $review, 'review_feedback' => 'Please review the original shop documents.']);
            $this->actingAs($shop->user)->get('/seller/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Settings')->where('shopReview.id', $shop->id)->where('shopReview.eligible', false)
                ->where('shopReview.can_submit', true)->where('shopReview.review_feedback', 'Please review the original shop documents.')
                ->has('shopReview.decision_history', 1));
            $this->get('/seller/dashboard')->assertRedirect('/seller/shops');
            $this->get('/seller/shops')->assertRedirect('/seller/settings')->assertSessionHas('error', 'Your shop cannot accept new work. Review its approval and activity in Settings.');
            $this->get('/seller/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('flash.error', 'Your shop cannot accept new work. Review its approval and activity in Settings.'));
            $this->post('/seller/products', ['name' => 'Unauthorized product'])->assertRedirect('/seller/shops');
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_missing_shop_is_visible_without_creating_one_from_a_portal_read(): void
    {
        $seller = User::factory()->seller()->create();
        $this->actingAs($seller)->get('/seller/shops')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('shop', null)->where('auth.user.shop', null)->missing('shops'));
        $this->assertDatabaseCount('shops', 0);
    }
}
