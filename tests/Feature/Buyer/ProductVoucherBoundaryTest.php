<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class ProductVoucherBoundaryTest extends TestCase
{
    use InteractsWithCheckoutNetwork, RefreshDatabase;

    private function fixture(string $price = '100.00', string $otherPrice = '700.00', bool $otherFirst = false): array
    {
        $this->withoutVite();
        $this->freezeTime();
        $buyer = User::factory()->buyer()->create();
        $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $selected = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id, 'price' => $price, 'stock' => 10]);
        $other = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id, 'price' => $otherPrice, 'stock' => 10]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        foreach ($otherFirst ? [$other, $selected] : [$selected, $other] as $product) {
            $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00']);
        }
        $this->actingAs($buyer);

        return [$buyer, $shop, $selected, $other, $cart];
    }

    public function test_fixed_targeted_discount_uses_only_qualifying_minimum_and_cannot_expand_to_other_items(): void
    {
        [$buyer, $shop, $selected, , $cart] = $this->fixture();
        $revision = Voucher::factory()->funded(['product_scope' => 'selected', 'product_ids' => [$selected->id]])
            ->create(['shop_id' => $shop->id, 'discount_type' => 'fixed', 'discount_value' => 30, 'min_spend' => 150, 'max_discount' => 30])->currentRevision;
        $quotes = app(CheckoutQuoteService::class);
        $items = $cart->items()->with('product.shop')->orderBy('id')->get();
        $quote = $quotes->quote($items, 'doorstep', collect([$revision]), $buyer);
        $this->assertSame('Add ₱50.00 in qualifying products from this shop to use this voucher.', $quote['voucher_error']);
        $this->assertSame(0, $quote['discount']);
        $items[0]->quantity = 2;
        $quote = $quotes->quote($items, 'doorstep', collect([$revision]), $buyer);
        $this->assertNull($quote['voucher_error']);
        $this->assertSame(30, $quote['discount']);
        $this->assertSame(920, $quote['total']);
    }

    public function test_targeted_shop_and_platform_allocations_keep_the_second_shop_unchanged_after_cancellation(): void
    {
        [$buyer, $shop, $selected, , $cart] = $this->fixture();
        $cart->items()->where('product_id', $selected->id)->update(['quantity' => 2]);
        $foreignShop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $foreign = Product::factory()->create(['shop_id' => $foreignShop->id, 'category_id' => $foreignShop->root_category_id, 'price' => '500.00', 'stock' => 10]);
        $cart->items()->create(['product_id' => $foreign->id, 'quantity' => 1, 'unit_price' => '1.00']);
        $shopOffer = Voucher::factory()->funded(['product_scope' => 'selected', 'product_ids' => [$selected->id]])
            ->create(['shop_id' => $shop->id, 'discount_type' => 'percent', 'discount_value' => 10, 'min_spend' => 0])->currentRevision;
        $platform = Voucher::factory()->funded()->create(['discount_type' => 'percent', 'discount_value' => 10, 'min_spend' => 0])->currentRevision;
        $this->createCheckoutNetwork($shop, ['Pasig' => 'Metro Manila']);
        $input = ['checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart), 'item_ids' => $cart->items()->pluck('id')->all(),
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '123 Mabini Street',
            'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1600', 'destination_barangay' => 'San Antonio',
            'delivery_type' => 'doorstep', 'payment_method' => 'cod', 'shop_voucher_revision_ids' => [$shop->id => $shopOffer->id],
            'platform_voucher_revision_id' => $platform->id];
        $this->postJson(route('buyer.vouchers.apply'), $input)->assertOk()->assertJsonPath('quote.discount', 158)->assertJsonPath('quote.total', 1342);
        $this->post('/checkout', $input)->assertSessionHas('success');
        $orders = Order::with('items')->get()->keyBy(fn ($order) => $order->items->first()->shop_id);
        $this->assertSame('842.00', $orders[$shop->id]->total_amount);
        $this->assertSame('500.00', $orders[$foreignShop->id]->total_amount);
        $original = $orders[$foreignShop->id]->getRawOriginal();
        $this->post(route('buyer.orders.cancel', $orders[$shop->id]), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->assertSame($original, $orders[$foreignShop->id]->fresh()->getRawOriginal());
        $this->assertSame(5000, $platform->balance->fresh()->committed_cents);
        $this->assertSame(8800, $platform->balance->fresh()->released_cents);
        $this->assertSame(2000, $shopOffer->balance->fresh()->released_cents);
        $this->assertSame(9, $foreign->fresh()->stock);
    }

    public function test_tiny_selected_line_with_no_attributable_seller_share_cannot_charge_the_other_product(): void
    {
        [$buyer, $shop, $selected, , $cart] = $this->fixture('0.01', '0.04', true);
        $revision = Voucher::factory()->funded(['product_scope' => 'selected', 'product_ids' => [$selected->id]])
            ->create(['shop_id' => $shop->id, 'discount_type' => 'percent', 'discount_value' => 90, 'min_spend' => 0])->currentRevision;
        $quote = app(CheckoutQuoteService::class)->quote($cart->items()->with('product.shop')->orderBy('id')->get(), 'doorstep', collect([$revision]), $buyer);
        $this->assertSame('This voucher does not reduce the selected item total.', $quote['voucher_error']);
        $this->assertSame(0, $quote['discount']);
        $this->assertSame([], $quote['redemptions']);
    }
}
