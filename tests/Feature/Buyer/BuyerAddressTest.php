<?php

namespace Tests\Feature\Buyer;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerAddressTest extends TestCase
{
    use RefreshDatabase;

    private function createBuyerWithCart(): array
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Sample Store',
            'slug' => 'sample-store',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'is_active' => true,
        ]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Sample Item',
            'slug' => 'sample-item',
            'price' => 300.00,
            'stock' => 15,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $cart = Cart::create(['user_id' => $buyer->id]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 300.00,
        ]);

        return [$buyer, $shop, $product];
    }

    public function test_user_can_create_new_address_and_it_persists(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->post('/buyer/addresses', [
            'recipient_name' => 'Juan Dela Cruz',
            'phone' => '+63 917 111 2222',
            'province' => 'Metro Manila',
            'city' => 'Taguig City',
            'barangay' => 'BGC',
            'street' => 'Unit 1204 High Street Residences',
            'postal_code' => '1634',
            'type' => 'Home',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'phone' => '+63 917 111 2222',
            'city' => 'Taguig City',
            'street' => 'Unit 1204 High Street Residences',
            'is_default' => true, // First address automatically becomes default
        ]);
    }

    public function test_first_address_created_automatically_becomes_default(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->assertEquals(0, $buyer->addresses()->count());

        $this->actingAs($buyer)->post('/buyer/addresses', [
            'recipient_name' => 'First Address',
            'phone' => '+63 917 111 0000',
            'city' => 'Makati',
            'street' => '123 Makati Ave',
            'is_default' => false,
        ]);

        $first = $buyer->addresses()->first();
        $this->assertNotNull($first);
        $this->assertTrue($first->is_default);
    }

    public function test_adding_second_address_without_default_keeps_first_as_default(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $addr1 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Address 1',
            'phone' => '+63 917 000 0001',
            'city' => 'Manila',
            'street' => 'Street 1',
            'is_default' => true,
        ]);

        $this->actingAs($buyer)->post('/buyer/addresses', [
            'recipient_name' => 'Address 2',
            'phone' => '+63 917 000 0002',
            'city' => 'Pasig',
            'street' => 'Street 2',
            'is_default' => false,
        ]);

        $addr1->refresh();
        $addr2 = Address::where('user_id', $buyer->id)->where('street', 'Street 2')->first();

        $this->assertTrue($addr1->is_default);
        $this->assertFalse($addr2->is_default);
    }

    public function test_adding_address_marked_as_default_unsets_previous_default(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $addr1 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Address 1',
            'phone' => '+63 917 000 0001',
            'city' => 'Manila',
            'street' => 'Street 1',
            'is_default' => true,
        ]);

        $this->actingAs($buyer)->post('/buyer/addresses', [
            'recipient_name' => 'Address 2',
            'phone' => '+63 917 000 0002',
            'city' => 'Pasig',
            'street' => 'Street 2',
            'is_default' => true,
        ]);

        $addr1->refresh();
        $addr2 = Address::where('user_id', $buyer->id)->where('street', 'Street 2')->first();

        $this->assertFalse($addr1->is_default);
        $this->assertTrue($addr2->is_default);
    }

    public function test_user_can_switch_default_address(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $addr1 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Home',
            'phone' => '+63 917 000 0001',
            'city' => 'Manila',
            'street' => 'Street 1',
            'is_default' => true,
        ]);

        $addr2 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Office',
            'phone' => '+63 917 000 0002',
            'city' => 'Taguig',
            'street' => 'Street 2',
            'is_default' => false,
        ]);

        $response = $this->actingAs($buyer)->post("/buyer/addresses/{$addr2->id}/default");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $addr1->refresh();
        $addr2->refresh();

        $this->assertFalse($addr1->is_default);
        $this->assertTrue($addr2->is_default);
    }

    public function test_user_can_delete_address_and_oldest_becomes_default(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $addr1 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Oldest Address',
            'phone' => '+63 917 000 0001',
            'city' => 'Manila',
            'street' => 'Street 1',
            'is_default' => false,
        ]);

        $addr2 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Default Address',
            'phone' => '+63 917 000 0002',
            'city' => 'Taguig',
            'street' => 'Street 2',
            'is_default' => true,
        ]);

        $response = $this->actingAs($buyer)->delete("/buyer/addresses/{$addr2->id}");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('addresses', ['id' => $addr2->id]);

        $addr1->refresh();
        $this->assertTrue($addr1->is_default);
    }

    public function test_user_cannot_access_or_modify_another_users_address(): void
    {
        $buyerA = User::factory()->create(['role' => 'buyer']);
        $buyerB = User::factory()->create(['role' => 'buyer']);

        $addrB = Address::create([
            'user_id' => $buyerB->id,
            'recipient_name' => 'Buyer B Address',
            'phone' => '+63 917 000 0002',
            'city' => 'Cebu',
            'street' => 'Osmena Blvd',
            'is_default' => true,
        ]);

        $response = $this->actingAs($buyerA)->post("/buyer/addresses/{$addrB->id}/default");
        $response->assertStatus(403);

        $deleteResponse = $this->actingAs($buyerA)->delete("/buyer/addresses/{$addrB->id}");
        $deleteResponse->assertStatus(403);
    }

    public function test_checkout_loads_saved_addresses_and_identifies_default_address(): void
    {
        [$buyer] = $this->createBuyerWithCart();

        $addr1 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Alex Primary',
            'phone' => '+63 917 999 1111',
            'city' => 'Mandaluyong',
            'street' => 'EDSA Crossing',
            'is_default' => true,
        ]);

        $addr2 = Address::create([
            'user_id' => $buyer->id,
            'recipient_name' => 'Alex Secondary',
            'phone' => '+63 917 999 2222',
            'city' => 'San Juan',
            'street' => 'Greenhills Ave',
            'is_default' => false,
        ]);

        $response = $this->actingAs($buyer)->get('/checkout');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Checkout/Index')
            ->has('addresses', 2)
            ->where('defaultAddressId', $addr1->id)
        );
    }

    public function test_checkout_places_order_with_selected_saved_address(): void
    {
        [$buyer] = $this->createBuyerWithCart();

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Selected Recipient',
            'recipient_phone' => '+63 918 333 4444',
            'shipping_address' => 'Unit 701, Tower 2, Ayala Ave',
            'shipping_city' => 'Makati City',
            'shipping_postal_code' => '1226',
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect(route('buyer.orders.index'));

        $order = Order::latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('Selected Recipient', $order->recipient_name);
        $this->assertEquals('+63 918 333 4444', $order->recipient_phone);
        $this->assertEquals('Unit 701, Tower 2, Ayala Ave', $order->shipping_address);
        $this->assertEquals('Makati City', $order->shipping_city);
    }

    public function test_checkout_can_save_new_address_to_address_book(): void
    {
        [$buyer] = $this->createBuyerWithCart();

        $this->assertEquals(0, $buyer->addresses()->count());

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'New Address Recipient',
            'recipient_phone' => '+63 918 555 6666',
            'shipping_address' => '99 Sunset Blvd',
            'shipping_city' => 'Pasig City',
            'shipping_postal_code' => '1600',
            'payment_method' => 'cod',
            'save_address' => true,
        ]);

        $response->assertRedirect(route('buyer.orders.index'));

        $savedAddress = Address::where('user_id', $buyer->id)->first();
        $this->assertNotNull($savedAddress);
        $this->assertEquals($buyer->name, $savedAddress->recipient_name);
        $this->assertEquals('+63 918 555 6666', $savedAddress->phone);
        $this->assertEquals('99 Sunset Blvd', $savedAddress->street);
        $this->assertEquals('Pasig City', $savedAddress->city);
    }

    public function test_address_recipient_name_is_strictly_locked_to_user_real_name_ignoring_payload(): void
    {
        $buyer = User::factory()->create([
            'name' => 'Maria Clara Santos',
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        // Attempt to spoof recipient name via address book endpoint
        $this->actingAs($buyer)->post('/buyer/addresses', [
            'recipient_name' => 'Fake Spoofed Name',
            'phone' => '+63 917 123 4567',
            'city' => 'Quezon City',
            'street' => '101 Katipunan Ave',
            'type' => 'Home',
        ]);

        $address = Address::where('user_id', $buyer->id)->first();
        $this->assertNotNull($address);
        $this->assertEquals('Maria Clara Santos', $address->recipient_name);
        $this->assertNotEquals('Fake Spoofed Name', $address->recipient_name);
    }
}
