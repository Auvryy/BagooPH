<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\ApplicationValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class ApplicationFormConsistencyTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase, WithRegistrationPolicies;

    private function applicant(string $role): User
    {
        Storage::fake('local');
        $data = $this->registrationPolicies() + [
            'role' => $role, 'first_name' => 'Ana', 'middle_initial' => 'J.', 'last_name' => 'O’Connor',
            'email' => 'applicant@bagoo.test', 'password' => 'BagooPassword2026!', 'password_confirmation' => 'BagooPassword2026!',
            'sex' => 'Female', 'postal_code' => '0123', 'birthday' => '2000-01-01', 'phone' => '09171234567',
            'address' => '12 Bagoo Street', 'city' => 'Makati', 'province' => 'Metro Manila',
            'shop_name' => 'Bagoo Shop', 'root_category_id' => $this->validMasterCategory()->id,
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'AB-12345', 'license_number' => 'N0112345678',
            'company_name' => 'Bagoo Logistics', 'company_code' => 'BGL', 'fleet_size' => '1', 'vehicle_types' => ['motorcycle'],
            ...array_fill_keys(['id_document', 'business_permit', 'driver_license', 'or_cr_document'], UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\nBagoo evidence\n%%EOF")),
        ];
        $this->post('/register', $data)->assertSessionHasNoErrors();

        return User::where('email', 'applicant@bagoo.test')->sole();
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics']];
    }

    #[DataProvider('roles')]
    public function test_application_corrections_cannot_clear_the_required_sex(string $role): void
    {
        $user = $this->applicant($role);
        foreach ([null, '', '   ', ['Female']] as $sex) {
            $this->actingAs($user)->post('/kyc/resubmit', ['sex' => $sex])->assertSessionHasErrors('sex');
            $this->assertSame('Female', $user->fresh()->sex);
            $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        }
        $this->actingAs($user)->post('/kyc/resubmit', ['sex' => 'Other'])->assertSessionHasNoErrors();
        $this->assertSame('Other', $user->fresh()->sex);
    }

    #[DataProvider('roles')]
    public function test_signup_details_and_correction_controls_match_for_each_role(string $role): void
    {
        $user = $this->applicant($role);
        $form = app(ApplicationValidationService::class)->form($user);
        $fields = collect($form['fields'])->keyBy('key');
        $this->assertFalse($fields->has('name'));
        foreach (['first_name', 'middle_name', 'last_name', 'sex', 'postal_code'] as $key) {
            $this->assertTrue($fields->has($key), $key);
        }
        $this->assertSame(50, $fields['first_name']['max_length']);
        $this->assertSame(50, $fields['last_name']['max_length']);
        $this->assertSame(4, $fields['postal_code']['max_length']);
        $this->assertSame('numeric', $fields['postal_code']['input_mode']);
        $this->assertTrue($fields['sex']['required']);
        $this->assertFalse($fields['postal_code']['required']);
        $this->assertSame('Female', $form['values']['sex']);
        $this->assertSame('0123', $form['values']['postal_code']);
        $this->assertSame($role === 'courier', $fields->has('vehicle_type'));
        $this->assertSame($role === 'seller', $fields->has('shop_name'));
        $this->assertSame($role === 'logistics', $fields->has('company_code'));
        if ($role === 'courier') {
            $this->assertTrue($fields['license_number']['required']);
            $this->assertSame('N01-12-345678', $user->courierProfile->license_number);
        }
    }

    #[DataProvider('roles')]
    public function test_corrections_derive_the_name_and_keep_demographics_under_review(string $role): void
    {
        $user = $this->applicant($role);
        $this->actingAs($user)->post('/kyc/resubmit', ['first_name' => 'María', 'name' => 'Conflicting Display', 'sex' => 'Male', 'postal_code' => '5432'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $user->refresh();
        $this->assertSame('María J. O’Connor', $user->name);
        $this->assertSame('Male', $user->sex);
        $this->assertSame('5432', $user->postal_code);
        $this->assertSame('pending_approval', $user->kyc_status);
        $this->assertSame('applicant@bagoo.test', $user->email);
        $this->assertDatabaseCount('kyc_decisions', 0);
        $before = $user->getRawOriginal();
        foreach ([['sex' => 'Unknown'], ['postal_code' => '12345'], ['postal_code' => '１２３４'], ['first_name' => ''], ['last_name' => 'Santos123'], ['middle_name' => str_repeat('A', 51)]] as $invalid) {
            $this->post('/kyc/resubmit', $invalid)->assertSessionHasErrors(array_keys($invalid));
            $this->assertSame($before, $user->fresh()->getRawOriginal());
        }
    }

    public function test_unchanged_legacy_identifiers_survive_but_changed_identifiers_need_the_new_format(): void
    {
        $user = $this->applicant('courier');
        $user->courierProfile->update(['plate_number' => 'LEGACY-PLATE', 'license_number' => 'LEGACY-LICENSE']);
        $this->actingAs($user)->post('/kyc/resubmit', ['sex' => 'Other'])->assertSessionHasNoErrors();
        $before = $user->courierProfile->fresh()->getRawOriginal();
        foreach ([['plate_number' => 'AB--12345'], ['license_number' => 'N01--12-345678'], ['plate_number' => 'ＡＢ-12345']] as $invalid) {
            $this->post('/kyc/resubmit', $invalid)->assertSessionHasErrors(array_keys($invalid));
            $this->assertSame($before, $user->courierProfile->fresh()->getRawOriginal());
        }
        $this->post('/kyc/resubmit', ['plate_number' => 'AB-54321', 'license_number' => 'N9916552022'])->assertSessionHasNoErrors();
        $this->assertSame('N99-16-552022', $user->courierProfile->fresh()->license_number);
    }

    public function test_company_initials_and_declared_fleet_corrections_cannot_bypass_registration_limits(): void
    {
        $user = $this->applicant('logistics');
        $user->logisticsCompany->update(['code' => 'LEGACY-123']);
        $this->actingAs($user)->post('/kyc/resubmit', ['sex' => 'Other'])->assertSessionHasNoErrors();
        $before = $user->logisticsCompany->fresh()->getRawOriginal();
        foreach ([['company_code' => 'BG12'], ['company_code' => 'ＢＧＬ'], ['company_code' => str_repeat('A', 11)], ['fleet_size' => true], ['fleet_size' => '01'], ['franchise_number' => 'BG--123']] as $invalid) {
            $this->post('/kyc/resubmit', $invalid)->assertSessionHasErrors(array_keys($invalid));
            $this->assertSame($before, $user->logisticsCompany->fresh()->getRawOriginal());
        }
    }
}
