<?php

namespace Tests\Feature\Courier;

use App\Models\CodAccount;
use App\Models\Message;
use App\Models\RiderCommand;
use App\Models\User;
use App\Services\Courier\CourierOutcomeService;
use App\Services\Courier\RiderCashService;
use App\Services\Courier\RiderTaskService;
use App\Services\RiderAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class RiderOperationsBoundaryTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function native(User $rider): string
    {
        $token = app(RiderAccountService::class)->session($rider, 'Boundary test')['token'];
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid());

        return $token;
    }

    public function test_wrong_roles_and_pending_accounts_cannot_read_or_write_operations(): void
    {
        foreach (['buyer', 'seller', 'logistics', 'admin'] as $role) {
            $actor = $this->createApprovedUser($role);
            $issued = $actor->createToken('Boundary test', ['rider:account', ...RiderAccountService::OPERATIONS_ABILITIES], now()->addHour());
            $issued->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $actor->getAuthPassword())])->save();
            $this->withToken($issued->plainTextToken)->getJson('/api/v1/rider/trips')->assertForbidden();
            $this->getJson('/api/v1/rider/cash')->assertUnauthorized();
        }
        $rider = $this->createPendingUser('courier');
        $this->native($rider);
        $this->getJson('/api/v1/rider/home')->assertForbidden();
        $this->patchJson('/api/v1/rider/duty', ['on_duty' => true])->assertForbidden();
        $this->getJson('/api/v1/rider/me')->assertOk()->assertJsonPath('data.access_state', 'holding');
        $this->assertSame(0, RiderCommand::count());
    }

    public function test_unplaced_parent_restricted_and_revoked_sessions_cannot_claim(): void
    {
        $parcel = $this->newFlowOrder('ready_for_pickup')->delivery;
        $rider = $this->flowRider($parcel->originBayanHub);
        $rider->courierProfile->update(['assigned_hub_id' => null]);
        $this->native($rider);
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.eligibility.claim_denial', 'PLACEMENT_UNAVAILABLE');
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', ['expected_version' => app(RiderTaskService::class)->version($parcel)])->assertForbidden();
        $rider->courierProfile->update(['assigned_hub_id' => $parcel->origin_bayan_hub_id]);
        $parcel->company->update(['status' => 'inactive']);
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.eligibility.operational', false);
        $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', ['expected_version' => app(RiderTaskService::class)->version($parcel)])->assertConflict();
        $token = app(RiderAccountService::class)->findToken(substr($this->defaultHeaders['Authorization'], 7));
        $token->delete();
        $this->getJson('/api/v1/rider/home')->assertUnauthorized();
        $this->assertNull($parcel->fresh()->courier_id);
        $this->assertSame(0, RiderCommand::count());
    }

    public function test_authority_withdrawn_inside_outcome_rolls_back_cash_proof_and_command(): void
    {
        Storage::fake('local');
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $plain = $this->native($rider);
        $writer = new CourierOutcomeService;
        $this->partialMock(CourierOutcomeService::class)->shouldReceive('record')->once()->andReturnUsing(
            function ($actor, $delivery, $target, $evidence) use ($writer, $plain) {
                $result = $writer->record($actor, $delivery, $target, $evidence);
                app(RiderAccountService::class)->findToken($plain)->forceFill(['abilities' => ['rider:account', 'rider:operations:read']])->save();

                return $result;
            });
        $this->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/deliver', [
            'expected_version' => app(RiderTaskService::class)->version($parcel), 'barcode' => $parcel->tracking_number,
            'recipient_name' => $parcel->order->recipient_name, 'recipient_relationship' => 'buyer',
            'cash_received' => (string) $parcel->order->total_amount, 'change_given' => '0.00', 'cash_confirmed' => true,
            'proof_image_file' => UploadedFile::fake()->image('proof.png'),
        ])->assertForbidden();
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->assertSame(0, $parcel->checkpoints()->where('checkpoint_type', 'delivered')->count());
        $this->assertSame(0, CodAccount::count());
        $this->assertSame(0, RiderCommand::count());
        $this->assertSame([], Storage::disk('local')->allFiles('delivery-proofs'));
    }

    public function test_cash_and_message_overrides_and_foreign_read_boundaries_reject(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        $this->native($rider);
        $thread = $this->getJson('/api/v1/rider/conversations')->assertOk()->json('data.items.0.id');
        $other = Message::create(['sender_id' => $parcel->order->buyer_id, 'receiver_id' => $rider->id,
            'order_id' => $parcel->order_id, 'message' => 'A different conversation.', 'is_read' => false]);
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/read', ['through_message_id' => (string) $other->id])->assertConflict();
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/read', ['through_message_id' => '9223372036854775808'])->assertNotFound();
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/messages', ['text' => 'A parcel update.', 'company_id' => '1'])->assertUnprocessable();
        $this->postJson('/api/v1/rider/cash/1/offer', ['expected_version' => '1', 'recipient_id' => '1', 'amount' => '1.00',
            'evidence_reference' => 'CASH-001', 'holder_id' => '1'])->assertUnprocessable();
        $this->postJson('/api/v1/rider/cash/1/offer', ['expected_version' => '1', 'recipient_id' => '9223372036854775808',
            'amount' => '1.00', 'evidence_reference' => 'CASH-001'])->assertNotFound();
        $this->assertFalse($other->fresh()->is_read);
        $this->assertSame(0, RiderCommand::count());
    }

    public function test_missing_notice_schema_is_unavailable_and_not_advertised(): void
    {
        $this->native($this->createApprovedUser('courier'));
        Schema::drop('notifications');
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.capabilities.notifications', false);
        $this->getJson('/api/v1/rider/notifications')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
    }

    public function test_database_errors_are_private_and_only_sanitized_correlation_is_logged(): void
    {
        $this->native($this->createApprovedUser('courier'));
        Log::spy();
        $this->partialMock(RiderCashService::class)->shouldReceive('list')->once()->andThrow(
            new QueryException('sqlite', 'select sensitive_customer_data', ['secret-binding'], new \PDOException('private-database-detail')));
        $response = $this->getJson('/api/v1/rider/cash')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        foreach (['sensitive_customer_data', 'secret-binding', 'private-database-detail', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        Log::shouldHaveReceived('error')->once()->with('Native Rider operation failed.', [
            'request_id' => $response->json('request_id'), 'exception_type' => QueryException::class,
        ]);
    }

    public function test_rate_limit_is_json_with_bounded_retry_and_no_html_redirect(): void
    {
        $this->native($this->createApprovedUser('courier'));
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/rider/home')->assertOk();
        }
        $response = $this->getJson('/api/v1/rider/home')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertGreaterThanOrEqual(1, $response->json('cooldown'));
        $this->assertLessThanOrEqual(300, $response->json('cooldown'));
        $this->assertSame((string) $response->json('cooldown'), $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Location'));
    }
}
