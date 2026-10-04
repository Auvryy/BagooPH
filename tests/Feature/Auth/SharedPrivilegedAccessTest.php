<?php

namespace Tests\Feature\Auth;

use App\Models\Cart;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\Orders\CheckoutOrderService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SharedPrivilegedAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function restrictedAdmins(): array
    {
        return [['inactive'], ['suspended'], ['pending_approval'], ['unknown']];
    }

    #[DataProvider('restrictedAdmins')]
    public function test_restricted_admin_has_no_foreign_order_oversight_or_shared_network_data(string $status): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => $status, 'kyc_status' => 'none']);
        $operator = User::factory()->create(['role' => 'logistics']);
        $company = LogisticsCompany::create(['user_id' => $operator->id, 'name' => 'Bagoo Network Test', 'slug' => 'bagoo-network-test', 'code' => 'BNT', 'status' => 'active', 'is_active' => true]);
        LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Network Test Hub', 'code' => 'BH-NETWORK', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => 'Network Test Road', 'is_active' => true]);
        $order = Order::factory()->create(['status' => 'delivered']);

        $this->actingAs($admin);
        foreach (['/my-orders/', '/buyer/orders/'] as $prefix) {
            $this->get('http://localhost'.$prefix.$order->id)->assertForbidden();
        }
        $this->get('http://localhost/overview')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.logisticsCompany', null)
            ->where('auth.user.activeHub', null)
            ->where('auth.user.allHubs', [])
            ->where('auth.user.canSwitchHubs', false));
    }

    public static function restrictedSellers(): array
    {
        return [['active', 'none'], ['active', 'pending_approval'], ['active', 'rejected'], ['inactive', 'approved'], ['suspended', 'approved'], ['unknown', 'approved']];
    }

    #[DataProvider('restrictedSellers')]
    public function test_shared_storefront_write_cannot_bypass_seller_eligibility(string $status, string $kyc): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => $status, 'kyc_status' => $kyc]);
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'name' => 'Original Shop', 'status' => 'active']);
        $this->actingAs($seller)->get('http://localhost/shop/'.$shop->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->where('isOwner', false));
        $this->post('http://localhost/shop/'.$shop->slug.'/update-branding', ['name' => 'Unauthorized Change'])->assertRedirect();
        $this->assertSame('Original Shop', $shop->fresh()->name);
    }

    public function test_active_admin_can_read_order_evidence_but_cannot_edit_seller_branding(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'kyc_status' => 'none']);
        $order = Order::factory()->create(['status' => 'delivered']);
        $shop = Shop::factory()->create(['name' => 'Original Shop', 'status' => 'active']);
        $this->actingAs($admin);
        foreach (['/my-orders/', '/buyer/orders/'] as $prefix) {
            $this->get('http://localhost'.$prefix.$order->id)->assertOk();
        }
        $this->get('http://localhost/shop/'.$shop->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->where('isOwner', false));
        $this->post('http://localhost/shop/'.$shop->slug.'/update-branding', ['name' => 'Unauthorized Change'])->assertForbidden();
        $this->assertSame('Original Shop', $shop->fresh()->name);
    }

    public static function nonBuyerRoles(): array
    {
        return [['admin'], ['seller'], ['courier'], ['logistics'], ['unknown']];
    }

    #[DataProvider('nonBuyerRoles')]
    public function test_other_roles_cannot_place_an_order_from_the_shared_checkout(string $role): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved']);
        $cart = Cart::create(['user_id' => $actor->id]);
        $this->actingAs($actor)->post('http://localhost/checkout', [])->assertForbidden();
        try {
            app(CheckoutOrderService::class)->place($actor, $cart, [], []);
            $this->fail('The order service must reject a non-buyer actor.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Buyer access is required to place an order.', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('nonBuyerRoles')]
    public function test_receipt_confirmation_requires_buyer_role_as_well_as_ownership(string $role): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved']);
        $order = Order::factory()->create(['buyer_id' => $actor->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        $this->actingAs($actor)->post('http://localhost/buyer/orders/'.$order->id.'/confirm')->assertForbidden();
        try {
            app(OrderLifecycleService::class)->buyerComplete($order, $actor);
            $this->fail('The lifecycle service must reject a non-buyer receipt confirmation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Only the buyer who placed this order may confirm receipt.', $exception->getMessage());
        }
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public static function restrictedBuyers(): array
    {
        return [['suspended'], ['inactive']];
    }

    #[DataProvider('restrictedBuyers')]
    public function test_restricted_buyer_keeps_only_the_owned_tracking_and_receipt_exception(string $status): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => $status, 'kyc_status' => 'approved']);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        $delivery = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        $foreignOrder = Order::factory()->create(['status' => 'delivered']);
        $pendingOrder = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'out_for_delivery']);
        Delivery::factory()->create(['order_id' => $pendingOrder->id, 'status' => 'out_for_delivery']);
        $this->actingAs($buyer);
        foreach (['/my-orders/', '/buyer/orders/'] as $prefix) {
            $this->get('http://localhost'.$prefix.$order->id)->assertOk();
            $this->get('http://localhost'.$prefix.$foreignOrder->id)->assertForbidden();
        }
        $this->post('http://localhost/checkout', [])->assertSessionHas('error', 'Your account is not active and cannot place an order.');
        $this->post('http://localhost/buyer/orders/'.$foreignOrder->id.'/confirm')->assertForbidden();
        $this->post('http://localhost/buyer/orders/'.$pendingOrder->id.'/confirm')->assertSessionHas('error');
        $this->assertSame('out_for_delivery', $pendingOrder->fresh()->status);
        $this->post('http://localhost/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $this->post('http://localhost/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 1);
        $this->assertSame($status, $buyer->fresh()->status);
        $this->assertAuthenticatedAs($buyer);
    }
}
