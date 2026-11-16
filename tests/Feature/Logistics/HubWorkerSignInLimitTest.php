<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

/** A hub's workers share one Wi-Fi, so the sign-in limit counts per account and network, not per network alone. */
class HubWorkerSignInLimitTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_a_whole_shift_can_sign_in_from_the_hub_wifi_while_one_account_stays_limited(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'unassigned');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $workers = collect(range(1, 7))->map(function () use ($hub) {
            $worker = $this->createApprovedUser('logistics');
            $worker->forceFill(['password' => 'Password1234', 'must_change_password' => false])->save();
            HubHandler::create(['user_id' => $worker->id, 'logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id,
                'job_role' => HubHandler::JOB_ROLE_INTAKE, 'role_title' => HubHandler::roleTitleFor(HubHandler::JOB_ROLE_INTAKE), 'is_active' => true]);

            return $worker;
        });
        foreach ($workers as $worker) {
            $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $worker->email, 'password' => 'Password1234', 'device_name' => 'Hub phone'])->assertOk();
        }
        $first = $workers->first();
        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $first->email, 'password' => 'wrong-password', 'device_name' => 'Hub phone']);
        }
        $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $first->email, 'password' => 'Password1234', 'device_name' => 'Hub phone'])->assertStatus(429);
    }
}
