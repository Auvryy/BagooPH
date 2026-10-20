<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\HandoverIssue;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\SidebarAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SidebarAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function order(Shop $shop, string $status = 'placed'): Order
    {
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $order = Order::factory()->create(['status' => $status]);
        OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $shop->id, 'product_id' => $product->id]);
        Delivery::factory()->create(['order_id' => $order->id]);

        return $order;
    }

    public function test_unread_informational_updates_do_not_add_work_dots_or_get_acknowledged(): void
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
        foreach ([$seller, $other] as $user) {
            $attention = $service->for($user);
            $this->assertFalse($attention['orders']);
            $this->assertFalse($attention['messages']);
            foreach (['products', 'vouchers', 'finances', 'promotions'] as $key) {
                $this->assertArrayNotHasKey($key, $attention);
            }
        }
        $this->assertFalse($service->for($admin)['review_requests']);
        $this->assertSame(5, $seller->notifications()->whereNull('read_at')->count());
        $this->assertSame(1, $admin->notifications()->whereNull('read_at')->count());
    }

    public function test_unavailable_notification_storage_preserves_other_owned_work_markers(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $this->order($shop);
        Schema::drop('notifications');
        $attention = app(SidebarAttentionService::class)->for($seller, 1);
        $this->assertTrue($attention['orders']);
        $this->assertTrue($attention['messages']);
        $this->assertArrayNotHasKey('products', $attention);
    }

    public function test_products_never_mark_the_sidebar_and_order_attention_requires_real_payment_and_ownership(): void
    {
        $seller = User::factory()->seller()->create();
        $other = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $foreign = Product::factory()->create(['status' => 'draft', 'stock' => 0]);
        $service = app(SidebarAttentionService::class);
        $empty = $service->for($seller);
        $this->assertArrayNotHasKey('products', $empty);
        $this->assertFalse($empty['orders']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'status' => 'draft', 'stock' => 10]);
        $order = $this->order($shop);
        $order->update(['payment_method' => 'wallet', 'payment_status' => 'pending']);
        $this->assertArrayNotHasKey('products', $service->for($seller));
        $this->assertFalse($service->for($other)['orders']);
        $this->assertFalse($service->for($seller)['orders'], 'An unpaid wallet order is not ready for seller preparation.');
        $order->update(['payment_status' => 'paid']);
        $this->assertFalse($service->for($seller)['orders'], 'A paid label alone does not replace recorded wallet payment.');
        $order->update(['payment_method' => 'cod', 'payment_status' => 'pending']);
        $this->assertTrue($service->for($seller)['orders']);
        $this->assertTrue($service->for($seller, 2)['messages']);
        $this->assertFalse($service->for($seller, 0)['messages']);
        $product->update(['status' => 'active', 'stock' => 2]);
        $this->assertArrayNotHasKey('products', $service->for($seller));
        $product->update(['stock' => 10]);
        $order->update(['status' => 'ready_for_pickup']);
        $this->assertArrayNotHasKey('products', $service->for($seller));
        $this->assertFalse($service->for($seller)['orders']);
        $this->assertSame('draft', $foreign->fresh()->status);
    }

    public function test_admin_attention_reflects_pending_reviews_without_product_or_numeric_badges(): void
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
        $this->assertArrayNotHasKey('products', $attention);
        $this->assertTrue(collect($attention)->every(fn ($value) => is_bool($value)));
        $applicant->update(['kyc_status' => 'approved']);
        $shop->forceFill(['review_status' => 'approved'])->save();
        $product->forceFill(['compliance_restricted' => false])->save();
        $attention = $service->for($admin);
        $this->assertFalse($attention['kyc']);
        $this->assertFalse($attention['shop_reviews']);
        $this->assertArrayNotHasKey('products', $attention);
        $admin->update(['status' => 'suspended']);
        $this->assertSame([], $service->for($admin->fresh()));
        $this->assertSame([], $service->for(User::factory()->create(['role' => 'buyer'])));
        $this->assertSame([], $service->for(null));
    }

    public static function waitingOrFinishedStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['ready_for_pickup', 'picked_up', 'at_sorting_center', 'sorted',
            'assigned_to_rider', 'out_for_delivery', 'shipped', 'in_transit', 'delivered', 'completed', 'delivery_failed', 'returned', 'cancelled']);
    }

    #[DataProvider('waitingOrFinishedStatuses')]
    public function test_waiting_in_process_and_finished_orders_do_not_need_seller_attention(string $status): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $this->order($shop, $status);
        app(NotificationDeliveryService::class)->record($seller->id, 'governance-event', 'status:'.$status,
            ['title' => 'Parcel status updated', 'target' => 'seller-orders']);
        $this->assertFalse(app(SidebarAttentionService::class)->for($seller)['orders']);
    }

    public function test_missing_preparation_route_claims_holds_and_ineligible_shops_do_not_offer_an_order_dot(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $service = app(SidebarAttentionService::class);
        $order = $this->order($shop, 'preparing');
        $this->assertFalse($service->for($seller)['orders'], 'An incomplete route and missing packing evidence block Ready for Pickup.');
        $order->update(['status' => 'confirmed']);
        $this->assertTrue($service->for($seller)['orders'], 'A confirmed order has the Pack Order action.');
        $order->delivery->update(['courier_id' => User::factory()->courier()->create()->id]);
        $this->assertFalse($service->for($seller)['orders']);
        $order->delivery->update(['courier_id' => null, 'assigned_rider_id' => User::factory()->courier()->create()->id]);
        $this->assertFalse($service->for($seller)['orders']);
        $order->delivery->update(['assigned_rider_id' => null]);
        $hold = HandoverIssue::create(['delivery_id' => $order->delivery->id, 'active_delivery_id' => $order->delivery->id,
            'reporter_id' => $seller->id, 'reporter_role' => 'seller', 'kind' => 'missed_ready', 'state' => 'needs_recovery',
            'reported_holder' => 'Pickup rider', 'reported_location' => 'Pickup address', 'notes' => 'This parcel needs reviewed recovery.',
            'source_snapshot' => [], 'evidence_path' => 'test-evidence.png', 'evidence_hash' => str_repeat('a', 64),
            'evidence_extension' => 'png', 'evidence_mime' => 'image/png', 'request_token' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64)]);
        $this->assertFalse($service->for($seller)['orders']);
        $hold->update(['state' => 'resolved', 'active_delivery_id' => null]);
        $this->assertTrue($service->for($seller)['orders']);
        $shop->update(['status' => 'inactive']);
        $this->assertFalse($service->for($seller)['orders']);
    }

    public function test_dot_clears_when_seller_marks_ready_even_while_its_notifications_remain_unread(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $order = $this->order($shop);
        app(NotificationDeliveryService::class)->record($seller->id, 'governance-event', 'original-order-update',
            ['title' => 'Order placed', 'target' => 'seller-orders']);
        $company = LogisticsCompany::create(['name' => 'Bagoo Attention Logistics', 'slug' => 'bagoo-attention', 'code' => 'BAT', 'status' => 'active', 'is_active' => true]);
        $hubs = [];
        foreach (['local_bayan_hub', 'regional_mother_hub', 'local_bayan_hub'] as $index => $tier) {
            $hubs[] = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Attention Hub '.$index,
                'code' => 'BAT-'.$index, 'tier' => $tier, 'province' => 'Laguna', 'city_municipality' => 'Calamba',
                'address' => 'Hub address '.$index, 'is_active' => true]);
        }
        $order->delivery->update(['logistics_company_id' => $company->id, 'origin_bayan_hub_id' => $hubs[0]->id,
            'origin_mother_hub_id' => $hubs[1]->id, 'destination_mother_hub_id' => $hubs[1]->id, 'destination_bayan_hub_id' => $hubs[2]->id]);
        $lifecycle = app(OrderLifecycleService::class);
        $service = app(SidebarAttentionService::class);
        $this->withoutVite();
        $this->actingAs($seller)->get('/seller/orders')->assertOk()->assertInertia(fn ($page) => $page->where('sidebarAttention.orders', true));
        $packed = $lifecycle->sellerAcceptAndPack($order, $shop, $seller);
        $this->assertTrue($service->for($seller)['orders']);
        $lifecycle->sellerTransition($packed, $shop, $seller, 'ready_for_pickup');
        $this->assertGreaterThan(0, $seller->notifications()->whereNull('read_at')->count());
        $this->assertFalse($service->for($seller)['orders']);
        $this->get('/seller/orders')->assertOk()->assertInertia(fn ($page) => $page->where('sidebarAttention.orders', false));
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? '', 'X-Inertia-Partial-Component' => 'Seller/Orders',
            'X-Inertia-Partial-Data' => 'orders,sidebarAttention'])->get('/seller/orders')->assertOk()
            ->assertJsonPath('props.sidebarAttention.orders', false);
    }

    public function test_orders_without_a_parcel_or_with_another_shops_items_do_not_offer_seller_actions(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $order = $this->order($shop);
        $order->delivery->delete();
        $service = app(SidebarAttentionService::class);
        $this->assertFalse($service->for($seller)['orders']);
        Delivery::factory()->create(['order_id' => $order->id]);
        $foreignProduct = Product::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $foreignProduct->shop_id, 'product_id' => $foreignProduct->id]);
        $this->assertFalse($service->for($seller)['orders']);
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
