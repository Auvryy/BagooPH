<?php

namespace Tests\Feature\Seller;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
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
            DB::table('shops')->insert(['user_id' => $shop->user_id, 'name' => 'Another shop', 'slug' => 'another-shop']);
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
        foreach (['/seller/shops', 'http://seller.localhost/shops'] as $url) {
            $this->actingAs($shop->user)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Shops')->where('shop.id', $shop->id)->where('shop.eligible', true)
                ->where('auth.user.shop.id', $shop->id)->missing('shops')->missing('auth.user.sellerShops'));
        }
    }

    public function test_missing_shop_is_visible_without_creating_one_from_a_portal_read(): void
    {
        $seller = User::factory()->seller()->create();
        $this->actingAs($seller)->get('/seller/shops')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('shop', null)->where('auth.user.shop', null)->missing('shops'));
        $this->assertDatabaseCount('shops', 0);
    }
}
