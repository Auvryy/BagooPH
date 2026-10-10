<?php

namespace Tests\Feature\Buyer;

use App\Models\BuyAgainSubmission;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\BuyAgainService;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class BuyerBuyAgainTest extends TestCase
{
    use InteractsWithCheckoutNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function purchase(array $variants = [], ?string $color = null, ?string $size = null): array
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => '100.00', 'stock' => 10, 'variants' => $variants]);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed', 'subtotal' => '200.00', 'shipping_fee' => '50.00', 'total_amount' => '250.00']);
        $line = $order->items()->create(['product_id' => $product->id, 'shop_id' => $shop->id, 'quantity' => 2, 'unit_price' => '100.00', 'subtotal' => '200.00', 'color' => $color, 'size' => $size, 'sku_snapshot' => 'ORIGINAL-BAG']);

        return [$buyer, $product, $order, $line];
    }

    private function payload(User $buyer, Order $order): array
    {
        $preview = app(BuyAgainService::class)->preview($buyer, $order);

        return ['request_token' => $preview['requestToken'], 'items' => collect($preview['items'])->filter(fn ($row) => $row['can_select'])->map(fn ($row) => [
            'order_item_id' => $row['order_item_id'], 'quantity' => $row['original_quantity'], 'expected_unit_price' => $row['current_unit_price'],
        ])->values()->all()];
    }

    private function url(Order $order): string
    {
        return '/buyer/orders/'.$order->id.'/buy-again';
    }

    public function test_preview_and_confirmation_use_current_prices_without_rewriting_history_or_reserving_stock(): void
    {
        [$buyer, $product, $order, $line] = $this->purchase();
        $orderBefore = $order->fresh()->getRawOriginal();
        $lineBefore = $line->fresh()->getRawOriginal();
        $product->update(['price' => '150.00']);
        $this->actingAs($buyer)->get($this->url($order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/BuyAgain')->where('items.0.previous_unit_price', '100.00')->where('items.0.current_unit_price', '150.00')
            ->where('items.0.original_quantity', 2)->where('items.0.maximum_quantity', 10)->where('items.0.can_select', true)
            ->where('requestToken', fn ($token) => is_string($token) && strlen($token) === 132)
            ->where('submitUrl', $this->url($order))->where('bagUrl', '/cart'));
        $this->assertDatabaseCount('carts', 0);
        $payload = $this->payload($buyer, $order);
        $payload += ['unit_price' => '1.00', 'buyer_id' => User::factory()->create()->id, 'payment_method' => 'bank_transfer', 'shipping_fee' => 0];
        $this->postJson($this->url($order), $payload)->assertOk()->assertJsonPath('replayed', false)
            ->assertJsonPath('result.items.0.unit_price', '150.00')->assertJsonPath('result.items.0.added_quantity', 2);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '150.00']);
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($lineBefore, $line->fresh()->getRawOriginal());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('buy_again_submissions', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_foreign_orders_unknown_ids_and_tokens_from_other_orders_are_rejected(): void
    {
        [$buyer, $product, $order, $line] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $this->actingAs(User::factory()->create())->get($this->url($order))->assertForbidden();
        $this->postJson($this->url($order), $payload)->assertForbidden();
        $this->actingAs($buyer)->get('/buyer/orders/999999/buy-again')->assertNotFound();
        $otherOrder = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        $this->postJson($this->url($otherOrder), $payload)->assertUnprocessable()->assertJsonValidationErrors('request_token');
        $foreignItem = Order::factory()->create()->items()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        $payload['items'][0]['order_item_id'] = $foreignItem->id;
        $this->postJson($this->url($order), $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $payload['items'][0]['order_item_id'] = 999999;
        $this->postJson($this->url($order), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('buy_again_submissions', 0);
    }

    public static function unfinished(): array
    {
        return array_map(fn ($status) => [$status], ['placed', 'confirmed', 'preparing', 'ready_for_pickup', 'picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'delivered', 'delivery_failed', 'returned', 'cancelled']);
    }

    #[DataProvider('unfinished')]
    public function test_only_completed_orders_can_be_rebuilt(string $status): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $order->update(['status' => $status]);
        $this->actingAs($buyer)->get($this->url($order))->assertStatus(409);
        $this->postJson($this->url($order), $payload)->assertStatus(409);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('buy_again_submissions', 0);
    }

    public static function unavailable(): array
    {
        return array_map(fn ($state) => [$state], ['archived', 'draft', 'restricted', 'shop_suspended', 'seller_suspended', 'category_inactive', 'sold_out']);
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_items_are_explained_and_a_stale_selection_adds_nothing(string $state): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        match ($state) {
            'restricted' => $product->forceFill(['compliance_restricted' => true])->save(),
            'shop_suspended' => $product->shop->update(['status' => 'suspended']),
            'seller_suspended' => $product->shop->user->update(['status' => 'suspended']),
            'category_inactive' => $product->category->update(['is_active' => false]),
            'sold_out' => $product->update(['stock' => 0]),
            default => $product->update(['status' => $state]),
        };
        $this->actingAs($buyer)->get($this->url($order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items.0.can_select', false)->where('items.0.maximum_quantity', 0)
            ->where('items.0.unavailable_reason', fn ($reason) => is_string($reason) && $reason !== '')
            ->where('items.0.current_unit_price', $state === 'sold_out' ? '100.00' : null)
            ->where('items.0.name', $state === 'sold_out' ? $product->name : 'ORIGINAL-BAG'));
        $this->postJson($this->url($order), $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('buy_again_submissions', 0);
    }

    public function test_changed_variants_are_never_substituted_with_an_available_variant(): void
    {
        [$buyer, $product, $order, $line] = $this->purchase(['colors' => [['name' => 'Red', 'in_stock' => true]], 'sizes' => [['name' => 'M', 'stock' => 10]]], 'Red', 'M');
        $payload = $this->payload($buyer, $order);
        $product->update(['variants' => ['colors' => [['name' => 'Blue', 'in_stock' => true]], 'sizes' => [['name' => 'L', 'stock' => 10]]]]);
        $this->actingAs($buyer)->get($this->url($order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items.0.color', 'Red')->where('items.0.size', 'M')->where('items.0.can_select', false));
        $this->postJson($this->url($order), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame('Red', $line->fresh()->color);
        $this->assertSame('M', $line->fresh()->size);
    }

    public function test_existing_lines_merge_under_the_same_limits_and_use_current_prices(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $cart = Cart::create(['user_id' => $buyer->id]);
        $existing = $cart->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '80.00']);
        $product->update(['price' => '120.00']);
        $this->actingAs($buyer)->postJson($this->url($order), $this->payload($buyer, $order))->assertOk()->assertJsonPath('result.items.0.cart_item_id', $existing->id);
        $this->assertSame(5, $existing->fresh()->quantity);
        $this->assertSame('120.00', $existing->fresh()->unit_price);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_a_buyer_can_reduce_the_quantity_to_fit_the_current_99_unit_limit(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $product->update(['stock' => 150]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $existing = $cart->items()->create(['product_id' => $product->id, 'quantity' => 98, 'unit_price' => 100]);
        $this->actingAs($buyer)->get($this->url($order))->assertOk()->assertInertia(fn (Assert $page) => $page->where('items.0.maximum_quantity', 1));
        $payload = $this->payload($buyer, $order);
        $this->postJson($this->url($order), $payload)->assertUnprocessable();
        $this->assertSame(98, $existing->fresh()->quantity);
        $payload['items'][0]['quantity'] = 1;
        $this->postJson($this->url($order), $payload)->assertOk();
        $this->assertSame(99, $existing->fresh()->quantity);
        $this->assertSame(150, $product->fresh()->stock);
    }

    public function test_a_failure_after_the_first_line_write_rolls_back_the_entire_selection(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $second = Product::factory()->create(['shop_id' => $product->shop_id, 'price' => '200.00', 'stock' => 5]);
        $order->items()->create(['product_id' => $second->id, 'shop_id' => $second->shop_id, 'quantity' => 1, 'unit_price' => 200, 'subtotal' => 200]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $existing = $cart->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '80.00']);
        $before = $existing->fresh()->getRawOriginal();
        $payload = $this->payload($buyer, $order);
        $second->update(['stock' => 0]);
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with($query->sql, 'update "cart_items"')) {
                $writes++;
            }
        });
        $this->actingAs($buyer)->postJson($this->url($order), $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(1, $writes, 'The first item must actually have been written before the later failure.');
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('buy_again_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_duplicate_original_variants_share_product_stock_and_roll_back_on_overflow(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $order->items()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id, 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200]);
        $product->update(['stock' => 3]);
        $this->actingAs($buyer)->postJson($this->url($order), $this->payload($buyer, $order))->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('buy_again_submissions', 0);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_a_price_change_after_preview_requires_a_new_acknowledgement(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $product->update(['price' => '120.00']);
        $this->actingAs($buyer)->postJson($this->url($order), $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('cart_items', 0);
        $this->postJson($this->url($order), $this->payload($buyer, $order))->assertOk()->assertJsonPath('result.items.0.unit_price', '120.00');
    }

    public function test_buyers_can_exclude_unavailable_items_and_select_only_available_original_variants(): void
    {
        [$buyer, $product, $order] = $this->purchase(['colors' => [['name' => '0', 'in_stock' => true]], 'sizes' => [['name' => '0', 'stock' => 10]]], '0', '0');
        $unavailable = Product::factory()->create(['shop_id' => $product->shop_id, 'status' => 'archived']);
        $order->items()->create(['product_id' => $unavailable->id, 'shop_id' => $unavailable->shop_id, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        $payload = $this->payload($buyer, $order);
        $this->assertCount(1, $payload['items']);
        $this->actingAs($buyer)->postJson($this->url($order), $payload)->assertOk();
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'color' => '0', 'size' => '0', 'quantity' => 2]);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $unavailable->id]);
    }

    public function test_duplicate_original_lines_report_final_bag_quantities_and_selection_order_does_not_duplicate_a_retry(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $order->items()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id, 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200]);
        $payload = $this->payload($buyer, $order);
        $response = $this->actingAs($buyer)->postJson($this->url($order), $payload)->assertOk()
            ->assertJsonPath('result.items.0.quantity', 4)->assertJsonPath('result.items.1.quantity', 4);
        $payload['items'] = array_reverse($payload['items']);
        $this->postJson($this->url($order), $payload)->assertOk()->assertJson(['result' => $response->json('result'), 'replayed' => true]);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 4]);
    }

    public function test_identical_retries_keep_the_original_result_after_bag_edits_unavailability_and_key_rotation(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $response = $this->actingAs($buyer)->postJson($this->url($order), $payload)->assertOk();
        $originalResult = $response->json('result');
        $this->postJson($this->url($order), $payload)->assertOk()->assertJson(['result' => $originalResult, 'replayed' => true]);
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $cart->items()->delete();
        $product->forceFill(['compliance_restricted' => true])->save();
        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);
        $this->postJson($this->url($order), $payload)->assertOk()->assertJson(['result' => $originalResult, 'replayed' => true]);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('buy_again_submissions', 1);
        $this->assertSame($originalResult, BuyAgainSubmission::firstOrFail()->result);
    }

    public function test_changed_token_reuse_and_an_unissued_token_cannot_add_more_items(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $this->actingAs($buyer)->postJson($this->url($order), $payload)->assertOk();
        $payload['items'][0]['quantity'] = 3;
        $this->postJson($this->url($order), $payload)->assertStatus(409);
        $payload['request_token'] = 'v1.'.str_repeat('A', 64).'.'.str_repeat('0', 64);
        $this->postJson($this->url($order), $payload)->assertUnprocessable()->assertJsonValidationErrors('request_token');
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 2]);
        $this->assertDatabaseCount('buy_again_submissions', 1);
    }

    public static function invalidQuantity(): array
    {
        return array_map(fn ($value) => [$value], [0, -1, 100, 1.5, '1.0', '1e1', '02', '２', true, []]);
    }

    #[DataProvider('invalidQuantity')]
    public function test_quantity_validation_matches_normal_bag_inputs(mixed $quantity): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $payload['items'][0]['quantity'] = $quantity;
        $this->actingAs($buyer)->postJson($this->url($order), $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('buy_again_submissions', 0);
    }

    public function test_duplicate_empty_or_malformed_selections_and_invalid_prices_are_rejected(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $this->actingAs($buyer);
        foreach ([[], 'invalid', ['row' => $payload['items'][0]], [$payload['items'][0], $payload['items'][0]]] as $selection) {
            $this->postJson($this->url($order), [...$payload, 'items' => $selection])->assertUnprocessable();
        }
        foreach (['1e2', -1, [], null] as $price) {
            $changed = $payload;
            $changed['items'][0]['expected_unit_price'] = $price;
            $this->postJson($this->url($order), $changed)->assertUnprocessable()->assertJsonValidationErrors('items.0.expected_unit_price');
        }
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('buy_again_submissions', 0);
    }

    public function test_a_result_persistence_failure_rolls_back_added_lines(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        Event::listen('eloquent.creating: '.BuyAgainSubmission::class, fn () => throw new RuntimeException('Injected result persistence failure.'));
        try {
            app(BuyAgainService::class)->add($buyer, $order, $payload);
            $this->fail('The injected persistence failure must abort the whole write.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected result persistence failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.BuyAgainSubmission::class);
        }
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('buy_again_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_original_request_results_are_immutable_in_models_and_the_database(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        app(BuyAgainService::class)->add($buyer, $order, $this->payload($buyer, $order));
        $submission = BuyAgainSubmission::firstOrFail();
        try {
            $submission->update(['result' => []]);
            $this->fail('Saved results must reject model edits.');
        } catch (LogicException) {
        }
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('buy_again_submissions')->where('id', $submission->id);
                $operation === 'update' ? $query->update(['request_hash' => str_repeat('0', 64)]) : $query->delete();
                $this->fail('Saved results must reject raw database changes.');
            } catch (QueryException) {
            }
        }
        $this->assertDatabaseCount('buy_again_submissions', 1);
    }

    public static function blockedBuyers(): array
    {
        return [['active', 'none'], ['active', 'pending_approval'], ['active', 'rejected'], ['suspended', 'approved'], ['inactive', 'verified']];
    }

    #[DataProvider('blockedBuyers')]
    public function test_ordinary_buyer_approval_is_required_even_for_owned_completed_orders(string $status, string $kyc): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        User::whereKey($buyer->id)->update(['status' => $status, 'kyc_status' => $kyc]);
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.$this->url($order))->assertRedirect(route('kyc.pending'));
            $this->post($host.$this->url($order), $payload)->assertRedirect(route('kyc.pending'));
        }
        $this->assertDatabaseCount('cart_items', 0);
        $this->expectException(AuthorizationException::class);
        app(BuyAgainService::class)->add($buyer, $order, $payload);
    }

    public function test_guest_nonbuyer_and_nonportal_order_viewers_have_no_buy_again_action(): void
    {
        [$buyer, $product, $order] = $this->purchase();
        $payload = $this->payload($buyer, $order);
        $this->get($this->url($order))->assertRedirect(route('login'));
        $this->postJson($this->url($order), $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->seller()->create())->get($this->url($order))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('buyAgainUrl', null));
        $this->actingAs($buyer)->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('buyAgainUrl', $this->url($order)));
        $buyer->update(['status' => 'suspended']);
        $this->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('buyAgainUrl', null));
    }

    public function test_items_added_with_buy_again_can_use_normal_checkout_and_a_new_delivery_snapshot(): void
    {
        [$buyer, $product, $order, $originalLine] = $this->purchase();
        $this->createCheckoutNetwork($product->shop, ['Pasig' => 'Metro Manila']);
        $product->update(['price' => '150.00']);
        $this->actingAs($buyer)->postJson($this->url($order), $this->payload($buyer, $order))->assertOk();
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $payload = ['recipient_name' => 'Maria Santos', 'recipient_phone' => '09171234567',
            'shipping_address' => '123 Mabini Street', 'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila',
            'shipping_postal_code' => '1600', 'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep',
            'payment_method' => 'cod', 'item_ids' => $cart->items()->pluck('id')->all(),
            'checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart)];
        $this->post('/checkout', $payload)->assertSessionMissing('error')->assertSessionDoesntHaveErrors();
        $new = Order::where('id', '!=', $order->id)->firstOrFail();
        $this->assertSame('placed', $new->status);
        $this->assertSame('300.00', $new->subtotal);
        $this->assertSame('Pasig', $new->shipping_city);
        $this->assertSame('cod', $new->payment_method);
        $this->assertNotSame($order->order_number, $new->order_number);
        $this->assertSame('150.00', $new->items()->firstOrFail()->unit_price);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('100.00', $originalLine->fresh()->unit_price);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('buy_again_submissions', 1);
    }
}
