<?php

namespace Tests\Feature\Buyer;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CheckoutSubmission;
use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\CheckoutOrderService;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class CheckoutReplayTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use RefreshDatabase;

    private function checkout(bool $withToken = true): array
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => '100.00', 'stock' => 10]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '100.00']);
        $this->createCheckoutNetwork($shop, ['Pasig' => 'Metro Manila']);

        $payload = [
            'recipient_name' => 'Maria Santos', 'recipient_phone' => '09171234567',
            'shipping_address' => '123 Mabini Street', 'shipping_city' => 'Pasig',
            'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1600',
            'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep',
            'payment_method' => 'cod', 'item_ids' => [$line->id],
        ];
        if ($withToken) {
            $payload['checkout_token'] = app(CheckoutSubmissionService::class)->issue($buyer, $cart);
        }

        return [$buyer, $cart, $product, $payload];
    }

    public function test_checkout_requires_a_server_issued_confirmation(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout(false);
        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)
            ->assertRedirect('/checkout')->assertSessionHasErrors('checkout_token');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_checkout_page_issues_an_opaque_confirmation_without_writing_business_records(): void
    {
        [$buyer, $cart, $product] = $this->checkout(false);
        $before = $buyer->fresh()->getRawOriginal();
        $this->actingAs($buyer)->get('/checkout')->assertInertia(fn (Assert $page) => $page
            ->component('Checkout/Index')->where('checkoutToken', fn ($token) => is_string($token) && strlen($token) > 100));
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertDatabaseCount('addresses', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame($before, $buyer->fresh()->getRawOriginal());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_same_submission_returns_original_shop_orders_after_consumption_and_catalogue_changes(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        [$second, $secondLine] = $this->addShop($cart);
        $payload['item_ids'][] = $secondLine->id;
        $payload['save_address'] = true;
        $payload['voucher_code'] = ' save_10 ';
        $voucher = $this->voucher();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertRedirect(route('buyer.orders.index'))->assertSessionHas('success');

        $orders = Order::with('delivery', 'items')->orderBy('id')->get();
        $snapshots = $this->snapshots();
        $this->assertCount(2, $orders);
        $this->assertSame(0, $cart->items()->count());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(8, $second->fresh()->stock);
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertDatabaseCount('addresses', 1);
        $this->assertDatabaseCount('checkout_submissions', 1);
        $this->assertDatabaseCount('checkout_submission_orders', 2);
        foreach ($orders as $order) {
            $this->assertSame('cod', $order->payment_method);
            $this->assertSame('pending', $order->payment_status);
            $this->assertSame('200.00', $order->subtotal);
            $this->assertSame('20.00', $order->voucher_discount);
            $this->assertSame('230.00', $order->total_amount);
            $this->assertNotNull($order->delivery->origin_mother_hub_id);
            $this->assertNotNull($order->delivery->destination_mother_hub_id);
        }

        $product->update(['price' => '999.00', 'stock' => 0]);
        $third = Product::factory()->create(['shop_id' => $second->shop_id, 'stock' => 10]);
        $this->post('/cart', ['product_id' => $third->id, 'quantity' => 1])->assertSessionHas('success');
        $newLines = $cart->items()->get()->map->getRawOriginal()->all();
        $this->post('/checkout', $payload)->assertRedirect(route('buyer.orders.index'))->assertSessionHas('success');
        $original = app(CheckoutOrderService::class)->place($buyer, $cart, array_reverse($payload['item_ids']), $payload);

        $this->assertSame($orders->modelKeys(), $original->modelKeys());
        $this->assertSame($snapshots, $this->snapshots());
        $this->assertSame($newLines, $cart->items()->get()->map->getRawOriginal()->all());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertDatabaseCount('addresses', 1);
        $this->assertDatabaseCount('checkout_submissions', 1);
    }

    #[DataProvider('changedPayloads')]
    public function test_completed_confirmation_rejects_changed_details_without_rewriting_orders(string $field, mixed $value): void
    {
        [$buyer, , $product, $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $before = $this->snapshots();
        $payload[$field] = $value;
        $this->postJson('/checkout', $payload)->assertConflict();
        $this->assertSame($before, $this->snapshots());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('addresses', 0);
        $this->assertDatabaseCount('checkout_submissions', 1);
    }

    public static function changedPayloads(): array
    {
        return [
            ['recipient_name', 'Juan Santos'], ['recipient_phone', '09181234567'],
            ['shipping_address', '456 Rizal Street'], ['shipping_city', 'Manila'],
            ['shipping_province', 'Laguna'], ['destination_barangay', 'Poblacion'],
            ['voucher_code', 'OTHER'], ['save_address', true], ['notes', 'Leave at the gate'],
            ['item_ids', [999999]],
        ];
    }

    public function test_web_conflict_has_a_clear_recovery_message_after_the_bag_is_empty(): void
    {
        [$buyer, , , $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $payload['recipient_name'] = 'Juan Santos';
        $this->from('/checkout')->post('/checkout', $payload)->assertRedirect(route('buyer.orders.index'))
            ->assertSessionHas('error', 'These checkout details differ from your submitted order. Open checkout again to place a different order.');
        $this->assertNull(session()->get('_old_input.checkout_token'));
        $this->assertDatabaseCount('orders', 1);
    }

    #[DataProvider('invalidConfirmations')]
    public function test_invalid_foreign_or_wrong_bag_confirmation_is_rejected(string $kind): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        if ($kind === 'foreign') {
            $other = User::factory()->create();
            $otherCart = Cart::create(['user_id' => $other->id]);
            $payload['checkout_token'] = app(CheckoutSubmissionService::class)->issue($other, $otherCart);
        } elseif ($kind === 'another_bag') {
            $otherCart = Cart::create(['user_id' => $buyer->id]);
            $payload['checkout_token'] = app(CheckoutSubmissionService::class)->issue($buyer, $otherCart);
        } elseif ($kind === 'altered') {
            $payload['checkout_token'] = 'A'.$payload['checkout_token'];
        } elseif ($kind === 'array') {
            $payload['checkout_token'] = ['unexpected'];
        } else {
            $payload['checkout_token'] = 'invented-confirmation';
        }
        $this->actingAs($buyer)->postJson('/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public static function invalidConfirmations(): array
    {
        return [['foreign'], ['another_bag'], ['altered'], ['array'], ['invented']];
    }

    public function test_validation_failure_leaves_the_same_confirmation_usable_for_corrected_input(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $bad = $payload;
        $bad['recipient_phone'] = 'not-a-phone';
        $this->actingAs($buyer)->postJson('/checkout', $bad)->assertUnprocessable()->assertJsonValidationErrors('recipient_phone');
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertDatabaseCount('checkout_submissions', 1);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_original_confirmation_remains_replayable_after_application_key_rotation(): void
    {
        [$buyer, , $product, $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $before = $this->snapshots();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertSame($before, $this->snapshots());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('checkout_submissions', 1);
    }

    #[DataProvider('rollbackStages')]
    public function test_mid_checkout_failure_rolls_back_shop_orders_stock_voucher_address_and_result(string $stage): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        [$second, $line] = $this->addShop($cart);
        $payload['item_ids'][] = $line->id;
        $payload['voucher_code'] = 'SAVE_10';
        $payload['save_address'] = true;
        $voucher = $this->voucher();
        $default = $buyer->addresses()->create(['recipient_name' => $buyer->name, 'phone' => '+639171234567',
            'city' => 'Manila', 'street' => '100 Old Street', 'is_default' => true]);
        $before = $default->fresh()->getRawOriginal();
        $event = match ($stage) {
            'second parcel' => 'eloquent.created: '.Delivery::class,
            'second stock' => 'eloquent.updated: '.Product::class,
            'voucher' => 'eloquent.updating: '.Voucher::class,
            'address' => 'eloquent.creating: '.Address::class,
            'result' => 'eloquent.created: '.CheckoutSubmission::class,
            'result links' => QueryExecuted::class,
        };
        Event::listen($event, function ($record) use ($stage, $second) {
            if (($stage === 'second parcel' && Delivery::count() !== 2)
                || ($stage === 'second stock' && $record->id !== $second->id)
                || ($stage === 'result links' && (! str_starts_with(strtolower($record->sql), 'insert')
                    || ! str_contains($record->sql, 'checkout_submission_orders')))) {
                return;
            }
            throw new RuntimeException('Private storage failure details.');
        });
        Log::spy();
        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)->assertRedirect('/checkout')
            ->assertSessionHas('error', 'We could not save your order. Please try again with the same checkout details.');
        Log::shouldHaveReceived('warning')->once()->with('Checkout could not be saved.', ['buyer_id' => $buyer->id, 'exception' => RuntimeException::class]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertDatabaseCount('checkout_submission_orders', 0);
        $this->assertDatabaseCount('addresses', 1);
        $this->assertSame($before, $default->fresh()->getRawOriginal());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(10, $second->fresh()->stock);
        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertSame(2, $cart->items()->count());

        Event::forget($event);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('checkout_submissions', 1);
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertDatabaseCount('addresses', 2);
        $this->assertTrue($default->fresh()->is_default);
    }

    public static function rollbackStages(): array
    {
        return [['second parcel'], ['second stock'], ['voucher'], ['address'], ['result'], ['result links']];
    }

    #[DataProvider('unsupportedDestinations')]
    public function test_destination_labels_and_coordinates_cannot_override_actual_network_coverage(string $field, string $value): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        LogisticsHub::where('city_municipality', 'Manila')->where('tier', 'local_bayan_hub')->update(['coverage_barangays' => ['San Antonio']]);
        $payload[$field] = $value;
        $payload['shipping_latitude'] = '14.5';
        $payload['shipping_longitude'] = '121.0';
        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)->assertRedirect('/checkout')->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public static function unsupportedDestinations(): array
    {
        return [['shipping_province', 'Batangas'], ['shipping_province', 'Cebu'],
            ['shipping_city', 'Pasi'], ['shipping_city', 'Unserved City']];
    }

    public function test_valid_city_alias_case_and_unicode_normalization_preserve_the_real_destination(): void
    {
        [$buyer, , , $payload] = $this->checkout();
        $payload['recipient_name'] = ' Ｍａｒｉａ Santos ';
        $payload['shipping_city'] = ' city of pasig ';
        $payload['shipping_province'] = ' metro manila ';
        $payload['shipping_address'] = ' '.str_repeat('Ａ', 500).' ';
        $payload['save_address'] = true;
        $payload['payment_method'] = 'card';
        $payload['total_amount'] = 0;
        $payload['status'] = 'completed';
        $payload['buyer_id'] = 999999;
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $order = Order::with('delivery')->firstOrFail();
        $this->assertSame('Maria Santos', $order->recipient_name);
        $this->assertSame('+639171234567', $order->recipient_phone);
        $this->assertSame('250.00', $order->total_amount);
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('placed', $order->status);
        $this->assertSame($buyer->id, $order->buyer_id);
        $this->assertSame('Pasig', $order->delivery->destinationBayanHub->city_municipality);
        $this->assertSame(str_repeat('A', 500), $order->shipping_address);
        $address = $buyer->addresses()->firstOrFail();
        $this->assertSame(str_repeat('A', 500), $address->street);
        $this->assertTrue($address->is_default);
    }

    public function test_self_pickup_rejects_a_hub_outside_the_stated_destination(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $origin = LogisticsHub::where('city_municipality', 'Manila')->where('tier', 'local_bayan_hub')->firstOrFail();
        $origin->update(['allows_self_pickup' => true]);
        $payload['delivery_type'] = 'hub_self_pickup';
        $payload['pickup_hub_id'] = $origin->id;
        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)->assertRedirect('/checkout')->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    #[DataProvider('unavailablePickupHubs')]
    public function test_new_checkout_rejects_unavailable_or_wrong_tier_pickup_hubs(string $kind): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $hub = LogisticsHub::where('city_municipality', 'Pasig')->where('tier', 'local_bayan_hub')->firstOrFail();
        $hub->update(['allows_self_pickup' => $kind !== 'no counter', 'is_active' => $kind !== 'inactive']);
        $payload['delivery_type'] = 'hub_self_pickup';
        $payload['pickup_hub_id'] = match ($kind) {
            'missing' => 999999, 'mother' => LogisticsHub::where('tier', 'regional_mother_hub')->firstOrFail()->id,
            default => $hub->id,
        };
        $this->actingAs($buyer)->postJson('/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('pickup_hub_id');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public static function unavailablePickupHubs(): array
    {
        return [['missing'], ['mother'], ['no counter'], ['inactive']];
    }

    public function test_self_pickup_keeps_the_selected_eligible_destination_hub_and_original_result(): void
    {
        [$buyer, , , $payload] = $this->checkout();
        $hub = LogisticsHub::where('city_municipality', 'Pasig')->where('tier', 'local_bayan_hub')->firstOrFail();
        $hub->update(['allows_self_pickup' => true]);
        $second = LogisticsHub::create(['logistics_company_id' => $hub->logistics_company_id,
            'name' => 'Pasig Second Counter', 'code' => 'PASIG-SECOND', 'tier' => 'local_bayan_hub',
            'city_municipality' => 'Pasig', 'province' => 'Metro Manila', 'address' => '200 Counter Road',
            'is_active' => true, 'allows_self_pickup' => true]);
        $payload['delivery_type'] = 'hub_self_pickup';
        $payload['pickup_hub_id'] = $second->id;
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $before = $this->snapshots();
        $second->update(['is_active' => false]);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $order = Order::with('delivery')->firstOrFail();
        $this->assertSame($second->id, $order->pickup_hub_id);
        $this->assertSame($second->id, $order->delivery->destination_bayan_hub_id);
        $this->assertSame('0.00', $order->shipping_fee);
        $this->assertNotNull($order->delivery->origin_mother_hub_id);
        $this->assertFalse($second->fresh()->is_active);
        $this->assertSame($before, $this->snapshots());
    }

    public function test_a_missing_route_in_the_second_shop_rolls_back_the_complete_checkout(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        [$second, $line] = $this->addShop($cart, 'Lipa');
        $payload['item_ids'][] = $line->id;
        $payload['voucher_code'] = 'SAVE_10';
        $payload['save_address'] = true;
        $voucher = $this->voucher();
        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)->assertRedirect('/checkout')->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('addresses', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(10, $second->fresh()->stock);
        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertSame(2, $cart->items()->count());
    }

    public function test_an_exhausted_voucher_blocks_a_new_confirmation_but_allows_the_original_replay(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $voucher = $this->voucher();
        $payload['voucher_code'] = 'SAVE_10';
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->post('/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSessionHas('success');
        $payload['checkout_token'] = app(CheckoutSubmissionService::class)->issue($buyer, $cart);
        $payload['item_ids'] = $cart->items()->pluck('id')->all();
        $this->from('/checkout')->post('/checkout', $payload)->assertRedirect('/checkout')->assertSessionHas('error');
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('checkout_submissions', 1);
    }

    public function test_a_cancelled_order_replay_does_not_restore_fulfillment_or_consume_returned_stock(): void
    {
        [$buyer, , $product, $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $order = Order::firstOrFail();
        $this->actingAs($product->shop->user)->post(route('seller.orders.cancel', $order), ['reason' => 'Buyer requested cancellation via chat'])->assertSessionHas('success');
        $before = $this->snapshots();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame($before, $this->snapshots());
    }

    public function test_stale_buyer_eligibility_blocks_even_a_completed_confirmation_replay(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $before = $this->snapshots();
        User::whereKey($buyer->id)->update(['status' => 'suspended']);
        try {
            app(CheckoutOrderService::class)->place($buyer, $cart, $payload['item_ids'], $payload);
            $this->fail('A replay cannot reopen buyer access.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Your account is not active and cannot place an order.', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshots());
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_recorded_checkout_results_prevent_destructive_migration_rollback(): void
    {
        [$buyer, , , $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $before = $this->snapshots();
        $migration = require database_path('migrations/2026_10_06_100000_create_checkout_submissions.php');
        try {
            $migration->down();
            $this->fail('A rollback must retain original checkout results.');
        } catch (LogicException $exception) {
            $this->assertSame('Recorded checkout results must be retained.', $exception->getMessage());
        }
        $this->assertDatabaseCount('checkout_submissions', 1);
        $this->assertDatabaseCount('checkout_submission_orders', 1);
        $this->assertSame($before, $this->snapshots());
        $this->post('/checkout', $payload)->assertSessionHas('success');
    }

    public function test_a_completed_result_cannot_gain_another_owned_legacy_order(): void
    {
        [$buyer, $cart, , $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $original = Order::firstOrFail();
        $legacy = Order::factory()->create(['buyer_id' => $buyer->id, 'created_at' => now()->subDay()]);
        $before = $this->snapshots();
        try {
            CheckoutSubmission::firstOrFail()->orders()->attach($legacy->id);
            $this->fail('An original result cannot be extended after it is committed.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshots());
        }
        $replayed = app(CheckoutOrderService::class)->place($buyer, $cart, $payload['item_ids'], $payload);
        $this->assertSame([$original->id], $replayed->pluck('id')->all());
        $this->assertDatabaseCount('checkout_submission_orders', 1);
    }

    #[DataProvider('retainedResultWrites')]
    public function test_original_checkout_result_and_ownership_cannot_be_rewritten(string $operation): void
    {
        [$buyer, , , $payload] = $this->checkout();
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHas('success');
        $submission = CheckoutSubmission::firstOrFail();
        $order = Order::firstOrFail();
        $other = User::factory()->create();
        $before = $this->snapshots();
        try {
            match ($operation) {
                'model update' => $submission->update(['request_hash' => str_repeat('a', 64)]),
                'model delete' => $submission->delete(),
                'raw result update' => DB::table('checkout_submissions')->where('id', $submission->id)->update(['request_hash' => str_repeat('a', 64)]),
                'raw link delete' => DB::table('checkout_submission_orders')->delete(),
                'order delete' => DB::table('orders')->where('id', $order->id)->delete(),
                'buyer change' => DB::table('orders')->where('id', $order->id)->update(['buyer_id' => $other->id]),
                'duplicate token' => CheckoutSubmission::create(array_diff_key($submission->getAttributes(), ['id' => true])),
            };
            $this->fail('The original result must be retained.');
        } catch (LogicException|QueryException) {
            $this->assertSame($before, $this->snapshots());
        }
        $this->assertDatabaseCount('checkout_submissions', 1);
        $this->assertDatabaseCount('checkout_submission_orders', 1);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertArrayNotHasKey('token_hash', $submission->toArray());
        $this->assertArrayNotHasKey('request_hash', $submission->toArray());
    }

    public static function retainedResultWrites(): array
    {
        return [['model update'], ['model delete'], ['raw result update'], ['raw link delete'], ['order delete'], ['buyer change'], ['duplicate token']];
    }

    private function addShop(Cart $cart, string $city = 'Manila'): array
    {
        $shop = Shop::factory()->approved()->create(['city' => $city]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 10, 'price' => '100.00']);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '100.00']);

        return [$product, $line];
    }

    private function voucher(): Voucher
    {
        return Voucher::create(['code' => 'SAVE_10', 'name' => 'Bagoo Savings', 'discount_type' => 'percent',
            'discount_value' => 10, 'min_spend' => 0, 'usage_limit' => 1, 'used_count' => 0, 'is_active' => true]);
    }

    private function snapshots(): array
    {
        return [Order::orderBy('id')->get()->map->getRawOriginal()->all(),
            Delivery::orderBy('id')->get()->map->getRawOriginal()->all(),
            DB::table('order_items')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }
}
