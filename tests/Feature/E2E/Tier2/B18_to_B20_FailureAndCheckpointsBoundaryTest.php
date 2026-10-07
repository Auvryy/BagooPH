<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B18_to_B20_FailureAndCheckpointsBoundaryTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    // ==========================================
    // Boundary 18: Delivery Failure Min-Length & Reason Codes
    // ==========================================

    public function test_t2_b18_01_empty_failure_reason_rejected(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), $this->flowFailurePayload($delivery, ''), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('failure_reason');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b18_02_under_5_chars_reason_rejected(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), $this->flowFailurePayload($delivery, 'bad'), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('failure_reason');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b18_03_invalid_failure_code_rejected(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), $this->flowFailurePayload($delivery, 'UNKNOWN_CODE'), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('failure_reason');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b18_04_reporting_failure_on_non_active_delivery_barred(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), $this->flowFailurePayload($delivery), ['Accept' => 'application/json'])
            ->assertSessionHas('error');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b18_05_non_assigned_courier_reporting_failure_barred(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $other = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($other)->patch(route('courier.updateStatus', $delivery), $this->flowFailurePayload($delivery))->assertSessionHas('error');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    // ==========================================
    // Boundary 19: Return Cycle Inventory Leakage
    // ==========================================

    public function test_t2_b19_01_inventory_double_restoration_guard(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $stock = $order->items->first()->product->fresh()->stock;
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $order->items->first()->product->fresh()->stock);
        // Reviewed reverse-route actions and seller receipt are still required; never write a return directly.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
        $this->assertSame('returned', $order->fresh()->status);
        $quantity = $order->items->sum('quantity');
        $this->assertSame($stock + $quantity, $order->items->first()->product->fresh()->stock);
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'parcel_returned')->count());
    }

    public function test_t2_b19_02_outbound_inspection_does_not_authorize_return(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertConflict();
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b19_03_uncollected_return_keeps_accounting_pending(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $stock = $order->items->first()->product->fresh()->stock;
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $order->items->first()->product->fresh()->stock);
        // Reviewed reverse-route actions and seller receipt are still required; never write a return directly.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertNull($order->fresh()->commissionLedger);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_t2_b19_04_buyer_cannot_scan_return_custody(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($order->buyer)->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertForbidden();
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b19_05_return_status_immutability(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $stock = $order->items->first()->product->fresh()->stock;
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $order->items->first()->product->fresh()->stock);
        // Reviewed reverse-route actions and seller receipt are still required; never write a return directly.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
        $this->assertSame('returned', $order->fresh()->status);
        $before = [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('error');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    // ==========================================
    // Boundary 20: Checkpoint Audit Immutability
    // ==========================================

    public function test_t2_b20_01_updating_existing_checkpoint_barred(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $checkpoint = $delivery->checkpoints()->where('checkpoint_type', 'courier_pickup')->sole();
        $before = $checkpoint->getRawOriginal();
        try {
            $checkpoint->update(['notes' => 'Changed custody evidence']);
        } catch (\LogicException|QueryException $exception) {
            // A persistence guard may reject the attempted historical mutation.
        }
        $this->assertNotNull($checkpoint->fresh(), 'Custody history must not be deleted.');
        $this->assertSame($before, $checkpoint->fresh()->getRawOriginal(), 'Custody evidence must remain unchanged.');
    }

    public function test_t2_b20_02_deleting_checkpoint_record_barred(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $checkpoint = $delivery->checkpoints()->where('checkpoint_type', 'courier_pickup')->sole();
        $before = $checkpoint->getRawOriginal();
        try {
            $checkpoint->delete();
        } catch (\LogicException|QueryException $exception) {
            // A persistence guard may reject the attempted historical mutation.
        }
        $this->assertNotNull($checkpoint->fresh(), 'Custody history must not be deleted.');
        $this->assertSame($before, $checkpoint->fresh()->getRawOriginal(), 'Custody evidence must remain unchanged.');
    }

    public function test_t2_b20_03_out_of_order_checkpoint_validation(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $hub = LogisticsHub::findOrFail($delivery->origin_mother_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_AT_MOTHER_HUB', 'expected_status' => 'picked_up',
        ])->assertConflict();
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b20_04_empty_barcode_scan_logging_guard(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), ['barcode' => '', 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
    }

    public function test_t2_b20_05_tampering_with_created_at(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $checkpoint = $delivery->checkpoints()->where('checkpoint_type', 'courier_pickup')->sole();
        $before = $checkpoint->getRawOriginal();
        try {
            DB::table('delivery_checkpoints')->where('id', $checkpoint->id)->update(['created_at' => now()->subDay()]);
        } catch (\LogicException|QueryException $exception) {
            // A persistence guard may reject the attempted historical mutation.
        }
        $this->assertNotNull($checkpoint->fresh(), 'Custody history must not be deleted.');
        $this->assertSame($before, $checkpoint->fresh()->getRawOriginal(), 'Custody evidence must remain unchanged.');
    }
}
