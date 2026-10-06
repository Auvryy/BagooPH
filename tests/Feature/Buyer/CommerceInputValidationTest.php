<?php

namespace Tests\Feature\Buyer;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\BuyerAddressService;
use App\Services\Orders\CheckoutOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class CommerceInputValidationTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use RefreshDatabase;

    private function checkout(): array
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => '100.00', 'stock' => 10]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '100.00']);
        $this->createCheckoutNetwork($shop, ['Pasig' => 'Metro Manila']);

        return [$buyer, $cart, $product, [
            'recipient_name' => 'Maria Santos', 'recipient_phone' => '09171234567',
            'shipping_address' => '123 Mabini Street', 'shipping_city' => 'Pasig',
            'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1600',
            'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep',
            'payment_method' => 'cod', 'item_ids' => [$line->id],
        ]];
    }

    #[DataProvider('invalidCheckoutFields')]
    public function test_checkout_rejects_unsafe_fields_without_consuming_the_bag(string $field, mixed $value, ?string $errorField = null): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $payload[$field] = $value;

        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)
            ->assertRedirect('/checkout')->assertSessionHasErrors($errorField ?? $field);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('addresses', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public static function invalidCheckoutFields(): array
    {
        return [
            'name markup' => ['recipient_name', '<b>Maria123</b>'],
            'name digits' => ['recipient_name', 'Maria 123'],
            'name punctuation' => ['recipient_name', '---'],
            'name long' => ['recipient_name', str_repeat('a', 101)],
            'name control before trim' => ['recipient_name', "\tMaria Santos"],
            'name bidi' => ['recipient_name', "Maria\u{202E} Santos"],
            'phone text' => ['recipient_phone', 'not-a-phone'],
            'phone extension' => ['recipient_phone', '09171234567 ext 3'],
            'phone Unicode digits' => ['recipient_phone', '０９１７１２３４５６７'],
            'phone control before trim' => ['recipient_phone', "09171234567\n"],
            'street markup' => ['shipping_address', '<script>alert(1)</script>'],
            'street punctuation' => ['shipping_address', '-----'],
            'street short' => ['shipping_address', 'Road'],
            'street long' => ['shipping_address', str_repeat('a', 501)],
            'street hidden' => ['shipping_address', "123\u{200B} Street"],
            'city markup' => ['shipping_city', '<b>Pasig</b>'],
            'province hidden' => ['shipping_province', "Metro\u{2066} Manila"],
            'barangay markup' => ['destination_barangay', '<b>San Antonio</b>'],
            'postal Unicode' => ['shipping_postal_code', '１６００'],
            'postal suffix control' => ['shipping_postal_code', "1600\n"],
            'landmark markup' => ['landmark', '<b>School</b>'],
            'notes markup' => ['notes', '<img src=x onerror=alert(1)>'],
            'notes hidden' => ['notes', "Near\u{200B} the school"],
            'voucher markup' => ['voucher_code', '<b>SAVE</b>'],
            'voucher Unicode' => ['voucher_code', 'ＳＡＶＥ'],
            'missing name' => ['recipient_name', null],
            'missing phone' => ['recipient_phone', null],
            'missing address' => ['shipping_address', null],
            'missing city' => ['shipping_city', null],
            'missing province' => ['shipping_province', null],
            'missing barangay' => ['destination_barangay', null],
            'missing postal' => ['shipping_postal_code', null],
            'name non Latin' => ['recipient_name', 'Мария'],
            'street control before trim' => ['shipping_address', "\n123 Mabini Street"],
            'landmark short' => ['landmark', 'Gate'],
            'latitude too large' => ['shipping_latitude', '90.1'],
            'latitude scientific notation' => ['shipping_latitude', '1e1'],
            'latitude hidden' => ['shipping_latitude', "14.5\u{200B}"],
            'latitude precision' => ['shipping_latitude', '14.12345678'],
            'missing coordinate pair' => ['shipping_latitude', '14.5', 'shipping_longitude'],
            'notes length' => ['notes', str_repeat('a', 501)],
            'unknown delivery type' => ['delivery_type', 'direct'],
            'unknown payment method' => ['payment_method', 'free'],
            'invalid save flag' => ['save_address', 'true'],
        ];
    }

    #[DataProvider('invalidCheckoutFields')]
    public function test_direct_order_service_has_the_same_input_boundary(string $field, mixed $value, ?string $errorField = null): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $payload[$field] = $value;
        try {
            app(CheckoutOrderService::class)->place($buyer, $cart, $payload['item_ids'], $payload);
            $this->fail('Malformed checkout details must fail through the service too.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorField ?? $field, $exception->errors());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    #[DataProvider('invalidAddressFields')]
    public function test_address_rejects_unsafe_fields_and_keeps_the_existing_default(string $field, mixed $value): void
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $default = $this->address($buyer, true);
        $before = $default->fresh()->getRawOriginal();
        $payload = ['phone' => '09171234567', 'province' => 'Metro Manila', 'city' => 'Pasig',
            'barangay' => 'San Antonio', 'street' => '123 Mabini Street', 'postal_code' => '1600', 'is_default' => true];
        $payload[$field] = $value;

        $this->actingAs($buyer)->from('/buyer/profile')->post('/buyer/addresses', $payload)
            ->assertRedirect('/buyer/profile')->assertSessionHasErrors($field);

        $this->assertDatabaseCount('addresses', 1);
        $this->assertSame($before, $default->fresh()->getRawOriginal());
    }

    public static function invalidAddressFields(): array
    {
        return [
            'phone malformed' => ['phone', 'hello'],
            'phone control before trim' => ['phone', "09171234567\n"],
            'street markup' => ['street', '<b>123 Mabini Street</b>'],
            'street short' => ['street', 'Road'],
            'street punctuation' => ['street', '-----'],
            'city markup' => ['city', '<b>Pasig</b>'],
            'province hidden' => ['province', "Metro\u{200B} Manila"],
            'barangay control' => ['barangay', "\tSan Antonio"],
            'postal Unicode' => ['postal_code', '１６００'],
            'postal short' => ['postal_code', '160'],
            'type unknown' => ['type', 'Arbitrary label'],
            'street long' => ['street', str_repeat('a', 501)],
            'phone missing' => ['phone', null],
            'street missing' => ['street', null],
            'city missing' => ['city', null],
            'province long' => ['province', str_repeat('a', 101)],
            'landmark markup' => ['landmark', '<b>School</b>'],
            'invalid default flag' => ['is_default', 'true'],
        ];
    }

    #[DataProvider('invalidAddressFields')]
    public function test_direct_address_service_has_the_same_input_boundary(string $field, mixed $value): void
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $default = $this->address($buyer, true);
        $before = $default->fresh()->getRawOriginal();
        $payload = ['phone' => '09171234567', 'city' => 'Pasig', 'street' => '123 Mabini Street', 'is_default' => true];
        $payload[$field] = $value;
        try {
            app(BuyerAddressService::class)->create($buyer, $payload);
            $this->fail('Malformed address details must fail through the service too.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('addresses', 1);
        $this->assertSame($before, $default->fresh()->getRawOriginal());
    }

    public function test_address_normalizes_valid_values_without_truncating_a_500_character_street(): void
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $address = app(BuyerAddressService::class)->create($buyer, ['recipient_name' => 'Untrusted Recipient',
            'phone' => ' 63 (917) 123-4567 ', 'street' => ' '.str_repeat('Ａ', 500).' ',
            'city' => ' Ｐａｓｉｇ ', 'province' => ' Metro Manila ', 'postal_code' => ' 1600 ',
            'latitude' => '14.5000000', 'longitude' => '121.0000000', 'type' => 'Office']);

        $this->assertSame('Maria Santos', $address->recipient_name);
        $this->assertSame('+639171234567', $address->phone);
        $this->assertSame(str_repeat('A', 500), $address->fresh()->street);
        $this->assertSame('Pasig', $address->city);
        $this->assertSame('1600', $address->postal_code);
        $this->assertSame(14.5, $address->latitude);
        $this->assertTrue($address->is_default);
    }

    #[DataProvider('addressActions')]
    public function test_failure_after_default_change_rolls_back_all_address_changes(string $action): void
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $first = $this->address($buyer, false);
        $default = $this->address($buyer, true);
        $before = $buyer->addresses()->orderBy('id')->get()->map->getRawOriginal()->all();
        Event::listen('eloquent.updating: '.Address::class, function (Address $address) use ($first) {
            if ($address->id === $first->id && $address->is_default) {
                throw new RuntimeException('Default address storage failed.');
            }
        });
        try {
            if ($action === 'switch') {
                app(BuyerAddressService::class)->setDefault($buyer, $first);
            } else {
                app(BuyerAddressService::class)->delete($buyer, $default);
            }
            $this->fail('The default write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Default address storage failed.', $exception->getMessage());
        }
        $this->assertSame($before, $buyer->addresses()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public static function addressActions(): array
    {
        return [['switch'], ['delete']];
    }

    public function test_stale_account_and_forged_address_owner_cannot_bypass_the_address_service(): void
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $other = User::factory()->create();
        $foreign = $this->address($other, true);
        $foreign->user_id = $buyer->id;
        try {
            app(BuyerAddressService::class)->setDefault($buyer, $foreign);
            $this->fail('A forged model cannot change the actual owner.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        User::whereKey($buyer->id)->update(['status' => 'suspended']);
        try {
            app(BuyerAddressService::class)->create($buyer, ['phone' => '09171234567', 'city' => 'Pasig', 'street' => '123 Mabini Street']);
            $this->fail('Current buyer eligibility must be checked.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('addresses', 1);
        }
        $this->assertTrue($foreign->fresh()->is_default);
        $this->assertSame($other->id, $foreign->fresh()->user_id);
    }

    public function test_profile_and_checkout_reads_do_not_invent_or_migrate_legacy_address_fields(): void
    {
        [$buyer, $cart, $product] = $this->checkout();
        $buyer->update(['phone' => null, 'province' => null]);
        $before = $buyer->fresh()->getRawOriginal();
        $this->actingAs($buyer)->get('/buyer/profile')->assertOk();
        $this->get('/checkout')->assertOk();
        $this->assertDatabaseCount('addresses', 0);
        $this->assertSame($before, $buyer->fresh()->getRawOriginal());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_failed_address_insert_preserves_the_previous_default(): void
    {
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $default = $this->address($buyer, true);
        $before = $default->fresh()->getRawOriginal();
        Event::listen('eloquent.creating: '.Address::class, fn () => throw new RuntimeException('Address storage failed.'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($buyer)->post('/buyer/addresses', ['phone' => '09171234567', 'city' => 'Pasig',
                'street' => '123 Mabini Street', 'is_default' => true]);
            $this->fail('Address storage failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Address storage failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('addresses', 1);
        $this->assertSame($before, $default->fresh()->getRawOriginal());
    }

    public function test_optional_address_failure_rolls_back_the_entire_checkout(): void
    {
        [$buyer, $cart, $product, $payload] = $this->checkout();
        $payload['save_address'] = true;
        Event::listen('eloquent.creating: '.Address::class, fn () => throw new RuntimeException('Address storage failed.'));

        $this->actingAs($buyer)->from('/checkout')->post('/checkout', $payload)
            ->assertRedirect('/checkout')->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('addresses', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
    }

    private function address(User $buyer, bool $default): Address
    {
        return $buyer->addresses()->create(['recipient_name' => $buyer->name, 'phone' => '+639171234567',
            'city' => 'Manila', 'street' => '100 Rizal Street', 'is_default' => $default]);
    }
}
