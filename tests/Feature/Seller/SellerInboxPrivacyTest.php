<?php

namespace Tests\Feature\Seller;

use App\Models\Message;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerInboxPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public static function inboxRoutes(): array
    {
        return [['/seller/messages'], ['http://seller.localhost/messages']];
    }

    #[DataProvider('inboxRoutes')]
    public function test_inbox_returns_chat_identity_without_contact_or_review_details(string $route): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id]);
        $customer = User::factory()->buyer()->create(['email' => 'private-contact@bagoo.test',
            'phone' => '+639175555194', 'address' => '194 Private Customer Street', 'birthday' => '1991-03-12',
            'kyc_feedback' => 'Private identity review feedback.']);
        Message::create(['sender_id' => $customer->id, 'receiver_id' => $seller->id, 'shop_id' => $shop->id,
            'product_id' => $product->id, 'message' => 'Is this item available?', 'is_read' => false]);
        Message::create(['sender_id' => $seller->id, 'receiver_id' => $customer->id, 'shop_id' => $shop->id,
            'message' => 'Yes, it is available.', 'is_read' => true]);

        $inbox = $this->actingAs($seller)->get($route)->assertOk()->inertiaProps('conversations');
        $this->assertCount(1, $inbox);
        $conversation = $inbox[0];
        $this->assertSame($customer->id, $conversation['user']['id']);
        $this->assertEqualsCanonicalizing(['id', 'name', 'avatar', 'role'], array_keys($conversation['user']));
        $this->assertSame(1, $conversation['unread_count']);
        $this->assertCount(2, $conversation['messages']);
        foreach ($conversation['messages'] as $message) {
            foreach (['sender', 'receiver'] as $person) {
                $this->assertEqualsCanonicalizing(['id', 'name', 'avatar'], array_keys($message[$person]));
            }
            if ($message['product']) {
                $this->assertSame($product->id, $message['product']['id']);
                $this->assertSame($product->name, $message['product']['name']);
                $this->assertEqualsCanonicalizing(['id', 'shop_id', 'name', 'slug', 'price', 'featured_image'], array_keys($message['product']));
            }
        }
        foreach ([$customer->email, $customer->phone, $customer->address, $customer->kyc_feedback] as $private) {
            $this->assertStringNotContainsString($private, json_encode($inbox, JSON_THROW_ON_ERROR));
        }
        $this->assertDatabaseHas('messages', ['sender_id' => $customer->id, 'receiver_id' => $seller->id, 'is_read' => false]);
    }

    #[DataProvider('inboxRoutes')]
    public function test_inbox_remains_role_guarded_and_excludes_another_sellers_conversations(string $route): void
    {
        $this->get($route)->assertRedirect();
        $customer = User::factory()->buyer()->create();
        $seller = User::factory()->seller()->create();
        Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $foreignSeller = User::factory()->seller()->create();
        $foreignShop = Shop::factory()->approved()->create(['user_id' => $foreignSeller->id]);
        Message::create(['sender_id' => $customer->id, 'receiver_id' => $foreignSeller->id,
            'shop_id' => $foreignShop->id, 'message' => 'Private conversation with the other shop.', 'is_read' => false]);

        $this->actingAs($seller)->get($route)->assertOk()->assertInertia(fn ($page) => $page->has('conversations', 0));
        $this->actingAs($customer)->get($route)->assertForbidden();
        $seller->update(['status' => 'suspended']);
        $this->actingAs($seller)->get($route)->assertRedirect(route('account.status'));
        $this->assertDatabaseHas('messages', ['receiver_id' => $foreignSeller->id, 'is_read' => false]);
    }
}
