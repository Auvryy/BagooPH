<?php

namespace Tests\Feature\Seller;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
            'avatar' => null,
        ]);

        $this->shop = Shop::create([
            'user_id' => $this->seller->id,
            'name' => 'Apex Artisan Store',
            'slug' => 'apex-artisan-store',
            'status' => 'active',
        ]);
    }

    public function test_seller_can_view_seller_profile(): void
    {
        $response = $this->actingAs($this->seller)->get(route('seller.profile'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Profile')
            ->has('user')
            ->has('shop')
            ->where('user.id', $this->seller->id)
            ->where('shop.id', $this->shop->id)
        );
    }

    public function test_seller_can_upload_avatar_image_file_and_stored_in_public_storage(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('merchant_avatar.png', 300, 300)->size(500);

        $response = $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => 'Updated Merchant Name',
            'email' => $this->seller->email,
            'phone' => '+63 912 345 6789',
            'avatar' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->seller->refresh();
        $this->assertSame('Updated Merchant Name', $this->seller->name);
        $this->assertSame('+63 912 345 6789', $this->seller->phone);
        $this->assertNotNull($this->seller->avatar);
        $this->assertStringStartsWith('/storage/avatars/', $this->seller->avatar);

        $storedPath = str_replace('/storage/', '', $this->seller->avatar);
        Storage::disk('public')->assertExists($storedPath);

        // Upload replacement photo and verify old file is cleaned up
        $secondFile = UploadedFile::fake()->image('replacement_photo.jpg', 300, 300)->size(600);

        $updateResponse = $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => $this->seller->name,
            'email' => $this->seller->email,
            'phone' => $this->seller->phone,
            'avatar' => $secondFile,
        ]);

        $updateResponse->assertRedirect();
        $this->seller->refresh();

        $secondPath = str_replace('/storage/', '', $this->seller->avatar);
        $this->assertNotSame($storedPath, $secondPath);
        Storage::disk('public')->assertMissing($storedPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_seller_can_remove_avatar(): void
    {
        Storage::fake('public');

        // First upload an avatar
        $file = UploadedFile::fake()->image('current_photo.jpg', 200, 200);
        $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => $this->seller->name,
            'email' => $this->seller->email,
            'phone' => $this->seller->phone,
            'avatar' => $file,
        ]);

        $this->seller->refresh();
        $storedPath = str_replace('/storage/', '', $this->seller->avatar);
        Storage::disk('public')->assertExists($storedPath);

        // Remove avatar
        $response = $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => $this->seller->name,
            'email' => $this->seller->email,
            'phone' => $this->seller->phone,
            'remove_avatar' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->seller->refresh();
        $this->assertNull($this->seller->avatar);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_avatar_file_size_and_mime_validations(): void
    {
        Storage::fake('public');

        // Test non-image mime type (PDF)
        $pdfFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $mimeResponse = $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => $this->seller->name,
            'email' => $this->seller->email,
            'avatar' => $pdfFile,
        ]);

        $mimeResponse->assertSessionHasErrors(['avatar']);
        $this->seller->refresh();
        $this->assertNull($this->seller->avatar);

        // Test file exceeding 3072 KB (3MB)
        $largeFile = UploadedFile::fake()->create('oversized.jpg', 3500, 'image/jpeg');
        $sizeResponse = $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => $this->seller->name,
            'email' => $this->seller->email,
            'avatar' => $largeFile,
        ]);

        $sizeResponse->assertSessionHasErrors(['avatar']);
        $this->seller->refresh();
        $this->assertNull($this->seller->avatar);
    }

    public function test_non_seller_is_denied_access(): void
    {
        // Unauthenticated guest
        $guestResponse = $this->get(route('seller.profile'));
        $guestResponse->assertRedirect(route('login'));

        $guestPostResponse = $this->post(route('seller.profile.update'), [
            'name' => 'Guest Attempt',
            'email' => 'guest@example.com',
        ]);
        $guestPostResponse->assertRedirect(route('login'));

        // Buyer role
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $buyerGetResponse = $this->actingAs($buyer)->get(route('seller.profile'));
        $buyerGetResponse->assertForbidden();

        $buyerPostResponse = $this->actingAs($buyer)->post(route('seller.profile.update'), [
            'name' => 'Buyer Attempt',
            'email' => $buyer->email,
        ]);
        $buyerPostResponse->assertForbidden();

        // Courier role
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $courierGetResponse = $this->actingAs($courier)->get(route('seller.profile'));
        $courierGetResponse->assertForbidden();

        $courierPostResponse = $this->actingAs($courier)->post(route('seller.profile.update'), [
            'name' => 'Courier Attempt',
            'email' => $courier->email,
        ]);
        $courierPostResponse->assertForbidden();
    }

    public function test_email_must_be_unique_except_for_current_seller(): void
    {
        $otherUser = User::factory()->create([
            'email' => 'other_user@example.com',
        ]);

        $response = $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => $this->seller->name,
            'email' => 'other_user@example.com',
        ]);

        $response->assertSessionHasErrors(['email']);

        // Keeping same email should succeed
        $successResponse = $this->actingAs($this->seller)->post(route('seller.profile.update'), [
            'name' => 'Same Email Seller',
            'email' => $this->seller->email,
        ]);

        $successResponse->assertSessionHasNoErrors();
        $this->seller->refresh();
        $this->assertSame('Same Email Seller', $this->seller->name);
    }
}
