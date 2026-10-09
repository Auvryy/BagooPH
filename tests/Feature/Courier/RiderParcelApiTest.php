<?php

namespace Tests\Feature\Courier;

use App\Models\CodAccount;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\RiderCommand;
use App\Models\User;
use App\Services\Courier\RiderTaskService;
use App\Services\Finance\CodMoney;
use App\Services\RiderAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class RiderParcelApiTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function native(User $rider): void
    {
        $this->withToken(app(RiderAccountService::class)->session($rider, 'Parcel test')['token']);
    }

    private function action(Delivery $parcel, string $phase, string $action, array $input, ?string $key = null)
    {
        $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid());
        $input += ['expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])), 'barcode' => $parcel->tracking_number];

        return $this->postJson('/api/v1/rider/tasks/'.$phase.'-'.$parcel->id.'/'.$action, $input);
    }

    public function test_native_pickup_is_waybill_verified_off_duty_and_origin_hub_receives_the_same_record(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        $rider->courierProfile->update(['is_available' => false]);
        $this->native($rider);
        $version = app(RiderTaskService::class)->version($parcel);
        $key = (string) Str::uuid();
        $this->action($parcel, 'pickup', 'pickup', ['barcode' => 'WRONG-WAYBILL'])->assertUnprocessable();
        $this->action($parcel, 'pickup', 'pickup', ['expected_version' => $version, 'notes' => 'Collected the sealed parcel at the seller.'], $key)->assertOk()
            ->assertJsonPath('data.result.operational_stage', 'picked_up');
        $this->action($parcel, 'pickup', 'pickup', ['expected_version' => $version, 'notes' => 'Collected the sealed parcel at the seller.'], $key)->assertOk()
            ->assertJsonPath('data.replayed', true);
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'picked_up')->count());
        $this->getJson('/api/v1/rider/tasks/pickup-'.$parcel->id)->assertOk()->assertJsonPath('data.stop.kind', 'origin_hub');
        $this->action($parcel, 'pickup', 'depart', [])->assertForbidden();
        $this->confirmFlowScan($parcel, $parcel->originBayanHub, 'RECEIVE_FROM_PICKUP_RIDER');
        $this->native($rider);
        $this->getJson('/api/v1/rider/tasks?phase=pickup')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
    }

    public function test_native_departure_and_private_delivery_record_real_cash_without_buyer_completion(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_to_rider');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->native($rider);
        $this->action($parcel, 'final_mile', 'depart', [])->assertOk()->assertJsonPath('data.result.operational_stage', 'out_for_delivery');
        $parcel->refresh();
        $this->getJson('/api/v1/rider/tasks/final_mile-'.$parcel->id)->assertOk()->assertJsonPath('data.stop.kind', 'buyer')
            ->assertJsonPath('data.payment.cod_due_cents', (string) CodMoney::cents($parcel->order->total_amount));
        $body = ['expected_version' => app(RiderTaskService::class)->version($parcel), 'recipient_name' => $parcel->order->recipient_name,
            'recipient_relationship' => 'buyer', 'cash_received' => (string) $parcel->order->total_amount,
            'change_given' => '0.00', 'cash_confirmed' => true, 'proof_image_file' => UploadedFile::fake()->image('handoff.jpg')];
        $key = (string) Str::uuid();
        $first = $this->action($parcel, 'final_mile', 'deliver', $body, $key)->assertOk()->assertJsonPath('data.result.operational_stage', 'delivered');
        $this->action($parcel, 'final_mile', 'deliver', $body, $key)->assertOk()->assertJsonPath('data.replayed', true);
        $this->getJson('/api/v1/rider/commands/'.$key)->assertOk()->assertJsonPath('data.result', $first->json('data.result'));
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $cash = CodAccount::where('delivery_id', $parcel->id)->sole();
        $this->assertNull($cash->reconciled_at);
        $this->assertSame(1, $cash->events()->count());
        $this->assertSame($cash->expected_cents, $cash->state['balances']['rider:'.$rider->id]);
        $this->assertTrue(Storage::disk('local')->exists($parcel->fresh()->proof_image));
        $this->assertSame([], Storage::disk('public')->allFiles('delivery-proofs'));
        $this->assertCount(1, Storage::disk('local')->allFiles('delivery-proofs'));
        $this->assertStringNotContainsString('delivery-proofs/', $first->getContent());
        $this->actingAs($parcel->order->buyer)->post('/buyer/orders/'.$parcel->order_id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $parcel->order->fresh()->status);
        $this->assertNull($cash->fresh()->reconciled_at);
    }

    public function test_failure_is_recorded_once_and_waits_for_real_destination_hub_return(): void
    {
        Storage::fake('local');
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->native($rider);
        $body = ['expected_version' => app(RiderTaskService::class)->version($parcel), 'reason' => 'customer_unreachable',
            'notes' => 'Called and waited at the saved address without an answer.', 'location_name' => 'The saved buyer address',
            'proof_image_file' => UploadedFile::fake()->image('attempt.png')];
        $key = (string) Str::uuid();
        $this->action($parcel, 'final_mile', 'fail', $body, $key)->assertOk()->assertJsonPath('data.result.operational_stage', 'delivery_failed');
        $this->action($parcel, 'final_mile', 'fail', $body, $key)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, DeliveryAttempt::where('delivery_id', $parcel->id)->count());
        $this->assertSame(1, $parcel->fresh()->failure_attempts);
        $this->getJson('/api/v1/rider/tasks/final_mile-'.$parcel->id)->assertOk()->assertJsonPath('data.stop.kind', 'destination_hub')
            ->assertJsonPath('data.failure_policy.recorded_attempts', 1);
        $this->receiveFailureFlow($parcel);
        $this->native($rider);
        $this->getJson('/api/v1/rider/tasks?phase=final_mile')->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_forged_evidence_status_counts_and_wrong_cash_never_advance_delivery(): void
    {
        Storage::fake('local');
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->native(User::findOrFail($parcel->assigned_rider_id));
        $base = ['recipient_name' => $parcel->order->recipient_name, 'recipient_relationship' => 'buyer',
            'cash_received' => (string) $parcel->order->total_amount, 'change_given' => '0.00', 'cash_confirmed' => true,
            'proof_image_file' => UploadedFile::fake()->image('proof.jpg')];
        $this->action($parcel, 'final_mile', 'deliver', $base + ['proof_path' => 'delivery-proofs/forged.jpg'])->assertUnprocessable();
        $this->action($parcel, 'final_mile', 'deliver', $base + ['status' => 'completed'])->assertUnprocessable();
        $this->action($parcel, 'final_mile', 'deliver', array_replace($base, ['cash_received' => '1.00']))->assertConflict();
        $this->action($parcel, 'final_mile', 'deliver', array_replace($base, ['proof_image_file' => UploadedFile::fake()->create('forged.jpg', 20, 'image/jpeg')]))->assertUnprocessable();
        $this->action($parcel, 'final_mile', 'fail', ['reason' => 'customer_unreachable', 'notes' => 'Tried calling at the saved address.',
            'location_name' => 'Saved address', 'proof_image_file' => UploadedFile::fake()->image('attempt.jpg'), 'failure_attempts' => 3])->assertUnprocessable();
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->assertSame(0, RiderCommand::count());
        $this->assertSame(0, CodAccount::count());
        $this->assertSame([], Storage::disk('local')->allFiles('delivery-proofs'));
    }
}
