<?php

namespace Tests\Feature\Auth;

use App\Models\LogisticsCompany;
use App\Models\User;
use App\Services\VerificationDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivateVerificationDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function applicant(string $role = 'courier'): User
    {
        Storage::disk('local')->put('kyc_documents/private-id.pdf', '%PDF-1.4 private evidence');

        return User::factory()->create([
            'role' => $role, 'status' => 'pending_approval', 'kyc_status' => 'pending_approval',
            'birthday' => '2000-01-01',
            'id_document_path' => 'kyc_documents/private-id.pdf',
        ]);
    }

    public function test_pending_applicant_can_read_own_document_without_public_storage_or_cache(): void
    {
        $owner = $this->applicant();
        foreach (['', 'http://courier.localhost'] as $prefix) {
            $this->actingAs($owner)->get($prefix.'/verification-documents/'.$owner->id.'/id.pdf')
                ->assertOk()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertStreamedContent('%PDF-1.4 private evidence');
        }
        $this->get('/storage/kyc_documents/private-id.pdf')->assertNotFound();
        Storage::disk('public')->assertMissing('kyc_documents/private-id.pdf');
        $this->assertSame('pending_approval', $owner->fresh()->kyc_status);
    }

    public static function deniedActors(): array
    {
        return [
            ['buyer', 'active'], ['seller', 'active'], ['courier', 'active'], ['logistics', 'active'],
            ['admin', 'suspended'], ['admin', 'inactive'], ['admin', 'pending_approval'],
        ];
    }

    #[DataProvider('deniedActors')]
    public function test_other_accounts_and_inactive_reviewers_cannot_read_documents(string $role, string $status): void
    {
        $owner = $this->applicant();
        $actor = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => 'approved']);
        foreach (['', 'http://courier.localhost', 'http://admin.localhost'] as $prefix) {
            $this->actingAs($actor)->get($prefix.'/verification-documents/'.$owner->id.'/id.pdf')->assertForbidden();
        }
    }

    public function test_guest_and_suspended_owner_cannot_read_documents(): void
    {
        $owner = $this->applicant();
        $url = '/verification-documents/'.$owner->id.'/id.pdf';
        $this->get($url)->assertRedirect(route('login'));
        $owner->update(['status' => 'suspended']);
        $this->actingAs($owner)->get($url)->assertForbidden();
    }

    public function test_inactive_platform_reviewer_cannot_inspect_approve_or_reject_applications(): void
    {
        $owner = $this->applicant();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'suspended']);
        foreach (['/admin/kyc', 'http://admin.localhost/kyc'] as $prefix) {
            $this->actingAs($admin)->get($prefix)->assertRedirect(route('login'));
            $this->assertGuest();
            $this->actingAs($admin)->post($prefix.'/'.$owner->id.'/approve')->assertRedirect(route('login'));
            $this->assertGuest();
            $this->actingAs($admin)->post($prefix.'/'.$owner->id.'/reject', ['reason' => 'Document needs review'])->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $this->assertSame('pending_approval', $owner->fresh()->kyc_status);
    }

    public function test_active_platform_reviewer_can_read_all_documents_and_private_accreditation(): void
    {
        $owner = $this->applicant('logistics');
        $owner->update([
            'business_permit_path' => 'kyc_documents/private-id.pdf',
            'driver_license_path' => 'kyc_documents/private-id.pdf', 'or_cr_path' => 'kyc_documents/private-id.pdf',
        ]);
        LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Test Logistics', 'slug' => 'bagoo-test-logistics', 'code' => 'BTL', 'accreditation_details' => [
            'franchise_document_path' => 'kyc_documents/private-id.pdf', 'business_permit_path' => 'kyc_documents/private-id.pdf', 'fleet_size' => 2,
        ]]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        foreach (VerificationDocumentService::FIELDS as $kind => $field) {
            $this->actingAs($admin)->get('/verification-documents/'.$owner->id.'/'.$kind.'.pdf')->assertOk();
        }
        foreach (['/admin/kyc', 'http://admin.localhost/kyc'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('applicants.data.0.id_document_path', '/verification-documents/'.$owner->id.'/id.pdf')
                ->where('applicants.data.0.franchise_document_path', '/verification-documents/'.$owner->id.'/franchise.pdf')
                ->missing('applicants.data.0.logistics_company.accreditation_details.franchise_document_path')
                ->where('applicants.data.0.logistics_company.accreditation_details.fleet_size', 2));
        }
        $this->assertArrayNotHasKey('id_document_path', $owner->toArray());
    }

    public function test_missing_legacy_and_unsafe_paths_never_fall_back_to_public_storage(): void
    {
        $owner = $this->applicant();
        $this->actingAs($owner);
        foreach (['missing.pdf', 'password.pdf', 'id.jpg', 'id.exe'] as $document) {
            $this->get('/verification-documents/'.$owner->id.'/'.$document)->assertNotFound();
        }
        foreach (['/storage/kyc_documents/public.pdf', 'kyc_documents/../private-id.pdf', 'private-secret.pdf'] as $path) {
            $owner->update(['id_document_path' => $path]);
            Storage::disk('public')->put('kyc_documents/public.pdf', '%PDF-1.4 legacy');
            $this->get('/verification-documents/'.$owner->id.'/id.pdf')->assertNotFound();
        }
    }

    public function test_buyer_upload_stores_only_private_files_and_profile_shows_an_authorized_link(): void
    {
        $owner = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'none']);
        $this->actingAs($owner)->post(route('buyer.kyc.upload'), [
            'id_document' => UploadedFile::fake()->create('identity.pdf', 20, 'application/pdf'),
        ])->assertSessionHas('success');
        $path = $owner->fresh()->id_document_path;
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->get(route('buyer.profile'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('user.id_document_path', '/verification-documents/'.$owner->id.'/id.pdf')
            ->where('auth.user.id_document_path', '/verification-documents/'.$owner->id.'/id.pdf'));
    }

    public function test_logistics_resubmission_updates_private_accreditation_without_serializing_paths(): void
    {
        $owner = User::factory()->create(['role' => 'logistics', 'status' => 'pending_approval', 'kyc_status' => 'rejected', 'birthday' => '2000-01-01']);
        $company = LogisticsCompany::create([
            'user_id' => $owner->id, 'name' => 'Bagoo Resubmission Logistics', 'slug' => 'bagoo-resubmission-logistics', 'code' => 'BRL',
            'accreditation_details' => ['fleet_size' => 4],
        ]);
        $this->actingAs($owner)->post(route('kyc.resubmit'), [
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 20, 'application/pdf'),
            'franchise_document' => UploadedFile::fake()->create('franchise.pdf', 20, 'application/pdf'),
        ])->assertSessionHas('success');
        foreach (['business_permit_path', 'franchise_document_path'] as $field) {
            $path = $company->fresh()->accreditation_details[$field];
            Storage::disk('local')->assertExists($path);
            Storage::disk('public')->assertMissing($path);
            $this->assertArrayNotHasKey($field, $company->fresh()->toArray()['accreditation_details']);
        }
        $this->assertSame('pending_approval', $owner->fresh()->kyc_status);
        $this->assertSame(4, $company->fresh()->accreditation_details['fleet_size']);
    }

    public function test_disguised_executable_is_rejected_using_file_content(): void
    {
        $owner = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'none']);
        Storage::disk('local')->put('incoming.txt', '<?php echo "unsafe";');
        $file = new UploadedFile(Storage::disk('local')->path('incoming.txt'), 'identity.jpg', 'image/jpeg', UPLOAD_ERR_OK, true);
        $this->actingAs($owner)->post(route('buyer.kyc.upload'), ['id_document' => $file])->assertSessionHasErrors('id_document');
        $this->assertNull($owner->fresh()->id_document_path);
        $this->assertSame([], Storage::disk('local')->allFiles('kyc_documents'));
    }

    public function test_storage_failure_does_not_report_success_or_change_account_approval(): void
    {
        $owner = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'none']);
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('local', $disk);

        $this->actingAs($owner)->post(route('buyer.kyc.upload'), [
            'id_document' => UploadedFile::fake()->create('identity.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('id_document')->assertSessionMissing('success');
        $this->assertNull($owner->fresh()->id_document_path);
        $this->assertSame('none', $owner->fresh()->kyc_status);
    }

    public function test_registration_does_not_store_unvalidated_role_documents(): void
    {
        $this->post('/register', [
            'name' => 'Bagoo Buyer', 'email' => 'documents.buyer@bagoo.test', 'role' => 'buyer',
            'password' => 'Password1234', 'password_confirmation' => 'Password1234',
            'driver_license' => UploadedFile::fake()->create('ignored.exe', 20, 'application/x-msdownload'),
        ])->assertRedirect();
        $owner = User::where('email', 'documents.buyer@bagoo.test')->firstOrFail();
        $this->assertNull($owner->driver_license_path);
        $this->assertSame([], Storage::disk('local')->allFiles('kyc_documents'));
        $this->assertSame([], Storage::disk('public')->allFiles('kyc_documents'));
    }
}
