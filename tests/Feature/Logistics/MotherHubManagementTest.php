<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\RiderCompanyRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class MotherHubManagementTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return [['/hub'], ['http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_existing_mother_handler_can_manage_personnel_without_impersonating_other_facility_handlers(string $base): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $manager = $this->flowHandler($mother);
        $bayan = $this->flowHandler($origin);
        $newHandler = $this->createApprovedUser('logistics');
        $companyOwner = $mother->company->user;
        $this->assertNotSame($companyOwner->id, $manager->id);
        $eligibility = app(LogisticsEligibilityService::class);
        $this->assertTrue($eligibility->isCompanyManager($manager, $mother->logistics_company_id));
        $this->assertFalse($eligibility->isCompanyManager($bayan, $mother->logistics_company_id));
        $this->assertFalse($eligibility->canScan($manager, $origin));
        $this->flushSession();
        $this->actingAs($manager)->get($base.'/scan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Hub/ScanStation')->where('activeHub.id', $mother->id));
        $input = ['kind' => 'handler', 'email' => $newHandler->email, 'hub_id' => $origin->id];
        $this->actingAs($bayan)->postJson($base.'/placements', $input)->assertForbidden();
        $this->assertFalse(HubHandler::where('user_id', $newHandler->id)->exists());
        $this->actingAs($manager)->postJson($base.'/placements', $input)->assertOk();
        $this->assertDatabaseHas('hub_handlers', ['user_id' => $newHandler->id, 'hub_id' => $origin->id,
            'logistics_company_id' => $mother->logistics_company_id, 'is_active' => true]);
        $this->assertFalse($eligibility->canScan($manager, $origin));
        $this->get($base.'/network')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Network')->where('scope.can_switch_facility', true)
            ->where('riderIntake.available', true));
        $this->get($base.'/fleet')->assertOk();
        $this->get($base.'/resources')->assertOk();
        $this->get($base.'/resources/hub/'.$origin->id)->assertOk();
        $this->actingAs($bayan)->get($base.'/resources')->assertForbidden();
        $this->actingAs($manager)->putJson($base.'/rider-request-intake', [
            'enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertTrue(app(RiderCompanyRequestService::class)->intakeRecorded($mother->company->fresh()));
        $this->assertTrue(app(RiderCompanyRequestService::class)->directory()->whereKey($mother->logistics_company_id)->exists());
        $this->putJson($base.'/rider-request-intake', [
            'enabled' => false, 'expected_version' => 1, 'request_token' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertFalse(app(RiderCompanyRequestService::class)->directory()->whereKey($mother->logistics_company_id)->exists());
        $otherOwner = $this->createApprovedUser('logistics');
        $foreignCompany = LogisticsCompany::create(['user_id' => $otherOwner->id,
            'name' => 'Other Delivery Network', 'slug' => 'other-delivery-network', 'code' => 'OTHER-MGT',
            'status' => 'active', 'is_active' => true]);
        $foreignHub = LogisticsHub::create(['logistics_company_id' => $foreignCompany->id,
            'name' => 'Other Mother Hub', 'code' => 'OTHER-MGT-MH', 'tier' => 'regional_mother_hub',
            'province' => 'Laguna', 'city_municipality' => 'Calamba', 'address' => '100 Other Hub Road', 'is_active' => true]);
        $this->assertFalse($eligibility->isCompanyManager($manager, $foreignCompany->id));
        $this->get($base.'/resources/hub/'.$foreignHub->id)->assertNotFound();
        $this->postJson($base.'/placements', [...$input, 'hub_id' => $foreignHub->id])->assertForbidden();
        $manager->update(['status' => 'suspended']);
        $this->assertFalse($eligibility->isCompanyManager($manager, $mother->logistics_company_id));
    }
}
