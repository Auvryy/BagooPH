<?php

namespace Tests\Feature\Seeders;

use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\MasterCategoryService;
use App\Services\ShopEligibilityService;
use App\Services\ShopReviewService;
use Database\Seeders\SellerDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SellerDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_fresh_demo_has_one_categorized_shop_and_products_visible_through_real_marketplace_routes(): void
    {
        $this->seed(SellerDemoSeeder::class);
        $seller = User::where('email', 'seller@bagoo.test')->sole();
        $shop = $seller->shop;
        $product = $shop->products()->firstOrFail();

        $this->assertTrue(Hash::check('Password1234', $seller->password));
        $this->assertNotNull($seller->email_verified_at);
        $this->assertNotNull($seller->birthday);
        $this->assertSame("Men's Apparel", $shop->rootCategory->name);
        $this->assertCount(14, app(MasterCategoryService::class)->choices());
        $this->assertTrue(app(ShopEligibilityService::class)->isEligible($shop));
        $this->assertSame([], app(ShopReviewService::class)->issues($shop, app(ShopReviewService::class)->submission($shop)));
        $this->assertSame($shop->products()->count(), Product::availableForSale()->count());
        $this->assertGreaterThanOrEqual(7, $shop->products()->count());
        $this->assertDatabaseCount('shops', 1);
        $decision = ShopReviewDecision::sole();
        $this->assertSame('demo_fixture', $decision->submission['source']);
        $this->assertStringContainsString('Synthetic demo fixture', $decision->reason);
        $this->get('/product/'.$product->slug)->assertOk();
        $this->get('/shop/'.$shop->slug)->assertOk();
        $this->get('/')->assertOk();
        $this->actingAs($seller)->get('/seller/dashboard')->assertOk();
        $this->get('/seller/shops')->assertInertia(fn (Assert $page) => $page
            ->where('shop.decision_history.0.source', 'Synthetic demo setup'));
        $url = '/shop-verification-documents/'.$shop->id.'/id.png?decision='.$decision->id;
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->seller()->create())->get($url)->assertForbidden();
    }

    public function test_repeat_seeding_preserves_stock_prices_identity_images_custom_products_and_purchased_history(): void
    {
        $this->seed(SellerDemoSeeder::class);
        $seller = User::where('email', 'seller@bagoo.test')->sole();
        $seller->update(['password' => 'ChangedPassword1234', 'name' => 'Updated Seller']);
        $shop = $seller->shop;
        $product = $shop->products()->firstOrFail();
        $product->update(['stock' => 3, 'price' => 999, 'sales_count' => 17, 'status' => 'inactive']);
        $custom = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id]);
        $item = OrderItem::factory()->create(['shop_id' => $shop->id, 'product_id' => $product->id]);
        $images = $product->images()->get()->map->getAttributes()->all();
        $before = [$seller->fresh()->getAttributes(), $shop->fresh()->getAttributes(), $product->fresh()->getAttributes(),
            $custom->fresh()->getAttributes(), $item->fresh()->getAttributes(), ShopReviewDecision::sole()->getAttributes()];
        $counts = [User::count(), Shop::count(), Product::count(), Category::count()];
        $files = Storage::disk('local')->allFiles();

        $this->seed(SellerDemoSeeder::class);
        $this->assertSame($before, [$seller->fresh()->getAttributes(), $shop->fresh()->getAttributes(), $product->fresh()->getAttributes(),
            $custom->fresh()->getAttributes(), $item->fresh()->getAttributes(), ShopReviewDecision::sole()->getAttributes()]);
        $this->assertSame($counts, [User::count(), Shop::count(), Product::count(), Category::count()]);
        $this->assertSame($images, $product->images()->get()->map->getAttributes()->all());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertTrue(Hash::check('ChangedPassword1234', $seller->fresh()->password));
    }

    public function test_legacy_fixture_repair_keeps_product_and_order_ids_and_does_not_move_the_shared_legacy_category(): void
    {
        $seller = User::factory()->seller()->create(['email' => 'seller@bagoo.test']);
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'slug' => 'apex-gear-and-studio']);
        $legacy = Category::factory()->create(['slug' => 'backpacks-and-bags', 'parent_id' => null]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $legacy->id,
            'sku' => 'APX-VNG-001', 'slug' => 'vanguard-commuter-backpack-26l', 'stock' => 4, 'price' => 700]);
        $item = OrderItem::factory()->create(['shop_id' => $shop->id, 'product_id' => $product->id]);
        $foreign = Product::factory()->create(['category_id' => $legacy->id]);
        $foreignBefore = [$foreign->fresh()->getAttributes(), $foreign->shop->getAttributes()];
        $itemBefore = $item->fresh()->getAttributes();

        $this->seed(SellerDemoSeeder::class);
        $this->assertSame($product->id, Product::where('shop_id', $shop->id)->where('sku', 'APX-VNG-001')->sole()->id);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame('700.00', $product->fresh()->price);
        $this->assertTrue(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
        $this->assertSame($itemBefore, $item->fresh()->getAttributes());
        $this->assertSame($foreignBefore, [$foreign->fresh()->getAttributes(), $foreign->shop->fresh()->getAttributes()]);
        $this->assertNull($legacy->fresh()->parent_id);
        $this->assertSame(1, $seller->shop()->count());
    }

    public function test_existing_real_shop_review_and_chosen_category_are_preserved_during_legacy_product_repair(): void
    {
        $seller = User::factory()->seller()->create(['email' => 'seller@bagoo.test']);
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $legacy = Category::factory()->create(['slug' => 'backpacks-and-bags']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $legacy->id, 'sku' => 'APX-VNG-001']);
        $before = [$seller->fresh()->getAttributes(), $shop->fresh()->getAttributes(), $shop->currentReview->getAttributes()];
        $this->seed(SellerDemoSeeder::class);
        $this->assertSame($before, [$seller->fresh()->getAttributes(), $shop->fresh()->getAttributes(), $shop->fresh()->currentReview->getAttributes()]);
        $this->assertTrue(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
        $this->assertDatabaseCount('shop_review_decisions', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_inactive_legacy_product_category_is_preserved_instead_of_bypassing_its_restriction(): void
    {
        $this->seed(SellerDemoSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $legacy = Category::factory()->create(['slug' => 'backpacks-and-bags', 'is_active' => false]);
        $product = $shop->products()->where('sku', 'APX-VNG-001')->sole();
        $product->update(['category_id' => $legacy->id]);
        $before = $product->fresh()->getAttributes();
        $this->seed(SellerDemoSeeder::class);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertFalse($legacy->fresh()->is_active);
        $this->assertFalse(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
    }

    public function test_a_reserved_demo_email_in_another_role_is_never_converted_into_a_seller(): void
    {
        $user = User::factory()->buyer()->create(['email' => 'seller@bagoo.test']);
        $before = $user->fresh()->getAttributes();
        try {
            $this->seed(SellerDemoSeeder::class);
            $this->fail('The reserved email cannot convert an existing account role.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicting role', $exception->getMessage());
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('shops', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function blockedFixtures(): array
    {
        return [['seller_restricted'], ['shop_restricted'], ['shop_rejected'], ['inactive_category']];
    }

    #[DataProvider('blockedFixtures')]
    public function test_seed_repair_cannot_clear_restrictions_change_roles_or_override_review(string $blocker): void
    {
        $this->seed(SellerDemoSeeder::class);
        $seller = User::where('email', 'seller@bagoo.test')->sole();
        $shop = $seller->shop;
        match ($blocker) {
            'seller_restricted' => $seller->update(['status' => 'suspended']),
            'shop_restricted' => $shop->update(['status' => 'suspended']),
            'shop_rejected' => $shop->update(['review_status' => 'rejected']),
            'inactive_category' => $shop->rootCategory->update(['is_active' => false]),
        };
        $before = [$seller->fresh()->getAttributes(), $shop->fresh()->getAttributes(), ShopReviewDecision::sole()->getAttributes()];
        $files = Storage::disk('local')->allFiles();
        try {
            $this->seed(SellerDemoSeeder::class);
            $this->fail('Demo repair cannot bypass account, shop or category policy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('demo', $exception->getMessage());
        }
        $this->assertSame($before, [$seller->fresh()->getAttributes(), $shop->fresh()->getAttributes(), ShopReviewDecision::sole()->getAttributes()]);
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_a_failed_fixture_review_rolls_back_database_writes_and_removes_only_new_private_files(): void
    {
        Storage::disk('local')->put('kyc_documents/retained.png', 'Previously retained evidence');
        Event::listen('eloquent.created: '.ShopReviewDecision::class, fn () => throw new RuntimeException('Fixture persistence failed'));
        try {
            $this->seed(SellerDemoSeeder::class);
            $this->fail('The failed fixture review must not partially persist.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture persistence failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.ShopReviewDecision::class);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('shop_review_decisions', 0);
        $this->assertSame(['kyc_documents/retained.png'], Storage::disk('local')->allFiles());
    }
}
