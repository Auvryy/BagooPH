<?php

namespace Tests\Feature\Buyer;

use App\Models\Category;
use App\Models\Message;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMerchantChatTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $seller;
    private Shop $shop;
    private Category $category;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->shop = Shop::factory()->create([
            'user_id' => $this->seller->id,
            'name' => 'Metro Flagship Store',
            'status' => 'active',
        ]);

        $this->category = Category::factory()->create([
            'name' => 'Apparel',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Premium Heavyweight Oversized Tee',
            'price' => 799.00,
            'stock' => 50,
            'status' => 'active',
            'featured_image' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=800&auto=format&fit=crop&q=80',
            'description' => '100% cotton premium boxy streetwear tee.',
        ]);
    }

    public function test_authenticated_buyer_can_send_message_with_product_attachment(): void
    {
        $response = $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->seller->id,
                'shop_id' => $this->shop->id,
                'product_id' => $this->product->id,
                'message' => 'Is this item available in XL size?',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->buyer->id,
            'receiver_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'message' => 'Is this item available in XL size?',
            'is_read' => false,
        ]);

        $responseData = $response->json();
        $this->assertEquals($this->product->id, $responseData['message']['product']['id']);
        $this->assertEquals('Premium Heavyweight Oversized Tee', $responseData['message']['product']['name']);
    }

    public function test_receiver_id_is_auto_resolved_from_product_when_omitted(): void
    {
        $response = $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'product_id' => $this->product->id,
                'message' => 'When can this be shipped out?',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->buyer->id,
            'receiver_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'message' => 'When can this be shipped out?',
        ]);
    }

    public function test_receiver_id_is_auto_resolved_from_shop_when_omitted(): void
    {
        $response = $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'shop_id' => $this->shop->id,
                'message' => 'Do you offer bulk discounts for corporate orders?',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->buyer->id,
            'receiver_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'message' => 'Do you offer bulk discounts for corporate orders?',
        ]);
    }

    public function test_seller_can_view_conversation_in_seller_inbox_with_product_relation(): void
    {
        Message::create([
            'sender_id' => $this->buyer->id,
            'receiver_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'message' => 'Hello seller, inquiry for this product.',
            'is_read' => false,
        ]);

        $response = $this->actingAs($this->seller)
            ->get('/seller/messages');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Seller/Messages')
            ->has('conversations', 1)
            ->where('conversations.0.messages.0.product_id', $this->product->id)
            ->where('conversations.0.messages.0.product.name', 'Premium Heavyweight Oversized Tee')
        );
    }

    public function test_buyer_can_view_conversation_in_buyer_inbox_with_product_relation(): void
    {
        Message::create([
            'sender_id' => $this->buyer->id,
            'receiver_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'message' => 'Hello seller, inquiry for this product.',
            'is_read' => false,
        ]);

        $response = $this->actingAs($this->buyer)
            ->get('/messages');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Buyer/Messages')
            ->has('conversations', 1)
            ->where('conversations.0.messages.0.product_id', $this->product->id)
            ->where('conversations.0.messages.0.product.name', 'Premium Heavyweight Oversized Tee')
        );
    }

    public function test_unauthenticated_guest_cannot_send_chat_message(): void
    {
        $response = $this->postJson(route('chat.send'), [
            'product_id' => $this->product->id,
            'message' => 'Guest message attempt',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('messages', 0);
    }
}
