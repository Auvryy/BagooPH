<?php

namespace Tests\Feature\Auth;

use App\Models\LogisticsCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class KycRegistrationTest extends TestCase
{
    use InteractsWithKycReviews;
    use RefreshDatabase;

    public function test_seller_registration_with_documents_creates_pending_user_and_shop(): void
    {
        Storage::fake('public');
        Storage::fake('local');

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
            'birthday' => '2000-01-01',
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
        Storage::fake('local');

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
            'birthday' => '2000-01-01',
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

    public function test_buyer_registration_without_id_defaults_to_none_and_redirects_to_login(): void
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

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $user = User::where('email', 'alex.buyer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('buyer', $user->role);
        $this->assertEquals('active', $user->status);
        $this->assertEquals('none', $user->kyc_status);
        $this->assertNull($user->id_document_path);
    }

    public function test_buyer_registration_with_optional_id_sets_pending_and_redirects_to_login(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $idFile = UploadedFile::fake()->create('buyer_id.jpg', 500, 'image/jpeg');

        $response = $this->post('/register', [
            'name' => 'Alex Buyer 2',
            'email' => 'alex.buyer2@example.com',
            'phone' => '+63 917 555 6666',
            'address' => '789 Sunrise Ave',
            'city' => 'Quezon City',
            'role' => 'buyer',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_document' => $idFile,
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $user = User::where('email', 'alex.buyer2@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('buyer', $user->role);
        $this->assertEquals('active', $user->status);
        $this->assertEquals('pending_approval', $user->kyc_status);
        $this->assertNotNull($user->id_document_path);
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
            'birthday' => '2000-01-01',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors(['id_document', 'business_permit']);
        $this->assertGuest();
    }

    public function test_logistics_registration_with_documents_creates_pending_user_and_logistics_company(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $permitFile = UploadedFile::fake()->create('business_permit.pdf', 1000, 'application/pdf');
        $franchiseFile = UploadedFile::fake()->create('franchise_cert.pdf', 1000, 'application/pdf');

        $response = $this->post('/register', [
            'name' => 'Captain Arturo Santos',
            'company_name' => 'Southern Freight Express',
            'company_code' => 'SFX',
            'email' => 'arturo@southernfreight.ph',
            'phone' => '+63 917 555 7777',
            'address' => 'KM 54 National Highway, Real',
            'city' => 'Calamba City',
            'province' => 'Laguna',
            'role' => 'logistics',
            'birthday' => '2000-01-01',
            'franchise_number' => 'LTFRB-2026-SFX-9988',
            'fleet_size' => 45,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_permit' => $permitFile,
            'franchise_document' => $franchiseFile,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('kyc.pending', absolute: false));

        $user = User::where('email', 'arturo@southernfreight.ph')->first();
        $this->assertNotNull($user);
        $this->assertEquals('logistics', $user->role);
        $this->assertEquals('pending_approval', $user->kyc_status);
        $this->assertEquals('pending_approval', $user->status);
        $this->assertNotNull($user->business_permit_path);

        $this->assertNotNull($user->logisticsCompany);
        $this->assertEquals('Southern Freight Express', $user->logisticsCompany->name);
        $this->assertEquals('SFX', $user->logisticsCompany->code);
        $this->assertEquals('pending', $user->logisticsCompany->status);
        $this->assertFalse($user->logisticsCompany->is_active);
    }

    public function test_admin_approves_logistics_registration_activates_company(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $applicant = User::factory()->create([
            'role' => 'logistics',
            'birthday' => '2000-01-01',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $company = LogisticsCompany::create([
            'user_id' => $applicant->id,
            'name' => 'Pacific Express Cargo',
            'slug' => 'pacific-express-cargo',
            'code' => 'PEC',
            'contact_email' => $applicant->email,
            'status' => 'pending',
            'is_active' => false,
        ]);

        $this->addKycEvidence($applicant);
        $this->inspectKycEvidence($admin, $applicant);
        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $applicant), $this->kycPayload($applicant));
        $response->assertSessionHas('success');

        $this->assertEquals('active', $applicant->fresh()->status);
        $this->assertEquals('approved', $applicant->fresh()->kyc_status);
        $this->assertEquals('active', $company->fresh()->status);
        $this->assertTrue($company->fresh()->is_active);
    }
}
