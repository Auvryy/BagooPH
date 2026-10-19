<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrderAllocation;
use App\Services\Finance\CodMoney;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class BuyerPolishBoundaryTest extends TestCase
{
    use InteractsWithCheckoutNetwork, RefreshDatabase;

    private function checkout(string $price, bool $sibling = false): array
    {
        $this->withoutVite();
        $buyer = User::factory()->create(['name' => 'Ana Santos']);
        $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => $price, 'stock' => 5]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $price]);
        $ids = [$line->id];
        if ($sibling) {
            $other = Product::factory()->create(['shop_id' => Shop::factory()->approved()->create(['city' => 'Manila'])->id, 'price' => '999.99', 'stock' => 5]);
            $ids[] = $cart->items()->create(['product_id' => $other->id, 'quantity' => 1, 'unit_price' => $other->price])->id;
        }
        $this->createCheckoutNetwork($shop, ['Pasig' => 'Metro Manila']);
        $payload = ['checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart), 'item_ids' => $ids,
            'recipient_name' => $buyer->name, 'recipient_phone' => '09171234567', 'shipping_address' => '15 Mabini Street',
            'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1600',
            'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep', 'payment_method' => 'cod'];
        $this->actingAs($buyer);

        return [$buyer, $shop, $product, $cart, $payload];
    }

    public static function minimumSpend(): array
    {
        return ['one cent below' => ['99.99', false], 'exactly at' => ['100.00', true], 'one cent above' => ['100.01', true]];
    }

    #[DataProvider('minimumSpend')]
    public function test_shop_minimum_cent_sequence_ignores_unselected_and_other_shop_merchandise(string $price, bool $eligible): void
    {
        [$buyer, $shop, $product, $cart, $payload] = $this->checkout($price, sibling: true);
        $unselected = Product::factory()->create(['shop_id' => $shop->id, 'price' => 500, 'stock' => 5]);
        $unselectedLine = $cart->items()->create(['product_id' => $unselected->id, 'quantity' => 1, 'unit_price' => 500]);
        $payload['checkout_token'] = app(CheckoutSubmissionService::class)->issue($buyer, $cart);
        $offer = Voucher::factory()->funded()->create(['shop_id' => $shop->id, 'code' => 'BAGOO_MINIMUM', 'min_spend' => 100,
            'discount_type' => 'fixed', 'discount_value' => 10, 'max_discount' => 10]);
        $payload['shop_voucher_revision_ids'] = [$shop->id => $offer->current_revision_id];
        $preview = $this->postJson('/buyer/vouchers/apply', $payload);
        if (! $eligible) {
            $preview->assertUnprocessable()->assertJsonPath('quote.discount', 0)->assertJsonPath('message', 'Add ₱0.01 from this shop to use this voucher.');
            $this->postJson('/checkout', $payload)->assertUnprocessable();
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('voucher_redemptions', 0);
            $this->assertSame(0, $offer->currentRevision->balance->fresh()->committed_cents);
            $this->assertSame(5, $product->fresh()->stock);
            $this->assertSame(0, $offer->fresh()->used_count);
            $this->assertSame(3, $cart->items()->count());

            return;
        }
        $quote = $preview->assertOk()->assertJsonPath('quote.discount', 10)->json('quote');
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $order = Order::whereHas('items', fn ($query) => $query->where('shop_id', $shop->id))->sole();
        $this->assertSame(CodMoney::cents($price), CodMoney::cents($order->subtotal));
        $this->assertSame(1000, CodMoney::cents($order->voucher_discount));
        $this->assertSame(CodMoney::cents($price) + 5000 - 1000, CodMoney::cents($order->total_amount));
        $this->assertSame((int) round($quote['total'] * 100), Order::get()->sum(fn ($order) => CodMoney::cents($order->total_amount)));
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('voucher_redemptions', 1);
        $this->assertSame(1, $offer->fresh()->used_count);
        $this->assertDatabaseHas('cart_items', ['id' => $unselectedLine->id]);
        $this->assertSame(4, $product->fresh()->stock);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertSame(1, $offer->fresh()->used_count);
        $this->assertSame(4, $product->fresh()->stock);
    }

    public static function percentageCap(): array
    {
        return ['below minimum' => ['99.99', null], 'minimum' => ['100.00', 1000], 'above minimum' => ['100.01', 1000],
            'proportional' => ['150.00', 1500], 'one cent below rounded cap' => ['199.94', 1999],
            'rounding reaches cap' => ['199.95', 2000], 'exact cap basis' => ['200.00', 2000],
            'above cap' => ['200.01', 2000], 'well above cap' => ['350.00', 2000]];
    }

    #[DataProvider('percentageCap')]
    public function test_funded_percentage_minimum_rounding_and_cap_match_preview_and_saved_cents(string $price, ?int $discount): void
    {
        [, $shop, $product, , $payload] = $this->checkout($price);
        $offer = Voucher::factory()->funded()->create(['shop_id' => $shop->id, 'code' => 'BAGOO_PERCENT_CAP', 'min_spend' => 100,
            'discount_type' => 'percent', 'discount_value' => 10, 'max_discount' => 20]);
        $payload['shop_voucher_revision_ids'] = [$shop->id => $offer->current_revision_id];
        $preview = $this->postJson('/buyer/vouchers/apply', $payload);
        if ($discount === null) {
            $preview->assertUnprocessable()->assertJsonPath('quote.discount', 0);
            $this->postJson('/checkout', $payload)->assertUnprocessable();
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('voucher_order_allocations', 0);
            $this->assertSame(5, $product->fresh()->stock);
            $this->assertSame(0, $offer->fresh()->used_count);

            return;
        }
        $quote = $preview->assertOk()->json('quote');
        $this->assertSame($discount, (int) round($quote['discount'] * 100));
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertSame(5, $product->fresh()->stock);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $order = Order::sole();
        $allocation = VoucherOrderAllocation::sole();
        $this->assertSame($discount, CodMoney::cents($order->voucher_discount));
        $this->assertSame(CodMoney::cents($price) + 5000 - $discount, CodMoney::cents($order->total_amount));
        $this->assertSame($discount, $allocation->product_discount_cents);
        $this->assertSame($discount, $allocation->seller_expense_cents);
        $this->assertSame(0, $allocation->platform_expense_cents);
        $this->assertSame($discount, $offer->currentRevision->balance->fresh()->committed_cents);
        $this->assertSame((int) round($quote['total'] * 100), CodMoney::cents($order->total_amount));
        $this->assertSame(4, $product->fresh()->stock);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertSame(1, $offer->fresh()->used_count);
        $this->assertDatabaseCount('voucher_redemptions', 1);
    }

    public static function noteLengths(): array
    {
        return [[499, true], [500, true], [501, false]];
    }

    public static function shippingThreshold(): array
    {
        return [['1499.99', 5000], ['1500.00', 5000], ['1500.01', 0]];
    }

    #[DataProvider('shippingThreshold')]
    public function test_doorstep_shipping_threshold_is_strictly_above_fifteen_hundred(string $price, int $shipping): void
    {
        [, , , , $payload] = $this->checkout($price);
        $this->postJson('/buyer/vouchers/apply', $payload)->assertOk()->assertJsonPath('quote.shipping_fee', $shipping / 100);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertSame($shipping, CodMoney::cents(Order::sole()->shipping_fee));
        $this->assertSame(CodMoney::cents($price) + $shipping, CodMoney::cents(Order::sole()->total_amount));
    }

    public static function productWeights(): array
    {
        return [['0.50'], ['5.00'], ['50.00']];
    }

    #[DataProvider('productWeights')]
    public function test_actual_product_weight_does_not_manufacture_an_unsupported_shipping_tariff(string $weight): void
    {
        [$buyer, , $product, $cart, $payload] = $this->checkout('100.00');
        $product->update(['weight_kg' => $weight]);
        $payload['checkout_token'] = app(CheckoutSubmissionService::class)->issue($buyer, $cart);
        $this->postJson('/buyer/vouchers/apply', $payload)->assertOk()->assertJsonPath('quote.shipping_fee', 50);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertSame(5000, CodMoney::cents(Order::sole()->shipping_fee));
    }

    #[DataProvider('noteLengths')]
    public function test_seller_note_exact_boundaries_persist_the_entire_accepted_value(int $length, bool $accepted): void
    {
        [, $shop, $product, , $payload] = $this->checkout('100.00');
        $note = str_pad('Please call before delivering this parcel. ', $length, 'a');
        $response = $this->postJson('/checkout', [...$payload, 'notes' => $note]);
        if (! $accepted) {
            $response->assertUnprocessable()->assertJsonValidationErrors('notes');
            $this->assertDatabaseCount('orders', 0);
            $this->assertSame(5, $product->fresh()->stock);

            return;
        }
        $response->assertRedirect()->assertSessionHas('success');
        $this->assertSame($note, Order::sole()->notes);
        $this->actingAs($shop->user)->get('/seller/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.data.0.notes', $note));
    }

    private function reviewPurchase(): array
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $order = Order::factory()->create(['status' => 'completed']);
        $item = $order->items()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        $this->actingAs($order->buyer);

        return ['order_item_id' => $item->id, 'rating' => 4];
    }

    public static function reviewLengths(): array
    {
        return [[999, true], [1000, true], [1001, false]];
    }

    #[DataProvider('reviewLengths')]
    public function test_review_text_exact_boundaries(int $length, bool $accepted): void
    {
        $payload = $this->reviewPurchase();
        $comment = str_pad('The parcel arrived safely and matches the item description. ', $length, 'a');
        $response = $this->postJson('/buyer/reviews', [...$payload, 'comment' => $comment]);
        if ($accepted) {
            $response->assertRedirect()->assertSessionHas('success');
            $this->assertDatabaseHas('reviews', ['order_item_id' => $payload['order_item_id'], 'comment' => $comment]);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('comment');
            $this->assertDatabaseCount('reviews', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public static function photoBytes(): array
    {
        return [[5242879, true], [5242880, true], [5242881, false]];
    }

    public static function photoCounts(): array
    {
        return [[4, true], [5, true], [6, false]];
    }

    #[DataProvider('photoCounts')]
    public function test_review_photo_count_boundary_does_not_leave_files_after_rejection(int $count, bool $accepted): void
    {
        $payload = $this->reviewPurchase();
        $files = array_map(fn ($index) => UploadedFile::fake()->image('parcel-'.$index.'.png'), range(1, $count));
        $response = $this->postJson('/buyer/reviews', [...$payload, 'comment' => 'The bag arrived in good condition.', 'images' => $files]);
        if ($accepted) {
            $response->assertRedirect()->assertSessionHas('success');
            $this->assertCount($count, Storage::disk('public')->allFiles());
            $this->assertDatabaseCount('reviews', 1);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('images');
            $this->assertSame([], Storage::disk('public')->allFiles());
            $this->assertDatabaseCount('reviews', 0);
        }
    }

    #[DataProvider('photoBytes')]
    public function test_actual_review_photo_bytes_enforce_the_five_megabyte_boundary(int $bytes, bool $accepted): void
    {
        $payload = $this->reviewPurchase();
        $source = UploadedFile::fake()->image('parcel.png', 2, 2);
        $path = $source->getRealPath();
        file_put_contents($path, str_pad(file_get_contents($path), $bytes, "\0"));
        clearstatcache(true, $path);
        $file = new UploadedFile($path, 'parcel.png', 'image/png', null, true);
        $this->assertSame($bytes, $file->getSize());
        $response = $this->postJson('/buyer/reviews', [...$payload, 'comment' => 'The bag arrived in good condition.', 'images' => [$file]]);
        if ($accepted) {
            $response->assertRedirect()->assertSessionHas('success');
            $paths = Storage::disk('public')->allFiles();
            $this->assertCount(1, $paths);
            $this->assertSame($bytes, Storage::disk('public')->size($paths[0]));
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('images.0');
            $this->assertDatabaseCount('reviews', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }
}
