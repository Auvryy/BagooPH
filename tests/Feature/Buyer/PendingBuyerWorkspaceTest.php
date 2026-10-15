<?php

namespace Tests\Feature\Buyer;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\BuyerAccessService;
use App\Services\Orders\CheckoutOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PendingBuyerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function applicants(): array
    {
        return [['active', 'none'], ['pending_approval', 'pending_approval'], ['active', 'rejected']];
    }

    #[DataProvider('applicants')]
    public function test_applicant_can_use_owned_profile_and_bag_but_cannot_checkout(string $status, string $kyc): void
    {
        $buyer = User::factory()->create(['status' => $status, 'kyc_status' => $kyc]);
        $other = User::factory()->create();
        Order::factory()->create(['buyer_id' => $other->id]);
        $product = Product::factory()->create(['stock' => 10]);
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.'/pending-approval')->assertRedirect(route('buyer.index', ['application' => 1]));
            foreach (['/buyer/profile', '/buyer/orders', '/my-orders', '/profile', '/account/settings'] as $path) {
                $this->get($host.$path)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Buyer/Profile')
                    ->where('user.id', $buyer->id)->where('orders.total', 0)->where('canUsePortal', false)
                    ->where('buyerApplication.user.kyc_status', $kyc));
            }
            $this->post($host.'/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSessionHas('success');
            $line = $buyer->cart->items()->sole();
            $this->patch($host.'/cart/'.$line->id, ['quantity' => 2])->assertSessionHas('success');
            $this->get($host.'/buyer/cart')->assertOk()->assertInertia(fn (Assert $page) => $page->where('cart.user_id', $buyer->id)->has('items', 1));
            $this->get($host.'/checkout')->assertRedirect(route('kyc.pending'));
            $this->post($host.'/checkout', ['item_ids' => [$line->id]])->assertRedirect(route('kyc.pending'));
            $this->delete($host.'/cart/'.$line->id)->assertSessionHas('success');
        }
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame($kyc, $buyer->fresh()->kyc_status);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_owned_history_does_not_grant_receipt_or_foreign_bag_access(): void
    {
        $buyer = User::factory()->pendingKyc()->create();
        $owned = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        $other = Order::factory()->create();
        $cart = Cart::create(['user_id' => $other->buyer_id]);
        $line = $cart->items()->create(['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_price' => 100]);
        $this->actingAs($buyer)->get('/buyer/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.total', 1)->where('orders.data.0.id', $owned->id));
        $this->get('/buyer/orders/'.$owned->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('canConfirmReceipt', false)->where('pickupCodeUrl', null));
        $this->post('/buyer/orders/'.$owned->id.'/confirm')->assertForbidden();
        $this->get('/buyer/orders/'.$other->id)->assertForbidden();
        $this->patch('/cart/'.$line->id, ['quantity' => 3])->assertForbidden();
        $this->delete('/cart/'.$line->id)->assertForbidden();
        $this->assertSame(1, $line->fresh()->quantity);
        $this->assertSame('delivered', $owned->fresh()->status);
    }

    public function test_current_restriction_blocks_bag_changes_even_from_a_stale_actor(): void
    {
        $buyer = User::factory()->pendingKyc()->create();
        $product = Product::factory()->create();
        $this->actingAs($buyer)->post('/cart', ['product_id' => $product->id])->assertSessionHas('success');
        $line = $buyer->cart->items()->sole();
        User::whereKey($buyer->id)->update(['status' => 'suspended']);
        $this->get('/cart')->assertOk();
        $this->post('/cart', ['product_id' => $product->id])->assertForbidden();
        $this->patch('/cart/'.$line->id, ['quantity' => 2])->assertForbidden();
        $this->delete('/cart/'.$line->id)->assertForbidden();
        try {
            app(BuyerAccessService::class)->requireBagChanges($buyer);
            $this->fail('A stale account must not bypass suspension.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $line->fresh()->quantity);
        }
        try {
            app(CheckoutOrderService::class)->place($buyer, $buyer->cart, [$line->id], []);
            $this->fail('An unapproved account must not bypass checkout.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_pending_buyer_can_save_ordinary_contact_and_avatar_without_granting_approval(): void
    {
        Storage::fake('public');
        $buyer = User::factory()->pendingKyc()->create(['first_name' => 'Ana', 'last_name' => 'Santos', 'name' => 'Ana Santos']);
        $this->actingAs($buyer)->from('/buyer/profile?tab=account')->post('/buyer/profile', [
            'name' => $buyer->name, 'phone' => '09171234567', 'avatar' => UploadedFile::fake()->image('avatar.png'),
        ])->assertSessionHas('success');
        $current = $buyer->fresh();
        $this->assertSame('+639171234567', $current->phone);
        $this->assertSame('pending_approval', $current->kyc_status);
        $this->assertSame('pending_approval', $current->status);
        Storage::disk('public')->assertExists(substr($current->avatar, strlen('/storage/')));
        $this->get('/buyer/profile?tab=account')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('user.avatar', $current->avatar)->where('auth.user.avatar', $current->avatar));
        $this->get('/checkout')->assertRedirect(route('kyc.pending'));
    }

    public function test_an_explicit_history_denial_is_honored_by_profile_and_order_aliases(): void
    {
        $buyer = User::factory()->pendingKyc()->create();
        Gate::before(fn (User $user, string $ability) => $ability === 'buyer.order-history' ? false : null);
        foreach (['/buyer/profile', '/buyer/orders', '/my-orders', '/profile', '/account/settings'] as $path) {
            $this->actingAs($buyer)->get($path)->assertForbidden();
        }
    }

    public function test_partial_status_check_uses_fresh_review_without_loading_the_whole_marketplace(): void
    {
        $buyer = User::factory()->pendingKyc()->create();
        User::whereKey($buyer->id)->update(['status' => 'active', 'kyc_status' => 'approved']);
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->actingAs($buyer)->get('/buyer', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Buyer/Home', 'X-Inertia-Partial-Data' => 'buyerApplication,auth'])
            ->assertOk()->assertJsonPath('props.buyerApplication.can_use_portal', true)
            ->assertJsonPath('props.auth.user.kyc_status', 'approved')->assertJsonMissingPath('props.feedProducts');
    }
}
