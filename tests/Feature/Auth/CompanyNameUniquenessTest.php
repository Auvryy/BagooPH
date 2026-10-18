<?php

namespace Tests\Feature\Auth;

use App\Models\LogisticsCompany;
use App\Models\User;
use App\Rules\UniqueCompanyName;
use App\Services\ApplicationIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class CompanyNameUniquenessTest extends TestCase
{
    use RefreshDatabase, WithRegistrationPolicies;

    private function company(string $name): LogisticsCompany
    {
        $owner = User::factory()->create(['role' => 'logistics']);

        return LogisticsCompany::create(['user_id' => $owner->id, 'name' => $name, 'slug' => 'company-'.$owner->id, 'code' => 'CODE'.$owner->id]);
    }

    private function application(string $name): array
    {
        return $this->registrationPolicies() + [
            'role' => 'logistics', 'name' => 'Ana Dela Cruz', 'sex' => 'Female', 'birthday' => '1990-01-01',
            'company_name' => $name, 'company_code' => 'BGTEST', 'email' => 'applicant@bagoo.test',
            'tin' => '123-456-789-000', 'service_areas' => ['NCR', 'Region III', 'Region IV-A'],
            'phone' => '09173334444', 'address' => '12 Sample Street', 'city' => 'Makati', 'province' => 'Metro Manila',
            'password' => 'CompanyPassword1!', 'password_confirmation' => 'CompanyPassword1!',
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
        ];
    }

    public static function equivalentNames(): array
    {
        return [['bagoo transport'], [' BAGOO  TRANSPORT '], ['Ｂａｇｏｏ Transport'], ["Bagoo\u{00a0}Transport"]];
    }

    #[DataProvider('equivalentNames')]
    public function test_preflight_and_final_submission_block_duplicate_names_without_partial_accounts(string $name): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->company('Bagoo Transport');
        $data = $this->application($name);
        $this->postJson('/register/validate', $data + ['step' => 1])->assertJsonValidationErrors('company_name');
        $this->post('/register', $data)->assertSessionHasErrors('company_name');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('logistics_companies', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public function test_database_rejects_duplicate_company_keys_even_without_form_validation(): void
    {
        $first = $this->company('Bagoo Transport');
        $second = $this->company('Another Company');
        try {
            DB::table('logistics_companies')->where('id', $second->id)->update(['name_key' => $first->name_key]);
            $this->fail('The duplicate company name was accepted.');
        } catch (QueryException $exception) {
            $this->assertArrayHasKey('company_name', ApplicationIdentityService::conflictErrors($exception));
        }
        $this->assertSame('Another Company', $second->fresh()->name);
    }

    public function test_a_name_claimed_after_preflight_still_blocks_final_registration(): void
    {
        Mail::fake();
        Storage::fake('local');
        $owner = $this->company('Original Company');
        $data = $this->application('Bagoo Transport');
        $this->postJson('/register/validate', $data)->assertOk();
        $owner->update(['name' => 'Bagoo Transport']);
        $this->post('/register', $data)->assertSessionHasErrors('company_name');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unchanged_owner_names_remain_available_for_other_application_corrections(): void
    {
        $company = $this->company('Bagoo Transport');
        $rule = new UniqueCompanyName($company->id);
        $validator = Validator::make(['company_name' => ' BAGOO TRANSPORT '], ['company_name' => [$rule]]);
        $this->assertTrue($validator->passes());
        $company->forceFill(['name_key' => str_repeat('a', 64)])->save();
        $this->assertSame(ApplicationIdentityService::shopNameKey($company->name), $company->fresh()->name_key);
        $this->assertArrayNotHasKey('name_key', $company->toArray());
    }
}
