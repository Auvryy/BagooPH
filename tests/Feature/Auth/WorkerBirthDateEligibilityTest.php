<?php

namespace Tests\Feature\Auth;

use App\Models\CourierProfile;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\Shop;
use App\Models\User;
use App\Services\BirthDateEligibility;
use App\Services\KycDecisionService;
use App\Services\KycSubmissionService;
use App\Services\VerificationDocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class WorkerBirthDateEligibilityTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 16:05:00', 'UTC'));
        Storage::fake('local');
    }

    public static function workerRoles(): array
    {
        return [['seller'], ['courier'], ['logistics']];
    }

    private function registration(string $role, mixed $birthday): array
    {
        $data = [
            'role' => $role, 'name' => 'Bagoo Test Applicant',
            'email' => Str::uuid().'@bagoo.test', 'phone' => '+639171234567',
            'address' => 'Bagoo Test Street', 'city' => 'Makati',
            'password' => 'A valid test passphrase', 'password_confirmation' => 'A valid test passphrase',
            'birthday' => $birthday, 'age' => 99,
        ];
        if ($role === 'seller') {
            $data['shop_name'] = 'Bagoo Application Shop';
            $data['root_category_id'] = $this->validMasterCategory()->id;
        } elseif ($role === 'courier') {
            $data += ['vehicle_type' => 'Motorcycle', 'plate_number' => 'TEST-123', 'license_number' => 'TEST-LICENSE'];
        } elseif ($role === 'logistics') {
            $data += ['company_name' => 'Bagoo Application Logistics', 'company_code' => 'BD'.Str::upper(Str::random(8))];
        }
        foreach (match ($role) {
            'seller' => ['id_document', 'business_permit'],
            'courier' => ['id_document', 'driver_license', 'or_cr_document'],
            'logistics' => ['business_permit'],
            default => [],
        } as $field) {
            $data[$field] = UploadedFile::fake()->createWithContent($field.'.pdf', '%PDF-1.4 application evidence');
        }

        return $data;
    }

    private function applicant(string $role, ?string $birthday = null, string $status = 'pending_approval'): User
    {
        $user = User::factory()->pendingKyc()->create(['role' => $role, 'status' => $status, 'birthday' => $birthday]);
        if ($role === 'seller') {
            Shop::factory()->create(['user_id' => $user->id, 'root_category_id' => $this->validMasterCategory()->id, 'status' => 'pending']);
        } elseif ($role === 'courier') {
            CourierProfile::factory()->create(['user_id' => $user->id, 'is_available' => false]);
        } else {
            LogisticsCompany::create(['user_id' => $user->id, 'name' => 'Bagoo Application Logistics', 'slug' => 'birthday-logistics-'.$user->id, 'code' => 'BD'.$user->id, 'contact_email' => $user->email, 'contact_phone' => $user->phone, 'address' => $user->address, 'status' => 'pending', 'is_active' => false]);
        }
        $this->addKycEvidence($user);

        return $user->fresh();
    }

    #[DataProvider('workerRoles')]
    public function test_exactly_eighteen_year_old_worker_registers_with_server_calculated_age(string $role): void
    {
        $data = $this->registration($role, '2008-10-04');
        $this->post('/register', $data)->assertRedirect(route('kyc.pending'))->assertSessionHasNoErrors();
        $user = User::where('email', $data['email'])->sole();
        $this->assertSame('2008-10-04', $user->birthday->toDateString());
        $this->assertSame(18, $user->age);
        $this->assertSame('pending_approval', $user->kyc_status);
        $this->assertSame('pending_approval', $user->status);
        $this->assertFalse($user->canAccessPortal());
    }

    public static function invalidWorkerDates(): array
    {
        $cases = [];
        foreach (['seller', 'courier', 'logistics'] as $role) {
            foreach ([null, '', '2008-10-05', '2026-10-04', '2027-01-01', '2000-02-30', '1900-02-29', '0000-01-01', '10/04/2000', '2000-10-04T00:00:00Z', ['2000-10-04']] as $date) {
                $cases[] = [$role, $date];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidWorkerDates')]
    public function test_invalid_missing_or_underage_worker_dates_cannot_be_overridden_by_client_age(string $role, mixed $date): void
    {
        $data = $this->registration($role, $date);
        $this->post('/register', $data)->assertSessionHasErrors('birthday');
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->assertSame([], Storage::disk('local')->allFiles('kyc_documents'));
    }

    public function test_buyer_age_is_derived_and_optional_birth_date_never_creates_a_claimed_age(): void
    {
        foreach ([['2013-10-04', 13], [null, null], ['2000-10-04', 26]] as [$date, $expected]) {
            $data = $this->registration('buyer', $date);
            $data['age'] = 'client-selected age';
            $this->post('/register', $data)->assertRedirect(route('login'))->assertSessionHasNoErrors();
            $this->assertSame($expected, User::where('email', $data['email'])->sole()->age);
        }
    }

    #[DataProvider('workerRoles')]
    public function test_kyc_approval_requires_a_valid_adult_birth_date_even_with_a_forged_stored_age(string $role): void
    {
        $user = $this->applicant($role, '2008-10-05');
        DB::table('users')->where('id', $user->id)->update(['age' => 99]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->inspectKycEvidence($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    #[DataProvider('workerRoles')]
    public function test_missing_birth_date_prevents_new_worker_approval_despite_valid_private_evidence(string $role): void
    {
        $user = $this->applicant($role);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->inspectKycEvidence($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_birth_date_correction_requires_the_reviewer_to_inspect_the_changed_submission(): void
    {
        $user = $this->applicant('seller', '2000-01-01');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->inspectKycEvidence($admin, $user);
        $this->actingAs($user)->post('/kyc/resubmit', ['birthday' => '2001-01-01'])->assertSessionHas('success');
        $payload = $this->kycPayload($user);
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('kyc_decisions', 0);
        $this->inspectKycEvidence($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertSame('approved', $user->fresh()->kyc_status);
    }

    public function test_approval_repairs_stored_age_and_preserves_the_before_and_after_values(): void
    {
        $user = $this->applicant('seller', '2000-01-01');
        DB::table('users')->where('id', $user->id)->update(['age' => 99]);
        $this->assertSame(26, app(KycDecisionService::class)->presentation($user->fresh())['review_age']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->inspectKycEvidence($admin, $user);
        $payload = $this->kycPayload($user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $decision = KycDecision::sole();
        $this->assertSame(99, $decision->before_state['account']['age']);
        $this->assertSame(26, $decision->after_state['account']['age']);
        $this->assertSame(26, $user->fresh()->age);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertDatabaseCount('kyc_decisions', 1);
    }

    public function test_privileged_services_reload_admin_birth_date_instead_of_trusting_cached_eligibility(): void
    {
        $user = $this->applicant('seller', '2000-01-01');
        $admin = User::factory()->create(['role' => 'admin', 'birthday' => '2000-01-01']);
        $payload = $this->kycPayload($user);
        DB::table('users')->where('id', $admin->id)->update(['birthday' => '2008-10-05']);
        $this->assertTrue($admin->canAccessPortal());
        $request = Request::create('/admin/kyc/'.$user->id.'/approve', 'POST');
        $request->setUserResolver(fn () => $admin);
        foreach ([
            fn () => app(VerificationDocumentService::class)->authorize($admin, $user),
            fn () => app(KycDecisionService::class)->decide($request, $user, 'approved', $payload),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Known underage admins cannot use cached privileged access.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_failed_birth_date_correction_retains_original_details_and_private_files(): void
    {
        $user = $this->applicant('logistics', null, 'suspended');
        $original = $user->getRawOriginal();
        $company = $user->logisticsCompany->getRawOriginal();
        $files = Storage::disk('local')->allFiles('kyc_documents');
        $event = 'eloquent.updating: '.User::class;
        Event::listen($event, fn () => throw new RuntimeException('Simulated birth-date persistence failure.'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post('/kyc/resubmit', [
                'birthday' => '2000-01-01',
                'business_permit' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 corrected permit'),
            ]);
            $this->fail('A failed birth-date correction must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated birth-date persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($original, $user->fresh()->getRawOriginal());
        $this->assertSame($company, $user->logisticsCompany->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles('kyc_documents'));
    }

    #[DataProvider('workerRoles')]
    public function test_missing_birth_date_is_reported_in_the_holding_screen_and_can_be_corrected(string $role): void
    {
        $user = $this->applicant($role, null, 'suspended');
        $paths = Storage::disk('local')->allFiles('kyc_documents');
        $this->actingAs($user)->get('/pending-approval')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('birthDate.needs_correction', true)->where('birthDate.value', null)
            ->where('birthDate.limits.adult_maximum', '2008-10-04'));
        $oldPayload = $this->kycPayload($user);
        $this->post('/kyc/resubmit', ['birthday' => '2000-10-04', 'age' => 99])->assertSessionHas('success');
        $this->assertSame('2000-10-04', $user->fresh()->birthday->toDateString());
        $this->assertSame(26, $user->fresh()->age);
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertSame($paths, Storage::disk('local')->allFiles('kyc_documents'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $oldPayload)->assertConflict();
        $this->inspectKycEvidence($admin, $user->fresh());
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHas('success');
        $this->assertSame('suspended', $user->fresh()->status);
    }

    public function test_rejected_birth_date_correction_retains_the_original_review(): void
    {
        $user = $this->applicant('seller', null);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = $this->kycPayload($user) + ['reason' => 'Birth date is required before review.'];
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $payload)->assertSessionHas('success');
        $original = KycDecision::sole()->getRawOriginal();
        $this->actingAs($user->fresh())->post('/kyc/resubmit', ['birthday' => '2000-10-04'])->assertSessionHas('success');
        $this->assertSame($original, KycDecision::sole()->getRawOriginal());
        $this->assertNull(KycDecision::sole()->submission['account']['birthday']);
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $payload)->assertConflict();
    }

    public function test_approved_and_legacy_verified_accounts_do_not_gain_an_unreviewed_correction_path(): void
    {
        $user = $this->applicant('courier', '2000-10-04');
        foreach (['approved', 'verified'] as $state) {
            $user->update(['kyc_status' => $state, 'status' => 'active']);
            $this->assertTrue($user->canAccessPortal());
            $this->actingAs($user)->post('/kyc/resubmit', ['birthday' => '2001-01-01'])->assertConflict();
            $this->assertSame('2000-10-04', $user->fresh()->birthday->toDateString());
            $this->assertSame($state, $user->fresh()->kyc_status);
        }
        $user->update(['birthday' => null]);
        $this->assertTrue($user->canAccessPortal());
    }

    public static function reviewedKnownDates(): array
    {
        $cases = [];
        foreach (['seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['approved', 'verified'] as $state) {
                $cases[] = [$role, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('reviewedKnownDates')]
    public function test_known_underage_reviewed_workers_and_admins_cannot_open_either_portal(string $role, string $state): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => $state, 'birthday' => '2008-10-05']);
        $this->assertFalse($user->canAccessPortal());
        $portal = $role === 'logistics' ? 'hub' : $role;
        $page = $role === 'courier' ? 'deliveries' : 'dashboard';
        foreach (['http://localhost/'.$portal.'/'.$page, 'http://'.$portal.'.localhost/'.$page] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect();
        }
        $this->assertSame($state, $user->fresh()->kyc_status);
        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_courier_query_and_model_eligibility_agree_on_the_philippine_day(): void
    {
        foreach ([null, '2000-01-01', '2008-10-04', '2008-10-05', '2027-01-01'] as $birthday) {
            $user = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved', 'birthday' => $birthday]);
            $this->assertSame($user->isEligibleCourier(), User::eligibleCouriers()->whereKey($user->id)->exists());
        }
    }

    public function test_age_writes_are_derived_from_birthday_instead_of_a_saved_claim(): void
    {
        $user = User::factory()->create(['birthday' => '2000-10-04', 'age' => 99]);
        $this->assertSame(26, $user->fresh()->age);
        $user->update(['age' => 99]);
        $this->assertSame(26, $user->fresh()->age);
        $user->update(['birthday' => '2001-10-04', 'age' => 99]);
        $this->assertSame(25, $user->fresh()->age);
        $user->update(['birthday' => null, 'age' => 99]);
        $this->assertNull($user->fresh()->age);
    }

    public function test_a_committed_submission_keeps_its_files_if_authenticated_refresh_fails(): void
    {
        $user = $this->applicant('seller', '2000-01-01');
        $user->update(['kyc_status' => 'rejected']);
        $subject = Mockery::mock(User::class)->makePartial();
        $subject->setRawAttributes($user->getAttributes(), true);
        $subject->shouldReceive('refresh')->once()->andThrow(new RuntimeException('Simulated post-commit refresh failure.'));
        try {
            app(KycSubmissionService::class)->submit($subject, ['business_permit' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 corrected permit')]);
            $this->fail('The post-commit refresh failure must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated post-commit refresh failure.', $exception->getMessage());
        }
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        Storage::disk('local')->assertExists($user->fresh()->business_permit_path);
    }

    public function test_birth_date_cutoff_handles_leap_years_and_local_midnight(): void
    {
        $service = app(BirthDateEligibility::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 15:59:00', 'UTC'));
        $this->assertSame(17, $service->age('2008-10-04'));
        $this->travelTo(CarbonImmutable::parse('2026-10-03 16:00:00', 'UTC'));
        $this->assertSame(18, $service->age('2008-10-04'));
        $this->travelTo(CarbonImmutable::parse('2024-02-29 04:00:00', 'UTC'));
        $this->assertSame('2006-02-28', $service->limits()['adult_maximum']);
        $this->assertSame(17, $service->age('2006-03-01'));
        $this->travelTo(CarbonImmutable::parse('2026-02-28 04:00:00', 'UTC'));
        $this->assertSame(17, $service->age('2008-02-29'));
        $this->travelTo(CarbonImmutable::parse('2026-03-01 04:00:00', 'UTC'));
        $this->assertSame(18, $service->age('2008-02-29'));
    }

    public function test_missing_birth_date_cannot_be_cleared_or_replaced_with_an_underage_correction(): void
    {
        $user = $this->applicant('courier');
        foreach ([null, '2008-10-05', '2027-01-01', '2000-02-30'] as $birthday) {
            $this->actingAs($user)->post('/kyc/resubmit', ['birthday' => $birthday, 'age' => 99])->assertSessionHasErrors('birthday');
            $this->assertNull($user->fresh()->birthday);
            $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        }
    }

    public function test_document_only_resubmission_does_not_bypass_missing_worker_birth_date(): void
    {
        $user = $this->applicant('logistics');
        $user->update(['kyc_status' => 'rejected']);
        $files = Storage::disk('local')->allFiles('kyc_documents');
        $this->actingAs($user)->post('/kyc/resubmit', ['business_permit' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 corrected permit')])->assertSessionHasErrors('birthday');
        $this->assertSame('rejected', $user->fresh()->kyc_status);
        $this->assertSame($files, Storage::disk('local')->allFiles('kyc_documents'));
    }

    public function test_pending_date_correction_cannot_replace_files_without_changing_the_date(): void
    {
        $user = $this->applicant('seller', '2000-01-01');
        $files = Storage::disk('local')->allFiles('kyc_documents');
        $this->actingAs($user)->post('/kyc/resubmit', ['birthday' => '2000-01-01', 'business_permit' => UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 replacement permit')])->assertSessionHasErrors('birthday');
        $this->assertSame($files, Storage::disk('local')->allFiles('kyc_documents'));
    }

    public function test_registration_pages_get_server_calendar_limits(): void
    {
        foreach (['/register', '/seller/register', '/courier/register', '/logistics/register', 'http://courier.localhost/register'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('birthDateLimits.today', '2026-10-04')->where('birthDateLimits.past_maximum', '2026-10-03')
                ->where('birthDateLimits.adult_maximum', '2008-10-04'));
        }
    }
}
