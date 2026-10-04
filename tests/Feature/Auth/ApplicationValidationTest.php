<?php

namespace Tests\Feature\Auth;

use App\Models\CourierProfile;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\Shop;
use App\Models\User;
use App\Services\ApplicationValidationService;
use App\Services\KycDecisionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class ApplicationValidationTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    public function test_registration_rejects_digits_in_a_person_name(): void
    {
        Storage::fake('local');
        $this->post('/register', ['name' => 'Bagoo123', 'email' => 'validation@bagoo.test', 'password' => 'Password1234', 'password_confirmation' => 'Password1234'])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_courier_registration_rejects_an_unknown_vehicle_enum(): void
    {
        Storage::fake('local');
        $this->post('/register', [
            'role' => 'courier', 'name' => 'Juan Bagoo', 'email' => 'courier-validation@bagoo.test',
            'phone' => '09171234567', 'birthday' => '2000-01-01', 'address' => '12 Bagoo Street', 'city' => 'Makati',
            'password' => 'Password1234', 'password_confirmation' => 'Password1234',
            'vehicle_type' => 'Unknown aircraft', 'plate_number' => 'ABC-123',
            'id_document' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
            'driver_license' => UploadedFile::fake()->create('license.pdf', 10, 'application/pdf'),
            'or_cr_document' => UploadedFile::fake()->create('orcr.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('vehicle_type');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('courier_profiles', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function payload(string $role = 'buyer'): array
    {
        $data = ['role' => $role, 'name' => 'José Dela Cruz', 'email' => Str::uuid().'@bagoo.test', 'phone' => '09171234567',
            'birthday' => $role === 'buyer' ? null : '2000-01-01', 'address' => '12 Bagoo Street', 'city' => 'Makati', 'postal_code' => '1200',
            'password' => 'Password1234', 'password_confirmation' => 'Password1234'];
        if ($role === 'seller') {
            $data += ['shop_name' => 'Bagoo Shop', 'root_category_id' => $this->validMasterCategory()->id];
        } elseif ($role === 'courier') {
            $data += ['vehicle_type' => 'Motorcycle', 'plate_number' => 'ABC-123', 'license_number' => 'TEST-LICENSE'];
        } elseif ($role === 'logistics') {
            $data += ['company_name' => 'Bagoo Logistics', 'company_code' => 'BAGOO', 'fleet_size' => 2, 'vehicle_types' => ['motorcycle']];
        }
        foreach (match ($role) {
            'seller' => ['id_document', 'business_permit'], 'courier' => ['id_document', 'driver_license', 'or_cr_document'], 'logistics' => ['business_permit'], default => []
        } as $field) {
            $data[$field] = UploadedFile::fake()->create($field.'.pdf', 10, 'application/pdf');
        }

        return $data;
    }

    private function applicant(string $role = 'seller'): User
    {
        $user = User::factory()->pendingKyc()->create(['role' => $role, 'name' => 'José Dela Cruz', 'phone' => '+639171234567', 'address' => '12 Bagoo Street', 'city' => 'Makati', 'postal_code' => '1200', 'birthday' => $role === 'buyer' ? null : '2000-01-01']);
        if ($role === 'seller') {
            Shop::factory()->create(['user_id' => $user->id, 'root_category_id' => $this->validMasterCategory()->id, 'name' => 'Bagoo Shop', 'phone' => '+639171234567', 'address' => '12 Bagoo Street', 'city' => 'Makati', 'status' => 'pending']);
        } elseif ($role === 'courier') {
            CourierProfile::factory()->create(['user_id' => $user->id, 'vehicle_type' => 'Motorcycle', 'plate_number' => 'ABC-123', 'license_number' => 'TEST-LICENSE', 'is_available' => false]);
        } elseif ($role === 'logistics') {
            LogisticsCompany::create(['user_id' => $user->id, 'name' => 'Bagoo Logistics', 'slug' => 'bagoo-logistics-'.$user->id, 'code' => 'BG'.$user->id, 'contact_email' => $user->email, 'contact_phone' => '+63281234567', 'address' => '12 Bagoo Street', 'status' => 'pending', 'is_active' => false]);
        }
        $this->addKycEvidence($user);

        return $user->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public static function invalidFields(): array
    {
        return [
            ['name', 'Juan123'], ['name', 'X'], ['name', str_repeat('a', 101)], ['name', '...'], ['name', "Juan\u{034f} Cruz"], ['name', "Juan\u{fe0f} Cruz"], ['name', 'Juan<script>'],
            ['name', "Juan\0Cruz"], ['name', "\tJuan Cruz"], ['name', "Juan Cruz\n"], ['name', "Juan\u{202e}Cruz"], ['name', "Juan\u{200b}Cruz"], ['name', "Juan\u{1F600}"], ['name', ['Juan']],
            ['phone', '12345'], ['phone', '0917ABC4567'], ['phone', '091712345678'], ['phone', '١٩١٧١٢٣٤٥٦٧'], ['phone', '０９１７１２３４５６７'], ['phone', '++639171234567'], ['phone', '19001234567'], ['phone', '09171234567 ext 1'],
            ['address', '...'], ['address', 'A123'], ['address', str_repeat('A', 501)], ['address', 'javascript:alert(1)'], ['address', '<b>12 Bagoo Street</b>'], ['address', "12 Bagoo\u{200d} Street"],
            ['postal_code', '１２３４'], ['postal_code', '١٢٣٤'], ['postal_code', '+1200'], ['postal_code', '120.0'], ['postal_code', '123'], ['postal_code', '12345'], ['postal_code', "1200\n"],
            ['sex', 'Unknown'], ['city', '...'], ['city', '<script>Makati</script>'], ['province', str_repeat('A', 256)], ['barangay', "Barangay\u{202d} One"],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_fields_have_the_same_rejection_at_registration_correction_and_review(string $field, mixed $value): void
    {
        Storage::fake('local');
        $data = array_replace($this->payload(), [$field => $value]);
        $this->post('/register', $data)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 0);
        $user = $this->applicant('buyer');
        $before = $user->getRawOriginal();
        $this->actingAs($user)->post('/kyc/resubmit', [$field => $value])->assertSessionHasErrors($field);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        // Persisted malformed legacy fields also block a new approval, without silently fixing them.
        if (is_string($value)) {
            $user->update([$field => $value]);
            $submission = app(KycDecisionService::class)->submission($user->fresh());
            $this->assertArrayHasKey($field, app(KycDecisionService::class)->fieldErrors($submission));
            $admin = $this->admin();
            $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user))->assertSessionHasErrors('review');
            $this->assertDatabaseCount('kyc_decisions', 0);
            $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        }
    }

    public static function validFormats(): array
    {
        return [
            ['buyer', "  Jose\u{301} Dela Cruz  ", '09 17123-4567', 'José Dela Cruz', '+639171234567'],
            ['buyer', 'Ana-Marie O’Neil', '639171234567', 'Ana-Marie O’Neil', '+639171234567'],
            ['seller', 'Ｊｕａｎ Cruz', '(02) 8123-4567', 'Juan Cruz', '+63281234567'],
            ['logistics', 'María Dela Cruz', '032 234-5678', 'María Dela Cruz', '+63322345678'],
            ['courier', 'J. Dela Cruz', '+639171234567', 'J. Dela Cruz', '+639171234567'],
        ];
    }

    #[DataProvider('validFormats')]
    public function test_legitimate_details_normalize_and_can_be_corrected_and_reviewed(string $role, string $name, string $phone, string $expectedName, string $expectedPhone): void
    {
        Storage::fake('local');
        $data = array_replace($this->payload($role), ['name' => $name, 'phone' => $phone, 'email' => 'Juan@BAGOO.TEST']);
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $user = User::where('email', 'Juan@bagoo.test')->sole();
        $this->assertSame($expectedName, $user->name);
        $this->assertSame($expectedPhone, $user->phone);
        $this->addKycEvidence($user);
        if ($role === 'buyer') {
            $user->update(['kyc_status' => 'pending_approval']);
        }
        $this->assertSame([], app(ApplicationValidationService::class)->errors($user->fresh()));
        $this->actingAs($user)->post('/kyc/resubmit', ['address' => '  34 Bagoo Avenue  '])->assertSessionHas('success');
        $this->assertSame('34 Bagoo Avenue', $user->fresh()->address);
        $this->inspectKycEvidence($this->admin(), $user->fresh());
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user->fresh()))->assertSessionHas('success');
    }

    public function test_database_safe_maxima_keep_full_names_addresses_and_generated_slugs(): void
    {
        Storage::fake('local');
        $data = array_replace($this->payload('seller'), ['name' => str_repeat('A', 100), 'address' => str_repeat('A', 500), 'shop_name' => str_repeat('A', 255)]);
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $user = User::where('email', $data['email'])->sole();
        $this->assertSame($data['name'], $user->name);
        $this->assertSame($data['address'], $user->address);
        $this->assertSame($data['address'], $user->shop->address);
        $this->assertSame($data['shop_name'], $user->shop->name);
        $this->assertLessThanOrEqual(255, strlen($user->shop->slug));
    }

    public function test_case_equivalent_email_is_rejected_by_validation_and_database(): void
    {
        Storage::fake('local');
        $original = User::factory()->create(['email' => 'Juan@Bagoo.test']);
        $this->post('/register', array_replace($this->payload('seller'), ['email' => 'juan@bagoo.test']))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('shops', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        try {
            User::factory()->create(['email' => 'JUAN@bagoo.test']);
            $this->fail('The database must enforce case-equivalent email identity.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
        $this->assertSame('Juan@Bagoo.test', $original->fresh()->email);
    }

    public function test_email_constraint_failure_after_validation_rolls_back_registration_and_files(): void
    {
        Storage::fake('local');
        Event::listen('eloquent.creating: '.User::class, function (User $user) {
            DB::table('users')->insert(['name' => 'Race Winner', 'email' => strtoupper($user->email), 'password' => 'test-placeholder']);
        });
        $this->post('/register', $this->payload('seller'))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function forbiddenIds(): array
    {
        return array_map(fn ($field) => [$field], ['user_id', 'shop_id', 'company_id', 'logistics_company_id', 'hub_id', 'assigned_hub_id', 'vehicle_id']);
    }

    #[DataProvider('forbiddenIds')]
    public function test_registration_and_correction_cannot_select_foreign_resource_ownership(string $field): void
    {
        Storage::fake('local');
        $this->post('/register', $this->payload('courier') + [$field => 999])->assertSessionHasErrors($field);
        $user = $this->applicant('courier');
        $this->actingAs($user)->post('/kyc/resubmit', [$field => 999])->assertSessionHasErrors($field);
        $this->assertSame('courier', $user->fresh()->role);
    }

    public function test_contact_correction_invalidates_review_inspection_and_keeps_restrictions_and_old_evidence(): void
    {
        $user = $this->applicant();
        $user->update(['status' => 'suspended']);
        $shop = $user->shop;
        $shop->update(['status' => 'suspended']);
        $extra = Shop::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $oldPayload = $this->kycPayload($user);
        $files = Storage::disk('local')->allFiles();
        $this->actingAs($user)->post('/kyc/resubmit', ['postal_code' => '1400', 'shop_phone' => '(02) 8123 4567'])->assertSessionHas('success');
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame('suspended', $shop->fresh()->status);
        $this->assertSame('+63281234567', $shop->fresh()->phone);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $oldPayload)->assertConflict();
        $fresh = $this->kycPayload($user->fresh());
        $this->post('/admin/kyc/'.$user->id.'/approve', $fresh)->assertSessionHasErrors('review');
        $this->inspectKycEvidence($admin, $user->fresh());
        $this->post('/admin/kyc/'.$user->id.'/approve', $fresh)->assertSessionHas('success');
        $this->assertSame('pending', $extra->fresh()->status);
        $this->assertSame('suspended', $shop->fresh()->status);
    }

    public function test_rejected_detail_correction_preserves_recorded_history_and_reviewed_edits_are_denied(): void
    {
        $user = $this->applicant('logistics');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $this->kycPayload($user) + ['reason' => 'Please fix your contact details.'])->assertSessionHas('success');
        $record = KycDecision::sole()->getRawOriginal();
        $this->actingAs($user)->post('/kyc/resubmit', ['company_phone' => '0322345678', 'company_address' => '34 Bagoo Avenue'])->assertSessionHas('success');
        $this->assertSame('+63322345678', $user->logisticsCompany->fresh()->contact_phone);
        $this->assertSame($record, KycDecision::sole()->getRawOriginal());
        $this->inspectKycEvidence($admin, $user->fresh());
        $payload = $this->kycPayload($user->fresh());
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $reviewed = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->post('/kyc/resubmit', ['name' => 'Ana Dela Cruz'])->assertConflict();
        $this->assertSame($reviewed, $user->fresh()->getRawOriginal());
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertDatabaseCount('kyc_decisions', 2);
    }

    public function test_failed_correction_preserves_account_profile_and_old_files(): void
    {
        $user = $this->applicant('logistics');
        $before = $user->getRawOriginal();
        $companyBefore = $user->logisticsCompany->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        Event::listen('eloquent.updating: '.User::class, fn () => throw new RuntimeException('Application write failed.'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post('/kyc/resubmit', ['company_address' => '34 Bagoo Avenue', 'business_permit' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')]);
            $this->fail('Expected write failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Application write failed.', $exception->getMessage());
        }
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($companyBefore, $user->logisticsCompany->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_legacy_readiness_does_not_rewrite_identity_and_completed_history_is_unchanged(): void
    {
        $user = $this->applicant();
        $user->update(['name' => '  Juan Dela Cruz ', 'email' => 'Juan@BAGOO.TEST', 'phone' => '09 17123 4567']);
        $before = $user->getRawOriginal();
        $this->assertSame([], app(ApplicationValidationService::class)->errors($user));
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->inspectKycEvidence($this->admin(), $user);
        $payload = $this->kycPayload($user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $record = KycDecision::sole()->getRawOriginal();
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertSame($record, KycDecision::sole()->getRawOriginal());
        $this->assertSame('Juan@BAGOO.TEST', $user->fresh()->email);
    }

    public function test_a_changed_email_requires_reverification_and_case_insensitive_login_preserves_saved_identity(): void
    {
        $user = $this->applicant('buyer');
        $user->update(['google_id' => 'test-google-id', 'email_verified_at' => now()]);
        $this->actingAs($user)->post('/kyc/resubmit', ['email' => 'Juan@BAGOO.TEST'])->assertSessionHas('success');
        $this->assertSame('Juan@bagoo.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->google_id);
        $this->post('/logout');
        $this->post('/login', ['email' => 'JUAN@BAGOO.TEST', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public static function invalidRoleDetails(): array
    {
        return [['courier', 'vehicle_type', 'motorcycle'], ['courier', 'plate_number', '../ABC'], ['courier', 'license_number', '<script>'],
            ['logistics', 'company_code', str_repeat('A', 21)], ['logistics', 'fleet_size', 0], ['logistics', 'fleet_size', 1.5], ['logistics', 'fleet_size', 10001],
            ['logistics', 'operating_province', '<script>Laguna</script>'], ['logistics', 'operating_province', str_repeat('A', 256)],
            ['logistics', 'vehicle_types', ['unknown']], ['logistics', 'vehicle_types', ['motorcycle', 'motorcycle']], ['logistics', 'franchise_number', 'PENDING-LTFRB']];
    }

    #[DataProvider('invalidRoleDetails')]
    public function test_role_details_use_the_same_registration_correction_and_readiness_rules(string $role, string $field, mixed $value): void
    {
        Storage::fake('local');
        $errorField = $field === 'vehicle_types' ? 'vehicle_types.0' : $field;
        $this->post('/register', array_replace($this->payload($role), [$field => $value]))->assertSessionHasErrors($errorField);
        $this->assertDatabaseCount('users', 0);
        $user = $this->applicant($role);
        $this->actingAs($user)->post('/kyc/resubmit', [$field => $value])->assertSessionHasErrors($errorField);
        if ($role === 'courier') {
            $user->courierProfile->update([$field => $value]);
        } elseif ($field === 'company_code') {
            $user->logisticsCompany->update(['code' => $value]);
        } else {
            $user->logisticsCompany->update(['accreditation_details' => [$field => $value]]);
        }
        $this->assertArrayHasKey($errorField, app(ApplicationValidationService::class)->errors($user->fresh()));
    }

    public function test_case_duplicate_migration_refuses_to_rewrite_ambiguous_legacy_accounts(): void
    {
        DB::statement('DROP INDEX users_email_case_insensitive_unique');
        $first = User::factory()->create(['email' => 'duplicate@bagoo.test']);
        $second = User::factory()->create(['email' => 'DUPLICATE@bagoo.test']);
        $before = [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal()];
        $migration = require database_path('migrations/2026_10_04_000002_enforce_application_field_integrity.php');
        try {
            $migration->up();
            $this->fail('Ambiguous legacy identities require review.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('controlled review', $exception->getMessage());
        }
        $this->assertSame($before, [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal()]);
    }

    public function test_holding_and_admin_inspection_show_fields_and_precise_errors_without_edits(): void
    {
        $user = $this->applicant();
        $user->update(['postal_code' => '12345']);
        $before = $user->getRawOriginal();
        $this->actingAs($user)->get('/pending-approval')->assertInertia(fn (Assert $page) => $page->where('application.can_correct', true)->where('application.values.postal_code', '12345')->has('application.errors.postal_code'));
        $this->actingAs($this->admin())->get('/admin/kyc')->assertInertia(fn (Assert $page) => $page->has('applicants.data.0.application_details.errors.postal_code')->where('applicants.data.0.application_details.values.postal_code', '12345'));
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public function test_an_unsafe_rejection_reason_cannot_change_the_application(): void
    {
        $user = $this->applicant();
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/reject', $this->kycPayload($user) + ['reason' => '<script>alert(1)</script>'])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('kyc_decisions', 0);
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
    }

    public static function registrationProfiles(): array
    {
        return [['courier', CourierProfile::class, 'courier_profiles'], ['logistics', LogisticsCompany::class, 'logistics_companies']];
    }

    public function test_correction_cannot_discard_vehicle_details_when_the_original_courier_profile_is_missing(): void
    {
        $user = $this->applicant('courier');
        $user->courierProfile->delete();
        $before = $user->fresh()->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        $this->actingAs($user)->post('/kyc/resubmit', [
            'vehicle_type' => 'Scooter', 'plate_number' => 'FIX-123',
            'driver_license' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('documents');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('courier_profiles', 0);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    #[DataProvider('registrationProfiles')]
    public function test_profile_creation_failure_rolls_back_the_account_and_private_uploads(string $role, string $model, string $table): void
    {
        Storage::fake('local');
        Event::listen('eloquent.creating: '.$model, fn () => throw new RuntimeException('Profile creation failed.'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/register', $this->payload($role));
            $this->fail('Expected profile creation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Profile creation failed.', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount($table, 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_older_recorded_decision_keeps_its_retry_contract_without_reactivating_resources(): void
    {
        $user = $this->applicant();
        $admin = $this->admin();
        $service = app(KycDecisionService::class);
        $legacySubmission = $service->submission($user);
        unset($legacySubmission['application'], $legacySubmission['applicant_id']);
        $token = $service->token($legacySubmission);
        $decision = KycDecision::create([
            'user_id' => $user->id, 'reviewer_id' => $admin->id, 'reviewer_role' => 'admin', 'reviewer_name' => $admin->name,
            'subject_role' => 'seller', 'submission_token' => $token, 'decision' => 'approved', 'reason' => null,
            'submission' => $legacySubmission, 'before_state' => ['account' => ['status' => 'pending_approval']],
            'after_state' => ['account' => ['status' => 'active']], 'reviewed_at' => now(),
        ]);
        $user->update(['status' => 'suspended', 'kyc_status' => 'approved', 'kyc_reviewed_at' => now()]);
        $user->shop->update(['status' => 'suspended']);
        $before = $decision->fresh()->getRawOriginal();
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', ['review_token' => $token, 'evidence_confirmed' => true])->assertSessionHas('success');
        $this->assertDatabaseCount('kyc_decisions', 1);
        $this->assertSame($before, $decision->fresh()->getRawOriginal());
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame('suspended', $user->shop->fresh()->status);
    }
}
