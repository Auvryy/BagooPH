<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

/**
 * Mother and child hubs run their own teams and do the scanning. The company owner adds hubs and watches;
 * no hub account can switch into another hub or act as the company.
 */
class MotherHubManagementTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return [['/hub'], ['http://hub.localhost']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function person(string $email, array $overrides = []): array
    {
        return $overrides + ['kind' => 'operator', 'job_role' => 'intake', 'first_name' => 'Lia', 'last_name' => 'Reyes',
            'birthday' => '1995-04-12', 'email' => $email, 'phone' => '0917'.str_pad((string) (crc32($email) % 10000000), 7, '0', STR_PAD_LEFT)];
    }

    #[DataProvider('portals')]
    public function test_a_hub_account_cannot_switch_hubs_or_act_as_the_company(string $base): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $motherOperator = $this->flowHandler($mother);
        $eligibility = app(LogisticsEligibilityService::class);

        $this->assertFalse($eligibility->isCompanyManager($motherOperator, $mother->logistics_company_id));
        $this->assertFalse($eligibility->canScan($motherOperator, $origin));
        $this->assertSame([$mother->id], $eligibility->accessibleHubs($motherOperator)->pluck('id')->all());

        $this->actingAs($motherOperator)->get($base.'/network')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scope.can_switch_facility', false)->where('auth.user.canSwitchHubs', false)->where('auth.user.canManageTeam', true));
        $this->post($base.'/switch-hub', ['hub_id' => $origin->id])->assertForbidden();
        $this->get($base.'/resources')->assertForbidden();
        $this->postJson($base.'/placements', ['kind' => 'handler', 'email' => $this->createApprovedUser('logistics')->email, 'hub_id' => $origin->id])
            ->assertForbidden();
    }

    #[DataProvider('portals')]
    public function test_each_hub_adds_and_runs_only_its_own_team(string $base): void
    {
        Mail::fake();
        $parcel = $this->newFlowOrder()->delivery;
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $motherOperator = $this->flowHandler($mother);
        $childOperator = $this->flowHandler($origin);

        $this->actingAs($motherOperator)->get($base.'/team')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Team')->where('hub.id', $mother->id)->where('hub.is_bayan', false));
        $this->post($base.'/team/staff', $this->person('bagger@example.test', ['job_role' => 'pack_dispatch', 'hub_id' => $origin->id]))
            ->assertSessionHasNoErrors();
        $bagger = User::where('email', 'bagger@example.test')->firstOrFail();
        $this->assertDatabaseHas('hub_handlers', ['user_id' => $bagger->id, 'hub_id' => $mother->id, 'job_role' => 'pack_dispatch']);
        $this->post($base.'/team/staff', $this->person('rider-at-mother@example.test', ['kind' => 'rider']))->assertSessionHasErrors('team');

        $this->actingAs($childOperator)->post($base.'/team/staff', $this->person('rider@example.test', ['kind' => 'rider']))->assertSessionHasNoErrors();
        $rider = User::where('email', 'rider@example.test')->firstOrFail();
        $this->assertSame($origin->id, CourierProfile::where('user_id', $rider->id)->value('assigned_hub_id'));
        $this->post($base.'/team/staff', $this->person('intake@example.test'))->assertSessionHasNoErrors();
        $intake = HubHandler::where('user_id', User::where('email', 'intake@example.test')->value('id'))->firstOrFail();
        $this->assertSame($origin->id, $intake->hub_id);

        // A child hub cannot change the Mother Hub's people or its own access.
        $baggerAssignment = HubHandler::where('user_id', $bagger->id)->firstOrFail();
        $this->patchJson($base.'/handlers/'.$baggerAssignment->id.'/job-role', ['job_role' => 'all'])->assertForbidden();
        $own = HubHandler::where('user_id', $childOperator->id)->firstOrFail();
        $this->patchJson($base.'/handlers/'.$own->id.'/job-role', ['job_role' => 'intake'])->assertForbidden();
        $this->patchJson($base.'/handlers/'.$intake->id.'/job-role', ['job_role' => 'pack_dispatch'])->assertOk();
        $this->assertSame('pack_dispatch', $intake->fresh()->job_role);

        // A worker with a single job does not manage the team.
        $this->assertNull(app(LogisticsEligibilityService::class)->teamHub(User::findOrFail($intake->user_id)));
    }

    #[DataProvider('portals')]
    public function test_company_owner_adds_hub_accounts_and_monitors_but_never_processes(string $base): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $owner = $mother->company->user;
        $eligibility = app(LogisticsEligibilityService::class);

        $this->assertTrue($eligibility->isCompanyManager($owner, $mother->logistics_company_id));
        $this->assertFalse($eligibility->canScan($owner, $mother));
        $this->actingAs($owner)->get($base.'/network')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scope.can_switch_facility', true)->where('auth.user.canScanParcels', false)->where('auth.user.canManageTeam', false));
        $this->get($base.'/team')->assertForbidden();
        $this->get($base.'/scan')->assertForbidden();

        $setup = $base.'/company-setup/'.$mother->logistics_company_id.'/staff';
        $this->post($setup, $this->person('worker@example.test', ['hub_id' => $mother->id]))->assertSessionHasErrors();
        $this->assertDatabaseMissing('users', ['email' => 'worker@example.test']);
        $this->post($setup, $this->person('hub-account@example.test', ['hub_id' => $mother->id, 'job_role' => 'all']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hub_handlers', ['hub_id' => $mother->id, 'job_role' => 'all',
            'user_id' => User::where('email', 'hub-account@example.test')->value('id')]);
    }
}
