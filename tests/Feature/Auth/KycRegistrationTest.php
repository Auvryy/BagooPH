<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_registration_with_documents_creates_pending_user_and_shop(): void
    {
        Storage::fake('public');

        $idFile = UploadedFile::fake()->create('seller_id.jpg', 500, 'image/jpeg');
        $permitFile = UploadedFile::fake()->create('business_permit.pdf', 1000, 'application/pdf');

        $response = $this->post('/register', [
            'name' => 'Sarah Store Owner',
            'shop_name' => 'Sarah Prime Boutique',
            'email' => 'sarah.store@example.com',
            'phone' => '+63 917 111 2222',
            'address' => 'Unit 102 Greenbelt Mall',
            'city' => 'Makati City',
            'role' => 'seller',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_document' => $idFile,
            'business_permit' => $permitFile,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('kyc.pending', absolute: false));

        $user = User::where('email', 'sarah.store@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('seller', $user->role);
        $this->assertEquals('pending_approval', $user->kyc_status);
        $this->assertEquals('pending_approval', $user->status);
        $this->assertNotNull($user->id_document_path);
        $this->assertNotNull($user->business_permit_path);
        $this->assertNotNull($user->kyc_submitted_at);

        $this->assertNotNull($user->shop);
        $this->assertEquals('Sarah Prime Boutique', $user->shop->name);
        $this->assertEquals('pending', $user->shop->status);
    }

    public function test_courier_registration_creates_pending_user_and_courier_profile(): void
    {
        Storage::fake('public');

        $idFile = UploadedFile::fake()->create('courier_id.jpg', 500, 'image/jpeg');
        $licenseFile = UploadedFile::fake()->create('driver_license.jpg', 500, 'image/jpeg');
        $orCrFile = UploadedFile::fake()->create('vehicle_orcr.pdf', 1000, 'application/pdf');

        $response = $this->post('/register', [
            'name' => 'Juan Rider',
            'email' => 'juan.rider@example.com',
            'phone' => '+63 917 333 4444',
            'address' => 'Block 5 Lot 22 Barangay San Antonio',
            'city' => 'Pasig City',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'ABC-9876',
            'license_number' => 'N02-22-123456',
            'role' => 'courier',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_document' => $idFile,
            'driver_license' => $licenseFile,
            'or_cr_document' => $orCrFile,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('kyc.pending', absolute: false));

        $user = User::where('email', 'juan.rider@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('courier', $user->role);
        $this->assertEquals('pending_approval', $user->kyc_status);
        $this->assertEquals('pending_approval', $user->status);
        $this->assertNotNull($user->driver_license_path);
        $this->assertNotNull($user->or_cr_path);

        $this->assertNotNull($user->courierProfile);
        $this->assertEquals('Motorcycle', $user->courierProfile->vehicle_type);
        $this->assertEquals('ABC-9876', $user->courierProfile->plate_number);
        $this->assertEquals('N02-22-123456', $user->courierProfile->license_number);
        $this->assertFalse($user->courierProfile->is_available);
    }

    public function test_buyer_registration_defaults_to_pending_approval(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alex Buyer',
            'email' => 'alex.buyer@example.com',
            'phone' => '+63 917 555 6666',
            'address' => '789 Sunrise Ave',
            'city' => 'Quezon City',
            'role' => 'buyer',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('kyc.pending', absolute: false));

        $user = User::where('email', 'alex.buyer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('buyer', $user->role);
        $this->assertEquals('pending_approval', $user->kyc_status);
    }

    public function test_seller_registration_requires_business_permit_and_id(): void
    {
        $response = $this->post('/register', [
            'name' => 'Incomplete Seller',
            'shop_name' => 'Incomplete Store',
            'email' => 'incomplete.seller@example.com',
            'phone' => '+63 917 111 0000',
            'address' => 'Some address',
            'city' => 'Manila',
            'role' => 'seller',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors(['id_document', 'business_permit']);
        $this->assertGuest();
    }
}
