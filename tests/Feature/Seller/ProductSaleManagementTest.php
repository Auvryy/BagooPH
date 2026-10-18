<?php

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductSaleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 500, 'stock' => 10]);
        $this->actingAs($shop->user);

        return [$shop, $product];
    }

    private function payload(array $changes = []): array
    {
        return [...['kind' => 'flash', 'name' => 'Weekend Bagoo sale', 'price' => '299.00', 'quantity' => 5,
            'starts_at' => now()->addHour()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHours(2)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')], ...$changes];
    }

    public function test_seller_creates_owned_slots_rejects_overlap_and_can_have_a_separate_store_sale(): void
    {
        [, $product] = $this->fixture();
        $this->post(route('seller.products.sales.store', $product), $this->payload())->assertSessionHasNoErrors();
        $sale = ProductSale::sole();
        $this->assertSame(now()->addHour()->toDateTimeString(), $sale->starts_at->toDateTimeString());
        $this->assertSame(0, $sale->used_quantity);
        $this->post(route('seller.products.sales.store', $product), $this->payload())->assertSessionHasErrors('starts_at');
        $this->post(route('seller.products.sales.store', $product), $this->payload(['kind' => 'store', 'quantity' => null, 'price' => '399.00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_sales', 2);
        $this->post(route('seller.products.sales.store', $product), $this->payload([
            'starts_at' => now()->addHours(2)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHours(3)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_sales', 3);
    }

    public function test_running_store_price_can_change_but_running_flash_terms_and_schedule_are_retained(): void
    {
        [, $product] = $this->fixture();
        $this->post(route('seller.products.sales.store', $product), $this->payload(['kind' => 'store', 'quantity' => null]))->assertSessionHasNoErrors();
        $store = ProductSale::sole();
        $this->travel(65)->minutes();
        $this->put(route('seller.products.sales.update', [$product, $store]), ['price' => '250.50'])->assertSessionHasNoErrors();
        $this->assertSame('250.50', $store->fresh()->price);
        $this->put(route('seller.products.sales.update', [$product, $store]), ['ends_at' => now()->addHour()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')])->assertSessionHasErrors('sale');
        $this->post(route('seller.products.sales.store', $product), $this->payload(['starts_at' => now()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')]))->assertSessionHasNoErrors();
        $flash = ProductSale::where('kind', 'flash')->firstOrFail();
        foreach ([['price' => 100], ['quantity' => 10], ['kind' => 'store']] as $change) {
            $this->put(route('seller.products.sales.update', [$product, $flash]), $change)->assertSessionHasErrors();
        }
        $this->post(route('seller.products.sales.end', [$product, $flash]))->assertSessionHas('success');
        $ended = $flash->fresh()->ended_at;
        $this->travel(1)->minutes();
        $this->post(route('seller.products.sales.end', [$product, $flash]))->assertSessionHas('success');
        $this->assertTrue($ended->equalTo($flash->fresh()->ended_at));
        $this->put(route('seller.products.sales.update', [$product, $flash]), ['price' => 50])->assertSessionHasErrors('sale');
    }

    public static function invalidValues(): array
    {
        return [
            [['price' => 500], 'price'], [['price' => '0.001'], 'price'], [['price' => '0'], 'price'],
            [['quantity' => 0], 'quantity'], [['quantity' => 1.5], 'quantity'], [['quantity' => null], 'quantity'],
            [['quantity' => 1000001], 'quantity'], [['name' => ' '], 'name'], [['name' => str_repeat('a', 101)], 'name'],
            [['starts_at' => '2026-02-31T10:00'], 'starts_at'], [['kind' => 'unsupported'], 'kind'], [['used_quantity' => 1], 'used_quantity'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_terms_do_not_create_a_sale(array $changes, string $field): void
    {
        [, $product] = $this->fixture();
        $this->post(route('seller.products.sales.store', $product), $this->payload($changes))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('product_sales', 0);
    }

    public function test_owner_authority_and_active_product_are_required_and_other_sales_cannot_be_edited(): void
    {
        [$shop, $product] = $this->fixture();
        $otherShop = Shop::factory()->approved()->create();
        $otherProduct = Product::factory()->create(['shop_id' => $otherShop->id, 'price' => 500]);
        $this->post(route('seller.products.sales.store', $otherProduct), $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('seller.products.sales.store', $product), $this->payload())->assertForbidden();
        $this->actingAs($otherShop->user)->post(route('seller.products.sales.store', $otherProduct), $this->payload())->assertSessionHasNoErrors();
        $foreignSale = ProductSale::sole();
        $this->actingAs($shop->user)->put(route('seller.products.sales.update', [$product, $foreignSale]), ['price' => 100])->assertNotFound();
        $product->update(['status' => 'draft']);
        $this->post(route('seller.products.sales.store', $product), $this->payload())->assertSessionHasErrors('sale');
        $this->assertDatabaseCount('product_sales', 1);
    }

    public function test_past_and_overlong_schedules_are_rejected(): void
    {
        [, $product] = $this->fixture();
        $this->post(route('seller.products.sales.store', $product), $this->payload(['starts_at' => now()->subMinutes(2)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')]))->assertSessionHasErrors('starts_at');
        $this->post(route('seller.products.sales.store', $product), $this->payload(['ends_at' => now()->addDays(35)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')]))->assertSessionHasErrors('ends_at');
        $this->assertDatabaseCount('product_sales', 0);
    }
}
