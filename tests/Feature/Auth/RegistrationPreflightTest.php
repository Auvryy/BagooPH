<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\Shop;
use App\Models\User;
use App\Services\ApplicationValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class RegistrationPreflightTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;
    use WithRegistrationPolicies;

    private function application(string $role): array
    {
        return $this->registrationPolicies() + [
            'role' => $role, 'name' => 'José Dela Cruz', 'first_name' => 'José', 'last_name' => 'Dela Cruz',
            'birthday' => '2000-01-01', 'email' => 'applicant@bagoo.test', 'phone' => '09171234567',
            'address' => '12 Bagoo Street', 'city' => 'Makati', 'province' => 'Metro Manila', 'sex' => 'Male',
            'password' => 'A valid passphrase 2026!', 'password_confirmation' => 'A valid passphrase 2026!',
            'shop_name' => 'Bagoo Shop', 'root_category_id' => $this->validMasterCategory()->id,
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'ABC-123', 'license_number' => 'N01-12-345678',
            'company_name' => 'Bagoo Delivery', 'company_code' => 'BGT', 'fleet_size' => 2,
            'vehicle_types' => ['motorcycle'], 'franchise_number' => null,
            'id_document' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
            'driver_license' => UploadedFile::fake()->create('license.pdf', 10, 'application/pdf'),
            'or_cr_document' => UploadedFile::fake()->create('registration.pdf', 10, 'application/pdf'),
        ];
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics']];
    }

    #[DataProvider('roles')]
    public function test_valid_preflight_is_repeatable_without_accounts_files_or_verification_mail(string $role): void
    {
        Storage::fake('local');
        Mail::fake();
        $data = $this->application($role);
        $this->postJson('/register/validate', $data)->assertExactJson(['valid' => true]);
        $this->postJson('/register/validate', $data)->assertExactJson(['valid' => true]);
        foreach (['users', 'shops', 'courier_profiles', 'logistics_companies', 'email_otps'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_name_sex_and_email_errors_are_available_at_the_first_step(string $role): void
    {
        $data = array_replace($this->application($role), ['step' => 1, 'first_name' => 'Applicant123', 'sex' => 'Unknown', 'email' => 'person @bagoo.test']);
        $this->postJson('/register/validate', $data)->assertJsonValidationErrors(['name', 'first_name', 'sex', 'email']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_future_steps_do_not_block_a_valid_seller_identity_step(): void
    {
        $data = array_replace($this->application('seller'), ['step' => 1, 'phone' => '', 'password' => '', 'id_document' => null, 'business_permit' => null]);
        $this->postJson('/register/validate', $data)->assertExactJson(['valid' => true]);
        $data['step'] = 2;
        $this->postJson('/register/validate', $data)->assertJsonValidationErrors(['phone']);
        unset($data['step']);
        $this->postJson('/register/validate', $data)->assertJsonValidationErrors(['password', 'id_document', 'business_permit']);
    }

    #[DataProvider('roles')]
    public function test_browser_form_requests_return_field_errors_as_json_on_incomplete_identity_steps(string $role): void
    {
        $prefix = match ($role) {
            'seller' => 'seller.', 'courier' => 'courier.', 'logistics' => 'hub.', default => ''
        };
        $data = array_replace($this->application($role), [
            'step' => '1', 'name' => 'Applicant123', 'first_name' => 'Applicant123',
            'last_name' => '', 'email' => 'not-an-address', 'birthday' => '0412-02-31',
            'phone' => '12341234123', 'city' => '',
        ]);
        $expected = ['first_name', 'last_name', 'email'];
        if ($role !== 'buyer') {
            $expected[] = 'birthday';
        }
        $this->post('http://'.$prefix.'localhost/register/validate', $data, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors($expected);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_otps', 0);
    }

    #[DataProvider('roles')]
    public function test_separated_names_are_checked_on_each_role_first_step(string $role): void
    {
        $data = array_replace($this->application($role), ['step' => 1]);
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            foreach (['Name123', 'Name@', "Name\u{200B}", '---'] as $invalid) {
                $this->postJson('/register/validate', array_replace($data, [$field => $invalid]))
                    ->assertJsonValidationErrors($field);
            }
        }
        foreach (['first_name', 'last_name'] as $field) {
            $this->postJson('/register/validate', array_replace($data, [$field => '']))->assertJsonValidationErrors($field);
        }
        $this->postJson('/register/validate', array_replace($data, ['middle_name' => '']))->assertExactJson(['valid' => true]);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('roles')]
    public function test_registration_saves_canonical_separated_names_instead_of_a_conflicting_display_name(string $role): void
    {
        Storage::fake('local');
        Mail::fake();
        $data = array_replace($this->application($role), [
            'name' => 'Different Name123', 'first_name' => '  Ana-María  ', 'middle_name' => 'J.', 'last_name' => '  Peña-O’Connor  ',
            'email' => '  New.Applicant@BAGOO.TEST  ',
        ]);
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect();
        $user = User::sole();
        $this->assertSame('Ana-María J. Peña-O’Connor', $user->name);
        $this->assertSame('new.applicant@bagoo.test', $user->email);
        if ($role === 'logistics') {
            $this->assertSame('new.applicant@bagoo.test', $user->logisticsCompany->contact_email);
        }
        $this->assertSame('Ana-María', $user->first_name);
        $this->assertSame('J.', $user->middle_name);
        $this->assertSame('Peña-O’Connor', $user->last_name);
        $this->assertSame($role, $user->role);
    }

    #[DataProvider('roles')]
    public function test_legacy_name_only_requests_remain_valid_and_invalid_components_cannot_bypass_final_registration(string $role): void
    {
        Mail::fake();
        Storage::fake('local');
        $data = $this->application($role);
        unset($data['first_name'], $data['last_name']);
        $this->postJson('/register/validate', array_replace($data, ['step' => 1]))->assertExactJson(['valid' => true]);
        $this->post('/register', array_replace($data, ['first_name' => 'Name123', 'last_name' => '']))
            ->assertSessionHasErrors(['first_name', 'last_name']);
        $this->assertDatabaseCount('users', 0);
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('José Dela Cruz', User::sole()->name);
        $this->assertNull(User::sole()->first_name);
    }

    #[DataProvider('roles')]
    public function test_component_and_combined_name_boundaries_reject_without_truncating_and_allow_optional_middle_initials(string $role): void
    {
        $data = array_replace($this->application($role), ['step' => 1, 'middle_name' => '']);
        foreach (['first_name', 'last_name'] as $field) {
            $this->postJson('/register/validate', array_replace($data, [$field => 'A']))->assertJsonValidationErrors($field);
            foreach (['Al', str_repeat('A', 50)] as $valid) {
                $this->postJson('/register/validate', array_replace($data, [$field => $valid]))->assertExactJson(['valid' => true]);
            }
            $invalid = array_replace($data, [$field => str_repeat('A', 51)]);
            $this->postJson('/register/validate', $invalid)->assertJsonValidationErrors($field);
            unset($invalid['step']);
            $this->post('/register', $invalid)->assertSessionHasErrors($field);
        }
        foreach (['', 'M', str_repeat('M', 50)] as $middle) {
            $this->postJson('/register/validate', array_replace($data, ['middle_name' => $middle]))->assertExactJson(['valid' => true]);
        }
        $this->postJson('/register/validate', array_replace($data, ['middle_name' => str_repeat('M', 51)]))->assertJsonValidationErrors('middle_name');
        $data = array_replace($data, ['first_name' => str_repeat('A', 49), 'last_name' => str_repeat('B', 50)]);
        $this->postJson('/register/validate', $data)->assertExactJson(['valid' => true]);
        $this->postJson('/register/validate', array_replace($data, ['first_name' => str_repeat('A', 50)]))->assertJsonValidationErrors('name');
        $this->postJson('/register/validate', array_replace($data, ['first_name' => ['Invalid'], 'middle_name' => ['Invalid']]))
            ->assertJsonValidationErrors(['first_name', 'middle_name']);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('roles')]
    public function test_registration_email_case_and_outer_spaces_cannot_bypass_existing_ownership(string $role): void
    {
        $owner = User::factory()->create(['email' => 'Existing.Owner@BAGOO.TEST', 'role' => $role]);
        $data = array_replace($this->application($role), ['email' => '  existing.owner@bagoo.test  ']);
        $this->postJson('/register/validate', $data)->assertJsonValidationErrors('email');
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertSame('Existing.Owner@BAGOO.TEST', $owner->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('roles')]
    public function test_middle_initial_accepts_one_letter_with_optional_period_and_rejects_invalid_direct_submissions(string $role): void
    {
        Storage::fake('local');
        Mail::fake();
        $data = array_replace($this->application($role), ['step' => 1, 'middle_name' => 'Conflicting Middle Name']);
        foreach (['', null, 'M', 'M.', 'ñ', '  M.  ', "E\u{0301}."] as $initial) {
            $this->postJson('/register/validate', array_replace($data, ['middle_initial' => $initial]))->assertExactJson(['valid' => true]);
        }
        foreach (['MM', 'M.M', 'M..', 'Middle', '1', 'M1', '.', 'M-', "M\n", "M\u{200b}", "M\u{034f}", ['M']] as $initial) {
            $invalid = array_replace($data, ['middle_initial' => $initial]);
            $this->postJson('/register/validate', $invalid)->assertJsonValidationErrors('middle_initial');
            unset($invalid['step']);
            $this->post('/register', $invalid)->assertSessionHasErrors('middle_initial');
        }
        $initialOnly = array_replace($data, ['middle_initial' => 'M']);
        unset($initialOnly['first_name'], $initialOnly['last_name'], $initialOnly['middle_name']);
        $this->postJson('/register/validate', $initialOnly)->assertJsonValidationErrors(['first_name', 'last_name']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_otps', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();

        unset($data['step']);
        $this->post('/register', array_replace($data, ['middle_initial' => '  ñ.  ']))->assertSessionHasNoErrors()->assertRedirect();
        $user = User::sole();
        $this->assertSame('ñ.', $user->middle_name);
        $this->assertSame('José ñ. Dela Cruz', $user->name);
        $this->assertSame([], app(ApplicationValidationService::class)->errors($user));
    }

    #[DataProvider('roles')]
    public function test_preflight_is_available_on_the_role_host_and_local_path_alias(string $role): void
    {
        $prefix = match ($role) {
            'seller' => 'seller.', 'courier' => 'courier.', 'logistics' => 'hub.', default => ''
        };
        $data = array_replace($this->application($role), ['step' => 1]);
        $this->postJson('http://'.$prefix.'localhost/register/validate', $data)->assertExactJson(['valid' => true]);
        $this->postJson('http://localhost/register/validate', $data)->assertExactJson(['valid' => true]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_early_duplicate_email_check_includes_verified_contact_addresses_and_case_equivalent_identity(): void
    {
        $owner = User::factory()->create(['email' => 'Owner@bagoo.test']);
        $owner->accountEmails()->create(['email' => 'contact@bagoo.test', 'verified_at' => now()]);
        foreach (['OWNER@BAGOO.TEST', 'CONTACT@BAGOO.TEST'] as $email) {
            $this->postJson('/register/validate', array_replace($this->application('buyer'), ['step' => 1, 'email' => $email]))
                ->assertJsonValidationErrors('email');
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_complete_form_rechecks_hidden_fields_and_rejects_a_changed_category_vehicle_or_fleet(): void
    {
        $this->postJson('/register/validate', array_replace($this->application('seller'), ['root_category_id' => 999999]))
            ->assertJsonValidationErrors('root_category_id');
        $this->postJson('/register/validate', array_replace($this->application('courier'), ['vehicle_type' => 'Aircraft']))
            ->assertJsonValidationErrors('vehicle_type');
        $this->postJson('/register/validate', array_replace($this->application('logistics'), ['vehicle_types' => ['unknown'], 'fleet_size' => 10001]))
            ->assertJsonValidationErrors(['vehicle_types.0', 'fleet_size']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_evidence_content_and_size_are_checked_before_verification_without_storing_files(): void
    {
        Storage::fake('local');
        $this->postJson('/register/validate', array_replace($this->application('seller'), ['business_permit' => UploadedFile::fake()->create('permit.pdf', 10, 'text/plain')]))
            ->assertJsonValidationErrors('business_permit');
        $this->postJson('/register/validate', array_replace($this->application('seller'), ['id_document' => UploadedFile::fake()->create('id.pdf', 5121, 'application/pdf')]))
            ->assertJsonValidationErrors('id_document');
        $this->postJson('/register/validate', array_replace($this->application('seller'), ['id_document' => UploadedFile::fake()->create('id.pdf', 5120, 'application/pdf')]))
            ->assertExactJson(['valid' => true]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_registration_step_cannot_claim_a_managed_resource_or_an_admin_role(): void
    {
        $this->postJson('/register/validate', array_replace($this->application('seller'), ['step' => 1, 'shop_id' => 1]))
            ->assertJsonValidationErrors('shop_id');
        $this->postJson('/register/validate', ['role' => 'admin'])->assertJsonValidationErrors('role');
        $this->postJson('/register/validate', ['role' => ['buyer']])->assertJsonValidationErrors('role');
        $this->postJson('/register/validate', ['role' => 'buyer', 'step' => 3])->assertJsonValidationErrors('step');
    }

    public function test_every_personal_account_mobile_field_rejects_landlines(): void
    {
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            $this->postJson('/register/validate', array_replace($this->application($role), ['phone' => '(02) 8123-4567']))
                ->assertJsonValidationErrors('phone');
        }
    }

    public static function mobileOwnersAndApplicants(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $owner) {
            foreach (['buyer', 'seller', 'courier', 'logistics'] as $applicant) {
                $cases[$owner.' to '.$applicant] = [$owner, $applicant];
            }
        }

        return $cases;
    }

    #[DataProvider('mobileOwnersAndApplicants')]
    public function test_a_mobile_stays_reserved_across_roles_and_equivalent_formats(string $ownerRole, string $applicantRole): void
    {
        Storage::fake('local');
        Mail::fake();
        User::factory()->create(['role' => $ownerRole, 'status' => 'inactive', 'phone' => '+639171234567']);
        $data = $this->application($applicantRole);
        $step = in_array($applicantRole, ['courier', 'logistics'], true) ? 1 : 2;
        foreach (['09171234567', '+639171234567', '+63 917 123 4567'] as $phone) {
            $this->postJson('/register/validate', array_replace($data, ['step' => $step, 'phone' => $phone]))
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->postJson('/register/validate', $data)->assertJsonValidationErrors('phone');
        $this->post('/register', $data)->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('users', 1);
        foreach (['shops', 'courier_profiles', 'logistics_companies', 'email_otps'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public function test_a_mobile_claimed_after_preflight_is_rechecked_before_creating_an_account(): void
    {
        Storage::fake('local');
        Mail::fake();
        $data = $this->application('buyer');
        $this->postJson('/register/validate', $data)->assertExactJson(['valid' => true]);
        User::factory()->admin()->create(['phone' => '+639171234567']);
        $this->post('/register', $data)->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_otps', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public function test_equivalent_shop_names_are_rejected_before_uploads_or_accounts_are_created(): void
    {
        Storage::fake('local');
        Mail::fake();
        Shop::factory()->create(['name' => 'Bagoo Shop', 'status' => 'suspended']);
        foreach (['bagoo shop', ' BAGOO   SHOP ', 'Ｂａｇｏｏ Shop'] as $name) {
            $data = array_replace($this->application('seller'), ['shop_name' => $name]);
            $this->postJson('/register/validate', $data + ['step' => 1])->assertJsonValidationErrors('shop_name');
            $this->post('/register', $data)->assertSessionHasErrors('shop_name');
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('shops', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_videos_and_disguised_documents_are_rejected_without_saving_anything(string $role): void
    {
        Storage::fake('local');
        Mail::fake();
        $field = $role === 'logistics' ? 'business_permit' : 'id_document';
        $payload = UploadedFile::fake()->createWithContent('payload.txt', '<html>This is not a PDF.</html>');
        foreach ([UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'),
            new UploadedFile($payload->getPathname(), 'not-a-document.pdf', 'application/pdf', UPLOAD_ERR_OK, true)] as $file) {
            $data = array_replace($this->application($role), [$field => $file]);
            $this->postJson('/register/validate', $data)->assertJsonValidationErrors($field);
            $this->post('/register', $data)->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_otps', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public static function malformedEmails(): array
    {
        return [['@example.com'], ['name@example'], ['name@bagoo'], ['name @bagoo.test'], ['name@bagoo..test'],
            ['name@@bagoo.test'], ['name@-bagoo.test'], ['name@bagoo.test extra'], ['name(comment)@bagoo.test'], [['name@bagoo.test']]];
    }

    #[DataProvider('malformedEmails')]
    public function test_malformed_addresses_are_rejected_at_preflight_otp_and_account_creation(mixed $email): void
    {
        Mail::fake();
        $data = array_replace($this->application('buyer'), ['email' => $email]);
        $this->postJson('/register/validate', $data)->assertJsonValidationErrors('email');
        $this->postJson('/api/otp/send', ['email' => $email])->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/rider/registration/email/send', ['email' => $email])->assertJsonValidationErrors('email');
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_otps', 0);
        Mail::assertNothingSent();
    }

    public static function legitimateEmails(): array
    {
        return [['buyer@bagoo.test'], ['buyer+orders@Bagoo.test'], ['buyer@department.bagoo.test'], ['buyer@delivery-team.test']];
    }

    #[DataProvider('legitimateEmails')]
    public function test_qualified_custom_and_demo_addresses_remain_eligible_for_verification(string $email): void
    {
        Mail::fake();
        $this->postJson('/register/validate', array_replace($this->application('buyer'), ['email' => $email]))
            ->assertExactJson(['valid' => true]);
        $this->postJson('/api/otp/send', ['email' => $email])->assertOk()->assertJsonPath('success', true);
        Mail::assertSent(OtpVerificationMail::class, fn ($mail) => $mail->hasTo(strtolower($email)));
        $this->assertDatabaseCount('users', 0);
    }
}
