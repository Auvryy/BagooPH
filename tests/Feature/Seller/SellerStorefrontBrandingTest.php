<?php

namespace Tests\Feature\Seller;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerStorefrontBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_owner_can_access_shop_detail_with_ownership_flag(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Artisan Craft Hub',
            'slug' => 'artisan-craft-hub',
            'description' => 'Original handcrafted items',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($seller)->get(route('shop.show', $shop->slug));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Marketplace/ShopDetail')
            ->where('isOwner', true)
            ->where('shop.name', 'Artisan Craft Hub')
        );
    }

    public function test_guest_or_other_user_sees_is_owner_as_false(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Artisan Craft Hub',
            'slug' => 'artisan-craft-hub',
            'description' => 'Original handcrafted items',
            'is_approved' => true,
        ]);

        $guestResponse = $this->get(route('shop.show', $shop->slug));
        $guestResponse->assertStatus(200);
        $guestResponse->assertInertia(fn ($page) => $page
            ->component('Marketplace/ShopDetail')
            ->where('isOwner', false)
        );

        $buyerResponse = $this->actingAs($buyer)->get(route('shop.show', $shop->slug));
        $buyerResponse->assertStatus(200);
        $buyerResponse->assertInertia(fn ($page) => $page
            ->component('Marketplace/ShopDetail')
            ->where('isOwner', false)
        );
    }

    public function test_shop_owner_can_update_storefront_branding_and_bio(): void
    {
        Storage::fake('public');

        $seller = User::factory()->create(['role' => 'seller']);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Artisan Craft Hub',
            'slug' => 'artisan-craft-hub',
            'description' => 'Original handcrafted items',
            'is_approved' => true,
        ]);

        $logoFile = UploadedFile::fake()->image('store_logo.png', 400, 400);
        $bannerFile = UploadedFile::fake()->image('store_banner.jpg', 1200, 400);

        $response = $this->actingAs($seller)->post(route('shop.updateBranding', $shop->slug), [
            'name' => 'Artisan Craft Flagship',
            'description' => 'Updated store biography and heritage story.',
            'city' => 'Makati City',
            'address' => '789 Ayala Avenue',
            'phone' => '09170001122',
            'logo' => $logoFile,
            'banner' => $bannerFile,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('message', 'Storefront branding and bio updated successfully.');

        $shop->refresh();
        $this->assertEquals('Artisan Craft Flagship', $shop->name);
        $this->assertEquals('Updated store biography and heritage story.', $shop->description);
        $this->assertEquals('Makati City', $shop->city);
        $this->assertEquals('789 Ayala Avenue', $shop->address);
        $this->assertEquals('09170001122', $shop->phone);

        $this->assertNotNull($shop->logo);
        $this->assertNotNull($shop->banner);

        $logoPath = str_replace('/storage/', '', $shop->logo);
        $bannerPath = str_replace('/storage/', '', $shop->banner);

        Storage::disk('public')->assertExists($logoPath);
        Storage::disk('public')->assertExists($bannerPath);
    }

    public function test_non_owner_cannot_update_shop_branding(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $otherSeller = User::factory()->create(['role' => 'seller']);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Artisan Craft Hub',
            'slug' => 'artisan-craft-hub',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($otherSeller)->post(route('shop.updateBranding', $shop->slug), [
            'name' => 'Hacked Store',
            'description' => 'Unauthorized update',
        ]);

        $response->assertStatus(403);
    }
}
