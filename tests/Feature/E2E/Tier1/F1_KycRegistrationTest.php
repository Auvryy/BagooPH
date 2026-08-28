<?php

namespace Tests\Feature\E2E\Tier1;

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

class F1_KycRegistrationTest extends TestCase
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

    public function test_f1_01_customer_can_register_with_id_document(): void
    {
        $idDoc = UploadedFile::fake()->create('valid_national_id.jpg', 800, 'image/jpeg');

        $response = $this->post('/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.buyer@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'buyer',
            'phone' => '+63 917 111 2222',
            'address' => 'Unit 4B Pioneer Woodlands',
            'city' => 'Mandaluyong City',
            'postal_code' => '1550',
            'id_document' => $idDoc,
        ]);

        $response->assertRedirect(route('kyc.pending'));

        $this->assertDatabaseHas('users', [
            'email' => 'juan.buyer@example.com',
            'role' => 'buyer',
            'name' => 'Juan Dela Cruz',
            'city' => 'Mandaluyong City',
        ]);

        $user = User::where('email', 'juan.buyer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->id_document_path);
    }

    public function test_f1_02_seller_can_register_with_business_permit_and_id(): void
    {
        $idDoc = UploadedFile::fake()->create('seller_id.png', 600, 'image/png');
        $permit = UploadedFile::fake()->create('dti_permit.pdf', 1200, 'application/pdf');

        $response = $this->post('/register', [
            'name' => 'Maria Santos',
            'email' => 'maria.artisan@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role' => 'seller',
            'shop_name' => 'Santos Handcrafted Bags',
            'phone' => '+63 918 333 4444',
            'address' => '789 Heritage Row',
            'city' => 'Vigan City',
            'id_document' => $idDoc,
            'business_permit' => $permit,
        ]);

        $response->assertRedirect(route('kyc.pending'));

        $this->assertDatabaseHas('users', [
            'email' => 'maria.artisan@example.com',
            'role' => 'seller',
        ]);

        $user = User::where('email', 'maria.artisan@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->id_document_path);
        $this->assertNotNull($user->business_permit_path);

        $this->assertDatabaseHas('shops', [
            'user_id' => $user->id,
            'name' => 'Santos Handcrafted Bags',
            'status' => 'pending',
        ]);
    }

    public function test_f1_03_courier_can_register_with_license_and_or_cr_documents(): void
    {
        $idDoc = UploadedFile::fake()->create('courier_id.jpg', 500, 'image/jpeg');
        $license = UploadedFile::fake()->create('drivers_license.jpg', 700, 'image/jpeg');
        $orCr = UploadedFile::fake()->create('lto_or_cr.pdf', 1500, 'application/pdf');

        $response = $this->post('/register', [
            'name' => 'Pedro Express',
            'email' => 'pedro.rider@example.com',
            'password' => 'RiderPass123!',
            'password_confirmation' => 'RiderPass123!',
            'role' => 'courier',
            'phone' => '+63 920 555 6666',
            'address' => 'Block 12 Lot 5, Karangalan Village',
            'city' => 'Pasig City',
            'vehicle_type' => 'Yamaha NMAX 155 (Motorcycle)',
            'plate_number' => 'ND-7821',
            'license_number' => 'N01-19-123456',
            'id_document' => $idDoc,
            'driver_license' => $license,
            'or_cr_document' => $orCr,
        ]);

        $response->assertRedirect(route('kyc.pending'));

        $user = User::where('email', 'pedro.rider@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('courier', $user->role);
        $this->assertNotNull($user->driver_license_path);
        $this->assertNotNull($user->or_cr_path);
    }

    public function test_f1_04_newly_registered_users_default_to_pending_approval_status(): void
    {
        $idDoc = UploadedFile::fake()->create('id.jpg', 400, 'image/jpeg');

        $this->post('/register', [
            'name' => 'Anna Reyes',
            'email' => 'anna.reyes@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'buyer',
            'id_document' => $idDoc,
        ]);

        $user = User::where('email', 'anna.reyes@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('pending_approval', $user->status);
        $this->assertEquals('pending_approval', $user->kyc_status);
        $this->assertNotNull($user->kyc_submitted_at);
        $this->assertNull($user->kyc_reviewed_at);
    }

    public function test_f1_05_courier_profile_record_is_created_upon_courier_registration(): void
    {
        $idDoc = UploadedFile::fake()->create('id.jpg', 400, 'image/jpeg');
        $license = UploadedFile::fake()->create('license.jpg', 400, 'image/jpeg');
        $orCr = UploadedFile::fake()->create('orcr.jpg', 400, 'image/jpeg');

        $this->post('/register', [
            'name' => 'Carlos Fleet',
            'email' => 'carlos.fleet@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'courier',
            'phone' => '+63 921 777 8888',
            'address' => 'Central Avenue',
            'city' => 'Quezon City',
            'vehicle_type' => 'Honda Click 125i',
            'plate_number' => 'QC-4412',
            'license_number' => 'N03-20-654321',
            'id_document' => $idDoc,
            'driver_license' => $license,
            'or_cr_document' => $orCr,
        ]);

        $user = User::where('email', 'carlos.fleet@example.com')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('courier_profiles', [
            'user_id' => $user->id,
            'vehicle_type' => 'Honda Click 125i',
            'plate_number' => 'QC-4412',
            'license_number' => 'N03-20-654321',
            'is_available' => false,
        ]);
    }
}
