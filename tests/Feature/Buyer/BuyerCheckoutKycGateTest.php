<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerCheckoutKycGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function createCartWithProduct(User $buyer): array
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $shop = Shop::factory()->approved()->create([
            'user_id' => $seller->id,
            'name' => 'Kyc Test Shop',
            'slug' => 'kyc-test-shop',
            'status' => 'active',
            'address' => 'Lopez Avenue, Batong Malake',
            'city' => 'Los Banos',
        ]);

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'is_active' => true,
            'parent_id' => $shop->root_category_id,
        ]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Sample Item',
            'slug' => 'sample-item',
            'price' => 250.00,
            'stock' => 20,
            'status' => 'active',
        ]);

        $cart = Cart::create(['user_id' => $buyer->id]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 250.00,
        ]);

        return [$seller, $shop, $product, $cart];
    }

    public function test_unverified_buyer_uses_holding_and_cannot_open_checkout_or_place_an_order(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'none',
        ]);

        $this->createCartWithProduct($buyer);

        $response = $this->actingAs($buyer)->get('/checkout');
        $response->assertRedirect(route('kyc.pending'));
        $this->get(route('kyc.pending'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/BuyerApproval')->where('user.kyc_status', 'none'));

        $postResponse = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Unverified Buyer',
            'recipient_phone' => '09171234567',
            'shipping_address' => '123 Test St',
            'shipping_city' => 'Manila',
            'payment_method' => 'cod',
        ]);

        $postResponse->assertRedirect();
        $postResponse->assertRedirect(route('kyc.pending'));
        $postResponse->assertSessionHas('error', 'Identity verification is required before placing an order. Submit your application for review.');
        $this->assertEquals(0, Order::count());
    }

    public function test_buyer_can_upload_id_document_at_checkout_and_transitions_to_pending_approval(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'none',
            'id_document_path' => null,
        ]);

        $idFile = UploadedFile::fake()->create('government_id.jpg', 500, 'image/jpeg');

        $response = $this->actingAs($buyer)->post('/checkout/kyc/upload', [
            'id_document' => $idFile,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $buyer->refresh();
        $this->assertEquals('pending_approval', $buyer->kyc_status);
        $this->assertNotNull($buyer->id_document_path);
        $this->assertNotNull($buyer->kyc_submitted_at);
        Storage::disk('local')->assertExists(str_replace('/storage/', '', $buyer->id_document_path));
    }

    public function test_pending_buyer_cannot_place_order_and_is_prompted_to_wait(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'pending_approval',
        ]);

        $this->createCartWithProduct($buyer);

        $response = $this->actingAs($buyer)->get('/checkout');
        $response->assertRedirect(route('kyc.pending'));
        $this->get(route('kyc.pending'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/BuyerApproval')->where('user.kyc_status', 'pending_approval'));

        $postResponse = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Pending Buyer',
            'recipient_phone' => '09171234567',
            'shipping_address' => '123 Test St',
            'shipping_city' => 'Manila',
            'payment_method' => 'cod',
        ]);

        $postResponse->assertRedirect();
        $postResponse->assertSessionHas('error', 'Your ID verification is currently pending review. Please wait for approval before completing your purchase.');
        $this->assertEquals(0, Order::count());
    }

    public function test_approved_status_is_authoritative_even_when_document_path_is_not_shared(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
            'id_document_path' => null,
        ]);

        $this->createCartWithProduct($buyer);

        $response = $this->actingAs($buyer)->get('/checkout');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Checkout/Index')
            ->where('kycStatus', 'approved')
        );
    }

    public function test_inactive_buyer_cannot_place_order_even_with_approved_kyc(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'suspended',
            'kyc_status' => 'approved',
        ]);

        $this->createCartWithProduct($buyer);

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Suspended Buyer',
            'recipient_phone' => '09171234567',
            'shipping_address' => '123 Test St',
            'shipping_city' => 'Manila',
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Your account is not active and cannot place an order.');
        $this->assertEquals(0, Order::count());
    }

    public function test_approved_buyer_can_successfully_place_order(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        [, , , $cart] = $this->createCartWithProduct($buyer);

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Approved Buyer',
            'recipient_phone' => '09171234567',
            'shipping_address' => 'Pedro Guevara Avenue, Poblacion III',
            'shipping_city' => 'Santa Cruz',
            'shipping_province' => 'Laguna',
            'shipping_postal_code' => '4009',
            'destination_barangay' => 'Poblacion III',
            'item_ids' => [$cart->items->first()->id],
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect(route('buyer.orders.index'));
        $this->assertEquals(1, Order::count());
    }
}
