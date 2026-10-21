<?php

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerProductVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        $this->shop = Shop::factory()->approved()->create();
        $this->product = $this->product();
        $this->actingAs($this->shop->user);
    }

    private function product(array $changes = []): Product
    {
        return Product::factory()->create([...['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id], ...$changes]);
    }

    private function input(array $changes = []): array
    {
        return [...['code' => 'STUDIOSAVE', 'name' => 'Selected studio savings', 'discount_type' => 'percent',
            'discount_value' => '10', 'min_spend' => '100.00', 'max_discount' => '100.00', 'budget' => '5000.00',
            'usage_limit' => 50, 'per_buyer_limit' => 2, 'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addDay()->toIso8601String(), 'source_version' => 0, 'request_key' => (string) Str::uuid(),
            'product_scope' => 'selected', 'product_ids' => [$this->product->id]], ...$changes];
    }

    private function action(Voucher $voucher, string $action = 'publish'): array
    {
        return ['action' => $action, 'source_version' => $voucher->fresh()->campaign_version, 'request_key' => (string) Str::uuid()];
    }

    public static function portals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    #[DataProvider('portals')]
    public function test_selection_survives_reload_publication_and_exact_retries_on_both_portals(string $base): void
    {
        $second = $this->product();
        $input = $this->input(['product_ids' => [$second->id, (string) $this->product->id]]);
        $this->post($base.'/vouchers', $input)->assertSessionHas('success');
        $this->post($base.'/vouchers', $input)->assertSessionHas('success');
        $voucher = Voucher::sole();
        $this->get($base.'/vouchers')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Seller/Vouchers')
            ->where('campaigns.data.0.draft_terms.product_scope', 'selected')
            ->where('campaigns.data.0.draft_terms.product_ids', [$this->product->id, $second->id])
            ->has('campaigns.data.0.selected_products', 2));
        $publish = $this->action($voucher);
        $this->post($base.'/vouchers/'.$voucher->id.'/actions', $publish)->assertSessionHas('success');
        $this->post($base.'/vouchers/'.$voucher->id.'/actions', $publish)->assertSessionHas('success');
        $revision = $voucher->fresh()->currentRevision;
        $this->assertSame('selected', $revision->product_scope);
        $this->assertSame([$this->product->id, $second->id], $revision->product_ids);
        $this->assertDatabaseCount('voucher_revisions', 1);
        $this->assertDatabaseCount('voucher_campaign_actions', 2);
        $this->get($base.'/vouchers/'.$voucher->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/VoucherHistory')->where('campaign.published_terms.product_ids', [$this->product->id, $second->id]));
        $before = $revision->getRawOriginal();
        $this->patch($base.'/vouchers/'.$voucher->id, $this->input(['product_scope' => 'all', 'product_ids' => [],
            'source_version' => $voucher->fresh()->campaign_version]))->assertSessionHas('success');
        $this->assertSame($before, $revision->fresh()->getRawOriginal());
        $this->post($base.'/vouchers/'.$voucher->id.'/actions', $this->action($voucher))->assertSessionHas('success');
        $this->assertSame('all', $voucher->fresh()->currentRevision->product_scope);
        $this->assertSame($before, $revision->fresh()->getRawOriginal());
        $this->expectException(LogicException::class);
        $revision->update(['product_ids' => []]);
    }

    public static function invalidSelections(): array
    {
        return [['product_scope', null], ['product_scope', ''], ['product_scope', 'other'], ['product_scope', ['selected']],
            ['product_ids', null], ['product_ids', '1'], ['product_ids', []], ['product_ids', ['']], ['product_ids', [0]],
            ['product_ids', [-1]], ['product_ids', [true]], ['product_ids', [1.5]], ['product_ids', ['1e2']],
            ['product_ids', ['01']], ['product_ids', [2147483648]], ['product_ids', [1, '1']],
            ['product_ids', ['one' => 1]], ['product_ids', array_fill(0, 101, 1)]];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_product_selection_never_saves_partial_campaigns(string $field, mixed $value): void
    {
        $response = $this->postJson('/seller/vouchers', $this->input([$field => $value]))->assertUnprocessable();
        $this->assertTrue(collect(array_keys($response->json('errors')))->contains(fn ($key) => $key === $field || str_starts_with($key, $field.'.')));
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseCount('voucher_campaign_actions', 0);
    }

    public function test_foreign_draft_archived_and_restricted_products_cannot_be_selected(): void
    {
        $foreign = Product::factory()->create();
        foreach ([$foreign, $this->product(['status' => 'draft']), $this->product(['status' => 'archived']),
            $this->product(['compliance_restricted' => true])] as $invalid) {
            $this->postJson('/seller/vouchers', $this->input(['product_ids' => [$invalid->id]]))->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        }
        $this->postJson('/seller/vouchers', $this->input(['product_scope' => 'all']))->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_one_hundred_distinct_products_are_accepted_and_publication_rechecks_current_eligibility(): void
    {
        $ids = [$this->product->id];
        for ($i = 1; $i < 100; $i++) {
            $ids[] = $this->product()->id;
        }
        $this->post('/seller/vouchers', $this->input(['product_ids' => $ids]))->assertSessionHas('success');
        $voucher = Voucher::sole();
        $this->product->update(['status' => 'draft']);
        $publish = $this->action($voucher);
        $this->postJson('/seller/vouchers/'.$voucher->id.'/actions', $publish)->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        $this->assertDatabaseCount('voucher_revisions', 0);
        $this->assertDatabaseCount('voucher_campaign_actions', 1);
        $this->product->update(['status' => 'active']);
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $publish)->assertSessionHas('success');
        $this->assertCount(100, $voucher->fresh()->currentRevision->product_ids);
    }

    #[DataProvider('portals')]
    public function test_product_picker_is_owned_bounded_searchable_and_never_binds_products_as_a_voucher(string $base): void
    {
        $this->product->update(['name' => 'Alpha Studio Bag']);
        for ($i = 0; $i < 13; $i++) {
            $this->product(['name' => 'Studio Bag '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $this->product(['status' => 'draft']);
        $this->product(['compliance_restricted' => true]);
        Product::factory()->create(['name' => 'Foreign Studio Bag']);
        $first = $this->getJson($base.'/vouchers/products?search=Studio')->assertOk()->assertJsonPath('products.total', 14)
            ->assertJsonCount(12, 'products.data')->assertHeader('Cache-Control', 'no-store, private');
        $second = $this->getJson($base.'/vouchers/products?search=Studio&page=2')->assertOk()->assertJsonCount(2, 'products.data');
        $this->assertEmpty(array_intersect(array_column($first->json('products.data'), 'id'), array_column($second->json('products.data'), 'id')));
        $this->getJson($base.'/vouchers/products?search=Alpha')->assertOk()->assertJsonPath('products.data.0.id', $this->product->id);
        $this->getJson($base.'/vouchers/products?search='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson($base.'/vouchers/products?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->shop->user->update(['status' => 'suspended']);
        $this->getJson($base.'/vouchers/products')->assertForbidden();
    }

    public function test_platform_scope_and_other_roles_cannot_target_or_browse_seller_products(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson('/admin/vouchers', $this->input())
            ->assertUnprocessable()->assertJsonValidationErrors('product_scope');
        foreach (['buyer', 'courier', 'logistics', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/seller/vouchers/products')->assertForbidden();
        }
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_an_older_edit_payload_cannot_silently_change_a_published_selection_to_the_whole_shop(): void
    {
        $this->post('/seller/vouchers', $this->input())->assertSessionHas('success');
        $voucher = Voucher::sole();
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action($voucher))->assertSessionHas('success');
        $input = $this->input(['source_version' => $voucher->fresh()->campaign_version]);
        unset($input['product_scope'], $input['product_ids']);
        $this->patch('/seller/vouchers/'.$voucher->id, $input)->assertSessionHas('success');
        $this->assertSame('selected', $voucher->fresh()->draft->terms['product_scope']);
        $this->assertSame([$this->product->id], $voucher->fresh()->draft->terms['product_ids']);
    }
}
