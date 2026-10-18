<?php

namespace Tests\Feature\Auth;

use App\Models\LogisticsCompany;
use App\Models\User;
use App\Services\ApplicationIdentityService;
use App\Services\ApplicationValidationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class LogisticsRegistrationDetailsTest extends TestCase
{
    use RefreshDatabase, WithRegistrationPolicies;

    private function application(): array
    {
        return $this->registrationPolicies() + [
            'role' => 'logistics', 'name' => 'Ana Dela Cruz', 'sex' => 'Female', 'birthday' => '1990-01-01',
            'company_name' => 'Bagoo Regional Transport', 'company_code' => 'BGT', 'email' => 'regional@bagoo.test',
            'tin' => '900-000-001-000', 'service_areas' => ['NCR', 'Region III', 'Region IV-A'],
            'phone' => '09173334444', 'address' => '12 Sample Street', 'city' => 'Calamba', 'province' => 'Laguna',
            'password' => 'CompanyPassword1!', 'password_confirmation' => 'CompanyPassword1!',
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
        ];
    }

    private function prepare(): void
    {
        Mail::fake();
        Storage::fake('local');
    }

    public function test_full_application_saves_canonical_tin_and_every_selected_region_independently_of_address(): void
    {
        $this->prepare();
        $this->postJson('/register/validate', $this->application())->assertOk();
        $this->post('/register', $this->application())->assertSessionHasNoErrors();
        $user = User::sole();
        $company = LogisticsCompany::sole();
        $this->assertSame('900000001000', $company->tin);
        $this->assertSame(['NCR', 'Region III', 'Region IV-A'], $company->service_areas);
        $this->assertSame('Laguna', $user->province);
        $this->assertSame('pending_approval', $user->kyc_status);
        $this->assertFalse($company->is_active);
        $this->assertFalse($user->canAccessPortal());
        $this->assertArrayNotHasKey('tin', $company->toArray());
        $this->get('/pending-approval')->assertInertia(fn (Assert $page) => $page
            ->where('application.values.tin', '900000001000')
            ->where('application.values.service_areas', ['NCR', 'Region III', 'Region IV-A']));
    }

    public static function invalidTins(): array
    {
        return array_map(fn ($value) => [$value], [null, '', '   ', '90000000100', '9000000010000',
            'ABC000001000', '９０００００００１０００', "900000001000\n", '900 000 001 000', '900-000-001000', ['900000001000'], true]);
    }

    #[DataProvider('invalidTins')]
    public function test_invalid_or_missing_tin_blocks_step_and_final_submission_without_side_effects(mixed $tin): void
    {
        $this->prepare();
        $data = array_replace($this->application(), ['tin' => $tin]);
        $this->postJson('/register/validate', $data + ['step' => 1])->assertJsonValidationErrors('tin');
        $this->post('/register', $data)->assertSessionHasErrors('tin');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('logistics_companies', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public static function invalidRegions(): array
    {
        return [[null], [[]], ['NCR'], [['NCR', 'NCR']], [['Unknown Region']], [array_fill(0, 19, 'NCR')], [[['NCR']]]];
    }

    #[DataProvider('invalidRegions')]
    public function test_invalid_region_selections_block_the_correct_step_and_final_submission(mixed $regions): void
    {
        $this->prepare();
        $data = array_replace($this->application(), ['service_areas' => $regions]);
        $response = $this->postJson('/register/validate', $data + ['step' => 2])->assertUnprocessable();
        $this->assertTrue(collect(array_keys($response->json('errors')))->contains(fn ($key) => str_starts_with($key, 'service_areas')));
        $this->postJson('/register', $data)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_equivalent_tin_is_rejected_even_with_a_different_company_name(): void
    {
        $this->prepare();
        $owner = User::factory()->create(['role' => 'logistics']);
        LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Existing Company', 'code' => 'EXIST', 'slug' => 'existing', 'tin' => '900000001000']);
        $this->postJson('/register/validate', $this->application())->assertJsonValidationErrors('tin');
        $this->post('/register', $this->application())->assertSessionHasErrors('tin');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('logistics_companies', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_tin_claimed_during_persistence_rolls_back_new_account_and_evidence(): void
    {
        $this->prepare();
        $owner = User::factory()->create(['role' => 'logistics']);
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Existing Company', 'code' => 'EXIST', 'slug' => 'existing']);
        $original = LogisticsCompany::getEventDispatcher();
        $events = clone $original;
        LogisticsCompany::setEventDispatcher($events);
        $events->listen('eloquent.creating: '.LogisticsCompany::class, fn ($applicant) => DB::table('logistics_companies')->where('id', $company->id)->update(['tin' => $applicant->tin]));
        try {
            $this->post('/register', $this->application())->assertSessionHasErrors('tin');
        } finally {
            LogisticsCompany::setEventDispatcher($original);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_emails', 1);
        $this->assertDatabaseCount('logistics_companies', 1);
        $this->assertNull($company->fresh()->tin);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_database_rejects_duplicate_and_invalid_tins_without_form_validation(): void
    {
        $owner = User::factory()->create(['role' => 'logistics']);
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'First Company', 'code' => 'FIRST', 'slug' => 'first', 'tin' => '900000001000']);
        $otherOwner = User::factory()->create(['role' => 'logistics']);
        $other = LogisticsCompany::create(['user_id' => $otherOwner->id, 'name' => 'Other Company', 'code' => 'OTHER', 'slug' => 'other']);
        try {
            DB::table('logistics_companies')->where('id', $other->id)->update(['tin' => $company->tin]);
            $this->fail('Duplicate TIN accepted.');
        } catch (QueryException $exception) {
            $this->assertArrayHasKey('tin', ApplicationIdentityService::conflictErrors($exception));
        }
        $this->expectException(QueryException::class);
        DB::table('logistics_companies')->where('id', $other->id)->update(['tin' => 'letters']);
    }

    public function test_owner_corrections_and_admin_review_keep_the_complete_saved_company_details(): void
    {
        $this->prepare();
        $this->post('/register', $this->application())->assertSessionHasNoErrors();
        $owner = User::sole();
        $this->post('/kyc/resubmit', ['tin' => '900-000-002-000', 'service_areas' => ['Region III', 'Region IV-A']])->assertSessionHasNoErrors();
        $this->assertSame('900000002000', $owner->fresh()->logisticsCompany->tin);
        $this->assertSame(['Region III', 'Region IV-A'], $owner->fresh()->logisticsCompany->service_areas);
        $this->assertSame([], app(ApplicationValidationService::class)->errors($owner->fresh()));
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/kyc')->assertInertia(fn (Assert $page) => $page
            ->where('applicants.data.0.application_details.values.tin', '900000002000')
            ->where('applicants.data.0.application_details.values.service_areas', ['Region III', 'Region IV-A']));
    }

    public function test_form_exposes_the_current_regions_without_enabling_operational_routes(): void
    {
        $this->get('/logistics/register')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/LogisticsRegister')->has('serviceRegions', 18)
            ->where('serviceRegions.NIR', 'NIR (Negros Island Region)'));
    }
}
