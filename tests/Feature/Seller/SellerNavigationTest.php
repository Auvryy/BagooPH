<?php

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\ProductModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->get('/')->assertOk();
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? '']);
    }

    private function partial(string $component, string $props): void
    {
        $this->withHeaders(['X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $props]);
    }

    public static function sellerPortals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    #[DataProvider('sellerPortals')]
    public function test_owned_lists_read_current_stock_and_reject_foreign_shop_and_revoked_access(string $portal): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 3]);
        $foreign = Product::factory()->create();
        $this->actingAs($seller);
        $this->partial('Seller/Products', 'products,filters,shop,categories,auth,flash,errors');
        $this->get($portal.'/products')->assertOk()->assertJsonPath('props.products.data.0.stock', 3);
        $product->update(['stock' => 0]);
        $this->get($portal.'/products?stock=out_of_stock')->assertOk()->assertJsonPath('props.products.total', 1)
            ->assertJsonPath('props.products.data.0.id', $product->id)->assertJsonPath('props.products.data.0.stock', 0);
        $this->get($portal.'/products?shop_id='.$foreign->shop_id)->assertForbidden();
        $seller->update(['status' => 'suspended']);
        $this->withHeader('Accept', 'application/json');
        $this->get($portal.'/products')->assertForbidden();
    }

    #[DataProvider('sellerPortals')]
    public function test_moderation_feedback_uses_one_lookup_for_the_page_and_keeps_private_history_out(string $portal): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $products = Product::factory()->count(6)->create(['shop_id' => $shop->id]);
        $admin = User::factory()->admin()->create();
        $moderation = app(ProductModerationService::class);
        foreach ($products as $product) {
            $moderation->decide($admin, $product, ['action' => 'remove', 'reason' => 'Correct the description.',
                'source_token' => app(AccountRestrictionService::class)->token($moderation->state($product->fresh()))]);
        }
        $moderation->decide($admin, $products->first(), ['action' => 'reinstate', 'reason' => 'The description has been corrected.',
            'source_token' => app(AccountRestrictionService::class)->token($moderation->state($products->first()->fresh()))]);
        $this->actingAs($seller);
        DB::enableQueryLog();
        $response = $this->get($portal.'/products')->assertOk()->assertJsonCount(6, 'props.products.data');
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "product_moderation_decisions"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        foreach ($response->json('props.products.data') as $product) {
            $this->assertSame($product['id'] === $products->first()->id ? 'The description has been corrected.' : 'Correct the description.', $product['compliance_feedback']['reason']);
            $this->assertArrayNotHasKey('moderation_decisions', $product);
        }
    }
}
