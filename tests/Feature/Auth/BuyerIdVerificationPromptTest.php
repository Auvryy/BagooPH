<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class BuyerIdVerificationPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_newly_registered_buyer_has_unverified_kyc_status(): void
    {
        $user = User::factory()->create([
            'role' => 'buyer',
            'id_document_path' => null,
            'kyc_status' => 'none',
        ]);

        $this->assertNull($user->id_document_path);
        $this->assertEquals('none', $user->kyc_status);
    }

    public function test_buyer_registered_via_google_oauth_starts_with_unverified_kyc(): void
    {
        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-kyc-test-999');
        $abstractUser->shouldReceive('getEmail')->andReturn('googlebuyer@example.com');
        $abstractUser->shouldReceive('getName')->andReturn('Google Buyer');
        $abstractUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/avatar.jpg');

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('buyer.index'));
        $this->assertAuthenticated();

        $buyer = User::where('email', 'googlebuyer@example.com')->first();
        $this->assertNotNull($buyer);
        $this->assertEquals('buyer', $buyer->role);
        $this->assertEquals('none', $buyer->kyc_status);
        $this->assertNull($buyer->id_document_path);
    }

    public function test_authenticated_buyer_can_upload_valid_id_document(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'id_document_path' => null,
            'kyc_status' => 'none',
        ]);

        $file = UploadedFile::fake()->create('valid_id.jpg', 800, 'image/jpeg');

        $response = $this->actingAs($buyer)
            ->post(route('buyer.kyc.upload'), [
                'id_document' => $file,
            ]);

        $response->assertSessionHas('success');
        $buyer->refresh();

        $this->assertEquals('pending_approval', $buyer->kyc_status);
        $this->assertNotNull($buyer->id_document_path);
        $this->assertNotNull($buyer->kyc_submitted_at);

        $storedPath = str_replace('/storage/', '', $buyer->id_document_path);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_id_upload_rejects_invalid_file_type(): void
    {
        Storage::fake('public');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'kyc_status' => 'none',
        ]);

        $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

        $response = $this->actingAs($buyer)
            ->post(route('buyer.kyc.upload'), [
                'id_document' => $file,
            ]);

        $response->assertSessionHasErrors('id_document');
        $buyer->refresh();
        $this->assertEquals('none', $buyer->kyc_status);
    }

    public function test_buyer_profile_renders_with_kyc_details(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'id_document_path' => '/storage/kyc_documents/sample_id.jpg',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($buyer)->get(route('buyer.profile', ['tab' => 'account']));

        $response->assertStatus(200);
    }
}
