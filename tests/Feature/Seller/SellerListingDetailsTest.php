<?php

namespace Tests\Feature\Seller;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class SellerListingDetailsTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $this->actingAs($this->shop->user);
    }

    private function listing(array $extra = []): array
    {
        return [...['name' => 'Everyday Canvas Carryall', 'description' => 'A reinforced canvas carryall with durable handles.',
            'price' => '450.00', 'stock' => 20, 'category_id' => $this->shop->root_category_id, 'weight_kg' => '0.80',
            'featured_image' => '/storage/products/carryall.jpg', 'brand' => 'Bagoo Studio', 'condition' => 'new',
            'length_cm' => '30.00', 'width_cm' => '20.00', 'height_cm' => '5.00', 'status' => 'active'], ...$extra];
    }

    public function test_all_listing_details_save_reload_and_reach_the_owned_editor_and_public_product(): void
    {
        $this->post('/seller/products', $this->listing())->assertSessionHasNoErrors();
        $product = Product::sole();
        foreach (['brand' => 'Bagoo Studio', 'condition' => 'new', 'weight_kg' => '0.80', 'length_cm' => '30.00', 'width_cm' => '20.00', 'height_cm' => '5.00'] as $field => $expected) {
            $this->assertSame($expected, $product->$field);
        }
        $this->get('/seller/products')->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.data.0.weight_kg', '0.80')->where('products.data.0.brand', 'Bagoo Studio'));
        $this->get('http://localhost:8000/product/'.$product->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.weight_kg', '0.80')->where('product.length_cm', '30.00')->where('product.condition', 'new'));
        $this->put('/seller/products/'.$product->id, $this->listing(['weight_kg' => '1.25', 'brand' => 'No brand']))->assertSessionHasNoErrors();
        $this->assertSame('1.25', $product->fresh()->weight_kg);
        $this->assertSame('No brand', $product->fresh()->brand);
    }

    public static function requiredFields(): array
    {
        return array_map(fn ($field) => [$field], ['name', 'description', 'featured_image', 'category_id', 'price', 'stock', 'weight_kg']);
    }

    #[DataProvider('requiredFields')]
    public function test_each_publication_requirement_is_rejected_independently_without_creating_a_product(string $field): void
    {
        $input = $this->listing();
        unset($input[$field]);
        $key = $field === 'featured_image' ? 'gallery_manifest' : $field;
        $response = $this->postJson('/seller/products', $input)->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->assertSame([$key], array_keys($response->json('errors')));
        $this->assertDatabaseCount('products', 0);
    }

    public static function measurements(): array
    {
        return [
            ['weight_kg', '0', false], ['weight_kg', '-1', false], ['weight_kg', 'abc', false], ['weight_kg', '0.01', true],
            ['weight_kg', '0.001', false], ['weight_kg', '50000.00', true], ['weight_kg', '50000.01', false],
            ['length_cm', '0', false], ['width_cm', '-1', false], ['height_cm', 'abc', false], ['height_cm', '0.01', true],
            ['height_cm', '10000.00', true], ['height_cm', '10000.01', false], ['height_cm', '1.001', false],
        ];
    }

    #[DataProvider('measurements')]
    public function test_measurement_boundaries_are_shared_by_creation_and_update(string $field, string $value, bool $accepted): void
    {
        $input = $this->listing([$field => $value]);
        $response = $this->postJson('/seller/products', $input);
        if ($accepted) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            $product = Product::sole();
            $this->assertSame(number_format((float) $value, 2, '.', ''), $product->$field);
            $this->put('/seller/products/'.$product->id, $input)->assertSessionHasNoErrors();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors($field);
            $product = Product::factory()->create(['shop_id' => $this->shop->id]);
            $before = $product->fresh()->getRawOriginal();
            $this->putJson('/seller/products/'.$product->id, $input)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, $product->fresh()->getRawOriginal());
        }
    }

    public function test_dimensions_are_optional_but_cannot_be_partial_and_drafts_have_no_invented_weight(): void
    {
        $input = $this->listing();
        unset($input['width_cm']);
        $this->postJson('/seller/products', $input)->assertUnprocessable()->assertJsonValidationErrors('width_cm');
        $this->post('/seller/products', ['name' => 'Private Draft Carryall', 'status' => 'draft'])->assertSessionHasNoErrors();
        $draft = Product::sole();
        $this->assertNull($draft->weight_kg);
        $this->assertNull($draft->length_cm);
        $this->put('/seller/products/'.$draft->id, $this->listing(['length_cm' => null, 'width_cm' => null, 'height_cm' => null]))->assertSessionHasNoErrors();
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->length_cm);
    }

    public function test_checkout_retains_declared_unit_weight_for_the_waybill_after_product_edits_and_retry(): void
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'weight_kg' => '0.80', 'price' => '100.00', 'stock' => 5]);
        $buyer = User::factory()->buyer()->create();
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '100.00']);
        $this->createCheckoutNetwork($this->shop, ['Pasig' => 'Metro Manila']);
        $input = ['recipient_name' => 'Ana Reyes', 'recipient_phone' => '09171234567', 'shipping_address' => '21 Mabini Street',
            'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1600',
            'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep', 'payment_method' => 'cod', 'item_ids' => [$line->id],
            'checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart)];
        $this->actingAs($buyer)->post('/checkout', $input)->assertSessionHasNoErrors();
        $order = Order::sole();
        $item = $order->items()->sole();
        $this->assertSame('0.80', $item->declared_weight_kg);
        $this->assertSame(2, $item->quantity);
        $product->update(['weight_kg' => '9.99']);
        $this->post('/checkout', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('0.80', $item->fresh()->declared_weight_kg);
        $this->actingAs($this->shop->user)->get('/seller/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.data.0.items.0.declared_weight_kg', '0.80'));
        $this->assertSame('50.00', $order->fresh()->shipping_fee);
    }
}
