<?php

namespace Tests\Feature\Buyer;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\SavedProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Tests\TestCase;

class BuyerNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->get('/')->assertOk();
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? '']);
    }

    private function partial(string $component, string $props): void
    {
        $this->withHeaders(['X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $props]);
    }

    public function test_order_filter_reads_skip_unrequested_addresses_account_settings_and_vouchers(): void
    {
        $buyer = User::factory()->create();
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'placed']);
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'cancelled']);
        $this->actingAs($buyer);
        $full = $this->get('/buyer/profile')->assertOk();
        $this->partial('Buyer/Profile', 'orders,ordersCount,orderCounts,currentOrderStatus,initialTab,auth,user,canUsePortal,flash,errors');
        DB::enableQueryLog();
        $response = $this->get('/buyer/profile?tab=orders&order_status=cancelled')->assertOk()
            ->assertJsonPath('props.orders.total', 1)->assertJsonPath('props.ordersCount', 2)
            ->assertJsonPath('props.orders.data.0.status', 'cancelled')->assertJsonPath('props.initialTab', 'orders')
            ->assertJsonMissingPath('props.addresses')->assertJsonMissingPath('props.emailSettings')
            ->assertJsonMissingPath('props.identityCorrection')->assertJsonMissingPath('props.voucherCollection')
            ->assertHeader('Cache-Control', 'no-store, private');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertFalse($queries->contains(fn ($sql) => preg_match('/from "(?:addresses|account_emails|vouchers|buyer_vouchers)"/', $sql)));
        $this->assertLessThan(strlen($full->getContent()), strlen($response->getContent()));
    }

    public function test_address_navigation_reads_saved_changes_without_rebuilding_orders(): void
    {
        $buyer = User::factory()->create();
        $address = Address::create(['user_id' => $buyer->id, 'recipient_name' => $buyer->name, 'phone' => '+639171234567', 'province' => 'Laguna', 'city' => 'Los Banos', 'barangay' => 'Bambang', 'street' => 'Original street']);
        Address::create(['user_id' => User::factory()->create()->id, 'recipient_name' => 'Other Buyer', 'phone' => '+639171234568', 'province' => 'Laguna', 'city' => 'Los Banos', 'barangay' => 'Bambang', 'street' => 'Foreign street']);
        $this->actingAs($buyer);
        $this->partial('Buyer/Profile', 'addresses,initialTab,auth,user,canUsePortal,flash,errors');
        $this->get('/buyer/profile?tab=addresses')->assertOk()->assertJsonPath('props.addresses.0.street', 'Original street');
        $address->update(['street' => 'Updated street']);
        DB::enableQueryLog();
        $this->get('/buyer/profile?tab=addresses')->assertOk()->assertJsonCount(1, 'props.addresses')
            ->assertJsonPath('props.addresses.0.street', 'Updated street')->assertJsonMissingPath('props.orders');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, 'from "orders"')));
    }

    public function test_partial_flags_do_not_replace_current_account_approval(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $this->partial('Buyer/Profile', 'user,auth,canUsePortal,initialTab');
        $buyer->update(['status' => 'pending_approval', 'kyc_status' => 'pending_approval']);
        $this->get('/buyer/profile?tab=account')->assertOk()->assertJsonPath('props.canUsePortal', false)
            ->assertJsonPath('props.auth.user.status', 'pending_approval');
        $buyer->forceFill(['closed_at' => now(), 'status' => 'inactive'])->save();
        $this->withHeader('Accept', 'application/json');
        $this->get('/buyer/profile?tab=account')->assertForbidden();
    }

    public function test_private_page_speculation_is_rejected_with_or_without_the_catalogue_marker(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $this->withSession(['success' => 'Your changes were saved.', '_flash' => ['old' => ['success'], 'new' => []]]);
        foreach (['', '1'] as $marker) {
            $this->withHeaders(['Purpose' => 'prefetch', 'X-Bagoo-Catalogue' => $marker]);
            foreach (['/buyer/profile', '/buyer/orders', '/buyer/saved-products', '/account/settings', '/checkout', '/notifications'] as $url) {
                $this->get($url)->assertStatus(409)->assertHeader('Cache-Control', 'no-store, private')
                    ->assertSessionHas('success', 'Your changes were saved.');
            }
        }
        $this->withHeaders(['Purpose' => '', 'X-Bagoo-Catalogue' => ''])->get('/buyer/profile')->assertOk()
            ->assertJsonPath('props.flash.success', 'Your changes were saved.');
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_saved_product_paging_uses_current_origin_and_fresh_owned_entries(): void
    {
        $buyer = User::factory()->create();
        $products = Product::factory()->count(25)->create();
        foreach ($products as $product) {
            SavedProduct::create(['buyer_id' => $buyer->id, 'product_id' => $product->id]);
        }
        $this->actingAs($buyer)->get('/buyer/saved-products')->assertOk()
            ->assertJsonPath('props.entries.next_page_url', '/buyer/saved-products?page=2');
        $this->delete('/buyer/saved-products/'.$products->last()->id)->assertRedirect();
        $this->get('/buyer/saved-products?page=2')->assertOk()->assertJsonPath('props.entries.total', 24)
            ->assertJsonPath('props.entries.current_page', 1);
    }
}
