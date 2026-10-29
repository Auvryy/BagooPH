<?php

namespace Tests\Feature\Logistics;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\CompanySetupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NewEraCompanySetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function company(): LogisticsCompany
    {
        return LogisticsHub::where('code', 'MH-LAG-01')->sole()->company;
    }

    private function person(string $kind = 'operator'): array
    {
        return ['kind' => $kind, 'hub_id' => LogisticsHub::where('code', 'BH-LBN-01')->sole()->id,
            'first_name' => 'Maya', 'last_name' => 'Santos', 'birthday' => '1995-04-12',
            'email' => 'CREW-QA@BAGOO.TEST', 'phone' => '09175551831', 'password' => 'Temporary-QA!4825'];
    }

    public function test_company_map_payload_keeps_saved_coordinates_and_excludes_another_company(): void
    {
        $company = $this->company();
        $hub = $company->hubs()->where('tier', 'local_bayan_hub')->firstOrFail();
        $hub->update(['latitude' => 14.16, 'longitude' => 121.24]);
        $foreignOwner = User::factory()->create(['role' => 'logistics', 'status' => 'active']);
        $foreignCompany = LogisticsCompany::create(['user_id' => $foreignOwner->id, 'name' => 'Separate Map Company',
            'slug' => 'separate-map-company', 'code' => 'MAP-OTHER', 'status' => 'active', 'is_active' => true]);
        $foreign = LogisticsHub::create(['logistics_company_id' => $foreignCompany->id, 'name' => 'Separate Map Hub',
            'code' => 'MAP-OTHER-HUB', 'tier' => 'regional_mother_hub', 'province' => 'Laguna',
            'city_municipality' => 'Calamba', 'address' => 'Test address', 'latitude' => 14.2, 'longitude' => 121.1, 'is_active' => true]);
        $this->actingAs($company->user)->get('/hub/company-setup')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Hub/CompanySetup')->where('hubs', function ($hubs) use ($hub, $foreign) {
                $saved = collect($hubs)->firstWhere('id', $hub->id);

                return $saved && (float) $saved['latitude'] === 14.16 && (float) $saved['longitude'] === 121.24
                    && $saved['parent_hub_id'] === $hub->parent_hub_id && $saved['address'] === $hub->address
                    && ! collect($hubs)->contains('id', $foreign->id);
            }));
    }

    public function test_owner_creates_a_scoped_operator_and_initial_password_and_email_checks_gate_work(): void
    {
        $company = $this->company();
        $owner = $company->user;
        $input = $this->person();
        $this->actingAs($owner)->post('/hub/company-setup/'.$company->id.'/staff', $input)->assertSessionHasNoErrors()->assertSessionHas('success');
        $staff = User::where('email', 'crew-qa@bagoo.test')->sole();
        $this->assertSame('logistics', $staff->role);
        $this->assertSame($company->id, $staff->provisioned_company_id);
        $this->assertTrue(Hash::check($input['password'], $staff->password));
        $this->assertTrue($staff->must_change_password);
        $this->assertFalse($staff->canAccessPortal());
        $this->assertDatabaseHas('hub_handlers', ['user_id' => $staff->id, 'hub_id' => $input['hub_id'], 'logistics_company_id' => $company->id]);
        $this->actingAs($staff)->get('/hub/dashboard')->assertRedirect('/staff/activate');
        $this->get('/staff/activate')->assertOk();
        $this->postJson('/staff/activate', ['current_password' => 'wrong', 'password' => 'My-New-Secret!4826', 'password_confirmation' => 'My-New-Secret!4826', 'assignment_confirmed' => true])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->post('/staff/activate', ['current_password' => $input['password'], 'password' => 'My-New-Secret!4826', 'password_confirmation' => 'My-New-Secret!4826', 'assignment_confirmed' => true])
            ->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));
        $this->assertFalse($staff->fresh()->must_change_password);
        $this->assertFalse($staff->fresh()->canAccessPortal());
        $this->get('/verify-email')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/VerifyEmail'));
        $this->get('/hub/dashboard')->assertRedirect(route('verification.notice'));
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('redirect', '/verify-email');
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(15), ['id' => $staff->id, 'hash' => sha1($staff->email)]);
        $this->get($url)->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        $this->assertTrue($staff->fresh()->canAccessPortal());
        $this->assertDatabaseHas('company_staff_provisions', ['user_id' => $staff->id, 'actor_id' => $owner->id]);
        $this->get('/hub/company-setup')->assertForbidden();
    }

    public function test_owner_can_create_a_rider_but_other_staff_cannot_provision_accounts(): void
    {
        $company = $this->company();
        $rider = app(CompanySetupService::class)->staff($company->user, $company->id, $this->person('rider'));
        $this->assertSame('courier', $rider->role);
        $this->assertSame($company->id, $rider->courierProfile->logistics_company_id);
        $this->assertFalse($rider->courierProfile->is_available);
        $this->assertFalse($rider->isEligibleCourier());
        $operator = User::where('email', 'losbanos.hub@bagoo.test')->sole();
        $before = User::count();
        $this->actingAs($operator)->post('/hub/company-setup/'.$company->id.'/staff', $this->person('operator'))
            ->assertForbidden();
        $this->assertSame($before, User::count());
    }

    public function test_parent_hub_and_postal_coverage_are_owned_and_future_tariffs_are_versioned(): void
    {
        $company = $this->company();
        $owner = $company->user;
        $mother = LogisticsHub::where('code', 'MH-LAG-01')->sole();
        $input = ['name' => 'Pagsanjan Child Hub', 'code' => 'QA-CH-PAGS', 'tier' => 'local_bayan_hub',
            'parent_hub_id' => $mother->id, 'province' => 'Laguna', 'city_municipality' => 'Pagsanjan',
            'address' => '100 Test Warehouse Road', 'postal_codes' => ['4008'], 'capacity' => 500,
            'allows_self_pickup' => true, 'operating_hours' => 'Monday to Saturday, 8 AM to 5 PM'];
        $this->actingAs($owner)->post('/hub/company-setup/'.$company->id.'/hubs', $input)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('logistics_hubs', ['code' => 'QA-CH-PAGS', 'parent_hub_id' => $mother->id, 'logistics_company_id' => $company->id]);
        $this->post('/hub/company-setup/'.$company->id.'/hubs', [...$input, 'code' => 'QA-CH-DUP'])
            ->assertSessionHasErrors('setup');
        $tariff = ['version' => 1, 'base_fee' => '60.00', 'additional_kg_fee' => '20.00', 'included_weight_grams' => 1000, 'shipping_earnings_model' => 'standard'];
        $this->post('/hub/company-setup/'.$company->id.'/tariff', $tariff)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(2, $company->fresh()->tariff_version);
        $this->assertSame(2000, $company->fresh()->additional_kg_cents);
        $this->post('/hub/company-setup/'.$company->id.'/tariff', $tariff)->assertSessionHasErrors('setup');
        $this->assertDatabaseCount('logistics_tariff_events', 1);
    }

    public function test_expired_temporary_logins_need_owner_confirmation_and_a_new_hashed_password(): void
    {
        $company = $this->company();
        $staff = app(CompanySetupService::class)->staff($company->user, $company->id, $this->person());
        $this->travel(8)->days();
        $this->actingAs($staff)->postJson('/staff/activate', ['current_password' => $this->person()['password'],
            'password' => 'Worker-New-Secret!8826', 'password_confirmation' => 'Worker-New-Secret!8826', 'assignment_confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $replacement = 'Replacement-QA!9283';
        $url = '/hub/company-setup/'.$company->id.'/staff/'.$staff->id.'/temporary-password';
        $this->actingAs($company->user)->postJson($url, ['current_password' => 'wrong', 'password' => $replacement])->assertUnprocessable();
        $this->post($url, ['current_password' => 'Password1234', 'password' => $replacement])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->assertTrue($staff->fresh()->temporary_password_expires_at->isFuture());
        $this->assertTrue(Hash::check($replacement, $staff->fresh()->password));
        $this->assertFalse(Hash::check($this->person()['password'], $staff->fresh()->password));
        $this->assertDatabaseCount('company_staff_login_resets', 1);
    }

    public function test_company_scope_duplicate_staff_addresses_and_stale_coverage_are_enforced(): void
    {
        $company = $this->company();
        $owner = $company->user;
        app(CompanySetupService::class)->staff($owner, $company->id, $this->person());
        $count = User::count();
        $this->actingAs($owner)->postJson('/hub/company-setup/'.$company->id.'/staff', [...$this->person(), 'email' => 'crew-qa@bagoo.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($count, User::count());
        $hub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $data = ['parent_hub_id' => LogisticsHub::where('code', 'MH-LAG-01')->sole()->id,
            'postal_codes' => ['4030'], 'coverage_token' => app(CompanySetupService::class)->coverageToken($hub)];
        $this->post('/hub/company-setup/'.$company->id.'/hubs/'.$hub->id.'/coverage', [...$data, 'postal_codes' => ['4030', '4031']])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post('/hub/company-setup/'.$company->id.'/hubs/'.$hub->id.'/coverage', $data)->assertSessionHasErrors('setup');
        $this->assertSame(['4030', '4031'], $hub->fresh()->postal_codes);
        $admin = User::where('email', 'admin@bagoo.test')->sole();
        $this->actingAs($admin)->get('/hub/company-setup')->assertOk();
        $this->post('/hub/company-setup/'.$company->id.'/staff', [...$this->person(), 'email' => 'another@bagoo.test'])->assertForbidden();
        $owner->update(['status' => 'suspended']);
        $this->actingAs($owner)->postJson('/hub/company-setup/'.$company->id.'/staff', [...$this->person(), 'email' => 'another@bagoo.test'])->assertForbidden();
        $this->assertSame($count, User::count());
    }
}
