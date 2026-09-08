<?php

namespace Tests\Feature\Buyer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_buyer_can_upload_avatar_image_file(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
            'avatar' => null,
        ]);

        $file = UploadedFile::fake()->image('my_avatar.png', 300, 300)->size(500);

        $response = $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => 'Avatar Tester',
            'phone' => '+63 912 345 6789',
            'avatar' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $buyer->refresh();
        $this->assertNotNull($buyer->avatar);
        $this->assertStringStartsWith('/storage/avatars/', $buyer->avatar);

        $storedPath = str_replace('/storage/', '', $buyer->avatar);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_authenticated_buyer_can_upload_avatar_using_avatar_file_key(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $file = UploadedFile::fake()->image('custom_photo.jpg', 400, 400)->size(600);

        $response = $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => 'File Key User',
            'avatar_file' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $buyer->refresh();
        $this->assertNotNull($buyer->avatar);
        $this->assertStringStartsWith('/storage/avatars/', $buyer->avatar);

        $storedPath = str_replace('/storage/', '', $buyer->avatar);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_authenticated_buyer_can_select_curated_avatar_preset_string(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'avatar' => null,
        ]);

        $presetUrl = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=200&auto=format&fit=crop&q=80';

        $response = $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => 'Preset Enthusiast',
            'avatar' => $presetUrl,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $buyer->refresh();
        $this->assertEquals($presetUrl, $buyer->avatar);
    }

    public function test_authenticated_buyer_can_select_preset_using_avatar_preset_key(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'avatar' => null,
        ]);

        $presetUrl = 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=200&auto=format&fit=crop&q=80';

        $response = $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => 'Preset Field User',
            'avatar_preset' => $presetUrl,
        ]);

        $response->assertRedirect();
        $buyer->refresh();
        $this->assertEquals($presetUrl, $buyer->avatar);
    }

    public function test_old_custom_avatar_is_deleted_when_replaced(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $firstPhoto = UploadedFile::fake()->image('first.jpg', 200, 200);
        $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => $buyer->name,
            'avatar' => $firstPhoto,
        ]);

        $buyer->refresh();
        $firstPath = str_replace('/storage/', '', $buyer->avatar);
        Storage::disk('public')->assertExists($firstPath);

        // Upload replacement photo
        $secondPhoto = UploadedFile::fake()->image('second.png', 200, 200);
        $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => $buyer->name,
            'avatar' => $secondPhoto,
        ]);

        $buyer->refresh();
        $secondPath = str_replace('/storage/', '', $buyer->avatar);

        // Verify old file was deleted from disk and new file exists
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_authenticated_buyer_can_remove_avatar(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $photo = UploadedFile::fake()->image('photo.jpg', 200, 200);
        $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => $buyer->name,
            'avatar' => $photo,
        ]);

        $buyer->refresh();
        $filePath = str_replace('/storage/', '', $buyer->avatar);
        Storage::disk('public')->assertExists($filePath);

        // Remove avatar
        $response = $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => $buyer->name,
            'remove_avatar' => true,
        ]);

        $response->assertRedirect();
        $buyer->refresh();
        $this->assertNull($buyer->avatar);
        Storage::disk('public')->assertMissing($filePath);
    }

    public function test_avatar_file_must_be_valid_image_format(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create(['role' => 'buyer']);

        $pdfFile = UploadedFile::fake()->create('document.pdf', 50, 'application/pdf');

        $response = $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => $buyer->name,
            'avatar' => $pdfFile,
        ]);

        $response->assertSessionHasErrors(['avatar']);
        $buyer->refresh();
        $this->assertNull($buyer->avatar);
    }

    public function test_avatar_file_must_not_exceed_3mb(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create(['role' => 'buyer']);

        // 3073 KB exceeds the 3072 KB max rule
        $largeImage = UploadedFile::fake()->create('huge.jpg', 3500, 'image/jpeg');

        $response = $this->actingAs($buyer)->post(route('buyer.profile.update'), [
            'name' => $buyer->name,
            'avatar' => $largeImage,
        ]);

        $response->assertSessionHasErrors(['avatar']);
        $buyer->refresh();
        $this->assertNull($buyer->avatar);
    }

    public function test_unauthenticated_user_cannot_update_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg');

        $response = $this->post(route('buyer.profile.update'), [
            'name' => 'Unauthenticated User',
            'avatar' => $file,
        ]);

        $response->assertRedirect(route('login'));
    }
}
