<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountSettingsUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_history_opens_the_purchases_workspace_with_only_owned_orders_on_both_hosts(): void
    {
        $buyer = User::factory()->buyer()->create();
        $owned = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'placed']);
        Order::factory()->create(['status' => 'placed']);
        foreach (['http://localhost/buyer/orders', 'http://buyer.localhost/my-orders'] as $url) {
            $this->actingAs($buyer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Buyer/Profile')->where('initialTab', 'orders')
                ->has('orders', 1)->where('orders.0.id', $owned->id)->where('ordersCount', 1));
        }
    }

    public function test_avatar_save_returns_fresh_profile_and_header_without_inventing_a_birth_date(): void
    {
        Storage::fake('public');
        $buyer = User::factory()->buyer()->create(['birthday' => null]);
        $this->actingAs($buyer)->from('/buyer/profile?tab=account')
            ->post('/buyer/profile', ['name' => $buyer->name, 'phone' => '09171234567', 'avatar' => UploadedFile::fake()->image('photo.png')])
            ->assertSessionHasNoErrors()->assertRedirect('/buyer/profile?tab=account');
        $saved = $buyer->fresh();
        $this->assertNull($saved->birthday);
        $this->get('/buyer/profile?tab=account')->assertInertia(fn (Assert $page) => $page
            ->where('user.avatar', $saved->avatar)->where('auth.user.avatar', $saved->avatar)
            ->where('user.phone', '+639171234567')->where('initialTab', 'account'));
        $this->post('/buyer/profile', ['name' => $saved->name, 'birthday' => '2000-01-15', 'avatar' => UploadedFile::fake()->image('blocked.png')])
            ->assertSessionHasErrors('birthday');
        $this->assertSame($saved->avatar, $buyer->fresh()->avatar);
    }

    public function test_shop_contact_and_branding_preserve_current_approval_and_catalogue_visibility(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id, 'status' => 'active']);
        $decision = $shop->currentReview->toArray();
        $this->actingAs($seller)->post('/seller/settings', ['phone' => '(02) 8123-4567', 'description' => 'Handmade bags for everyday trips.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('+63281234567', $shop->fresh()->phone);
        $this->assertSame($decision, $shop->currentReview->fresh()->toArray());
        $this->assertTrue(app(ShopEligibilityService::class)->isEligible($shop->fresh()));
        $this->assertTrue(Product::availableForSale()->whereKey($product->id)->exists());
        $this->post('/seller/settings', ['phone' => 'invalid'])->assertSessionHasErrors('phone');
        $this->post('/seller/settings', ['name' => 'Unreviewed rename'])->assertSessionHasErrors('name');
        $this->assertSame('+63281234567', $shop->fresh()->phone);
    }

    public function test_branding_does_not_reactivate_a_shop_or_allow_foreign_ownership(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id, 'status' => 'suspended']);
        $foreign = Shop::factory()->approved()->create();
        $this->actingAs($seller)->post('/seller/settings', ['description' => 'Updated shop description.'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $shop->fresh()->status);
        $this->assertFalse(app(ShopEligibilityService::class)->isEligible($shop->fresh()));
        $this->post('/seller/settings', ['shop_id' => $foreign->id, 'description' => 'Unauthorized edit'])->assertForbidden();
        $this->assertNotSame('Unauthorized edit', $foreign->fresh()->description);
    }

    public function test_owned_address_edits_update_future_choices_without_rewriting_existing_orders(): void
    {
        $buyer = User::factory()->buyer()->create();
        $address = Address::create(['user_id' => $buyer->id, 'recipient_name' => $buyer->name, 'phone' => '+639171234567', 'city' => 'Makati', 'street' => 'Old delivery street', 'is_default' => true]);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'shipping_address' => 'Old delivery street', 'status' => 'placed']);
        $this->actingAs($buyer)->from('/buyer/profile?tab=addresses')->patch('/buyer/addresses/'.$address->id,
            ['recipient_name' => 'Unreviewed rename', 'phone' => '09171234567', 'city' => 'Makati', 'street' => 'New delivery street'])
            ->assertSessionHasNoErrors()->assertRedirect('/buyer/profile?tab=addresses');
        $this->assertSame('Old delivery street', $order->fresh()->shipping_address);
        $this->assertSame($buyer->name, $address->fresh()->recipient_name);
        $this->assertTrue($address->fresh()->is_default);
        $this->get('/buyer/profile?tab=addresses')->assertInertia(fn (Assert $page) => $page->where('addresses.0.street', 'New delivery street')->where('initialTab', 'addresses'));
        $this->actingAs(User::factory()->buyer()->create())->patchJson('/buyer/addresses/'.$address->id,
            ['phone' => '09171234567', 'city' => 'Makati', 'street' => 'Foreign delivery street'])->assertForbidden();
        $this->assertSame('New delivery street', $address->fresh()->street);
    }

    public function test_reviewed_accounts_have_corrections_inside_role_settings_and_no_foreign_user_selection(): void
    {
        foreach (['buyer' => 'Buyer/Profile', 'seller' => 'Seller/Profile', 'courier' => 'Courier/Profile', 'logistics' => 'Profile/Edit', 'admin' => 'Profile/Edit'] as $role => $component) {
            $user = User::factory()->create(['role' => $role]);
            if ($role === 'seller') {
                Shop::factory()->approved()->create(['user_id' => $user->id]);
            }
            $this->actingAs($user)->get('/account/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component($component)->where('identityCorrection.subject.id', $user->id)->missing('adminReview'));
            $this->get('/account/identity-corrections')->assertRedirect('/account/settings#identity-correction');
        }
    }

    public function test_restricted_reviewed_accounts_keep_narrow_settings_without_contact_or_work_bypasses(): void
    {
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'suspended', 'birthday' => '2015-01-01']);
            if ($role === 'seller') {
                Shop::factory()->create(['user_id' => $user->id]);
            }
            $this->actingAs($user)->get('/account/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')->where('settingsOnly', true)->where('identityCorrection.subject.id', $user->id)
                ->missing('orders')->missing('addresses')->missing('wallet')->where('closure', null));
            $this->patchJson('/profile', ['name' => $user->name, 'email' => $user->email])->assertForbidden();
            $this->postJson('/account/emails/send', ['email' => 'contact@bagoo.test', 'current_password' => 'password'])->assertForbidden();
            $this->assertSame('suspended', $user->fresh()->status);
        }
        $pending = User::factory()->buyer()->create(['kyc_status' => 'pending_approval']);
        $this->actingAs($pending)->get('/account/settings')->assertForbidden();
        $this->get('/account/identity-corrections')->assertConflict();
    }
}
