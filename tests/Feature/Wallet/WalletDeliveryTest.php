<?php

namespace Tests\Feature\Wallet;

use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Services\Courier\RiderTaskService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Orders\OrderTrackingService;
use App\Services\RiderAccountService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class WalletDeliveryTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function paidOrder(bool $pickup = false): Order
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $shipping = ['payment_method' => 'wallet'];
        if ($pickup) {
            $hub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', 'Santa Cruz')->sole();
            $hub->update(['allows_self_pickup' => true]);
            $this->actingAs($hub->company->user)->postJson('/hub/counter/hours', ['hub_id' => $hub->id, 'operating_hours' => 'Monday to Saturday, 08:00 to 17:00'])->assertOk();
            $shipping += ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id];
        }
        $order = $this->checkoutFlowOrder($buyer, $shop, shipping: $shipping);
        $credits = app(WalletCreditService::class);
        $request = $credits->request($buyer, ['amount' => '10000.00', 'reason' => 'Demo credits for an actual recorded delivery.', 'request_token' => (string) Str::uuid()]);
        $credits->review($this->createApprovedUser('admin'), $request, ['decision' => 'approved', 'reason' => 'Approved demo credits for a delivery acceptance check.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        $this->actingAs($buyer)->postJson('/wallet/payments/'.$order->walletPayment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertOk()->assertJsonPath('outcome', 'paid');

        return $order->fresh(['buyer', 'items', 'delivery']);
    }

    public function test_wallet_delivery_retains_every_hub_and_buyer_receipt_without_cod_collection(): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'delivered');
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertTrue($parcel->checkpoints()->where('checkpoint_type', 'arrived_at_mother_hub')->exists());
        $this->assertSame('delivered', $order->fresh()->status);
        $this->actingAs($order->buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_prepaid_delivery_replay_retains_the_original_recipient_and_rejects_a_different_one(): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'delivered');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $payload = ['status' => 'delivered', ...$this->codCollectionInput($parcel)];
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('success');
        $this->patch(route('courier.updateStatus', $parcel), [...$payload, 'recipient_name' => 'Another Recipient'])->assertConflict();
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'delivered')->count());
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
        $this->assertDatabaseCount('cod_accounts', 0);
    }

    public function test_a_paid_wallet_parcel_rejects_cash_collection_instead_of_recording_fake_cash(): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->actingAs(User::findOrFail($parcel->assigned_rider_id))->patch(route('courier.updateStatus', $parcel), [
            'status' => 'delivered', ...$this->codCollectionInput($parcel, ['cash_received' => (string) $order->total_amount, 'cash_confirmed' => true]),
            'proof_image_file' => UploadedFile::fake()->image('wallet-handover.png'),
        ])->assertUnprocessable();
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_handover_receipts', 0);
    }

    public function test_native_wallet_handover_uses_the_same_paid_source_and_needs_no_cash_fields(): void
    {
        Storage::fake('local');
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->assertStringContainsString('no cash is due', app(OrderTrackingService::class)->present($order->fresh())['next_step']);
        $this->withToken(app(RiderAccountService::class)->session($rider, 'Wallet handover test')['token']);
        $this->getJson('/api/v1/rider/tasks/final_mile-'.$parcel->id)->assertOk()->assertJsonPath('data.actions.deliver', true)
            ->assertJsonPath('data.payment.method', 'wallet')->assertJsonPath('data.payment.cod_due_cents', null);
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $this->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/deliver', [
            'expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])), 'barcode' => $parcel->tracking_number,
            'recipient_name' => $order->recipient_name, 'recipient_relationship' => 'buyer', 'proof_image_file' => UploadedFile::fake()->image('wallet-proof.png'),
        ])->assertOk()->assertJsonPath('data.result.operational_stage', 'delivered');
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_wallet_counter_collection_keeps_identity_code_and_receipt_without_a_cash_receipt(): void
    {
        $order = $this->paidOrder(true);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'STAGE_FOR_PICKUP', 'expected_status' => 'arrived_at_destination_hub'])->assertOk();
        $this->assertStringContainsString('requires no cash', app(OrderTrackingService::class)->present($order->fresh())['next_step']);
        $code = $this->actingAs($order->buyer)->postJson('/buyer/orders/'.$order->id.'/pickup-code')->assertOk()->json('code');
        $payload = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'claim_code' => $code,
            'buyer_id' => $order->buyer_id, 'recipient_name' => $order->buyer->name, 'identity_confirmed' => true,
            'request_token' => (string) Str::uuid(), 'cash_received' => '0', 'change_given' => '0', 'cash_confirmed' => false];
        $this->actingAs($handler)->postJson('/hub/release', $payload)->assertOk();
        $this->assertTrue(app(PickupClaimService::class)->hasCollectionEvidence($parcel->fresh(['order'])));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->actingAs($order->buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
    }
}
