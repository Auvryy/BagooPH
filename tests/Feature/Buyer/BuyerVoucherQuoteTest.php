<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Finance\CodMoney;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class BuyerVoucherQuoteTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use RefreshDatabase;

    private function bag(): array
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $this->actingAs($buyer);

        return [$buyer, $cart];
    }

    private function line(Cart $cart, string $price, int $quantity = 1, ?Shop $shop = null): CartItem
    {
        $shop ??= Shop::factory()->approved()->create(['city' => 'Manila']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => $price, 'stock' => 100]);

        return $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '1.00']);
    }

    private function voucher(array $overrides = []): Voucher
    {
        $factory = Voucher::factory();
        if (($overrides['usage_limit'] ?? 1) > 0 && ($overrides['max_discount'] ?? 1) > 0
            && ($overrides['discount_value'] ?? 20) > 0 && ! (($overrides['discount_type'] ?? '') === 'percent' && ($overrides['discount_value'] ?? 0) > 100)) {
            $factory = $factory->funded();
        }

        return $factory->create(array_replace(['code' => 'BAGOO_SAVE', 'name' => 'Bagoo savings', 'discount_type' => 'fixed',
            'discount_value' => '20.00', 'min_spend' => '0.00', 'max_discount' => null, 'usage_limit' => null,
            'expires_at' => null, 'is_active' => true, 'used_count' => 0], $overrides));
    }

    private function preview(array $ids, array $input = [])
    {
        return $this->postJson(route('buyer.vouchers.apply'), array_replace(['item_ids' => $ids, 'delivery_type' => 'doorstep'], $input));
    }

    public function test_bag_checkout_and_preview_use_current_prices_and_per_shop_shipping(): void
    {
        [, $cart] = $this->bag();
        $first = $this->line($cart, '800.00');
        $second = $this->line($cart, '800.00');
        $ids = [$first->id, $second->id];
        $quote = $this->preview($ids)->assertOk()->assertJsonPath('quote.subtotal', 1600)
            ->assertJsonPath('quote.shipping_fee', 100)->assertJsonPath('quote.total', 1700)
            ->assertJsonPath('quote.items.0.unit_price', 800)->assertJsonPath('quote.items.0.subtotal', 800)->json('quote');
        $this->getJson(route('cart.index', ['item_ids' => $ids]))->assertOk()->assertJsonPath('quote', $quote);
        $this->get(route('checkout.index', ['items' => implode(',', $ids)]))->assertInertia(fn (Assert $page) => $page
            ->component('Checkout/Index')->where('quote', $quote)->where('total', 1700)->where('shippingFee', 100));
        $this->get(route('buyer.cart'))->assertInertia(fn (Assert $page) => $page->where('items.0.unit_price', '800.00'));
        $this->assertSame('1.00', $first->fresh()->unit_price);
    }

    public function test_shop_minimum_uses_only_its_selected_items_and_explains_shortfall(): void
    {
        [, $cart] = $this->bag();
        $first = $this->line($cart, '200.00');
        $other = $this->line($cart, '1000.00');
        $unselected = $this->line($cart, '900.00', shop: $first->product->shop);
        $this->voucher(['shop_id' => $first->product->shop_id, 'min_spend' => '500.00']);
        $this->preview([$first->id, $other->id], ['code' => 'BAGOO_SAVE'])->assertUnprocessable()
            ->assertJsonPath('quote.voucher', null)->assertJsonPath('quote.discount', 0)
            ->assertJsonPath('message', 'Add ₱300.00 from this shop to use this voucher.');
        $this->preview([$first->id, $unselected->id], ['code' => 'BAGOO_SAVE'])->assertOk()->assertJsonPath('quote.discount', 20);
    }

    public function test_unselected_products_cannot_qualify_a_platform_offer(): void
    {
        [, $cart] = $this->bag();
        $selected = $this->line($cart, '200.00');
        $this->line($cart, '900.00');
        $this->voucher(['min_spend' => '500.00']);
        $this->preview([$selected->id], ['code' => 'BAGOO_SAVE'])->assertUnprocessable()
            ->assertJsonPath('message', 'Add ₱300.00 in selected products to use this voucher.');
    }

    #[DataProvider('unavailableOffers')]
    public function test_rejected_offers_leave_the_undiscounted_quote(array $changes, string $reason): void
    {
        [, $cart] = $this->bag();
        $line = $this->line($cart, '500.00');
        $expired = $changes['expired'] ?? false;
        unset($changes['expired']);
        $this->voucher($changes + ($expired ? ['expires_at' => now()->subMinute()] : []));
        $this->preview([$line->id], ['code' => 'BAGOO_SAVE'])->assertUnprocessable()->assertJsonPath('message', $reason)
            ->assertJsonPath('quote.voucher', null)->assertJsonPath('quote.discount', 0)->assertJsonPath('quote.total', 550);
    }

    public static function unavailableOffers(): array
    {
        return [
            'paused' => [['is_active' => false], 'This voucher is paused.'],
            'expired' => [['expired' => true], 'This voucher has expired.'],
            'exhausted' => [['usage_limit' => 1, 'used_count' => 1], 'This voucher has reached its usage limit.'],
            'legacy zero quota' => [['usage_limit' => 0], 'This older voucher needs its funding reviewed before use.'],
            'legacy invalid percentage' => [['discount_type' => 'percent', 'discount_value' => 120], 'This older voucher needs its funding reviewed before use.'],
            'legacy zero cap' => [['max_discount' => 0], 'This older voucher needs its funding reviewed before use.'],
        ];
    }

    public function test_unknown_or_unrelated_code_cannot_appear_applied(): void
    {
        [, $cart] = $this->bag();
        $line = $this->line($cart, '500.00');
        $other = Shop::factory()->approved()->create();
        $this->voucher(['shop_id' => $other->id]);
        $this->preview([$line->id], ['code' => 'BAGOO_SAVE'])->assertUnprocessable()
            ->assertJsonPath('message', 'This voucher is not for the selected shop items.')->assertJsonCount(0, 'availableVouchers');
        $this->preview([$line->id], ['code' => 'UNKNOWN'])->assertUnprocessable()->assertJsonPath('quote.voucher', null);
    }

    public function test_shipping_discount_uses_actual_fees_and_leaves_the_unpaid_cap_visible(): void
    {
        [, $cart] = $this->bag();
        $free = $this->line($cart, '2000.00');
        $first = $this->line($cart, '800.00');
        $second = $this->line($cart, '100.00');
        $this->voucher(['discount_type' => 'free_shipping', 'discount_value' => 75, 'max_discount' => 30]);
        $quote = $this->preview([$free->id, $first->id, $second->id], ['code' => 'BAGOO_SAVE'])->assertOk()
            ->assertJsonPath('quote.shipping_fee', 100)->assertJsonPath('quote.shipping_discount', 30)
            ->assertJsonPath('quote.total', 2970)->json('quote');
        $this->assertEquals([0, 15, 15], array_column($quote['shops'], 'discount'));
        $this->assertEquals([2000, 835, 135], array_column($quote['shops'], 'total'));
    }

    public function test_quantity_and_self_pickup_changes_remove_a_now_ineligible_shipping_offer(): void
    {
        [, $cart] = $this->bag();
        $line = $this->line($cart, '800.00');
        $this->voucher(['discount_type' => 'free_shipping', 'discount_value' => 30]);
        $this->preview([$line->id], ['code' => 'BAGOO_SAVE'])->assertOk()->assertJsonPath('quote.total', 820);
        $this->preview([$line->id], ['code' => 'BAGOO_SAVE', 'delivery_type' => 'hub_self_pickup'])->assertUnprocessable()
            ->assertJsonPath('quote.discount', 0)->assertJsonPath('quote.total', 800)
            ->assertJsonPath('message', 'There is no shipping fee for this voucher to reduce.');
        $line->update(['quantity' => 2]);
        $this->preview([$line->id], ['code' => 'BAGOO_SAVE'])->assertUnprocessable()->assertJsonPath('quote.total', 1600);
    }

    public function test_percent_and_fixed_caps_never_exceed_eligible_merchandise(): void
    {
        [, $cart] = $this->bag();
        $line = $this->line($cart, '200.00');
        $voucher = $this->voucher(['discount_type' => 'percent', 'discount_value' => 25, 'max_discount' => 30]);
        $this->preview([$line->id], ['code' => 'BAGOO_SAVE'])->assertOk()->assertJsonPath('quote.discount', 30)->assertJsonPath('quote.total', 220);
        $this->voucher(['code' => 'BAGOO_FIXED', 'discount_type' => 'fixed', 'discount_value' => 500, 'max_discount' => null]);
        $this->preview([$line->id], ['code' => 'BAGOO_FIXED'])->assertOk()->assertJsonPath('quote.discount', 200)->assertJsonPath('quote.total', 50);
    }

    public function test_allocation_is_deterministic_to_the_cent_even_for_large_prices(): void
    {
        [$buyer, $cart] = $this->bag();
        $first = $this->line($cart, '999999999.99');
        $second = $this->line($cart, '999999999.99');
        $voucher = $this->voucher(['discount_value' => '99999999.99']);
        $quotes = app(CheckoutQuoteService::class);
        $quote = $quotes->quote($quotes->selectedItems($buyer, [$second->id, $first->id]), 'doorstep', $voucher);
        $this->assertEquals([50000000, 49999999.99], array_column($quote['shops'], 'discount'));
        $voucher = $this->voucher(['code' => 'BAGOO_CENT', 'discount_value' => '0.01']);
        $quote = $quotes->quote($quotes->selectedItems($buyer, [$second->id, $first->id]), 'doorstep', $voucher);
        $this->assertEquals([0.01, 0], array_column($quote['shops'], 'discount'));
    }

    public function test_a_percentage_that_rounds_to_zero_cannot_appear_applied(): void
    {
        [, $cart] = $this->bag();
        $line = $this->line($cart, '0.01');
        $this->voucher(['discount_type' => 'percent', 'discount_value' => 1]);
        $this->preview([$line->id], ['code' => 'BAGOO_SAVE'])->assertUnprocessable()
            ->assertJsonPath('quote.voucher', null)->assertJsonPath('quote.discount', 0)
            ->assertJsonPath('message', 'This voucher does not reduce the selected item total.');
    }

    public function test_owned_selection_and_available_stock_are_required_for_both_quote_routes(): void
    {
        [, $cart] = $this->bag();
        $owned = $this->line($cart, '200.00');
        $foreignCart = Cart::create(['user_id' => User::factory()->create()->id]);
        $foreign = $this->line($foreignCart, '500.00');
        $this->preview([$foreign->id])->assertUnprocessable();
        $this->getJson(route('cart.index', ['item_ids' => [$foreign->id]]))->assertUnprocessable();
        $this->preview([$owned->id, $owned->id])->assertUnprocessable();
        $this->preview(['1e2'])->assertUnprocessable();
        $owned->product->update(['stock' => 0]);
        $this->preview([$owned->id])->assertUnprocessable();
        $this->getJson(route('cart.index', ['item_ids' => [$owned->id]]))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_preview_rechecks_address_and_pickup_hub(): void
    {
        [, $cart] = $this->bag();
        $line = $this->line($cart, '200.00');
        $this->preview([$line->id], ['shipping_province' => 'Cebu', 'shipping_city' => 'Cebu City'])->assertUnprocessable();
        $this->preview([$line->id], ['shipping_province' => 'Metro Manila', 'shipping_city' => 'Pasig'])->assertOk();
        $this->preview([$line->id], ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => 999999])->assertUnprocessable();
        $this->createCheckoutNetwork($line->product->shop, ['Pasig' => 'Metro Manila']);
        $hub = LogisticsHub::where('city_municipality', 'Pasig')->where('tier', 'local_bayan_hub')->firstOrFail();
        $hub->update(['allows_self_pickup' => true]);
        $this->preview([$line->id], ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id])->assertOk()->assertJsonPath('quote.shipping_fee', 0);
    }

    public function test_safe_public_offers_and_actual_count_are_bounded_and_exclude_invalid_campaigns(): void
    {
        [, $cart] = $this->bag();
        $line = $this->line($cart, '200.00');
        foreach (range(1, 26) as $index) {
            $this->voucher(['code' => 'BAGOO_'.$index]);
        }
        $this->voucher(['code' => 'PAUSED', 'is_active' => false]);
        $this->voucher(['code' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $this->voucher(['code' => 'EXHAUSTED', 'usage_limit' => 0]);
        $this->voucher(['code' => 'ZERO', 'discount_value' => 0]);
        $this->voucher(['code' => 'BAD_PERCENT', 'discount_type' => 'percent', 'discount_value' => 120]);
        $offers = $this->preview([$line->id])->assertOk()->assertJsonCount(24, 'availableVouchers')->json('availableVouchers');
        foreach (['used_count', 'usage_limit', 'is_active', 'created_at', 'updated_at'] as $privateField) {
            $this->assertArrayNotHasKey($privateField, $offers[0]);
        }
        $this->get(route('buyer.profile', ['tab' => 'vouchers']))->assertInertia(fn (Assert $page) => $page
            ->where('availableVoucherCount', 26)->has('availableVouchers', 24));
    }

    public function test_empty_offer_profile_has_a_true_zero_count(): void
    {
        $this->bag();
        $this->get(route('buyer.profile', ['tab' => 'vouchers']))->assertInertia(fn (Assert $page) => $page
            ->where('availableVoucherCount', 0)->has('availableVouchers', 0));
    }

    #[DataProvider('persistedOffers')]
    public function test_discounted_preview_matches_each_persisted_order_and_counts_one_checkout(array $offer): void
    {
        [$buyer, $cart] = $this->bag();
        $first = $this->line($cart, '800.01');
        $second = $this->line($cart, '100.02');
        $free = $this->line($cart, '2000.00');
        $ids = [$first->id, $second->id, $free->id];
        if ($offer['shop_scoped'] ?? false) {
            unset($offer['shop_scoped']);
            $offer['shop_id'] = $first->product->shop_id;
        }
        $voucher = $this->voucher($offer + ['usage_limit' => 1]);
        $quote = $this->preview($ids, ['code' => 'BAGOO_SAVE'])->assertOk()->json('quote');
        $this->createCheckoutNetwork($first->product->shop, ['Pasig' => 'Metro Manila']);
        $payload = ['checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart), 'item_ids' => $ids,
            'recipient_name' => $buyer->name, 'recipient_phone' => '09171234567', 'shipping_address' => '123 Mabini Street',
            'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1600',
            'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep', 'payment_method' => 'cod', 'voucher_code' => 'BAGOO_SAVE'];
        $this->post(route('checkout.store'), $payload)->assertSessionHas('success');
        $orders = Order::with('items')->get()->keyBy(fn (Order $order) => $order->items->first()->shop_id);
        foreach ($quote['shops'] as $shop) {
            $order = $orders[$shop['shop_id']];
            $this->assertEquals($shop['subtotal'], $order->subtotal);
            $this->assertEquals($shop['shipping'], $order->shipping_fee);
            $this->assertEquals($shop['discount'], $order->voucher_discount);
            $this->assertEquals($shop['total'], $order->total_amount);
        }
        $this->assertSame((int) round($quote['total'] * 100), $orders->sum(fn (Order $order) => CodMoney::cents($order->total_amount)));
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame(0, $cart->items()->count());
        $this->post(route('checkout.store'), $payload)->assertSessionHas('success');
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertDatabaseCount('orders', 3);
    }

    public static function persistedOffers(): array
    {
        return [
            'percentage with cap' => [['discount_type' => 'percent', 'discount_value' => 25, 'max_discount' => 100]],
            'shipping with cap' => [['discount_type' => 'free_shipping', 'discount_value' => 75, 'max_discount' => 30]],
            'one cent product allocation' => [['discount_value' => '0.01']],
            'shop product offer' => [['shop_scoped' => true]],
        ];
    }
}
