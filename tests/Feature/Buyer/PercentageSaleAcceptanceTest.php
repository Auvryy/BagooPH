<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\InventoryService;
use App\Services\Commerce\SalePriceService;
use App\Services\Orders\CheckoutQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class PercentageSaleAcceptanceTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    private Shop $shop;

    private Product $product;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        $this->shop = Shop::factory()->approved()->create(['city' => 'Taguig City']);
        $this->buyer = User::factory()->buyer()->create();
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id,
            'price' => '500.00', 'stock' => 10, 'variants' => ['pricing_mode' => 'independent', 'colors' => [],
                'sizes' => [['name' => 'Compact', 'stock' => 6], ['name' => 'Large', 'stock' => 4]], 'combinations' => [
                    ['color' => null, 'size' => 'Compact', 'stock' => 6, 'price' => '199.95', 'sku' => 'BGO-COMPACT'],
                    ['color' => null, 'size' => 'Large', 'stock' => 4, 'price' => '450.00', 'sku' => 'BGO-LARGE'],
                ]]]);
        $this->createCheckoutNetwork($this->shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($this->shop->user);
    }

    private function terms(array $changes = []): array
    {
        return [...['kind' => 'store', 'name' => 'Studio weekend sale', 'discount_type' => 'percentage', 'percentage' => '15',
            'starts_at' => now()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHour()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')], ...$changes];
    }

    private function schedule(array $changes = []): ProductSale
    {
        $this->actingAs($this->shop->user)->post(route('seller.products.sales.store', $this->product), $this->terms($changes))->assertSessionHasNoErrors();

        return ProductSale::latest('id')->firstOrFail();
    }

    private function bag(int $quantity = 1, string $size = 'Compact'): Cart
    {
        $this->actingAs($this->buyer)->post(route('cart.store'), ['product_id' => $this->product->id, 'quantity' => $quantity, 'size' => $size])->assertSessionHasNoErrors();

        return Cart::where('user_id', $this->buyer->id)->firstOrFail();
    }

    private function input(Cart $cart): array
    {
        return ['checkout_token' => $this->checkoutToken($this->buyer), 'item_ids' => $cart->items()->pluck('id')->all(),
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '100 Main Street',
            'shipping_city' => 'Taguig City', 'shipping_province' => 'Metro Manila', 'destination_barangay' => 'Fort Bonifacio',
            'shipping_postal_code' => '1634', 'payment_method' => 'cod'];
    }

    public static function percentages(): array
    {
        return [['0', false], ['1', true], ['99', true], ['100', false], ['101', false], ['-5', false], ['10.5', false], ['', false], ['1e1', false], ['text', false]];
    }

    #[DataProvider('percentages')]
    public function test_only_whole_percentages_from_one_to_ninety_nine_can_be_authored(string $value, bool $accepted): void
    {
        $response = $this->post(route('seller.products.sales.store', $this->product), $this->terms(['percentage' => $value]));
        if ($accepted) {
            $response->assertSessionHasNoErrors();
            $this->assertSame((int) $value, ProductSale::sole()->percentage);
        } else {
            $response->assertSessionHasErrors('percentage');
            $this->assertDatabaseCount('product_sales', 0);
        }
    }

    public function test_percentage_prices_start_and_expire_across_detail_search_and_bag_quotes(): void
    {
        $this->schedule(['percentage' => '25', 'starts_at' => now()->addMinute()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')]);
        $prices = app(SalePriceService::class);
        $this->assertSame('199.95', $prices->range($this->product->fresh())['min']);
        $this->travel(1)->minutes();
        $this->assertSame('149.96', $prices->range($this->product->fresh())['min']);
        $this->get('/product/'.$this->product->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.price_range.min', '149.96')->where('product.price_range.max', '337.50')
            ->where('product.sale_offer.active.percentage', 25));
        $this->get('/products?sort=price_asc&min_price=149&max_price=150')->assertOk()->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $this->product->id));
        $cart = $this->bag();
        $quote = app(CheckoutQuoteService::class)->quote($cart->items()->with('product.shop')->get());
        $this->assertSame(149.96, $quote['subtotal']);
        $this->travel(59)->minutes();
        $this->assertSame('199.95', $prices->range($this->product->fresh())['min']);
        $this->assertNull($this->product->fresh()->sale_offer['active']);
        $this->assertSame(199.95, app(CheckoutQuoteService::class)->quote($cart->items()->with('product.shop')->get())['subtotal']);
    }

    public function test_discount_uses_full_surcharge_prices_and_full_independent_prices_with_exact_rounding(): void
    {
        $this->schedule();
        $inventory = app(InventoryService::class);
        $this->assertSame('169.96', $inventory->unitPrice($this->product->fresh(), null, 'Compact'));
        $this->assertSame('382.50', $inventory->unitPrice($this->product->fresh(), null, 'Large'));
        $legacy = Product::factory()->create(['shop_id' => $this->shop->id, 'price' => '100.00', 'stock' => 10,
            'variants' => ['sizes' => [['name' => 'Large', 'stock' => 10, 'extra_price' => '99.95']]]]);
        $legacy->sales()->create(['kind' => 'store', 'name' => 'Legacy full price sale', 'price' => '85.00', 'discount_type' => 'percentage', 'percentage' => 15, 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $this->assertSame('169.96', $inventory->unitPrice($legacy->fresh(), null, 'Large'));
        $this->assertSame(1, app(SalePriceService::class)->discountedCents(100, 99));
        $legacy->sales()->update(['ended_at' => now()]);
        $legacy->sales()->create(['kind' => 'store', 'name' => 'Legacy fixed sale', 'price' => '80.00', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $this->assertSame('179.95', $inventory->unitPrice($legacy->fresh(), null, 'Large'));
    }

    public function test_running_percentage_edit_requires_new_checkout_quote_and_keeps_original_order_values(): void
    {
        $sale = $this->schedule();
        $cart = $this->bag();
        $old = $this->input($cart);
        $this->actingAs($this->shop->user)->put(route('seller.products.sales.update', [$this->product, $sale]), ['percentage' => '20'])->assertSessionHasNoErrors();
        $this->actingAs($this->buyer)->post(route('checkout.store'), $old)->assertSessionHasErrors('checkout_token');
        $fresh = $this->input($cart);
        $this->post(route('checkout.store'), $fresh)->assertSessionHasNoErrors();
        $order = Order::sole();
        $item = $order->items()->sole();
        $this->assertSame('159.96', $item->unit_price);
        $this->assertSame('BGO-COMPACT', $item->sku_snapshot);
        $this->assertSame(9, $this->product->fresh()->stock);
        $this->actingAs($this->shop->user)->put(route('seller.products.sales.update', [$this->product, $sale]), ['percentage' => '50'])->assertSessionHasNoErrors();
        $variants = $this->product->fresh()->variants;
        $variants['combinations'][0]['sku'] = 'BGO-REVISED';
        $variants['combinations'][0]['price'] = '300.00';
        $this->product->fresh()->update(['variants' => $variants]);
        $this->assertSame('150.00', app(InventoryService::class)->unitPrice($this->product->fresh(), null, 'Compact'));
        $this->assertSame('159.96', $item->fresh()->unit_price);
        $this->assertSame('BGO-COMPACT', $item->fresh()->sku_snapshot);
        $this->actingAs($this->buyer)->post(route('checkout.store'), $fresh)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(9, $this->product->fresh()->stock);
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertSame(6, $this->product->fresh()->variants['combinations'][0]['stock']);
    }

    public function test_flash_quota_limits_full_stock_and_running_percentage_terms_cannot_change(): void
    {
        $this->post(route('seller.products.sales.store', $this->product), $this->terms(['kind' => 'flash', 'quantity' => 11]))->assertSessionHasErrors('quantity');
        $sale = $this->schedule(['kind' => 'flash', 'quantity' => 10]);
        $this->assertSame(10, $this->product->fresh()->stock);
        foreach ([['percentage' => '20'], ['discount_type' => 'fixed', 'price' => '400.00'], ['quantity' => 9]] as $change) {
            $this->put(route('seller.products.sales.update', [$this->product, $sale]), $change)->assertSessionHasErrors('sale');
        }
        $this->assertSame(15, $sale->fresh()->percentage);
    }

    public function test_scheduled_flash_quota_edit_accounts_for_used_units_without_creating_stock(): void
    {
        $sale = $this->schedule(['kind' => 'flash', 'quantity' => 10, 'starts_at' => now()->addHour()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'), 'ends_at' => now()->addHours(2)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i')]);
        $sale->update(['used_quantity' => 4]);
        $this->product->update(['stock' => 6]);
        $this->put(route('seller.products.sales.update', [$this->product, $sale]), ['quantity' => 11])->assertSessionHasErrors('quantity');
        $this->put(route('seller.products.sales.update', [$this->product, $sale]), ['quantity' => 3])->assertSessionHasErrors('quantity');
        $this->put(route('seller.products.sales.update', [$this->product, $sale]), ['quantity' => 10])->assertSessionHasNoErrors();
        $this->assertSame(6, $this->product->fresh()->stock);
        $this->assertSame(4, $sale->fresh()->used_quantity);
    }

    public function test_percentage_flash_and_store_units_split_and_cancel_once_with_original_snapshots(): void
    {
        $this->schedule(['percentage' => '20']);
        $flash = $this->schedule(['kind' => 'flash', 'percentage' => '50', 'quantity' => 1]);
        $cart = $this->bag(3);
        $input = $this->input($cart);
        $this->post(route('checkout.store'), $input)->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame('419.90', $order->subtotal);
        $this->assertSame(['99.98', '159.96'], $order->items()->orderBy('id')->pluck('unit_price')->all());
        $this->assertSame(['BGO-COMPACT', 'BGO-COMPACT'], $order->items()->orderBy('id')->pluck('sku_snapshot')->all());
        $this->assertSame(1, $flash->fresh()->used_quantity);
        $this->post(route('checkout.store'), $input)->assertSessionHasNoErrors();
        $this->assertSame(7, $this->product->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertSame(0, $flash->fresh()->used_quantity);
        $this->assertSame('419.90', $order->fresh()->subtotal);
    }

    public function test_fixed_sales_and_later_variant_edits_cannot_make_an_option_free(): void
    {
        $this->post(route('seller.products.sales.store', $this->product), $this->terms(['discount_type' => 'fixed', 'price' => '250.00']))->assertSessionHasErrors('price');
        $this->schedule(['discount_type' => 'fixed', 'price' => '450.00']);
        $variants = $this->product->variants;
        $variants['combinations'][0]['price'] = '40.00';
        try {
            $this->product->update(['variants' => $variants]);
            $this->fail('An existing fixed reduction cannot produce a free option.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('price', $exception->errors());
        }
        $this->assertSame('199.95', $this->product->fresh()->variants['combinations'][0]['price']);
    }

    public function test_running_percentage_flash_name_edit_keeps_authored_terms_after_a_base_price_edit(): void
    {
        $sale = $this->schedule(['kind' => 'flash', 'quantity' => 2]);
        $this->product->update(['price' => '600.00']);
        $this->put(route('seller.products.sales.update', [$this->product, $sale]), ['name' => 'Studio flash offer'])->assertSessionHasNoErrors();
        $this->assertSame(15, $sale->fresh()->percentage);
        $this->assertSame(2, $sale->fresh()->quantity);
        $this->assertSame('169.96', app(InventoryService::class)->unitPrice($this->product->fresh(), null, 'Compact'));
    }
}
