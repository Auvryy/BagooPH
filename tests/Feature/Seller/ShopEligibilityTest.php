<?php

namespace Tests\Feature\Seller;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Orders\CheckoutOrderService;
use App\Services\ShopEligibilityService;
use App\Services\ShopReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class ShopEligibilityTest extends TestCase
{
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    public function test_a_missing_shop_uses_the_review_screen_without_creating_a_store(): void
    {
        $seller = User::factory()->seller()->create();

        $this->actingAs($seller)->get('/seller/dashboard')->assertRedirect('/seller/shops');

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_old_shop_selections_cannot_override_the_sellers_only_shop(): void
    {
        $seller = User::factory()->seller()->create();
        $owned = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $product = Product::factory()->create(['shop_id' => $owned->id]);
        $foreign = Product::factory()->create();

        foreach ([$foreign->shop_id, 999999, ['invalid']] as $id) {
            $this->actingAs($seller)->withSession(['active_seller_shop_id' => $id])
                ->get('/seller/products')->assertOk()->assertSessionMissing('active_seller_shop_id')
                ->assertInertia(fn (Assert $page) => $page->where('shop.id', $owned->id)
                    ->where('auth.user.shop.id', $owned->id)->missing('auth.user.sellerShops')->missing('availableShops')
                    ->has('products.data', 1)->where('products.data.0.id', $product->id));
        }
        $this->assertDatabaseCount('shops', 2);
    }

    public function test_additional_shop_creation_cannot_grant_itself_approval(): void
    {
        $seller = User::factory()->seller()->create();
        $category = Category::create(['name' => 'Pet Supplies', 'slug' => 'pet-supplies', 'is_active' => true]);

        foreach (['/seller/shops', 'http://seller.localhost/shops'] as $url) {
            $this->actingAs($seller)->post($url, [
                'name' => 'Pet Corner', 'root_category_id' => $category->id,
                'phone' => '09171234567', 'address' => '123 Rizal Street', 'city' => 'Manila',
                'status' => 'active', 'review_status' => 'approved',
            ])->assertStatus(405);
        }
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_valid_additional_shop_input_cannot_create_another_shop_or_save_an_upload(): void
    {
        Storage::fake('local');
        $seller = User::factory()->seller()->create();
        $current = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $before = $current->fresh()->getAttributes();
        foreach (['/seller/shops', 'http://seller.localhost/shops'] as $url) {
            $this->actingAs($seller)->post($url, [
                'name' => 'Pet Corner', 'phone' => '09171234567', 'address' => '123 Rizal Street', 'city' => 'Manila',
                'root_category_id' => $current->root_category_id,
                'business_permit' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4\nShop permit\n%%EOF"),
            ])->assertStatus(405);
        }
        $this->assertSame($before, $current->fresh()->getAttributes());
        $this->assertDatabaseCount('shops', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_shop_switching_is_unavailable_even_with_an_owned_or_foreign_shop_id(): void
    {
        $seller = User::factory()->seller()->create();
        $owned = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $foreign = Shop::factory()->approved()->create();
        foreach (['/seller/shops/switch', 'http://seller.localhost/shops/switch'] as $url) {
            foreach ([$owned->id, $foreign->id, 999999] as $id) {
                $this->actingAs($seller)->post($url, ['shop_id' => $id])->assertNotFound();
            }
        }
        $this->assertDatabaseCount('shops', 2);
    }

    #[DataProvider('blockedShopStates')]
    public function test_ineligible_session_cannot_write_products_stock_or_vouchers(string $review, string $activity): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 10]);
        $shop->update(['review_status' => $review === 'legacy' ? null : $review, 'status' => $activity]);
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id]);
        $this->post('/seller/products', ['name' => 'Bypass product'])->assertRedirect('/seller/shops');
        $this->patch('/seller/products/'.$product->id.'/stock', ['mode' => 'add', 'quantity' => 2])->assertRedirect('/seller/shops');
        $this->post('/seller/vouchers', ['code' => 'BYPASS'])->assertRedirect('/seller/shops');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public static function blockedShopStates(): array
    {
        return [['pending_approval', 'active'], ['rejected', 'active'], ['unknown', 'active'], ['legacy', 'active'],
            ['approved', 'pending'], ['approved', 'suspended'], ['approved', 'inactive'], ['approved', 'unknown']];
    }

    public function test_an_eligible_shop_accepts_only_active_categories_in_its_reviewed_tree(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $child = Category::factory()->create(['parent_id' => $shop->root_category_id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);
        $foreign = Category::factory()->create();
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id]);
        $data = ['name' => 'Pet Blanket', 'price' => 150, 'stock' => 10, 'description' => 'A soft pet blanket.'];
        foreach ([$shop->root_category_id, $child->id, $grandchild->id, null] as $categoryId) {
            $this->post('/seller/products', $data + ['category_id' => $categoryId])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame($shop->root_category_id, Product::latest('id')->first()->category_id);
        $this->post('/seller/products', $data + ['category_id' => $foreign->id])->assertSessionHasErrors('category_id');
        $child->update(['is_active' => false]);
        $this->post('/seller/products', $data + ['category_id' => $grandchild->id])->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('products', 4);
    }

    public function test_foreign_products_cannot_be_claimed_through_direct_updates(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $foreign = Product::factory()->create();
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id]);
        foreach ([$foreign] as $product) {
            $this->post('/seller/products/'.$product->id, ['name' => 'Claimed'])->assertForbidden();
            $this->delete('/seller/products/'.$product->id)->assertForbidden();
        }
        $this->assertDatabaseCount('products', 1);
    }

    public function test_reviewed_shop_scope_cannot_be_replaced_by_generic_settings_or_branding(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id]);
        $this->post('/seller/settings', ['name' => 'A different business'])->assertSessionHasErrors('name');
        $this->post('/shop/'.$shop->slug.'/update-branding', ['root_category_id' => 999999])->assertSessionHasErrors('root_category_id');
        $this->assertSame($shop->name, $shop->fresh()->name);
        $this->assertSame('approved', $shop->fresh()->review_status);
    }

    public function test_restricted_owned_order_history_is_visible_without_changing_the_work_context(): void
    {
        $seller = User::factory()->seller()->create();
        $current = Shop::factory()->approved()->create();
        $restricted = Shop::factory()->create(['user_id' => $seller->id, 'status' => 'suspended']);
        $product = Product::factory()->create(['shop_id' => $restricted->id]);
        $item = OrderItem::factory()->create(['shop_id' => $restricted->id, 'product_id' => $product->id]);
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $current->id])
            ->get('/seller/orders?shop_id='.$restricted->id)->assertOk()->assertSessionMissing('active_seller_shop_id')
            ->assertInertia(fn (Assert $page) => $page->where('shop.id', $restricted->id)->where('shopEligible', false)->where('orderItems.data.0.id', $item->id));
        $this->get('/seller/orders?shop_id='.Shop::factory()->create()->id)->assertForbidden();
        $this->post('/seller/orders/'.$item->order_id.'/accept')->assertRedirect()->assertSessionHas('error');
        $this->assertSame($item->order->status, $item->order->fresh()->status);
        $this->assertSame('suspended', $restricted->fresh()->status);
    }

    #[DataProvider('saleBlockers')]
    public function test_direct_checkout_revalidates_shop_account_provenance_and_category_atomically(string $blocker): void
    {
        $buyer = User::factory()->buyer()->create();
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 10]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $item = CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1]);
        match ($blocker) {
            'shop' => $shop->update(['status' => 'suspended']),
            'review' => $shop->update(['review_status' => 'pending_approval']),
            'seller' => $shop->user->update(['status' => 'suspended']),
            'kyc' => $shop->user->update(['kyc_status' => 'none']),
            'legacy' => $shop->update(['review_decision_id' => null]),
            'foreign_review' => $shop->update(['review_decision_id' => Shop::factory()->approved()->create()->review_decision_id]),
            'null_category' => $product->update(['category_id' => null]),
            'foreign_category' => $product->update(['category_id' => Category::factory()->create()->id]),
            'category_inactive' => $shop->rootCategory->update(['is_active' => false]),
            'root_changed' => $shop->update(['root_category_id' => Category::factory()->create(['name' => 'Food and Gourmet'])->id]),
            'details_changed' => $shop->update(['name' => 'Another business']),
        };
        try {
            app(CheckoutOrderService::class)->place($buyer, $cart, [$item->id], [
                'checkout_token' => $this->checkoutToken($buyer, $cart),
                'recipient_name' => 'Maria Santos', 'recipient_phone' => '+639171234567',
                'shipping_address' => '123 Mabini Street', 'shipping_city' => 'Manila',
                'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1000',
                'destination_barangay' => 'Poblacion', 'delivery_type' => 'doorstep', 'payment_method' => 'cod',
            ]);
            $this->fail('New checkout must reject an ineligible shop or scope.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unavailable', $exception->getMessage());
        }
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
        $this->actingAs($buyer)->post('/cart', ['product_id' => $product->id])->assertSessionHasErrors('product_id');
    }

    public static function saleBlockers(): array
    {
        return array_map(fn ($value) => [$value], ['shop', 'review', 'seller', 'kyc', 'legacy', 'foreign_review', 'null_category',
            'foreign_category', 'category_inactive', 'root_changed', 'details_changed']);
    }

    public function test_public_discovery_and_product_urls_do_not_expose_unreviewed_shops(): void
    {
        $approved = Product::factory()->create();
        $legacy = Product::factory()->create(['shop_id' => Shop::factory()->create()->id]);
        $this->get('/product/'.$approved->slug)->assertOk();
        $this->get('/product/'.$legacy->slug)->assertNotFound();
        $this->get('/product/'.$legacy->id)->assertNotFound();
        $this->get('/shop/'.$legacy->shop->slug)->assertNotFound();
        $this->assertSame([$approved->id], Product::availableForSale()->pluck('id')->all());
    }

    public function test_seller_subdomain_cannot_bypass_shop_approval_for_products(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'status' => 'pending']);
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id])->post('http://seller.localhost/products', ['name' => 'Bypass'])
            ->assertRedirect('/seller/shops');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_rendered_product_write_failure_rolls_back_the_listing(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        Event::listen('eloquent.created: '.Product::class, fn () => throw new RuntimeException('Listing write failed'));
        try {
            $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id])->post('/seller/products', [
                'name' => 'Pet Blanket', 'price' => 100, 'stock' => 10, 'description' => 'A soft pet blanket.',
            ])->assertServerError();
        } finally {
            Event::forget('eloquent.created: '.Product::class);
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_failed_submission_removes_only_its_new_private_upload(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('kyc_documents/kept.pdf', "%PDF-1.4\nPreviously retained evidence\n%%EOF");
        $seller = User::factory()->seller()->create();
        $category = Category::create(['name' => 'Pet Supplies', 'slug' => 'pet-supplies', 'is_active' => true]);
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'root_category_id' => $category->id]);
        $before = $shop->fresh()->getAttributes();
        Event::listen('eloquent.updated: '.Shop::class, fn () => throw new RuntimeException('Shop write failed'));
        try {
            $this->actingAs($seller)->post('/seller/shops/'.$shop->id.'/resubmit', [
                'review_token' => app(ShopReviewService::class)->token(app(ShopReviewService::class)->submission($shop)),
                'name' => 'Pet Corner', 'phone' => '09171234567', 'address' => '123 Rizal Street', 'city' => 'Manila',
                'root_category_id' => $category->id,
                'business_permit' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4\nNew shop permit\n%%EOF"),
            ])->assertServerError();
        } finally {
            Event::forget('eloquent.updated: '.Shop::class);
        }
        $this->assertDatabaseCount('shops', 1);
        $this->assertSame($before, $shop->fresh()->getAttributes());
        $this->assertSame(['kyc_documents/kept.pdf'], Storage::disk('local')->allFiles('kyc_documents'));
    }

    public function test_shop_submission_uses_canonical_contact_category_and_text_validation(): void
    {
        Storage::fake('local');
        $seller = User::factory()->seller()->create();
        $category = Category::create(['name' => 'Pet Supplies', 'slug' => 'pet-supplies', 'is_active' => true]);
        $child = Category::factory()->create(['parent_id' => $category->id]);
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'root_category_id' => $category->id]);
        $data = ['review_token' => app(ShopReviewService::class)->token(app(ShopReviewService::class)->submission($shop)),
            'name' => 'Pet Corner', 'phone' => '09171234567', 'address' => '123 Rizal Street', 'city' => 'Manila',
            'root_category_id' => $category->id, 'business_permit' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4\nShop permit\n%%EOF")];
        foreach ([['phone', '123', 'shop_phone'], ['name', "Pet Corner\n", 'shop_name'], ['root_category_id', $child->id, 'root_category_id'], ['address', '', 'shop_address']] as [$field, $value, $error]) {
            $this->actingAs($seller)->post('/seller/shops/'.$shop->id.'/resubmit', array_replace($data, [$field => $value]))->assertSessionHasErrors($error);
        }
        $this->assertDatabaseCount('shops', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('kyc_documents'));
    }

    public function test_null_scopes_and_unrecorded_flags_never_grant_a_shop_access(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'status' => 'active', 'review_status' => 'approved']);
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id])->get('/seller/products')->assertRedirect('/seller/shops');
        $this->assertFalse(app(ShopEligibilityService::class)->isEligible($shop));
        $shop->update(['root_category_id' => Category::factory()->create()->id]);
        $this->get('/seller/products')->assertRedirect('/seller/shops');
        $this->assertDatabaseCount('shop_review_decisions', 0);
    }

    public function test_an_approved_shop_cannot_be_resubmitted_and_foreign_or_stale_submissions_conflict(): void
    {
        $seller = User::factory()->seller()->create();
        $approved = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $pending = Shop::factory()->create(['root_category_id' => $approved->root_category_id]);
        $service = app(ShopReviewService::class);
        $data = $pending->only(ShopEligibilityService::DETAILS) + ['review_token' => $service->token($service->submission($pending->fresh()))];
        $this->actingAs($seller)->post('/seller/shops/'.$approved->id.'/resubmit', $data)->assertConflict();
        $this->actingAs($pending->user);
        $pending->update(['city' => 'Updated Shop City']);
        $this->post('/seller/shops/'.$pending->id.'/resubmit', $data)->assertConflict();
        $foreign = Shop::factory()->create();
        $this->post('/seller/shops/'.$foreign->id.'/resubmit', $data)->assertForbidden();
        $this->assertSame('Updated Shop City', $pending->fresh()->city);
    }
}
