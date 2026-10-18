<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class BuyerCancellationTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    private function purchase(): array
    {
        $this->withoutVite();
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create(['city' => 'Taguig City']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100, 'stock' => 10,
            'variants' => ['sizes' => [['name' => 'Large', 'stock' => 10, 'extra_price' => 5]]]]);
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2, 'size' => 'Large']);
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $this->post(route('checkout.store'), ['checkout_token' => $this->checkoutToken($buyer), 'item_ids' => $cart->items()->pluck('id')->all(),
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '100 Main Street',
            'shipping_city' => 'Taguig City', 'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1634',
            'destination_barangay' => 'Fort Bonifacio', 'payment_method' => 'cod', 'notes' => 'Please call on arrival.'])->assertSessionHasNoErrors();

        return [$buyer, Order::firstOrFail(), $product];
    }

    public function test_owned_early_cancellation_restores_stock_once_and_retains_delivery_notes_and_the_reason(): void
    {
        [$buyer, $order, $product] = $this->purchase();
        $this->get(route('buyer.orders.show', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cancelOrderUrl', route('buyer.orders.cancel', $order, absolute: false)));
        $input = ['reason' => 'Other reason', 'notes' => 'I selected the wrong size.'];
        $this->post(route('buyer.orders.cancel', $order), $input)->assertSessionHas('success');
        $this->post(route('buyer.orders.cancel', $order), $input)->assertSessionHas('success');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->delivery->status);
        $this->assertSame('Please call on arrival.', $order->fresh()->notes);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(10, $product->fresh()->variants['sizes'][0]['stock']);
        $this->assertSame(0, $product->fresh()->sales_count);
        $this->assertSame(1, DeliveryCheckpoint::where('checkpoint_type', 'cancelled')->count());
        $this->get(route('buyer.orders.show', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cancelOrderUrl', null)->where('cancellationReason', 'Other reason: I selected the wrong size.'));
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public static function closedStages(): array
    {
        return [['ready_for_pickup', 'unassigned'], ['picked_up', 'picked_up'], ['delivered', 'delivered'], ['completed', 'delivered']];
    }

    #[DataProvider('closedStages')]
    public function test_cancellation_is_blocked_from_ready_for_pickup_onward(string $state, string $parcel): void
    {
        [, $order, $product] = $this->purchase();
        $order->update(['status' => $state]);
        $order->delivery->update(['status' => $parcel]);
        $this->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame($state, $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_foreign_buyer_and_retained_physical_custody_cannot_cancel_an_order(): void
    {
        [$buyer, $order, $product] = $this->purchase();
        $this->actingAs(User::factory()->create())->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertForbidden();
        DeliveryCheckpoint::create(['delivery_id' => $order->delivery->id, 'checkpoint_type' => 'courier_pickup', 'custody_after' => ['kind' => 'pickup_rider', 'user_id' => $buyer->id]]);
        $this->actingAs($buyer)->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertUnprocessable();
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_reason_validation_and_storage_failure_leave_the_complete_purchase_unchanged(): void
    {
        [, $order, $product] = $this->purchase();
        $this->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Other reason'])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Made up'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        Event::listen('eloquent.updated: '.Product::class, fn () => throw new RuntimeException('Private failure detail.'));
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHasErrors('reason');
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame('unassigned', $order->fresh()->delivery->status);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(8, $product->fresh()->variants['sizes'][0]['stock']);
        $this->assertSame(0, DeliveryCheckpoint::where('checkpoint_type', 'cancelled')->count());
    }

    public function test_legacy_online_or_paid_orders_need_payment_review_instead_of_a_fake_refund(): void
    {
        [, $order, $product] = $this->purchase();
        $order->update(['payment_method' => 'card', 'payment_status' => 'paid']);
        $this->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
    }
}
