<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\RiderCompanyRequest;
use App\Models\User;
use App\Services\Courier\CourierOperationsService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\RiderCompanyRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class HubRiderHiringTest extends TestCase
{
    use RefreshDatabase, WithRegistrationPolicies;

    private User $owner;

    private LogisticsCompany $company;

    private LogisticsHub $mother;

    private LogisticsHub $child;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = $this->account('logistics');
        $this->company = LogisticsCompany::create(['user_id' => $this->owner->id, 'name' => 'Bagoo Hiring',
            'code' => 'HIRE', 'slug' => 'bagoo-hiring', 'status' => 'active', 'is_active' => true]);
        $this->mother = $this->hub('MH', 'regional_mother_hub');
        $this->child = $this->hub('BH', 'local_bayan_hub');
        $this->child->update(['parent_hub_id' => $this->mother->id]);
        app(RiderCompanyRequestService::class)->changeIntake($this->owner, ['enabled' => true,
            'expected_version' => 0, 'request_token' => (string) Str::uuid()]);
    }

    private function account(string $role = 'courier'): User
    {
        return User::factory()->create(['role' => $role, 'birthday' => '1990-01-01', 'status' => 'active',
            'kyc_status' => 'approved', 'email_verified_at' => now()]);
    }

    private function rider(): User
    {
        $rider = $this->account();
        CourierProfile::factory()->create(['user_id' => $rider->id, 'logistics_company_id' => null,
            'assigned_hub_id' => null, 'vehicle_id' => null, 'is_available' => false]);

        return $rider;
    }

    private function hub(string $code, string $tier): LogisticsHub
    {
        return LogisticsHub::create(['logistics_company_id' => $this->company->id, 'name' => 'Hiring '.$code,
            'code' => $code, 'tier' => $tier, 'province' => 'Laguna', 'city_municipality' => 'Calamba City',
            'address' => '100 Hub Road', 'is_active' => true]);
    }

    private function operator(LogisticsHub $hub): User
    {
        $operator = $this->account('logistics');
        HubHandler::create(['user_id' => $operator->id, 'logistics_company_id' => $this->company->id,
            'hub_id' => $hub->id, 'job_role' => 'all', 'is_active' => true, 'role_title' => 'Operator']);

        return $operator;
    }

    public static function actors(): array
    {
        return [['owner', 'mother'], ['operator', 'mother'], ['owner', 'child'], ['operator', 'child']];
    }

    #[DataProvider('actors')]
    public function test_invitation_requires_rider_acceptance_then_matches_seeded_membership(string $actorKind, string $hubKind): void
    {
        $hub = $this->{$hubKind};
        $actor = $actorKind === 'owner' ? $this->owner : $this->operator($hub);
        $rider = $this->rider();
        $type = $hub->isMotherHub() ? 'wing_truck' : 'l300_van';
        $capacity = $hub->isMotherHub() ? 8000 : 1200;
        $this->actingAs($actor)->post(route('hub.fleet.store'), ['hub_id' => $hub->id,
            'plate_number' => 'HIRE-001', 'vehicle_type' => $type, 'capacity_kg' => $capacity])->assertSessionHasNoErrors();
        $vehicle = LogisticsFleet::where('plate_number', 'HIRE-001')->sole();
        $this->assertSame($type, $vehicle->vehicle_type);
        $this->assertSame((float) $capacity, $vehicle->capacity_kg);
        $this->assertSame('active', $vehicle->status);
        $this->assertNotEmpty($vehicle->model);
        $this->assertSame($hub->id, $vehicle->hub_id);
        $this->assertSame($this->company->id, $vehicle->logistics_company_id);
        // Direct invitations still work when public applications are paused.
        app(RiderCompanyRequestService::class)->changeIntake($this->owner, ['enabled' => false,
            'expected_version' => 1, 'request_token' => (string) Str::uuid()]);
        $input = ['email' => $rider->email, 'hub_id' => $hub->id, 'vehicle_id' => $vehicle->id,
            'terms' => 'Drive the assigned truck from this hub.', 'request_token' => (string) Str::uuid()];
        $offer = $this->actingAs($actor)->postJson('/hub/rider-invitations', $input)->assertOk()->json('data');
        $this->assertNull($rider->courierProfile->fresh()->logistics_company_id);
        $this->assertNull($vehicle->fresh()->assigned_driver_id);
        $this->postJson('/hub/rider-invitations', $input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('rider_company_requests', 1);
        $stranger = $this->rider();
        $accept = ['expected_version' => 2, 'offer_id' => $offer['offer']['id'], 'terms_hash' => $offer['offer']['terms_hash'],
            'acknowledged' => true, 'request_token' => (string) Str::uuid()];
        $this->actingAs($stranger)->postJson('/courier/company-requests/'.$offer['id'].'/accept', $accept)->assertNotFound();
        $this->actingAs($rider)->postJson('/courier/company-requests/'.$offer['id'].'/accept', $accept)->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson('/courier/company-requests/'.$offer['id'].'/accept', $accept)->assertOk()->assertJsonPath('data.replayed', true);
        $profile = $rider->courierProfile->fresh();
        $this->assertSame($this->company->id, $profile->logistics_company_id);
        $this->assertSame($hub->id, $profile->assigned_hub_id);
        $this->assertSame($vehicle->id, $profile->vehicle_id);
        $this->assertSame($rider->id, $vehicle->fresh()->assigned_driver_id);
        $this->assertFalse($profile->is_available);
        $this->assertTrue(CourierProfile::operational()->whereKey($profile->id)->exists());
        app(CourierOperationsService::class)->setAvailability($rider, true);
        $dispatchVehicle = DB::transaction(fn () => app(LogisticsManifestService::class)->vehicle(
            $this->company->id, $hub->id, $vehicle->id, $rider->id, true, $hub->isMotherHub() ? 'line_haul' : 'feeder'));
        $this->assertSame($vehicle->id, $dispatchVehicle->id);
        $this->assertDatabaseCount('rider_company_consents', 1);
        $this->assertDatabaseCount('logistics_placement_records', 1);
    }

    public function test_hub_application_is_scoped_and_owner_can_reject_or_approve(): void
    {
        $motherOperator = $this->operator($this->mother);
        $childOperator = $this->operator($this->child);
        $rider = $this->rider();
        $request = $this->actingAs($rider)->postJson('/courier/company-requests', ['company_id' => $this->company->id,
            'requested_hub_id' => $this->mother->id, 'intake_version' => 1, 'sharing_acknowledged' => true,
            'request_token' => (string) Str::uuid()])->assertOk()->json('data');
        $workspace = app(RiderCompanyRequestService::class)->companyWorkspace($childOperator);
        $this->assertSame(0, $workspace['requests']->total());
        $input = ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'reason' => 'This hub has no vacancy.'];
        $this->actingAs($childOperator)->postJson('/hub/rider-company-requests/'.$request['id'].'/reject', $input)->assertForbidden();
        $this->actingAs($this->owner)->postJson('/hub/rider-company-requests/'.$request['id'].'/reject', $input)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertNull($rider->courierProfile->fresh()->assigned_hub_id);
        $second = app(RiderCompanyRequestService::class)->submit($rider, ['company_id' => $this->company->id,
            'requested_hub_id' => $this->mother->id, 'intake_version' => 1, 'sharing_acknowledged' => true, 'request_token' => (string) Str::uuid()]);
        $offer = $this->actingAs($this->owner)->postJson('/hub/rider-company-requests/'.$second['id'].'/offers', [
            'expected_version' => 1, 'request_token' => (string) Str::uuid(), 'hub_id' => $this->mother->id,
            'terms' => 'Work at the selected mother hub.'])->assertOk()->json('data');
        $this->actingAs($rider)->postJson('/courier/company-requests/'.$second['id'].'/decline', [
            'expected_version' => 2, 'request_token' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertNull($rider->courierProfile->fresh()->assigned_hub_id);
    }

    public function test_invitation_rejects_wrong_hub_or_unapproved_rider_and_signup_requires_sharing(): void
    {
        $operator = $this->operator($this->child);
        $rider = $this->rider();
        $input = ['email' => $rider->email, 'hub_id' => $this->mother->id, 'terms' => 'Work at the selected hub.', 'request_token' => (string) Str::uuid()];
        $this->actingAs($operator)->postJson('/hub/rider-invitations', $input)->assertForbidden();
        $rider->update(['kyc_status' => 'pending_approval']);
        $this->actingAs($this->owner)->postJson('/hub/rider-invitations', $input)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/hub/rider-invitations', [...$input, 'email' => $this->owner->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('rider_company_requests', 0);
        Auth::logout();
        $this->postJson('/register/validate', ['role' => 'courier', 'step' => 2,
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'AB-98765', 'license_number' => 'N02-22-123456',
            'requested_hub_id' => $this->mother->id, 'hub_sharing_acknowledged' => false])->assertUnprocessable()->assertJsonValidationErrors('hub_sharing_acknowledged');
        $this->postJson('/register/validate', ['role' => 'courier', 'step' => 2,
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'AB-98765', 'license_number' => 'N02-22-123456',
            'requested_hub_id' => 999999, 'hub_sharing_acknowledged' => true])->assertUnprocessable()->assertJsonValidationErrors('requested_hub_id');
    }

    public function test_signup_saves_hub_application_without_granting_work_access(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->get('/courier/register')->assertOk();
        $this->post('/register', [...$this->registrationPolicies(), 'sex' => 'Female', 'name' => 'New Hub Driver',
            'email' => 'new.driver@example.com', 'phone' => '+63 917 333 4444', 'address' => '100 Hub Street', 'city' => 'Pasig City',
            'role' => 'courier', 'birthday' => '2000-01-01', 'vehicle_type' => 'Motorcycle', 'plate_number' => 'AB-98765',
            'license_number' => 'N02-22-123456', 'password' => 'Password1234!', 'password_confirmation' => 'Password1234!',
            'id_document' => UploadedFile::fake()->image('id.jpg'), 'driver_license' => UploadedFile::fake()->image('license.jpg'),
            'or_cr_document' => UploadedFile::fake()->image('orcr.jpg'), 'requested_hub_id' => $this->mother->id,
            'hub_sharing_acknowledged' => true])->assertSessionHasNoErrors();
        $rider = User::where('email', 'new.driver@example.com')->sole();
        $this->assertSame('pending_approval', $rider->kyc_status);
        $this->assertNull($rider->courierProfile->logistics_company_id);
        $request = RiderCompanyRequest::where('rider_id', $rider->id)->sole();
        $this->assertSame($this->mother->id, $request->requested_hub_id);
        $this->actingAs($this->owner)->postJson('/hub/rider-company-requests/'.$request->id.'/offers', [
            'expected_version' => 1, 'request_token' => (string) Str::uuid(), 'hub_id' => $this->mother->id,
            'terms' => 'Start work after all approvals.'])->assertForbidden();
        $this->assertDatabaseCount('rider_company_consents', 0);
        $rider->forceFill(['status' => 'active', 'kyc_status' => 'approved', 'email_verified_at' => now()])->save();
        $offer = $this->actingAs($this->owner)->postJson('/hub/rider-company-requests/'.$request->id.'/offers', [
            'expected_version' => 1, 'request_token' => (string) Str::uuid(), 'hub_id' => $this->mother->id,
            'terms' => 'Start work at the selected hub.'])->assertOk()->json('data');
        $this->actingAs($rider)->postJson('/courier/company-requests/'.$request->id.'/accept', [
            'expected_version' => 2, 'request_token' => (string) Str::uuid(), 'offer_id' => $offer['offer']['id'],
            'terms_hash' => $offer['offer']['terms_hash'], 'acknowledged' => true])->assertOk();
        $this->assertSame($this->mother->id, $rider->courierProfile->fresh()->assigned_hub_id);
    }
}
