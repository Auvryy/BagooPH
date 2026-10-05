<?php

namespace Tests\Feature\Admin;

use App\Models\CourierProfile;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\Shop;
use App\Models\User;
use App\Services\KycDecisionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class KycDecisionGovernanceTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active', 'kyc_status' => 'none']);
    }

    private function applicant(string $role = 'buyer', string $status = 'pending_approval'): User
    {
        $user = User::factory()->pendingKyc()->create([
            'role' => $role, 'status' => $status, 'kyc_status' => 'pending_approval',
            'birthday' => '2000-01-01',
            'phone' => '+639171234567', 'address' => 'Bagoo Test Street', 'city' => 'Makati',
        ]);
        if ($role === 'seller') {
            Shop::create(['user_id' => $user->id, 'root_category_id' => $this->validMasterCategory()->id, 'name' => 'Bagoo Application Shop', 'phone' => $user->phone, 'address' => $user->address, 'city' => $user->city, 'slug' => 'bagoo-application-'.$user->id, 'status' => 'pending']);
        } elseif ($role === 'courier') {
            CourierProfile::create(['user_id' => $user->id, 'vehicle_type' => 'Motorcycle', 'plate_number' => 'TEST-123', 'or_cr_status' => 'Pending Verification', 'is_available' => false]);
        } elseif ($role === 'logistics') {
            LogisticsCompany::create(['user_id' => $user->id, 'name' => 'Bagoo Application Logistics', 'slug' => 'bagoo-logistics-'.$user->id, 'code' => 'B'.$user->id, 'contact_email' => $user->email, 'contact_phone' => $user->phone, 'address' => $user->address, 'status' => 'pending', 'is_active' => false]);
        }
        $this->addKycEvidence($user);

        return $user->fresh();
    }

    public static function rolesAndPortals(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            foreach (['http://localhost/admin/kyc', 'http://admin.localhost/kyc'] as $prefix) {
                $cases[] = [$role, $prefix];
            }
        }

        return $cases;
    }

    #[DataProvider('rolesAndPortals')]
    public function test_review_is_recorded_with_evidence_and_original_profile_only(string $role, string $prefix): void
    {
        $user = $this->applicant($role);
        $admin = $this->admin();
        $extraShop = $role === 'seller' ? Shop::create(['user_id' => $user->id, 'name' => 'Additional Shop', 'slug' => 'additional', 'status' => 'pending']) : null;
        $this->inspectKycEvidence($admin, $user);
        $this->post($prefix.'/'.$user->id.'/approve', $this->kycPayload($user))->assertRedirect()->assertSessionHas('success');
        $decision = KycDecision::sole();
        $this->assertSame($admin->id, $decision->reviewer_id);
        $this->assertSame('admin', $decision->reviewer_role);
        $this->assertSame($role, $decision->subject_role);
        $this->assertSame('pending_approval', $decision->before_state['account']['status']);
        $this->assertSame('active', $decision->after_state['account']['status']);
        $this->assertSame('approved', $user->fresh()->kyc_status);
        $this->assertNotNull($decision->reviewed_at);
        foreach (app(KycDecisionService::class)->requiredDocuments($decision->submission) as $kind) {
            $this->assertTrue($decision->submission['documents'][$kind]['valid']);
            $this->assertNotNull($decision->submission['documents'][$kind]['sha256']);
        }
        if ($extraShop) {
            $this->assertSame('active', $user->shop->fresh()->status);
            $this->assertSame('pending', $extraShop->fresh()->status);
        }
        if ($role === 'courier') {
            $this->assertFalse($user->courierProfile->fresh()->is_available);
        }
        if ($role === 'logistics') {
            $this->assertTrue($user->logisticsCompany->fresh()->is_active);
        }
    }

    public static function restrictions(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            foreach (['inactive', 'suspended', 'unknown'] as $status) {
                foreach (['approve', 'reject'] as $action) {
                    $cases[] = [$role, $status, $action];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('restrictions')]
    public function test_decisions_preserve_independent_account_restrictions(string $role, string $status, string $action): void
    {
        $user = $this->applicant($role, $status);
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/'.$action, $this->kycPayload($user) + ['reason' => 'Evidence is incomplete.'])->assertSessionHas('success');
        $this->assertSame($status, $user->fresh()->status);
        $this->assertFalse($user->fresh()->canAccessPortal());
        $this->assertSame($status, KycDecision::sole()->after_state['account']['status']);
        if ($role === 'seller') {
            $this->assertSame('pending', $user->shop->fresh()->status);
        }
        if ($role === 'logistics') {
            $this->assertSame('pending', $user->logisticsCompany->fresh()->status);
            $this->assertFalse($user->logisticsCompany->fresh()->is_active);
        }
    }

    public static function requiredEvidence(): array
    {
        return [['buyer', 'id_document_path'], ['seller', 'id_document_path'], ['seller', 'business_permit_path'], ['courier', 'id_document_path'], ['courier', 'driver_license_path'], ['courier', 'or_cr_path'], ['logistics', 'business_permit_path']];
    }

    #[DataProvider('requiredEvidence')]
    public function test_missing_private_evidence_prevents_approval(string $role, string $field): void
    {
        $user = $this->applicant($role);
        Storage::disk('local')->delete($user->getRawOriginal($field));
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_disguised_file_and_declared_franchise_require_valid_evidence(): void
    {
        $user = $this->applicant('logistics');
        Storage::disk('local')->put($user->business_permit_path, '<?php echo "invalid";');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        Storage::disk('local')->put($user->business_permit_path, '%PDF-1.4 valid permit');
        $user->logisticsCompany->update(['accreditation_details' => ['franchise_number' => 'DECLARED-FRANCHISE']]);
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_required_documents_must_be_opened_by_the_current_reviewer(): void
    {
        $user = $this->applicant('seller');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->get('/verification-documents/'.$user->id.'/id.pdf')->assertOk();
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->inspectKycEvidence($admin, $user);
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_evidence_change_invalidates_the_review_and_inspection(): void
    {
        $user = $this->applicant();
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $oldPayload = $this->kycPayload($user);
        Storage::disk('local')->put($user->id_document_path, '%PDF-1.4 replaced evidence');
        $this->post('/admin/kyc/'.$user->id.'/approve', $oldPayload)->assertConflict();
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_application_profile_change_requires_a_new_review(): void
    {
        $user = $this->applicant('courier');
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $payload = $this->kycPayload($user);
        $user->courierProfile->update(['plate_number' => 'CHANGED']);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertConflict();
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_identical_approval_retry_preserves_review_and_a_later_suspension(): void
    {
        $user = $this->applicant('seller');
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $payload = $this->kycPayload($user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $original = KycDecision::sole()->getRawOriginal();
        $reviewTime = $user->fresh()->kyc_reviewed_at->toISOString();
        $this->travel(1)->days();
        $user->update(['status' => 'suspended']);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertSame($original, KycDecision::sole()->getRawOriginal());
        $this->assertSame($reviewTime, $user->fresh()->kyc_reviewed_at->toISOString());
        $this->assertSame('suspended', $user->fresh()->status);
        $this->travelBack();
    }

    public function test_retry_cannot_replace_feedback_reviewer_or_decision(): void
    {
        $user = $this->applicant();
        $admin = $this->admin();
        $payload = $this->kycPayload($user) + ['reason' => 'Identity is unreadable.'];
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $payload)->assertSessionHas('success');
        $original = KycDecision::sole()->getRawOriginal();
        $this->travel(1)->days();
        $this->post('/admin/kyc/'.$user->id.'/reject', $payload)->assertSessionHas('success');
        $this->post('/admin/kyc/'.$user->id.'/reject', array_replace($payload, ['reason' => 'A different rejection reason.']))->assertConflict();
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertConflict();
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/reject', $payload)->assertConflict();
        $this->assertSame($original, KycDecision::sole()->getRawOriginal());
        $this->assertSame($payload['reason'], $user->fresh()->kyc_feedback);
        $this->travelBack();
    }

    public static function reviewedStates(): array
    {
        return [['approved'], ['verified'], ['rejected'], ['none']];
    }

    #[DataProvider('reviewedStates')]
    public function test_only_pending_submissions_can_receive_a_new_decision(string $state): void
    {
        $user = $this->applicant();
        $user->update(['kyc_status' => $state]);
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/reject', $this->kycPayload($user) + ['reason' => 'Cannot reverse this review.'])->assertConflict();
        $this->assertSame($state, $user->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_direct_requests_need_a_submission_token_and_inspection_confirmation(): void
    {
        $user = $this->applicant();
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/approve')->assertSessionHasErrors(['review_token', 'evidence_confirmed']);
        $this->post('/admin/kyc/'.$user->id.'/reject', ['reason' => 'Unreadable identity document.'])->assertSessionHasErrors('review_token');
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public static function writeFailures(): array
    {
        return [['eloquent.updating: '.User::class], ['eloquent.updating: '.Shop::class], ['eloquent.creating: '.KycDecision::class]];
    }

    #[DataProvider('writeFailures')]
    public function test_account_profile_and_audit_writes_roll_back_together(string $event): void
    {
        $user = $this->applicant('seller');
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $payload = $this->kycPayload($user);
        Event::listen($event, fn () => throw new RuntimeException('Simulated KYC persistence failure.'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/kyc/'.$user->id.'/approve', $payload);
            $this->fail('Persistence failure should abort the review.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated KYC persistence failure.', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertSame('pending_approval', $user->fresh()->status);
        $this->assertNull($user->fresh()->kyc_reviewed_at);
        $this->assertSame('pending', $user->shop->fresh()->status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_resubmission_preserves_restrictions_old_files_and_decisions(): void
    {
        $user = $this->applicant('seller', 'suspended');
        $admin = $this->admin();
        $payload = $this->kycPayload($user) + ['reason' => 'Permit must be corrected.'];
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $payload)->assertSessionHas('success');
        $original = KycDecision::sole()->getRawOriginal();
        $oldPath = $user->business_permit_path;
        $this->actingAs($user)->post('/kyc/resubmit', ['business_permit' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 corrected permit')])->assertSessionHas('success');
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertNotSame($oldPath, $user->fresh()->business_permit_path);
        Storage::disk('local')->assertExists($oldPath);
        $this->assertSame($original, KycDecision::sole()->getRawOriginal());
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $payload)->assertConflict();
        $this->get('/verification-documents/'.$user->id.'/permit.pdf?decision='.KycDecision::sole()->id)->assertOk()->assertStreamedContent("%PDF-1.4\nBagoo permit evidence for {$user->id}\n%%EOF");
    }

    public function test_resubmission_cannot_be_empty_or_replace_a_reviewed_application(): void
    {
        $user = $this->applicant();
        $user->update(['kyc_status' => 'rejected']);
        $this->actingAs($user)->post('/kyc/resubmit')->assertSessionHasErrors('documents');
        $this->assertSame('rejected', $user->fresh()->kyc_status);
        $user->update(['kyc_status' => 'pending_approval']);
        $oldPath = $user->id_document_path;
        $this->post('/kyc/resubmit', ['id_document' => UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 replacement')])->assertSessionHas('success');
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertNotSame($oldPath, $user->fresh()->id_document_path);
        Storage::disk('local')->assertExists($oldPath);
        foreach (['approved', 'verified'] as $state) {
            $user->update(['kyc_status' => $state]);
            $this->post('/kyc/resubmit', ['id_document' => UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 replacement')])->assertConflict();
            $this->assertSame($state, $user->fresh()->kyc_status);
        }
    }

    public function test_history_is_private_and_old_evidence_cannot_be_read_by_another_account(): void
    {
        $user = $this->applicant();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $this->kycPayload($user) + ['reason' => 'Please upload a readable identity.'])->assertSessionHas('success');
        $decision = KycDecision::sole();
        foreach (['/admin/kyc?status=all', 'http://admin.localhost/kyc?status=all'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('applicants.data.0.decision_history', 1)
                ->where('applicants.data.0.decision_history.0.reviewer', $admin->name)
                ->where('applicants.data.0.decision_history.0.documents.id_document_path', '/verification-documents/'.$user->id.'/id.pdf?decision='.$decision->id)
                ->missing('applicants.data.0.decision_history.0.submission')
                ->missing('applicants.data.0.decision_history.0.before_state'));
        }
        $url = '/verification-documents/'.$user->id.'/id.pdf?decision='.$decision->id;
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($admin)->get('/verification-documents/'.$admin->id.'/id.pdf?decision='.$decision->id)->assertNotFound();
        Storage::disk('local')->put($user->id_document_path, '%PDF-1.4 altered reviewed document');
        $this->get($url)->assertConflict();
        $this->assertArrayNotHasKey('submission', $decision->toArray());
    }

    public static function auditMutations(): array
    {
        return [['update'], ['delete']];
    }

    #[DataProvider('auditMutations')]
    public function test_audit_cannot_be_mutated_through_raw_queries(string $action): void
    {
        $user = $this->applicant();
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/reject', $this->kycPayload($user) + ['reason' => 'Please correct the document.'])->assertSessionHas('success');
        $original = KycDecision::sole()->getRawOriginal();
        try {
            $query = DB::table('kyc_decisions');
            $action === 'update' ? $query->update(['reason' => 'Overwritten review']) : $query->delete();
            $this->fail('Audit mutation must be blocked by the database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('KYC decisions are immutable', $exception->getMessage());
        }
        $this->assertSame($original, KycDecision::sole()->getRawOriginal());
    }

    public function test_subject_and_reviewer_cannot_delete_review_history(): void
    {
        $user = $this->applicant();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $this->kycPayload($user) + ['reason' => 'Please correct the document.'])->assertSessionHas('success');
        $this->assertFalse($user->canDeleteOwnAccount());
        $this->assertFalse($admin->canDeleteOwnAccount());
        $this->actingAs($admin)->delete('/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseCount('kyc_decisions', 1);
    }

    public static function profileRestrictions(): array
    {
        return [['seller', 'suspended'], ['seller', 'inactive'], ['logistics', 'suspended'], ['logistics', 'inactive'], ['logistics', 'active']];
    }

    #[DataProvider('profileRestrictions')]
    public function test_approval_does_not_clear_independent_profile_restrictions(string $role, string $status): void
    {
        $user = $this->applicant($role);
        $profile = $role === 'seller' ? $user->shop : $user->logisticsCompany;
        $profile->update(['status' => $status]);
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHas('success');
        $this->assertSame($status, $profile->fresh()->status);
        if ($role === 'logistics') {
            $this->assertFalse($profile->fresh()->is_active);
        }
    }

    public function test_courier_approval_preserves_existing_duty_and_placement(): void
    {
        $user = $this->applicant('courier');
        $user->courierProfile->update(['is_available' => true, 'assigned_barangay' => 'Bagoo Test Barangay']);
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHas('success');
        $this->assertTrue($user->courierProfile->fresh()->is_available);
        $this->assertSame('Bagoo Test Barangay', $user->courierProfile->fresh()->assigned_barangay);
    }

    public function test_approved_buyer_cannot_replace_reviewed_id_through_upload(): void
    {
        $user = $this->applicant();
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHas('success');
        $original = KycDecision::sole()->getRawOriginal();
        $path = $user->id_document_path;
        $this->actingAs($user->fresh())->post(route('buyer.kyc.upload'), ['id_document' => UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 replacement')])->assertConflict();
        $this->assertSame($path, $user->fresh()->id_document_path);
        $this->assertSame('approved', $user->fresh()->kyc_status);
        $this->assertSame($original, KycDecision::sole()->getRawOriginal());
    }

    public function test_resubmission_failure_rolls_back_company_paths_and_removes_only_new_files(): void
    {
        $user = $this->applicant('logistics', 'suspended');
        $user->update(['kyc_status' => 'rejected']);
        $oldPath = $user->business_permit_path;
        $oldCompany = $user->logisticsCompany->getRawOriginal();
        $oldFiles = Storage::disk('local')->allFiles('kyc_documents');
        $event = 'eloquent.updating: '.User::class;
        Event::listen($event, fn () => throw new RuntimeException('Simulated submission failure.'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post('/kyc/resubmit', ['business_permit' => UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 corrected permit')]);
            $this->fail('Submission persistence failure must abort.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated submission failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('rejected', $user->fresh()->kyc_status);
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame($oldPath, $user->fresh()->business_permit_path);
        $this->assertSame($oldCompany, $user->logisticsCompany->fresh()->getRawOriginal());
        $this->assertSame($oldFiles, Storage::disk('local')->allFiles('kyc_documents'));
    }

    public function test_required_original_profile_cannot_be_missing(): void
    {
        $user = $this->applicant('courier');
        $user->courierProfile->delete();
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_platform_admin_is_not_an_applicant(): void
    {
        $subject = $this->admin();
        $this->actingAs($this->admin())->post('/admin/kyc/'.$subject->id.'/reject', $this->kycPayload($subject) + ['reason' => 'Admins are not applicants.'])->assertForbidden();
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_queue_counts_only_applicants_and_includes_legacy_reviewed_accounts(): void
    {
        $admin = $this->admin();
        $this->applicant();
        $legacy = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'verified']);
        User::factory()->create(['role' => 'buyer', 'kyc_status' => 'rejected']);
        User::factory()->create(['role' => 'admin', 'kyc_status' => 'pending_approval']);
        $this->actingAs($admin)->get('/admin/kyc?status=all')->assertInertia(fn (Assert $page) => $page
            ->has('applicants.data', 3)->where('stats.pending_count', 1)
            ->where('stats.approved_count', 1)->where('stats.rejected_count', 1)->where('stats.total_count', 3));
        $this->get('/admin/kyc?status=approved')->assertInertia(fn (Assert $page) => $page
            ->has('applicants.data', 1)->where('applicants.data.0.id', $legacy->id)
            ->where('applicants.data.0.decision_history', []));
    }

    public function test_document_access_rechecks_a_reviewer_restricted_in_the_database(): void
    {
        $user = $this->applicant();
        $admin = $this->admin();
        User::whereKey($admin->id)->update(['status' => 'suspended']);
        $this->actingAs($admin)->get('/verification-documents/'.$user->id.'/id.pdf')->assertForbidden();
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_foreign_actor_cannot_probe_historical_decision_ids(): void
    {
        $user = $this->applicant();
        $this->actingAs(User::factory()->create())->get('/verification-documents/'.$user->id.'/id.pdf?decision=999')->assertForbidden();
    }
}
