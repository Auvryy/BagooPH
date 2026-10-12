<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class RegistrationFieldBoundariesTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase, WithRegistrationPolicies;

    private function application(string $role): array
    {
        return $this->registrationPolicies() + [
            'role' => $role, 'first_name' => 'Ana', 'last_name' => 'Santos', 'middle_initial' => '',
            'email' => 'applicant@bagoo.test', 'password' => 'BagooPassword2026!', 'password_confirmation' => 'BagooPassword2026!',
            'birthday' => '2000-01-01', 'sex' => 'Female', 'phone' => '09171234567',
            'address' => '12 Bagoo Street', 'city' => 'Makati', 'province' => 'Metro Manila', 'municipality' => 'Makati', 'barangay' => 'Poblacion', 'postal_code' => '1210',
            'shop_name' => 'Bagoo Shop', 'root_category_id' => $this->validMasterCategory()->id,
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'AB-12345', 'license_number' => 'N01-12-345678',
            'company_name' => 'Bagoo Logistics', 'company_code' => 'BGL', 'fleet_size' => '1', 'vehicle_types' => ['motorcycle'],
            ...array_fill_keys(['id_document', 'business_permit', 'driver_license', 'or_cr_document'], UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\nBagoo evidence\n%%EOF")),
        ];
    }

    public static function textBoundaries(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            foreach (['first_name' => [2, 50], 'last_name' => [2, 50], 'address' => [5, 500], 'city' => [2, 255], 'province' => [2, 255], 'municipality' => [2, 255], 'barangay' => [2, 255]] as $field => [$minimum, $maximum]) {
                $cases[$role.' '.$field] = [$role, $field, $minimum, $maximum];
            }
        }
        foreach (['seller' => 'shop_name', 'logistics' => 'company_name'] as $role => $field) {
            $cases[$role.' '.$field] = [$role, $field, 2, 255];
        }

        return $cases;
    }

    #[DataProvider('textBoundaries')]
    public function test_text_limits_and_types_are_enforced_at_preflight_and_submission(string $role, string $field, int $minimum, int $maximum): void
    {
        Storage::fake('local');
        $data = $this->application($role);
        foreach ([str_repeat('A', $minimum), str_repeat('A', $maximum)] as $valid) {
            $this->postJson('/register/validate', array_replace($data, [$field => $valid]))->assertOk();
        }
        foreach ([str_repeat('A', $minimum - 1), str_repeat('A', $maximum + 1), ['invalid'], true, "Value\u{200B}", '<script>value</script>'] as $invalid) {
            $request = array_replace($data, [$field => $invalid]);
            $this->postJson('/register/validate', $request)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->post('/register', $request)->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics']];
    }

    #[DataProvider('roles')]
    public function test_sex_and_postal_code_are_validated_and_saved_for_every_public_role(string $role): void
    {
        Storage::fake('local');
        $data = $this->application($role);
        foreach ([['sex', 'unknown'], ['sex', ['Female']], ['postal_code', '123'], ['postal_code', '12345'], ['postal_code', '12AB'], ['postal_code', '１２３４'], ['postal_code', 1210]] as [$field, $invalid]) {
            $this->postJson('/register/validate', array_replace($data, [$field => $invalid]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->post('/register', array_replace($data, ['postal_code' => '0123']))->assertSessionHasNoErrors();
        $user = User::where('email', 'applicant@bagoo.test')->sole();
        $this->assertSame('Female', $user->sex);
        $this->assertSame('0123', $user->postal_code);
    }

    public function test_company_initials_and_fleet_inputs_cannot_be_coerced_into_different_values(): void
    {
        $data = $this->application('logistics');
        foreach (['A', str_repeat('A', 11), 'BG1', 'B-G', 'B G', ['BGL'], "BGL\n"] as $value) {
            $this->postJson('/register/validate', array_replace($data, ['company_code' => $value]))->assertJsonValidationErrors('company_code');
        }
        foreach ([0, -1, 1.5, true, '1e2', '1.5', '01', '10001', ['1']] as $value) {
            $this->postJson('/register/validate', array_replace($data, ['fleet_size' => $value]))->assertJsonValidationErrors('fleet_size');
        }
        foreach (['AB', str_repeat('A', 10), 'bgl'] as $code) {
            foreach (['1', 10000, ''] as $size) {
                $this->postJson('/register/validate', array_replace($data, ['company_code' => $code, 'fleet_size' => $size]))->assertOk();
            }
        }
        foreach ([['motorcycle', 'motorcycle'], ['invalid'], [['motorcycle']], ['motorcycle', 'l300_van', 'wing_truck', 'motorcycle']] as $types) {
            $response = $this->postJson('/register/validate', array_replace($data, ['vehicle_types' => $types]))->assertUnprocessable();
            $this->assertTrue(collect(array_keys($response->json('errors')))->contains(fn ($field) => str_starts_with($field, 'vehicle_types')));
        }
    }

    public function test_plate_formats_are_bounded_for_the_selected_vehicle_and_license_is_not_arbitrary_text(): void
    {
        $data = $this->application('courier');
        foreach (['AB-12345', 'AB12345', '123ABC', '1234AB', 'A1234A', '123-AA'] as $plate) {
            $this->postJson('/register/validate', array_replace($data, ['plate_number' => $plate]))->assertOk();
        }
        foreach (['ABC-1234', 'ABC123', 'ABC12'] as $plate) {
            $this->postJson('/register/validate', array_replace($data, ['vehicle_type' => 'Sedan / Van', 'plate_number' => $plate]))->assertOk();
        }
        foreach (['AB-------12345', 'AB123456', '1234567', 'ABCDEFG', '12', 'ABC?', '１２３ABC', "AB12345\n"] as $plate) {
            $this->postJson('/register/validate', array_replace($data, ['plate_number' => $plate]))->assertJsonValidationErrors('plate_number');
        }
        foreach (['1234D', 'LICENSE-123', 'N01-12-3456789', "N01-12-345678\n", ['N01-12-345678']] as $license) {
            $this->postJson('/register/validate', array_replace($data, ['license_number' => $license]))->assertJsonValidationErrors('license_number');
        }
    }

    public function test_private_documents_have_exact_byte_limits_and_require_matching_content(): void
    {
        Storage::fake('local');
        $data = $this->application('buyer');
        $pdf = "%PDF-1.4\n";
        $this->postJson('/register/validate', array_replace($data, ['id_document' => UploadedFile::fake()->createWithContent('valid.PDF', $pdf.str_repeat(' ', 5242880 - strlen($pdf)))]))->assertOk();
        $invalid = [
            UploadedFile::fake()->createWithContent('empty.pdf', ''),
            UploadedFile::fake()->createWithContent('large.pdf', $pdf.str_repeat(' ', 5242881 - strlen($pdf))),
            UploadedFile::fake()->createWithContent('script.pdf', '<?php echo "invalid";'),
            UploadedFile::fake()->createWithContent('script.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->createWithContent('document.php', $pdf),
            UploadedFile::fake()->createWithContent('wrong.png', $pdf),
        ];
        foreach ($invalid as $file) {
            // Framework fake uploads report the extension's MIME type, even for disguised content.
            $upload = new UploadedFile($file->getPathname(), $file->getClientOriginalName(), null, null, true);
            $request = array_replace($data, ['id_document' => $upload]);
            $this->postJson('/register/validate', $request)->assertJsonValidationErrors('id_document');
            $this->post('/register', $request)->assertSessionHasErrors('id_document');
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
