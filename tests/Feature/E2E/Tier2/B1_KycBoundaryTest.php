<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\CourierProfile;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B1_KycBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    protected function setUp(): void
    {
        parent::setUp();
        $tempDir = sys_get_temp_dir() . '/bagoo_testing_disks_' . getmypid() . '/public';
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        Storage::set('public', Storage::createLocalDriver([
            'driver' => 'local',
            'root' => $tempDir,
        ]));
    }

    public function test_b1_01_registration_fails_when_required_kyc_documents_are_missing(): void
    {
        // Seller registration without id_document and business_permit
        $sellerResponse = $this->post('/register', [
            'name' => 'Maria Incomplete',
            'email' => 'seller.missing.docs@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'seller',
            'shop_name' => 'Missing Docs Store',
            'phone' => '+63 917 111 2233',
            'address' => 'Sample Address',
            'city' => 'Manila',
        ]);

        $sellerResponse->assertSessionHasErrors(['id_document', 'business_permit']);
        $this->assertDatabaseMissing('users', ['email' => 'seller.missing.docs@example.com']);
        $this->assertDatabaseMissing('shops', ['name' => 'Missing Docs Store']);

        // Courier registration without driver_license and or_cr_document
        $courierResponse = $this->post('/register', [
            'name' => 'Pedro Incomplete',
            'email' => 'courier.missing.docs@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'courier',
            'phone' => '+63 920 333 4455',
            'address' => 'Courier Address',
            'city' => 'Pasig City',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'AB-1234',
        ]);

        $courierResponse->assertSessionHasErrors(['id_document', 'driver_license', 'or_cr_document']);
        $this->assertDatabaseMissing('users', ['email' => 'courier.missing.docs@example.com']);
    }

    public function test_b1_02_registration_rejects_disallowed_file_types_and_oversized_payloads(): void
    {
        // Upload disallowed executable script as document
        $invalidScript = UploadedFile::fake()->create('exploit.sh', 100, 'application/x-sh');
        $validPermit = UploadedFile::fake()->create('permit.pdf', 200, 'application/pdf');

        $invalidTypeResponse = $this->post('/register', [
            'name' => 'Bad File User',
            'email' => 'bad.file@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'seller',
            'shop_name' => 'Bad File Shop',
            'phone' => '+63 917 000 1111',
            'address' => 'Malicious Ave',
            'city' => 'Quezon City',
            'id_document' => $invalidScript,
            'business_permit' => $validPermit,
        ]);

        $invalidTypeResponse->assertSessionHasErrors(['id_document']);
        $this->assertDatabaseMissing('users', ['email' => 'bad.file@example.com']);

        // Upload oversized document (> 5120 KB)
        $oversizedDoc = UploadedFile::fake()->create('huge_id.jpg', 6000, 'image/jpeg');

        $oversizedResponse = $this->post('/register', [
            'name' => 'Oversized User',
            'email' => 'oversized@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'seller',
            'shop_name' => 'Oversized Shop',
            'phone' => '+63 917 000 2222',
            'address' => 'Heavy Way',
            'city' => 'Taguig',
            'id_document' => $oversizedDoc,
            'business_permit' => $validPermit,
        ]);

        $oversizedResponse->assertSessionHasErrors(['id_document']);
        $this->assertDatabaseMissing('users', ['email' => 'oversized@example.com']);
    }

    public function test_b1_03_courier_registration_fails_with_incomplete_vehicle_or_plate_details(): void
    {
        $idDoc = UploadedFile::fake()->create('id.jpg', 400, 'image/jpeg');
        $license = UploadedFile::fake()->create('license.jpg', 400, 'image/jpeg');
        $orCr = UploadedFile::fake()->create('orcr.pdf', 400, 'application/pdf');

        // Missing vehicle_type and plate_number
        $response = $this->post('/register', [
            'name' => 'Courier Missing Vehicle',
            'email' => 'missing.vehicle@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'courier',
            'phone' => '+63 920 123 4567',
            'address' => 'Vehicle St',
            'city' => 'Makati',
            'id_document' => $idDoc,
            'driver_license' => $license,
            'or_cr_document' => $orCr,
        ]);

        $response->assertSessionHasErrors(['vehicle_type', 'plate_number']);
        $this->assertDatabaseMissing('users', ['email' => 'missing.vehicle@example.com']);
        $this->assertDatabaseMissing('courier_profiles', ['license_number' => 'N01-99-999999']);
    }

    public function test_b1_04_duplicate_email_registration_fails_cleanly_without_orphaned_kyc_records(): void
    {
        // Existing user
        $existing = User::factory()->create([
            'email' => 'existing.merchant@example.com',
            'role' => 'buyer',
        ]);

        $idDoc = UploadedFile::fake()->create('id.png', 300, 'image/png');
        $permit = UploadedFile::fake()->create('permit.pdf', 500, 'application/pdf');

        $response = $this->post('/register', [
            'name' => 'Duplicate Attempt',
            'email' => 'existing.merchant@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'seller',
            'shop_name' => 'Orphaned Shop Attempt',
            'phone' => '+63 918 888 9999',
            'address' => 'Duplicate Row',
            'city' => 'Cebu City',
            'id_document' => $idDoc,
            'business_permit' => $permit,
        ]);

        $response->assertSessionHasErrors(['email']);

        // Assert exactly 1 user with that email exists
        $this->assertEquals(1, User::where('email', 'existing.merchant@example.com')->count());

        // Assert no orphaned shop was created
        $this->assertDatabaseMissing('shops', ['name' => 'Orphaned Shop Attempt']);
    }

    public function test_b1_05_malformed_phone_number_and_postal_codes_are_rejected_at_validation(): void
    {
        $idDoc = UploadedFile::fake()->create('id.jpg', 200, 'image/jpeg');

        $response = $this->post('/register', [
            'name' => 'Invalid Formatting User',
            'email' => 'not-a-valid-email-address',
            'password' => 'Password123!',
            'password_confirmation' => 'DifferentPassword456!',
            'role' => 'buyer',
            'phone' => str_repeat('0', 300), // Exceeds max 255
            'postal_code' => str_repeat('9', 25), // Exceeds max 20
            'id_document' => $idDoc,
        ]);

        $response->assertSessionHasErrors(['email', 'password', 'phone', 'postal_code']);
        $this->assertDatabaseMissing('users', ['name' => 'Invalid Formatting User']);
    }
}
