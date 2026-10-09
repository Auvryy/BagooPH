<?php

namespace Tests\Feature\Courier;

use App\Models\Delivery;
use App\Models\RiderCommand;
use App\Models\User;
use App\Services\RiderAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class RiderOperationsApiTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function ready(): array
    {
        $parcel = $this->newFlowOrder('ready_for_pickup')->delivery;
        $rider = $this->flowRider($parcel->originBayanHub);
        $rider->update(['password' => 'Password1234']);

        return [$parcel, $rider];
    }

    private function native(User $rider, ?array $abilities = null): void
    {
        $token = app(RiderAccountService::class)->session($rider, 'API test')['token'];
        if ($abilities !== null) {
            app(RiderAccountService::class)->findToken($token)->forceFill(['abilities' => $abilities])->save();
        }
        $this->withToken($token);
    }

    private function key(): string
    {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key);

        return $key;
    }

    private function version(Delivery $parcel): string
    {
        return $this->getJson('/api/v1/rider/pickup-jobs')->assertOk()->json('data.items.0.version');
    }

    public function test_real_login_keeps_settings_and_adds_narrow_operations_authority(): void
    {
        [, $rider] = $this->ready();
        $session = $this->postJson('/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'Password1234', 'device_name' => 'Rider app'])
            ->assertOk()->assertJsonPath('data.user.settings_api_version', 1)->assertJsonPath('data.user.operations_api_version', 1);
        $token = app(RiderAccountService::class)->findToken($session->json('data.token'));
        $this->assertTrue($token->can('rider:operations:work'));
        $this->assertFalse($token->can('admin:reconcile'));
        $this->withToken($session->json('data.token'))->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.on_duty', true);
        $this->getJson('/api/v1/rider/settings')->assertOk();
    }

    public function test_home_discovers_available_parcel_trip_and_cash_features_from_the_installed_schema(): void
    {
        [, $rider] = $this->ready();
        $this->native($rider);
        $this->getJson('/api/v1/rider/home')->assertOk()
            ->assertJsonPath('data.capabilities.parcel_actions', true)
            ->assertJsonPath('data.capabilities.trips', true)
            ->assertJsonPath('data.capabilities.cash', true)
            ->assertJsonPath('data.capabilities.pre_custody_release', false)
            ->assertJsonPath('data.capabilities.native_restricted_recovery', false)
            ->assertJsonPath('data.capabilities.rider_earnings', false);
        $this->getJson('/api/v1/rider/trips')->assertOk();
        $this->getJson('/api/v1/rider/cash')->assertOk();
    }

    public function test_home_keeps_parcel_trip_and_cash_features_unavailable_without_the_cash_journal_schema(): void
    {
        [, $rider] = $this->ready();
        $this->native($rider);
        Schema::drop('cod_cash_events');
        $this->getJson('/api/v1/rider/home')->assertOk()
            ->assertJsonPath('data.capabilities.parcel_actions', false)
            ->assertJsonPath('data.capabilities.trips', false)
            ->assertJsonPath('data.capabilities.cash', false)
            ->assertJsonPath('data.capabilities.messages', true)
            ->assertJsonPath('data.capabilities.notifications', true);
        $this->assertSame(0, RiderCommand::count());
    }

    public function test_native_preview_matches_web_scope_without_private_destination_or_payment(): void
    {
        [$parcel, $rider] = $this->ready();
        $this->native($rider);
        $response = $this->getJson('/api/v1/rider/pickup-jobs')->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.delivery_id', (string) $parcel->id)->assertJsonPath('data.items.0.preview', true)
            ->assertJsonPath('data.items.0.payment', null)->assertJsonPath('data.items.0.stop.phone', null)
            ->assertJsonPath('data.items.0.stop.latitude', null)->assertJsonPath('data.items.0.actions.claim', true);
        $this->assertStringNotContainsString($parcel->order->recipient_phone, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        $this->actingAs($rider)->get('/courier/deliveries')->assertOk();
        $this->getJson('/api/v1/rider/tasks/pickup-'.$parcel->id)->assertNotFound();
    }

    public function test_claim_lost_response_replay_and_owned_reconciliation_preserve_one_assignment(): void
    {
        [$parcel, $rider] = $this->ready();
        $this->native($rider);
        $version = $this->version($parcel);
        $key = $this->key();
        $body = ['expected_version' => $version];
        $first = $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', $body)->assertOk()
            ->assertJsonPath('data.state', 'committed')->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.result.operational_stage', 'assigned_pickup');
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->getJson('/api/v1/rider/commands/'.$key)->assertOk()->assertJsonPath('data.result', $first->json('data.result'));
        $this->assertSame($rider->id, $parcel->fresh()->courier_id);
        $this->assertSame('ready_for_pickup', $parcel->order->fresh()->status);
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'assigned_pickup')->count());
        $this->assertSame(1, RiderCommand::count());
        $this->getJson('/api/v1/rider/tasks/pickup-'.$parcel->id)->assertOk()->assertJsonPath('data.preview', false);
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.counts.pickup', 1);
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', ['expected_version' => str_repeat('a', 64)])
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $other = $this->flowRider($parcel->originBayanHub, $this->createApprovedUser('courier'));
        $this->native($other);
        $this->getJson('/api/v1/rider/commands/'.$key)->assertNotFound()->assertJsonPath('code', 'COMMAND_UNKNOWN');
    }

    public function test_competing_claim_and_stale_cancellation_leave_original_assignment_intact(): void
    {
        [$parcel, $rider] = $this->ready();
        $other = $this->flowRider($parcel->originBayanHub, $this->createApprovedUser('courier'));
        $this->native($rider);
        $version = $this->version($parcel);
        $this->key();
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', ['expected_version' => $version])->assertOk();
        $this->native($other);
        $this->key();
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', ['expected_version' => $version])->assertConflict()->assertJsonPath('code', 'STALE_RESOURCE');
        $this->assertSame($rider->id, $parcel->fresh()->courier_id);
        $this->assertSame(1, RiderCommand::count());
    }

    public function test_duty_is_explicit_replayable_and_preserves_existing_work_off_duty(): void
    {
        [$parcel, $rider] = $this->ready();
        $this->native($rider);
        $version = $this->version($parcel);
        $this->key();
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', ['expected_version' => $version])->assertOk();
        $this->key();
        $this->patchJson('/api/v1/rider/duty', ['on_duty' => false])->assertOk()->assertJsonPath('data.result.on_duty', false);
        $this->patchJson('/api/v1/rider/duty', ['on_duty' => false])->assertOk()->assertJsonPath('data.replayed', true);
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.eligibility.claim_denial', 'OFF_DUTY')->assertJsonPath('data.counts.pickup', 1);
        $this->getJson('/api/v1/rider/tasks/pickup-'.$parcel->id)->assertOk();
        $this->key();
        $this->patchJson('/api/v1/rider/duty', ['on_duty' => 'false'])->assertUnprocessable();
        $this->assertSame(2, RiderCommand::count());
    }

    public static function badIds(): array
    {
        return array_map(fn ($id) => [$id], ['0', '-1', '01', '1e2', '9223372036854775808', '999999999999999999999999999999999999']);
    }

    #[DataProvider('badIds')]
    public function test_malformed_or_overflow_ids_are_rejected_before_lookup(string $id): void
    {
        [, $rider] = $this->ready();
        $this->native($rider);
        $this->key();
        $this->postJson('/api/v1/rider/pickup-jobs/'.$id.'/claim', ['expected_version' => str_repeat('a', 64)])->assertNotFound();
        $this->getJson('/api/v1/rider/tasks/pickup-'.$id)->assertNotFound();
        $this->assertSame(0, RiderCommand::count());
    }

    public function test_old_ability_expiry_restriction_and_missing_schema_do_not_create_work(): void
    {
        [$parcel, $rider] = $this->ready();
        $this->native($rider, ['rider:account', 'rider:logout', ...RiderAccountService::SETTINGS_ABILITIES]);
        $this->getJson('/api/v1/rider/home')->assertForbidden();
        $this->getJson('/api/v1/rider/settings')->assertOk();
        $this->native($rider);
        $token = app(RiderAccountService::class)->findToken($this->defaultHeaders['Authorization'] ? substr($this->defaultHeaders['Authorization'], 7) : '');
        $token->update(['expires_at' => now()]);
        $this->getJson('/api/v1/rider/home')->assertUnauthorized();
        $this->native($rider);
        $rider->update(['status' => 'suspended']);
        $this->getJson('/api/v1/rider/home')->assertForbidden();
        $this->getJson('/api/v1/rider/home')->assertUnauthorized();
        $rider->update(['status' => 'active']);
        $this->native($rider);
        Schema::drop('rider_commands');
        $this->getJson('/api/v1/rider/home')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $this->getJson('/api/v1/rider/me')->assertOk()->assertJsonMissingPath('data.operations_api_version');
        $this->assertNull($parcel->fresh()->courier_id);
    }

    public function test_unknown_fields_pagination_keys_and_request_ids_are_bounded(): void
    {
        [$parcel, $rider] = $this->ready();
        $this->native($rider);
        $this->withHeader('X-Request-ID', 'untrusted request data')->getJson('/api/v1/rider/home')->assertOk();
        $this->getJson('/api/v1/rider/pickup-jobs?per_page=51')->assertUnprocessable();
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', ['expected_version' => str_repeat('a', 64), 'courier_id' => $rider->id])->assertUnprocessable();
        $this->patchJson('/api/v1/rider/duty', ['on_duty' => false])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->getJson('/api/v1/rider/commands/not-a-key')->assertUnprocessable();
        $this->assertSame(0, RiderCommand::count());
    }

    public function test_command_result_is_retained_after_expiry_without_reapplying_duty(): void
    {
        [, $rider] = $this->ready();
        $this->native($rider);
        $key = $this->key();
        $this->patchJson('/api/v1/rider/duty', ['on_duty' => false])->assertOk();
        $this->travel(7)->days();
        $this->native($rider);
        $this->patchJson('/api/v1/rider/duty', ['on_duty' => false])->assertConflict()->assertJsonPath('code', 'COMMAND_EXPIRED');
        $this->getJson('/api/v1/rider/commands/'.$key)->assertOk()->assertJsonPath('data.retry_expired', true);
        $this->assertSame(1, RiderCommand::count());
    }
}
