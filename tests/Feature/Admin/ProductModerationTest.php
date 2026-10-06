<?php

namespace Tests\Feature\Admin;

use App\Models\Cart;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductModerationDecision;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\ProductModerationService;
use App\Services\ShopEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function listing(string $status = 'active'): array
    {
        $admin = User::factory()->admin()->create();
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id,
            'status' => $status, 'price' => '125.25', 'stock' => 9, 'variants' => null]);

        return [$admin, $shop->user, $shop, $product];
    }

    private function input(Product $product, string $action = 'remove', string $reason = 'Correct the listing details before another compliance review.'): array
    {
        return ['action' => $action, 'reason' => $reason,
            'source_token' => app(AccountRestrictionService::class)->token(app(ProductModerationService::class)->state($product->fresh()))];
    }

    public static function portalStatuses(): array
    {
        $cases = [];
        foreach (['/admin', 'http://admin.localhost'] as $host) {
            foreach (['active', 'draft', 'archived'] as $status) {
                $cases[$host.' '.$status] = [$host, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('portalStatuses')]
    public function test_reasoned_removal_preserves_seller_state_and_commercial_details(string $host, string $status): void
    {
        [$admin, , $shop, $product] = $this->listing($status);
        $before = $product->only(['shop_id', 'category_id', 'name', 'price', 'stock', 'variants', 'status']);
        $this->actingAs($admin)->postJson($host.'/products/'.$product->id.'/moderation', $this->input($product))->assertOk();
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $this->assertTrue($product->fresh()->compliance_restricted);
        $this->assertSame(1, $product->fresh()->moderation_version);
        $this->assertFalse(Product::availableForSale()->whereKey($product->id)->exists());
        $this->assertFalse(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
        $decision = ProductModerationDecision::sole();
        $this->assertSame($admin->id, $decision->actor_id);
        $this->assertSame('admin', $decision->actor_role);
        $this->assertSame($shop->id, $decision->before_state['shop']['id']);
        $this->assertFalse($decision->before_state['product']['compliance_restricted']);
        $this->assertTrue($decision->after_state['product']['compliance_restricted']);
        $this->assertNull($decision->prior_decision_id);
    }

    public static function invalidInputs(): array
    {
        return [['reason', ''], ['reason', '    '], ['reason', '<script>bad</script>'], ['reason', "Hidden\u{200b}reason"],
            ['reason', "\0Hidden control reason"], ['reason', "Hidden control reason\t"],
            ['reason', str_repeat('a', 1001)], ['source_token', null], ['source_token', 'wrong'],
            ['action', 'activate'], ['status', 'active'], ['price', '1.00'], ['stock', 99],
            ['shop_id', 2], ['category_id', 2], ['actor_id', 2], ['compliance_restricted', false], ['moderation_version', 10]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_injected_decision_input_changes_nothing(string $field, mixed $value): void
    {
        [$admin, , , $product] = $this->listing();
        $before = $product->fresh()->getAttributes();
        $this->actingAs($admin)->postJson('/admin/products/'.$product->id.'/moderation', [...$this->input($product), $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('product_moderation_decisions', 0);
    }

    public function test_reinstatement_preserves_draft_state_and_only_restores_eligible_active_sales(): void
    {
        [$admin, , , $product] = $this->listing('draft');
        $service = app(ProductModerationService::class);
        $first = $service->decide($admin, $product, $this->input($product));
        $second = $service->decide($admin, $product, $this->input($product, 'reinstate', 'The product information now meets the listing requirements.'));
        $this->assertFalse($product->fresh()->compliance_restricted);
        $this->assertSame('draft', $product->fresh()->status);
        $this->assertFalse(Product::availableForSale()->whereKey($product->id)->exists());
        $this->assertSame($first->id, $second->prior_decision_id);
        $product->update(['status' => 'active']);
        $this->assertTrue(Product::availableForSale()->whereKey($product->id)->exists());
        $this->assertTrue(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
    }

    public static function parentChanges(): array
    {
        return [['seller_inactive'], ['seller_rejected'], ['seller_underage'], ['shop_suspended'],
            ['shop_unreviewed'], ['root_inactive'], ['foreign_category']];
    }

    #[DataProvider('parentChanges')]
    public function test_reinstatement_requires_current_seller_shop_and_category_eligibility(string $change): void
    {
        [$admin, $seller, $shop, $product] = $this->listing();
        app(ProductModerationService::class)->decide($admin, $product, $this->input($product));
        match ($change) {
            'seller_inactive' => $seller->update(['status' => 'inactive']),
            'seller_rejected' => $seller->update(['kyc_status' => 'rejected']),
            'seller_underage' => $seller->update(['birthday' => now()->subYears(12)->toDateString()]),
            'shop_suspended' => $shop->update(['status' => 'suspended']),
            'shop_unreviewed' => $shop->update(['review_status' => null]),
            'root_inactive' => $shop->rootCategory->update(['is_active' => false]),
            'foreign_category' => $product->update(['category_id' => Category::factory()->create()->id]),
        };
        $this->actingAs($admin)->postJson('/admin/products/'.$product->id.'/moderation', $this->input($product, 'reinstate'))->assertConflict();
        $this->assertTrue($product->fresh()->compliance_restricted);
        $this->assertDatabaseCount('product_moderation_decisions', 1);
        $this->assertFalse(Product::availableForSale()->whereKey($product->id)->exists());
    }

    public function test_unknown_status_and_archived_reinstatement_are_not_activation_shortcuts(): void
    {
        [$admin, , , $product] = $this->listing('unknown');
        $this->actingAs($admin)->postJson('/admin/products/'.$product->id.'/moderation', $this->input($product))->assertConflict()->assertJsonPath('permitted_actions', []);
        $product->update(['status' => 'archived']);
        app(ProductModerationService::class)->decide($admin, $product, $this->input($product));
        $this->postJson('/admin/products/'.$product->id.'/moderation', $this->input($product, 'reinstate'))->assertConflict();
        $this->assertSame('archived', $product->fresh()->status);
        $this->assertTrue($product->fresh()->compliance_restricted);
    }

    public function test_original_retry_remains_one_decision_after_reinstatement_and_competing_requests_conflict(): void
    {
        [$admin, , , $product] = $this->listing();
        $service = app(ProductModerationService::class);
        $input = $this->input($product, reason: '  Suriin ang detalye ng produktong José.  ');
        $first = $service->decide($admin, $product, $input);
        $original = $first->fresh()->getAttributes();
        $this->travel(2)->minutes();
        $this->assertSame($first->id, $service->decide($admin, $product, $input)->id);
        $this->assertSame('Suriin ang detalye ng produktong José.', $first->reason);
        $this->actingAs($admin)->postJson('/admin/products/'.$product->id.'/moderation', [...$input, 'action' => 'reinstate'])->assertConflict();
        $this->postJson('/admin/products/'.$product->id.'/moderation', [...$input, 'reason' => 'Different feedback after the same decision.'])->assertConflict();
        $this->actingAs(User::factory()->admin()->create())->postJson('/admin/products/'.$product->id.'/moderation', $input)->assertConflict();
        $service->decide($admin, $product, $this->input($product, 'reinstate'));
        $service->decide($admin, $product, $input);
        $this->assertFalse($product->fresh()->compliance_restricted);
        $this->assertSame(2, $product->fresh()->moderation_version);
        $this->assertSame($original, $first->fresh()->getAttributes());
        $this->assertDatabaseCount('product_moderation_decisions', 2);
    }

    public function test_stale_product_edit_or_parent_restriction_conflicts_before_a_decision(): void
    {
        [$admin, $seller, , $product] = $this->listing();
        $input = $this->input($product);
        $product->update(['name' => 'Corrected listing title']);
        $this->actingAs($admin)->postJson('/admin/products/'.$product->id.'/moderation', $input)->assertConflict();
        $input = $this->input($product);
        $seller->update(['status' => 'suspended']);
        $this->postJson('/admin/products/'.$product->id.'/moderation', $input)->assertConflict();
        $this->assertDatabaseCount('product_moderation_decisions', 0);
    }

    public function test_seller_edit_keeps_restriction_and_shows_owned_plain_language_feedback(): void
    {
        [$admin, $seller, $shop, $product] = $this->listing();
        app(ProductModerationService::class)->decide($admin, $product, $this->input($product));
        $data = $product->only(['name', 'category_id', 'price', 'stock', 'sku', 'status']) + ['description' => 'Corrected product details for the current listing.'];
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id])->post('/seller/products/'.$product->id, $data)->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->compliance_restricted);
        $this->assertSame('active', $product->fresh()->status);
        $this->post('/seller/products/'.$product->id, $data + ['compliance_restricted' => false])->assertSessionHasErrors('compliance_restricted');
        $this->get('/seller/products')->assertInertia(fn (Assert $page) => $page->where('products.data.0.compliance_restricted', true)
            ->where('products.data.0.compliance_feedback.reason', $this->input($product)['reason'])
            ->missing('products.data.0.compliance_feedback.source_token'));
        $this->actingAs(User::factory()->seller()->create())->post('/seller/products/'.$product->id, $data)->assertForbidden();
        $this->assertTrue($product->fresh()->compliance_restricted);
    }

    public function test_removal_blocks_detail_bag_and_checkout_without_rewriting_bought_items_or_images(): void
    {
        [$admin, , , $product] = $this->listing();
        $buyer = User::factory()->buyer()->create();
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id,
            'unit_price' => '100.10', 'quantity' => 2, 'subtotal' => '200.20']);
        $image = ProductImage::create(['product_id' => $product->id, 'image_url' => '/storage/products/example.jpg', 'is_primary' => true, 'sort_order' => 0]);
        $snapshot = [$item->fresh()->getAttributes(), $item->order->fresh()->getAttributes(), $image->fresh()->getAttributes()];
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $product->price]);
        app(ProductModerationService::class)->decide($admin, $product, $this->input($product));
        $this->actingAs($buyer)->get('/cart')->assertInertia(fn (Assert $page) => $page->where('items.0.available_for_purchase', false)
            ->has('items.0.unavailable_reason')->missing('items.0.product.compliance_feedback'));
        $this->actingAs($buyer)->get('/product/'.$product->slug)->assertNotFound();
        $this->post('/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('product_id');
        $this->patch('/cart/'.$line->id, ['quantity' => 2])->assertSessionHasErrors();
        $this->from('/checkout')->post('/checkout', ['item_ids' => [$line->id], 'recipient_name' => 'Maria Santos',
            'recipient_phone' => '+639171234567', 'shipping_address' => '123 Rizal Street', 'shipping_city' => 'Manila',
            'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1000', 'destination_barangay' => 'Ermita', 'delivery_type' => 'doorstep', 'payment_method' => 'cod'])
            ->assertRedirect('/checkout')->assertSessionHas('error');
        $this->assertSame($snapshot, [$item->fresh()->getAttributes(), $item->order->fresh()->getAttributes(), $image->fresh()->getAttributes()]);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_decision_failure_rolls_back_eligibility_and_history_together(): void
    {
        [$admin, , , $product] = $this->listing();
        $before = $product->fresh()->getAttributes();
        Event::listen('eloquent.created: '.ProductModerationDecision::class, fn () => throw new RuntimeException('Moderation audit failed'));
        try {
            app(ProductModerationService::class)->decide($admin, $product, $this->input($product));
            $this->fail('The injected audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Moderation audit failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.ProductModerationDecision::class);
        }
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('product_moderation_decisions', 0);
        $this->assertTrue(Product::availableForSale()->whereKey($product->id)->exists());
    }

    public function test_model_and_database_protect_referenced_products_and_immutable_audit(): void
    {
        [$admin, $seller, $shop, $product] = $this->listing();
        $decision = app(ProductModerationService::class)->decide($admin, $product, $this->input($product));
        try {
            $product->delete();
            $this->fail('A referenced product cannot be deleted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('archived', $exception->getMessage());
        }
        foreach (['decision_update', 'decision_delete', 'product_delete'] as $operation) {
            try {
                match ($operation) {
                    'decision_update' => DB::table('product_moderation_decisions')->where('id', $decision->id)->update(['reason' => 'Replaced feedback']),
                    'decision_delete' => DB::table('product_moderation_decisions')->where('id', $decision->id)->delete(),
                    'product_delete' => DB::table('products')->where('id', $product->id)->delete(),
                };
                $this->fail('Retained evidence cannot be changed or deleted.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id])->delete('/seller/products/'.$product->id)->assertSessionHas('success');
        $this->assertSame('archived', $product->fresh()->status);
        $this->assertTrue($product->fresh()->compliance_restricted);
        $this->assertDatabaseCount('product_moderation_decisions', 1);
    }

    public function test_purchased_product_cannot_be_deleted_directly_without_moderation_history(): void
    {
        [, , , $product] = $this->listing();
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id]);
        try {
            DB::table('products')->where('id', $product->id)->delete();
            $this->fail('The order-item cascade must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('retained', $exception->getMessage());
        }
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_preview_history_privilege_and_removed_toggle_are_consistent_on_both_portals(): void
    {
        [$admin, $seller, , $product] = $this->listing();
        foreach (['/admin', 'http://admin.localhost'] as $host) {
            $url = $host.'/products/'.$product->id.'/moderation';
            $this->actingAs($admin)->get($url)->assertInertia(fn (Assert $page) => $page->component('Admin/ProductModeration')
                ->where('subject.state.product.status', 'active')->has('subject.source_token')->where('subject.history', []));
            $this->patch($host.'/products/'.$product->id.'/toggle')->assertNotFound();
            $this->actingAs($seller)->get($url)->assertForbidden();
            $this->postJson($url, $this->input($product))->assertForbidden();
        }
        $stale = User::findOrFail($admin->id);
        $admin->update(['status' => 'inactive']);
        foreach (['/admin', 'http://admin.localhost'] as $host) {
            $this->actingAs($stale)->get($host.'/products/'.$product->id.'/moderation')->assertRedirect('/login');
            $this->actingAs($stale)->postJson($host.'/products/'.$product->id.'/moderation', $this->input($product))->assertForbidden();
        }
        $this->assertDatabaseCount('product_moderation_decisions', 0);
    }
}
