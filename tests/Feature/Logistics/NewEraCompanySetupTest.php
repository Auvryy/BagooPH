<?php

namespace Tests\Feature\Logistics;

use App\Mail\OtpVerificationMail;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\CompanySetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class NewEraCompanySetupTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureStorage = sys_get_temp_dir().'/bagoo-hub-test-'.bin2hex(random_bytes(8));
        config(['filesystems.disks.local.root' => $this->fixtureStorage.'/private', 'filesystems.disks.public.root' => $this->fixtureStorage.'/public']);
        Storage::forgetDisk('local');
        Storage::forgetDisk('public');
        Mail::fake();
        $this->seed(RoutingCheckoutSeeder::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureStorage);
        parent::tearDown();
    }

    private function company(): LogisticsCompany
    {
        return LogisticsHub::where('code', 'MH-LAG-01')->sole()->company;
    }

    private function person(string $kind = 'operator'): array
    {
        return ['kind' => $kind, 'hub_id' => LogisticsHub::where('code', 'BH-LBN-01')->sole()->id,
            'first_name' => 'Maya', 'last_name' => 'Santos', 'birthday' => '1995-04-12',
            'email' => 'CREW-QA@BAGOO.TEST', 'phone' => '09175551831'];
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

    public function test_owner_invites_a_scoped_operator_and_email_setup_gates_work(): void
    {
        $company = $this->company();
        $owner = $company->user;
        $input = $this->person();
        $this->actingAs($owner)->post('/hub/company-setup/'.$company->id.'/staff', $input)->assertSessionHasNoErrors()->assertSessionHas('success');
        $staff = User::where('email', 'crew-qa@bagoo.test')->sole();
        $this->assertSame('logistics', $staff->role);
        $this->assertSame($company->id, $staff->provisioned_company_id);
        $this->assertTrue($staff->must_change_password);
        $this->assertFalse($staff->canAccessPortal());
        $this->assertDatabaseHas('hub_handlers', ['user_id' => $staff->id, 'hub_id' => $input['hub_id'], 'logistics_company_id' => $company->id]);
        $this->flushSession();
        $this->actingAs($staff)->get('/hub/dashboard')->assertRedirect('/staff/activate');
        $identity = ['email' => $staff->email, 'kind' => 'operator'];
        $code = Mail::sent(OtpVerificationMail::class)->last()->otpCode;
        $verified = $this->postJson('/work-account/verify', [...$identity, 'code' => $code])->assertOk();
        $this->postJson('/work-account/activate', [...$identity, 'token' => $verified->json('token'),
            'assignment_token' => $verified->json('assignment_token'), 'assignment_confirmed' => true,
            'password' => 'My-New-Secret!4826', 'password_confirmation' => 'My-New-Secret!4826'])->assertOk();
        $this->assertTrue(Hash::check('My-New-Secret!4826', $staff->fresh()->password));
        $this->assertTrue($staff->fresh()->canAccessPortal());
        $this->assertDatabaseHas('company_staff_provisions', ['user_id' => $staff->id, 'actor_id' => $owner->id]);
        $this->assertGuest();
        $this->post('/login', ['email' => $staff->email, 'password' => 'My-New-Secret!4826', 'role' => 'logistics'])->assertSessionHasNoErrors();
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

    public function test_staff_email_checks_include_owned_recovery_addresses_within_the_selected_role(): void
    {
        $company = $this->company();
        $owner = $company->user;
        $owner->accountEmails()->create(['email' => 'crew-qa@bagoo.test', 'role' => 'logistics',
            'is_original' => false, 'verified_at' => now()]);
        $before = User::count();
        $url = '/hub/company-setup/'.$company->id.'/staff';

        $this->actingAs($owner)->postJson($url, $this->person())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('company_staff_provisions', 0);
        $this->assertDatabaseHas('account_emails', ['user_id' => $owner->id,
            'email' => 'crew-qa@bagoo.test', 'role' => 'logistics', 'is_original' => false]);

        $this->post($url, $this->person('rider'))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('courier', User::where('email', 'crew-qa@bagoo.test')->sole()->role);
    }

    public static function invalidTemporaryPasswords(): array
    {
        return [['Temporary QA4825'], ["Temporary-QA!4825\n"], ["Temporary-QA!4825\u{200b}"]];
    }

    #[DataProvider('invalidTemporaryPasswords')]
    public function test_owners_cannot_choose_or_reset_worker_passwords(string $password): void
    {
        $company = $this->company();
        $owner = $company->user;
        $before = User::count();
        $url = '/hub/company-setup/'.$company->id.'/staff';
        $this->actingAs($owner)->postJson($url, [...$this->person(), 'password' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('company_staff_provisions', 0);

        $staff = app(CompanySetupService::class)->staff($owner, $company->id, $this->person());
        $originalHash = $staff->password;
        $this->postJson($url.'/'.$staff->id.'/temporary-password', [
            'current_password' => 'Password1234', 'password' => $password,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($originalHash, $staff->fresh()->password);
        $this->assertDatabaseCount('company_staff_login_resets', 0);
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
        $this->actingAs($owner)->postJson('/hub/company-setup/'.$company->id.'/hubs', $input)
            ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
        $input += ['latitude' => '14.2734567', 'longitude' => '121.4551234'];
        $this->actingAs($owner)->post('/hub/company-setup/'.$company->id.'/hubs', $input)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('logistics_hubs', ['code' => 'QA-CH-PAGS', 'parent_hub_id' => $mother->id, 'logistics_company_id' => $company->id]);
        $hub = LogisticsHub::where('code', 'QA-CH-PAGS')->sole();
        $this->assertSame(14.2734567, $hub->latitude);
        $this->assertSame(121.4551234, $hub->longitude);
        $this->post('/hub/company-setup/'.$company->id.'/hubs', [...$input, 'code' => 'QA-CH-DUP'])
            ->assertSessionHasErrors('setup');
        $tariff = ['version' => 1, 'base_fee' => '60.00', 'additional_kg_fee' => '20.00', 'included_weight_grams' => 1000, 'shipping_earnings_model' => 'standard'];
        $this->post('/hub/company-setup/'.$company->id.'/tariff', $tariff)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(2, $company->fresh()->tariff_version);
        $this->assertSame(2000, $company->fresh()->additional_kg_cents);
        $this->post('/hub/company-setup/'.$company->id.'/tariff', $tariff)->assertSessionHasErrors('setup');
        $this->assertDatabaseCount('logistics_tariff_events', 1);
    }

    public function test_expired_invitations_need_owner_confirmation_before_resending(): void
    {
        $company = $this->company();
        $staff = app(CompanySetupService::class)->staff($company->user, $company->id, $this->person());
        $originalHash = $staff->password;
        $this->travel(8)->days();
        $this->postJson('/work-account/verify', ['email' => $staff->email, 'kind' => 'operator', 'code' => '123456'])->assertUnprocessable();
        $url = '/hub/company-setup/'.$company->id.'/staff/'.$staff->id.'/temporary-password';
        $this->actingAs($company->user)->postJson($url, ['current_password' => 'wrong'])->assertUnprocessable();
        $this->post($url, ['current_password' => 'Password1234'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->assertTrue($staff->fresh()->temporary_password_expires_at->isFuture());
        $this->assertNotSame($originalHash, $staff->fresh()->password);
        Mail::assertSent(OtpVerificationMail::class, fn ($mail) => $mail->hasTo($staff->email));
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

    public function test_hub_pin_updates_require_ownership_valid_coordinates_and_current_location(): void
    {
        $company = $this->company();
        $hub = $company->hubs()->where('tier', 'local_bayan_hub')->firstOrFail();
        $hub->update(['latitude' => null, 'longitude' => null]);
        $service = app(CompanySetupService::class);
        $token = $service->locationToken($hub);
        $url = '/hub/company-setup/'.$company->id.'/hubs/'.$hub->id.'/location';
        $pin = ['latitude' => '14.1601234', 'longitude' => '121.2409876', 'location_token' => $token];
        $other = User::factory()->create(['role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved']);
        $this->actingAs($other)->postJson($url, $pin)->assertForbidden();
        $foreignCompany = LogisticsCompany::create(['user_id' => $other->id, 'name' => 'Separate Pin Company',
            'slug' => 'separate-pin-company', 'code' => 'PIN-OTHER', 'status' => 'active', 'is_active' => true]);
        $foreign = LogisticsHub::create(['logistics_company_id' => $foreignCompany->id, 'name' => 'Separate Pin Hub',
            'code' => 'PIN-OTHER-HUB', 'tier' => 'regional_mother_hub', 'province' => 'Laguna',
            'city_municipality' => 'Calamba', 'address' => 'Test address', 'is_active' => true]);
        $this->actingAs($company->user)->post('/hub/company-setup/'.$company->id.'/hubs/'.$foreign->id.'/location',
            [...$pin, 'location_token' => $service->locationToken($foreign)])->assertSessionHasErrors('location_token');
        $this->assertNull($foreign->fresh()->latitude);
        $this->actingAs($company->user)->postJson($url, [...$pin, 'latitude' => '91'])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->post($url, $pin)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(14.1601234, $hub->fresh()->latitude);
        $this->assertSame(121.2409876, $hub->fresh()->longitude);
        $this->post($url, [...$pin, 'latitude' => '14.17'])->assertSessionHasErrors('location_token');
        $this->assertSame(14.1601234, $hub->fresh()->latitude);
        $previousLocation = $service->locationToken($hub->fresh());
        $hub->update(['barangay' => 'A different barangay']);
        $this->post($url, [...$pin, 'latitude' => '14.18', 'location_token' => $previousLocation])->assertSessionHasErrors('location_token');
        $this->assertSame(14.1601234, $hub->fresh()->latitude);
    }
}
