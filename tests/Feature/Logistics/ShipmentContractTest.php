<?php

namespace Tests\Feature\Logistics;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\CodAccount;
use App\Models\CodCashEvent;
use App\Models\LogisticsHub;
use App\Services\Logistics\LogisticsRoutingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentContractTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_native_return_identification_cannot_transfer_forward_custody_or_change_money(): void
    {
        $case = $this->approvedReturn();
        $before = $this->shipmentState($case);
        $hub = LogisticsHub::findOrFail($case->parcel->hub_ids[0]);
        $handler = $this->flowHandler($hub);
        $handler->update(['password' => 'Scanner-Test!4832']);
        $token = $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $handler->email,
            'password' => 'Scanner-Test!4832', 'device_name' => 'Shipment contract scanner'])
            ->assertOk()->json('data.token');
        $input = ['barcode' => $case->parcel->barcode, 'hub_id' => $hub->id];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($token)->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'inspect'])
                ->assertOk()->assertJsonPath('confirmed', false)
                ->assertJsonPath('return_case.reference', $case->reference)
                ->assertJsonPath('return_case.barcode', $case->parcel->barcode)
                ->assertJsonMissingPath('delivery')->assertJsonMissingPath('prompt');
        }
        $this->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up'])->assertConflict();

        $this->assertNotSame($case->delivery->tracking_number, $case->parcel->barcode);
        $this->assertSame($before, $this->shipmentState($case));
    }

    public function test_copied_return_code_does_not_grant_public_tracking_or_another_buyers_private_access(): void
    {
        $case = $this->approvedReturn();
        $before = $this->shipmentState($case);
        $this->actingAs($this->createApprovedUser('buyer'));
        $this->get('/buyer-returns/'.$case->id)->assertNotFound();
        foreach (['/track/', '/api/track/'] as $path) {
            $this->getJson($path.$case->parcel->barcode)->assertNotFound();
            $this->getJson($path.$case->delivery->tracking_number)->assertOk()
                ->assertJsonPath('parcel.status', 'completed')
                ->assertJsonMissingPath('parcel.return_case')
                ->assertJsonMissingPath('parcel.shipping_address')
                ->assertJsonMissingPath('parcel.custody');
        }
        $this->assertSame($before, $this->shipmentState($case));
    }

    public function test_historical_loop_exception_does_not_enable_v2_or_already_received_backhaul(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'), ['city' => 'Santa Cruz']);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = $parcel->destinationBayanHub;
        $mother = LogisticsHub::where('logistics_company_id', $parcel->logistics_company_id)
            ->where('tier', 'regional_mother_hub')->firstOrFail();
        $engine = app(LogisticsRoutingEngine::class);
        $this->assertSame(2, $order->logistics_flow_version);
        $this->assertTrue($parcel->same_hub_bypass);
        $before = $parcel->getAttributes();

        // Retained legacy-style parents alone must not opt a v2 purchase into that contract.
        $parcel->origin_mother_hub_id = $mother->id;
        $parcel->destination_mother_hub_id = $mother->id;

        $this->assertNotNull($engine->validateBackhaul($parcel, $mother, $hub));
        // Even an old client submitting an origin-intake stage cannot opt a v2 purchase into the loop.
        $parcel->status = 'arrived_at_origin_hub';
        $this->assertNotNull($engine->validateBackhaul($parcel, $mother, $hub));
        $order->logistics_flow_version = 1;
        $parcel->setRelation('order', $order);
        $this->assertNull($engine->validateBackhaul($parcel, $mother, $hub));
        $parcel->status = 'arrived_at_destination_hub';
        $this->assertNotNull($engine->validateBackhaul($parcel, $mother, $hub));
        $this->assertSame($before, $parcel->fresh()->getAttributes());
    }

    private function shipmentState(BuyerReturnCase $case): array
    {
        return [
            $case->fresh()->getAttributes(), $case->parcel->fresh()->getAttributes(),
            $case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes(),
            $case->delivery->checkpoints()->orderBy('id')->get()->map->getAttributes()->all(),
            $case->events()->get()->map->getAttributes()->all(),
            CodAccount::orderBy('id')->get()->map->getAttributes()->all(),
            CodCashEvent::orderBy('id')->get()->map->getAttributes()->all(),
            BuyerReturnMoneyEvent::orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }
}
