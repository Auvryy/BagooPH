<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\AccountSessions\AccountSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class LogisticsNativeAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RoutingCheckoutSeeder::class);
    }

    private function login(): array
    {
        $operator = User::where('email', 'losbanos.hub@bagoo.test')->sole();
        $response = $this->postJson('/api/v1/logistics/auth/tokens', ['email' => strtoupper($operator->email),
            'password' => 'Password1234', 'device_name' => 'Test scanner'])->assertOk();

        return [$operator, $response->json('data.token'), $response->json('data.hubs')];
    }

    public function test_only_the_real_operator_and_hubs_are_returned_and_scanning_has_no_default_facility(): void
    {
        [$operator, $token, $hubs] = $this->login();
        $hub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $this->assertSame([$hub->id], array_column($hubs, 'id'));
        $this->withToken($token)->getJson('/api/v1/logistics/session')->assertOk()->assertJsonPath('data.user.id', $operator->id);
        $this->postJson('/api/v1/logistics/scan', ['barcode' => 'BG-NOT-RECORDED', 'mode' => 'inspect'])->assertUnprocessable()->assertJsonValidationErrors('hub_id');
        $this->postJson('/api/v1/logistics/scan', ['barcode' => 'BG-NOT-RECORDED', 'mode' => 'inspect',
            'hub_id' => LogisticsHub::where('code', 'BH-SCZ-01')->sole()->id])->assertForbidden();
        $this->postJson('/api/v1/logistics/scan', ['barcode' => 'BG-NOT-RECORDED', 'mode' => 'inspect', 'hub_id' => $hub->id])->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_logout_password_changes_and_revoked_assignments_reject_the_old_native_session(): void
    {
        [$operator, $token] = $this->login();
        $this->withToken($token)->deleteJson('/api/v1/logistics/auth/token')->assertOk();
        $this->getJson('/api/v1/logistics/session')->assertUnauthorized();
        [, $token] = $this->login();
        app(AccountSessionService::class)->passwordChanged($operator);
        $this->withToken($token)->getJson('/api/v1/logistics/session')->assertUnauthorized();
        [, $token] = $this->login();
        HubHandler::where('user_id', $operator->id)->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/v1/logistics/session')->assertForbidden();
    }

    public function test_cookie_only_rider_or_unactivated_accounts_cannot_open_a_scanner_session(): void
    {
        $operator = User::where('email', 'losbanos.hub@bagoo.test')->sole();
        $this->actingAs($operator)->getJson('/api/v1/logistics/session')->assertUnauthorized();
        $this->postJson('/api/v1/logistics/auth/tokens', ['email' => 'rider@bagoo.test', 'password' => 'Password1234', 'device_name' => 'Wrong app'])->assertUnprocessable();
        $operator->forceFill(['must_change_password' => true, 'provisioned_company_id' => LogisticsHub::where('code', 'BH-LBN-01')->sole()->logistics_company_id])->save();
        $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $operator->email, 'password' => 'Password1234', 'device_name' => 'Pending'])->assertStatus(423)->assertJsonPath('activation_required', true);
        $this->withToken('99999999999999999999999999999|bad')->getJson('/api/v1/logistics/session')->assertUnauthorized();
    }

    public function test_expired_wrong_ability_and_disabled_password_logins_cannot_create_or_use_sessions(): void
    {
        [$operator, $token] = $this->login();
        $record = $operator->tokens()->sole();
        $record->forceFill(['abilities' => ['logistics:account']])->save();
        $this->withToken($token)->postJson('/api/v1/logistics/scan', [])->assertUnauthorized();
        $record->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->getJson('/api/v1/logistics/session')->assertUnauthorized();
        $operator->update(['password_login_enabled' => false]);
        $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $operator->email,
            'password' => 'Password1234', 'device_name' => 'Disabled password'])->assertUnprocessable();
        $this->assertSame(1, $operator->tokens()->count());
    }
}
