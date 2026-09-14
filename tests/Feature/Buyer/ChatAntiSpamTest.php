<?php

namespace Tests\Feature\Buyer;

use App\Models\Category;
use App\Models\Message;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatAntiSpamTest extends TestCase
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
            'name' => 'Apex Tactical Studio',
            'status' => 'active',
        ]);

        $this->category = Category::factory()->create([
            'name' => 'Gear',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Insulated Stainless Steel Flask 1000ml',
            'price' => 790.00,
            'stock' => 20,
            'status' => 'active',
            'description' => 'Double wall vacuum insulated flask.',
        ]);
    }

    public function test_anti_spam_guard_prevents_duplicate_rapid_messages(): void
    {
        // First message
        $response1 = $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->seller->id,
                'product_id' => $this->product->id,
                'message' => 'Is this available?',
            ]);

        $response1->assertStatus(200);
        $this->assertDatabaseCount('messages', 1);

        // Immediate identical duplicate attempt (spam simulation)
        $response2 = $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->seller->id,
                'product_id' => $this->product->id,
                'message' => 'Is this available?',
            ]);

        $response2->assertStatus(200);
        $response2->assertJson(['is_duplicate' => true]);

        // Assert database still only contains 1 message (no duplicate spam)
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_different_messages_are_not_blocked_by_anti_spam(): void
    {
        $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->seller->id,
                'product_id' => $this->product->id,
                'message' => 'Is this available?',
            ]);

        $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->seller->id,
                'message' => 'Thank you for the quick confirmation!',
            ]);

        $this->assertDatabaseCount('messages', 2);
    }
}
