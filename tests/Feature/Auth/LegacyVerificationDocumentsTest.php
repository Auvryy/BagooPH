<?php

namespace Tests\Feature\Auth;

use App\Models\LogisticsCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyVerificationDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_legacy_files_are_copied_verified_retired_and_accessible_only_to_the_owner(): void
    {
        $path = 'kyc_documents/legacy.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 legacy evidence');
        Storage::disk('public')->put('kyc_documents/unreferenced.pdf', 'retained privately');
        $owner = User::factory()->create([
            'role' => 'logistics', 'status' => 'pending_approval', 'kyc_status' => 'pending_approval',
            'business_permit_path' => '/storage/'.$path,
        ]);
        $company = LogisticsCompany::create([
            'user_id' => $owner->id, 'name' => 'Bagoo Legacy Logistics', 'slug' => 'bagoo-legacy-logistics', 'code' => 'BLL',
            'accreditation_details' => ['business_permit_path' => '/storage/'.$path, 'franchise_document_path' => '/storage/'.$path, 'fleet_size' => 4],
        ]);

        $this->artisan('verification:privatize', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('/storage/'.$path, $owner->fresh()->business_permit_path);

        $this->artisan('verification:privatize')->assertSuccessful();
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing('kyc_documents/unreferenced.pdf');
        $this->assertSame('%PDF-1.4 legacy evidence', Storage::disk('local')->get($path));
        Storage::disk('local')->assertExists('kyc_documents/unreferenced.pdf');
        $this->assertSame($path, $owner->fresh()->business_permit_path);
        $this->assertSame($path, $company->fresh()->accreditation_details['franchise_document_path']);
        $this->assertSame(4, $company->fresh()->accreditation_details['fleet_size']);
        $this->assertSame('pending_approval', $owner->fresh()->kyc_status);

        $this->actingAs($owner)->get('/verification-documents/'.$owner->id.'/permit.pdf')->assertOk();
        $this->get('/storage/'.$path)->assertNotFound();
        $this->artisan('verification:privatize')->assertSuccessful();
    }

    public function test_conflicting_copy_does_not_overwrite_private_evidence_or_change_approval(): void
    {
        $path = 'kyc_documents/conflict.pdf';
        Storage::disk('public')->put($path, 'public copy');
        Storage::disk('local')->put($path, 'different private evidence');
        $owner = User::factory()->create(['id_document_path' => '/storage/'.$path, 'kyc_status' => 'approved']);
        $this->artisan('verification:privatize', ['--dry-run' => true])->assertFailed();
        $this->artisan('verification:privatize')->assertFailed();
        $this->assertSame('different private evidence', Storage::disk('local')->get($path));
        Storage::disk('public')->assertExists($path);
        $this->assertSame('/storage/'.$path, $owner->fresh()->id_document_path);
        $this->assertSame('approved', $owner->fresh()->kyc_status);
    }

    public function test_missing_and_unsafe_references_fail_closed_without_reading_other_files(): void
    {
        $owner = User::factory()->create(['id_document_path' => '/storage/kyc_documents/missing.pdf']);
        User::factory()->create(['id_document_path' => '/storage/kyc_documents/../unrelated.pdf']);
        Storage::disk('public')->put('unrelated.pdf', 'ordinary public asset');
        $this->artisan('verification:privatize')->assertFailed();
        Storage::disk('public')->assertExists('unrelated.pdf');
        $this->assertSame('/storage/kyc_documents/missing.pdf', $owner->fresh()->id_document_path);
    }

    public function test_interrupted_copy_can_resume_without_duplicate_or_lost_files(): void
    {
        $path = 'kyc_documents/resume.pdf';
        Storage::disk('local')->put($path, 'already copied');
        $owner = User::factory()->create(['id_document_path' => '/storage/'.$path]);
        $this->artisan('verification:privatize')->assertSuccessful();
        $this->assertSame($path, $owner->fresh()->id_document_path);
        $this->assertSame('already copied', Storage::disk('local')->get($path));
    }
}
