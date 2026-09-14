<?php

namespace Tests\Feature\Buyer;

use App\Models\Category;
use App\Models\Message;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatUnreadNotificationTest extends TestCase
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

    public function test_unread_messages_count_is_zero_when_no_unread_messages(): void
    {
        $response = $this->actingAs($this->buyer)
            ->get(route('buyer.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('unreadMessagesCount', 0)
        );
    }

    public function test_unread_messages_count_increments_when_receiving_unread_message(): void
    {
        Message::create([
            'sender_id' => $this->seller->id,
            'receiver_id' => $this->buyer->id,
            'shop_id' => $this->shop->id,
            'message' => 'Hello buyer, your flask is ready to be dispatched!',
            'is_read' => false,
        ]);

        $response = $this->actingAs($this->buyer)
            ->get(route('buyer.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('unreadMessagesCount', 1)
        );
    }

    public function test_opening_chat_marks_messages_as_read_and_resets_count(): void
    {
        Message::create([
            'sender_id' => $this->seller->id,
            'receiver_id' => $this->buyer->id,
            'shop_id' => $this->shop->id,
            'message' => 'Hello buyer, your flask is ready to be dispatched!',
            'is_read' => false,
        ]);

        // Buyer opens conversation with seller
        $response = $this->actingAs($this->buyer)
            ->getJson(route('chat.messages', ['receiverId' => $this->seller->id]));

        $response->assertStatus(200);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->seller->id,
            'receiver_id' => $this->buyer->id,
            'is_read' => true,
        ]);

        // Now verify shared unread count is reset to 0
        $pageResponse = $this->actingAs($this->buyer)
            ->get(route('buyer.index'));

        $pageResponse->assertInertia(fn ($page) => $page
            ->where('unreadMessagesCount', 0)
        );
    }
}
