<?php

namespace Tests\Feature\Buyer;

use App\Models\Address;
use App\Models\Cart;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\BuyerAddressService;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class BuyerAddressBookTest extends TestCase
{
    use InteractsWithCheckoutNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['region' => '1300000000', 'recipient_name' => "Andrea O'Neil García", 'phone' => '09171234567',
            'province' => 'Metro Manila', 'city' => 'City of Pasig', 'barangay' => 'San Antonio', 'street' => '24 Mabini Street',
            'postal_code' => '1600', 'type' => 'Home', 'is_default' => false], $overrides);
    }

    private function add(User $buyer, array $overrides = []): Address
    {
        return app(BuyerAddressService::class)->create($buyer, $this->data($overrides))->fresh();
    }

    private function cart(User $buyer, Address $address): array
    {
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 20, 'price' => 250]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 250]);
        $this->createCheckoutNetwork($shop, [$address->city => $address->province]);

        return [$shop, $product, $cart, ['checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart),
            'item_ids' => [$line->id], 'recipient_name' => $address->recipient_name, 'recipient_phone' => $address->phone,
            'shipping_address' => $address->street, 'shipping_city' => $address->city, 'shipping_province' => $address->province,
            'shipping_postal_code' => $address->postal_code, 'destination_barangay' => $address->barangay, 'payment_method' => 'cod']];
    }

    public function test_saved_recipient_and_location_reload_without_changing_identity_or_existing_default(): void
    {
        $buyer = User::factory()->create(['name' => 'Registered Buyer']);
        $first = $this->add($buyer);
        $payload = $this->data(['recipient_name' => 'Delivery Recipient', 'city' => 'City of Makati', 'barangay' => 'Bel-Air', 'street' => '48 Acacia Avenue', 'type' => 'Office']);
        $this->actingAs($buyer)->from('/buyer/profile?tab=addresses')->post('/buyer/addresses', $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        $saved = $buyer->addresses()->where('id', '<>', $first->id)->sole();
        foreach ($payload as $key => $value) {
            $this->assertSame($key === 'phone' ? '+639171234567' : $value, $saved->$key);
        }
        $this->assertTrue($first->fresh()->is_default);
        $this->assertSame('Registered Buyer', $buyer->fresh()->name);
        $this->get('/buyer/profile?tab=addresses')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('addresses', 2)->where('addresses.0.id', $first->id)->where('addresses.1.recipient_name', 'Delivery Recipient')->where('addressBookLimit', 10));
        $this->get('/buyer/addresses/'.$saved->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('initialTab', 'addresses')->where('editingAddressId', $saved->id));
    }

    public function test_location_lists_and_direct_writes_keep_the_same_parent_relationships(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->getJson('/buyer/address-locations?region=1300000000')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('resolved.province', 'Metro Manila');
        $cities = $this->getJson('/buyer/address-locations?region=1300000000&province=Metro%20Manila')->assertOk()->json('cities');
        $this->assertContains('City of Makati', array_column($cities, 'value'));
        $this->assertNotContains('City of Cebu', array_column($cities, 'value'));
        $this->getJson('/buyer/address-locations?region=0700000000')->assertOk()->assertJsonCount(0, 'cities')->assertJsonCount(0, 'barangays');
        $choices = $this->getJson('/buyer/address-locations?region=0700000000&province=Cebu')->assertOk()->json('cities');
        $this->assertContains('City of Cebu', array_column($choices, 'value'));
        $this->postJson('/buyer/addresses', $this->data(['region' => '0700000000']))->assertJsonValidationErrors('province');
        $this->postJson('/buyer/addresses', $this->data(['city' => 'City of Cebu']))->assertJsonValidationErrors('city');
        $this->postJson('/buyer/addresses', $this->data(['barangay' => 'Capitol Site']))->assertJsonValidationErrors('barangay');
        $this->assertDatabaseCount('addresses', 0);
    }

    public static function missingFields(): array
    {
        $cases = [];
        foreach (['recipient_name', 'phone', 'region', 'province', 'city', 'barangay', 'street'] as $field) {
            foreach ([null, '', '   '] as $value) {
                $cases[] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('missingFields')]
    public function test_each_missing_required_field_has_an_error_and_preserves_existing_addresses(string $field, mixed $value): void
    {
        $buyer = User::factory()->create();
        $first = $this->add($buyer);
        $before = $first->getRawOriginal();
        $this->actingAs($buyer)->postJson('/buyer/addresses', $this->data([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('addresses', 1);
        $this->assertSame($before, $first->fresh()->getRawOriginal());
    }

    public static function phones(): array
    {
        return [['09171234567', true], ['+639171234567', true], ['0917123', false], ['091712345678', false], ['+14155552671', false]];
    }

    #[DataProvider('phones')]
    public function test_phone_formats_are_normalized_or_rejected(string $phone, bool $valid): void
    {
        $buyer = User::factory()->create();
        $response = $this->actingAs($buyer)->postJson('/buyer/addresses', $this->data(['phone' => $phone]));
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->assertSame('+639171234567', $buyer->addresses()->sole()->phone);
        } else {
            $response->assertJsonValidationErrors('phone');
            $this->assertDatabaseCount('addresses', 0);
        }
    }

    public function test_tenth_address_saves_but_eleventh_and_direct_service_cannot_replace_the_default(): void
    {
        $buyer = User::factory()->create();
        $first = $this->add($buyer);
        foreach (range(2, 9) as $number) {
            $this->add($buyer, ['street' => $number.' Garden Street']);
        }
        $this->actingAs($buyer)->postJson('/buyer/addresses', $this->data(['street' => '10 Garden Street']))->assertSessionHasNoErrors();
        $this->assertSame(10, $buyer->addresses()->count());
        $payload = $this->data(['is_default' => true]);
        $this->postJson('/buyer/addresses', $payload)->assertJsonValidationErrors('address_book');
        try {
            app(BuyerAddressService::class)->create($buyer, $payload);
            $this->fail('The service must enforce the address limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('address_book', $exception->errors());
        }
        $this->assertSame(10, $buyer->addresses()->count());
        $this->assertTrue($first->fresh()->is_default);
        $this->assertSame(1, $buyer->addresses()->where('is_default', true)->count());
        $this->patchJson('/buyer/addresses/'.$first->id, $this->data(['street' => 'Updated Default Street', 'is_default' => false]))->assertSessionHasNoErrors();
        $this->assertTrue($first->fresh()->is_default);
    }

    public function test_another_default_must_be_chosen_before_deletion_and_checkout_selects_it(): void
    {
        $buyer = User::factory()->create();
        $first = $this->add($buyer);
        $this->actingAs($buyer)->deleteJson('/buyer/addresses/'.$first->id)->assertJsonValidationErrors('address_book');
        $second = $this->add($buyer, ['recipient_name' => 'Office Recipient', 'type' => 'Office']);
        $this->deleteJson('/buyer/addresses/'.$first->id)->assertJsonValidationErrors('address_book');
        $this->post('/buyer/addresses/'.$second->id.'/default')->assertSessionHasNoErrors();
        $this->post('/buyer/addresses/'.$second->id.'/default')->assertSessionHasNoErrors();
        $this->delete('/buyer/addresses/'.$first->id)->assertSessionHasNoErrors();
        $this->assertNull($first->fresh());
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, $buyer->addresses()->where('is_default', true)->count());
        $this->cart($buyer, $second);
        $this->get('/checkout')->assertOk()->assertInertia(fn (Assert $page) => $page->where('defaultAddressId', $second->id)->where('addresses.0.recipient_name', 'Office Recipient'));
    }

    public function test_a_full_book_can_place_an_order_without_saving_but_cannot_bypass_the_limit(): void
    {
        $buyer = User::factory()->create();
        $first = $this->add($buyer);
        foreach (range(2, 10) as $number) {
            $this->add($buyer, ['street' => $number.' Garden Street']);
        }
        [$shop, $product, $cart, $payload] = $this->cart($buyer, $first);
        $noticeCount = NotificationDelivery::count();
        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload + ['save_address' => true])->assertSessionHasErrors('address_book');
        foreach (['orders', 'deliveries', 'checkout_submissions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('notification_deliveries', $noticeCount);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
        $this->post('/checkout', $payload + ['save_address' => false])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(10, $buyer->addresses()->count());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_address_edits_and_deletion_leave_buyer_seller_and_parcel_delivery_snapshots_unchanged(): void
    {
        $buyer = User::factory()->create();
        $this->add($buyer);
        $office = $this->add($buyer, ['recipient_name' => 'Original Recipient', 'street' => '84 Original Office Street', 'type' => 'Office']);
        [$shop, $product, $cart, $payload] = $this->cart($buyer, $office);
        $this->actingAs($buyer)->post('/checkout', $payload)->assertSessionHasNoErrors();
        $order = Order::sole();
        $original = $order->getRawOriginal();
        $parcel = $order->delivery->getRawOriginal();
        $this->patch('/buyer/addresses/'.$office->id, $this->data(['recipient_name' => 'Changed Future Recipient', 'street' => '15 Future Street']))->assertSessionHasNoErrors();
        $this->assertSame('Changed Future Recipient', $office->fresh()->recipient_name);
        $this->assertSame('15 Future Street', $office->fresh()->street);
        $this->delete('/buyer/addresses/'.$office->id)->assertSessionHasNoErrors();
        $this->assertSame($original, $order->fresh()->getRawOriginal());
        $this->assertSame($parcel, $order->delivery->fresh()->getRawOriginal());
        $this->get('/buyer/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.recipient_name', 'Original Recipient')->where('order.shipping_address', '84 Original Office Street')
            ->where('order.destination_barangay', 'San Antonio')->where('order.shipping_postal_code', '1600'));
        $this->actingAs($shop->user)->get('/seller/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.recipient_name', 'Original Recipient')->where('orders.data.0.shipping_address', '84 Original Office Street')
            ->where('orders.data.0.destination_barangay', 'San Antonio')->where('orders.data.0.shipping_postal_code', '1600')->where('orders.data.0.can_print_waybill', true));
    }

    public function test_checkout_address_save_errors_point_to_visible_shipping_fields_and_roll_back(): void
    {
        $buyer = User::factory()->create();
        $address = $this->add($buyer);
        [$shop, $product, $cart, $payload] = $this->cart($buyer, $address);
        $payload['save_address'] = true;
        $payload['destination_barangay'] = 'Capitol Site';
        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)
            ->assertSessionHasErrors('destination_barangay')->assertSessionDoesntHaveErrors('barangay');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertDatabaseCount('addresses', 1);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_other_buyers_cannot_read_edit_or_mutate_an_address_by_changing_its_identifier(): void
    {
        $owner = User::factory()->create();
        $foreign = $this->add($owner);
        $before = $foreign->getRawOriginal();
        $other = User::factory()->create();
        $this->actingAs($other)->get('/buyer/addresses/'.$foreign->id.'/edit')->assertForbidden();
        $this->getJson('/buyer/addresses/'.$foreign->id.'/edit')->assertForbidden();
        $this->patchJson('/buyer/addresses/'.$foreign->id, $this->data())->assertForbidden();
        $this->postJson('/buyer/addresses/'.$foreign->id.'/default')->assertForbidden();
        $this->deleteJson('/buyer/addresses/'.$foreign->id)->assertForbidden();
        $this->get('/buyer/profile?tab=addresses')->assertInertia(fn (Assert $page) => $page->has('addresses', 0));
        $this->assertSame($before, $foreign->fresh()->getRawOriginal());
        $this->get('/buyer/addresses/'.str_repeat('9', 60).'/edit')->assertNotFound();
    }

    public static function otherRoles(): array
    {
        return [['seller'], ['courier'], ['logistics'], ['admin']];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_read_or_mutate_a_buyers_address(string $role): void
    {
        $owner = User::factory()->create();
        $address = $this->add($owner);
        $before = $address->getRawOriginal();
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor)->getJson('/buyer/addresses/'.$address->id.'/edit')->assertForbidden();
        $this->postJson('/buyer/addresses', $this->data())->assertForbidden();
        $this->patchJson('/buyer/addresses/'.$address->id, $this->data())->assertForbidden();
        $this->postJson('/buyer/addresses/'.$address->id.'/default')->assertForbidden();
        $this->deleteJson('/buyer/addresses/'.$address->id)->assertForbidden();
        $this->assertSame($before, $address->fresh()->getRawOriginal());
        $this->assertDatabaseCount('addresses', 1);
    }

    public function test_region_migration_retains_existing_address_values_without_fabricating_a_region(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $migration = require database_path('migrations/2026_10_18_120000_add_region_to_saved_addresses.php');
        $migration->down();
        $buyer = User::factory()->create();
        $id = DB::table('addresses')->insertGetId(['user_id' => $buyer->id,
            'recipient_name' => 'Existing Recipient', 'phone' => 'legacy-contact', 'city' => 'Existing City', 'street' => 'Existing Street', 'is_default' => true]);
        $before = (array) DB::table('addresses')->find($id);
        $migration->up();
        $after = (array) DB::table('addresses')->find($id);
        $this->assertNull($after['region']);
        unset($after['region']);
        $this->assertSame($before, $after);
        $this->assertNull(Address::findOrFail($id)->region_name);
    }
}
