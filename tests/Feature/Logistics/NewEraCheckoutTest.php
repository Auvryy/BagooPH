<?php

namespace Tests\Feature\Logistics;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutSubmission;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Logistics\LogisticsCheckoutService;
use App\Services\Orders\CheckoutQuoteService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class NewEraCheckoutTest extends TestCase
{
    use InteractsWithCheckoutSubmission, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function purchase(): array
    {
        $buyer = User::where('email', 'buyer@bagoo.test')->sole();
        $product = Product::whereHas('shop', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', 'seller@bagoo.test')))->firstOrFail();
        $product->update(['stock' => 20, 'weight_kg' => '1.00', 'length_cm' => '70.00', 'width_cm' => '50.00', 'height_cm' => '10.00']);
        $company = LogisticsHub::where('code', 'BH-LBN-01')->sole()->company;
        $company->update(['additional_kg_cents' => 2000]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $product->price]);
        $input = ['checkout_token' => $this->checkoutToken($buyer, $cart), 'item_ids' => [$line->id],
            'recipient_name' => $buyer->name, 'recipient_phone' => $buyer->phone, 'shipping_address' => '100 Test Recipient Road',
            'shipping_province' => 'Laguna', 'shipping_city' => 'Santa Cruz', 'shipping_postal_code' => '4009', 'destination_barangay' => 'Poblacion III',
            'delivery_type' => 'doorstep', 'payment_method' => 'cod', 'logistics_company_id' => $company->id];

        return [$buyer, $product, $company, $cart, $line, $input];
    }

    public function test_selected_tariff_volumetric_weight_and_route_are_frozen_and_client_fees_cannot_replace_them(): void
    {
        [$buyer, $product, $company, , , $input] = $this->purchase();
        $this->actingAs($buyer)->post(route('checkout.store'), [...$input, 'shipping_fee' => '0.01', 'total_amount' => '0.01', 'logistics_flow_version' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $order = Order::sole();
        $this->assertSame(2, $order->logistics_flow_version);
        $this->assertSame('240.00', $order->shipping_fee);
        $this->assertSame(1000, $order->shipping_quote['actual_weight_grams']);
        $this->assertSame(10000, $order->shipping_quote['volumetric_weight_grams']);
        $this->assertSame(10000, $order->shipping_quote['billable_weight_grams']);
        $this->assertSame($company->id, $order->delivery->logistics_company_id);
        $this->assertSame(['BH-LBN-01', 'MH-LAG-01', 'BH-SCZ-01'], array_column($order->shipping_quote['route'], 'code'));
        $before = [$order->getAttributes(), $order->delivery->getAttributes()];
        $company->update(['base_shipping_cents' => 12000, 'additional_kg_cents' => 3000, 'tariff_version' => 2]);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()]);
        $this->assertSame(19, $product->fresh()->stock);
    }

    public function test_quote_and_best_voucher_endpoints_use_the_selected_weight_tariff_and_only_list_serving_companies(): void
    {
        [$buyer, , $company, $cart, , $input] = $this->purchase();
        $response = $this->actingAs($buyer)->postJson(route('buyer.vouchers.apply'), $input)->assertOk();
        $response->assertJsonPath('quote.shipping_fee', 240)->assertJsonPath('quote.shops.0.shipping_quote.billable_weight_grams', 10000)
            ->assertJsonPath('logisticsCompanies.0.id', $company->id);
        $this->postJson(route('buyer.vouchers.best'), $input)->assertOk()->assertJsonPath('quote.shipping_fee', 240);
        $this->postJson(route('buyer.vouchers.choices'), $input)->assertOk();
        $items = app(CheckoutQuoteService::class)->selectedItems($buyer, $cart->items()->pluck('id')->all());
        $this->assertCount(0, app(LogisticsCheckoutService::class)->choices($items, [...$input, 'shipping_city' => 'Pagsanjan', 'shipping_postal_code' => '4008']));
    }

    public function test_wrong_company_or_an_ambiguous_postal_route_leaves_stock_and_bag_unchanged(): void
    {
        [$buyer, $product, $company, , $line, $input] = $this->purchase();
        $this->actingAs($buyer)->post(route('checkout.store'), [...$input, 'logistics_company_id' => 999999])->assertSessionHas('error');
        $duplicate = LogisticsHub::where('code', 'BH-SCZ-01')->sole()->replicate();
        $duplicate->fill(['code' => 'QA-DUP-COVERAGE', 'name' => 'Ambiguous Coverage Fixture'])->save();
        $input['checkout_token'] = $this->checkoutToken($buyer, $line->cart);
        $this->post(route('checkout.store'), $input)->assertSessionHas('error');
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertNotNull($line->fresh());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CheckoutSubmission::count());
    }

    public function test_tariff_or_hub_coverage_edits_require_a_fresh_checkout_token_before_stock_is_reserved(): void
    {
        [$buyer, $product, $company, , , $input] = $this->purchase();
        $company->update(['base_shipping_cents' => 8000, 'tariff_version' => 2]);
        $this->actingAs($buyer)->post(route('checkout.store'), $input)->assertSessionHasErrors('checkout_token');
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(0, Order::count());
    }

    public function test_configured_surcharges_reject_missing_measurements_instead_of_undercharging_a_bulky_product(): void
    {
        [$buyer, $product, , , , $input] = $this->purchase();
        $product->update(['length_cm' => null]);
        $this->actingAs($buyer)->postJson(route('buyer.vouchers.apply'), $input)->assertUnprocessable()->assertJsonPath('success', false);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(0, Order::count());
    }
}
