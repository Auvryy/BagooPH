<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Notifications\NotificationCenterService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\SidebarAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SidebarAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_unread_updates_mark_their_destination_and_clear_after_bulk_read(): void
    {
        $seller = User::factory()->seller()->create();
        $other = User::factory()->seller()->create();
        $admin = User::factory()->admin()->create();
        $delivery = app(NotificationDeliveryService::class);
        foreach (['seller-products', 'seller-orders', 'seller-vouchers', 'seller-settlement', 'promotion-seller'] as $target) {
            $delivery->record($seller->id, 'governance-event', 'attention:'.$target, ['title' => 'Saved update', 'target' => $target]);
        }
        $delivery->record($admin->id, 'governance-event', 'attention:admin-review', ['title' => 'Saved review update', 'target' => 'account-review-admin']);
        $service = app(SidebarAttentionService::class);
        foreach (['products', 'orders', 'vouchers', 'finances', 'promotions'] as $key) {
            $this->assertTrue($service->for($seller)[$key]);
            $this->assertFalse($service->for($other)[$key]);
        }
        $this->assertTrue($service->for($admin)['review_requests']);
        $center = app(NotificationCenterService::class);
        $center->acknowledgeSnapshot($seller, $center->readSnapshot($seller)['id']);
        foreach (['products', 'orders', 'vouchers', 'finances', 'promotions'] as $key) {
            $this->assertFalse($service->for($seller)[$key]);
        }
    }

    public function test_unavailable_notification_storage_preserves_other_owned_work_markers(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        Product::factory()->create(['shop_id' => $shop->id, 'status' => 'draft']);
        Schema::drop('notifications');
        $attention = app(SidebarAttentionService::class)->for($seller, 1);
        $this->assertTrue($attention['products']);
        $this->assertTrue($attention['messages']);
        $this->assertArrayNotHasKey('promotions', $attention);
        $this->assertArrayNotHasKey('finances', $attention);
    }

    public function test_seller_attention_is_owned_and_clears_when_messages_drafts_stock_and_orders_are_handled(): void
    {
        $seller = User::factory()->seller()->create();
        $other = User::factory()->seller()->create();
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $foreign = Product::factory()->create(['status' => 'draft', 'stock' => 0]);
        $service = app(SidebarAttentionService::class);
        $empty = $service->for($seller);
        $this->assertFalse($empty['products']);
        $this->assertFalse($empty['orders']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'status' => 'draft', 'stock' => 10]);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'placed', 'payment_method' => 'wallet', 'payment_status' => 'pending']);
        OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $shop->id, 'product_id' => $product->id]);
        $this->assertTrue($service->for($seller)['products']);
        $this->assertFalse($service->for($other)['products']);
        $this->assertFalse($service->for($seller)['orders'], 'An unpaid wallet order is not ready for seller preparation.');
        $order->update(['payment_status' => 'paid']);
        $this->assertTrue($service->for($seller)['orders']);
        $this->assertTrue($service->for($seller, 2)['messages']);
        $this->assertFalse($service->for($seller, 0)['messages']);
        $product->update(['status' => 'active', 'stock' => 2]);
        $this->assertTrue($service->for($seller)['products']);
        $product->update(['stock' => 10]);
        $order->update(['status' => 'ready_for_pickup']);
        $this->assertFalse($service->for($seller)['products']);
        $this->assertFalse($service->for($seller)['orders']);
        $this->assertSame('draft', $foreign->fresh()->status);
    }

    public function test_admin_attention_reflects_real_review_queues_and_restrictions_without_numeric_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->create(['kyc_status' => 'pending_approval']);
        $shop = Shop::factory()->create(['review_status' => 'pending_approval']);
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $product->forceFill(['compliance_restricted' => true])->save();
        $service = app(SidebarAttentionService::class);
        $attention = $service->for($admin);
        $this->assertTrue($attention['kyc']);
        $this->assertTrue($attention['shop_reviews']);
        $this->assertTrue($attention['products']);
        $this->assertTrue(collect($attention)->every(fn ($value) => is_bool($value)));
        $applicant->update(['kyc_status' => 'approved']);
        $shop->forceFill(['review_status' => 'approved'])->save();
        $product->forceFill(['compliance_restricted' => false])->save();
        $attention = $service->for($admin);
        $this->assertFalse($attention['kyc']);
        $this->assertFalse($attention['shop_reviews']);
        $this->assertFalse($attention['products']);
        $admin->update(['status' => 'suspended']);
        $this->assertSame([], $service->for($admin->fresh()));
        $this->assertSame([], $service->for(User::factory()->create(['role' => 'buyer'])));
        $this->assertSame([], $service->for(null));
    }

    public function test_shared_sidebar_uses_the_current_owned_unread_message_state(): void
    {
        $seller = User::factory()->seller()->create();
        $buyer = User::factory()->create();
        Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $message = Message::create(['sender_id' => $buyer->id, 'receiver_id' => $seller->id, 'message' => 'Can you help with this product?', 'is_read' => false]);
        $this->withoutVite();
        $this->actingAs($seller)->get('/seller/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('sidebarAttention.messages', true));
        $message->update(['is_read' => true]);
        $this->get('/seller/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('sidebarAttention.messages', false));
    }
}
