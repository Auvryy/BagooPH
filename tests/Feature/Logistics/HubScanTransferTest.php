<?php

namespace Tests\Feature\Logistics;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\HubParcelWorkstationService;
use App\Services\Logistics\HubScanTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class HubScanTransferTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return [['/hub'], ['http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_receiving_hubs_progress_without_manifests_and_keep_the_same_waybill(string $base): void
    {
        $order = $this->newFlowOrder();
        $waybill = $order->delivery->tracking_number;
        $parcel = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $this->assertSame($waybill, $parcel->tracking_number);
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        foreach ([$mother, $destination] as $hub) {
            $handler = $this->flowHandler($hub);
            $before = $parcel->fresh()->status;
            $count = $parcel->checkpoints()->count();
            $this->actingAs($handler)->postJson($base.'/scan', ['barcode' => $waybill, 'hub_id' => $hub->id, 'mode' => 'inspect'])
                ->assertOk()->assertJsonPath('confirmed', false)->assertJsonPath('prompt.requires_confirmation', true)
                ->assertJsonPath('prompt.manifest_url', null)->assertJsonPath('delivery.guidance.action', 'scan');
            $this->assertSame($count, $parcel->checkpoints()->count());
            $input = ['barcode' => $waybill, 'hub_id' => $hub->id, 'mode' => 'confirm',
                'action' => app(HubScanTransferService::class)->action($hub), 'expected_status' => $before];
            $this->postJson($base.'/scan', $input)->assertOk()->assertJsonPath('confirmed', true);
            $parcel->refresh();
            $this->assertSame($hub->id, $parcel->current_hub_id);
            $this->assertSame($waybill, $parcel->tracking_number);
            $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
            $this->assertSame($count + 1, $parcel->checkpoints()->count());
            $checkpoint = $parcel->checkpoints()->latest('id')->firstOrFail();
            $this->assertTrue(app(HubScanTransferService::class)->supportsReceipt($checkpoint,
                app(DeliveryReturnService::class)->forwardRoute($parcel), $checkpoint->source_state['current_hub_id'], $hub->id));
            $this->postJson($base.'/scan', $input)->assertConflict();
            $this->assertSame($count + 1, $parcel->checkpoints()->count());
        }
        $this->assertDatabaseCount('logistics_manifests', 0);
        $this->assertSame('arrived_at_destination_hub', $parcel->status);
        $this->assertSame('sort', app(HubParcelWorkstationService::class)->guidance(
            $parcel->fresh(), $destination, $this->flowHandler($destination), collect(), '/hub')['action']);
        $order = $parcel->order;
        $this->actingAs($this->flowHandler($destination))->postJson(route('hub.sort'), ['delivery_id' => $parcel->id,
            'barangay' => $order->destination_barangay])->assertOk();
        $rider = $this->flowRider($destination);
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $rider->id])->assertOk();
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), ['status' => 'out_for_delivery', 'barcode' => $waybill])
            ->assertSessionHas('success');
        Storage::fake('local');
        $this->patch(route('courier.updateStatus', $parcel), [...$this->codCollectionInput($parcel->fresh()),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('receipt.jpg')])->assertSessionHas('success');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->actingAs($order->buyer)->post('http://localhost'.route('buyer.orders.confirm', $order, false))->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $parcel->fresh()->status);
        $this->assertSame($waybill, $parcel->fresh()->tracking_number);
        $this->assertSame([$destination->id, $mother->id, $parcel->origin_bayan_hub_id],
            app(DeliveryReturnService::class)->evidencedForwardRoute($parcel->fresh(), PHP_INT_MAX)['hub_ids']);
    }

    public function test_destination_cannot_skip_the_required_mother_hub(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $parcel->checkpoints()->count();
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_BAYAN_HUB', 'expected_status' => $parcel->status])->assertConflict();
        $this->assertSame($before, $parcel->checkpoints()->count());
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
    }

    public function test_origin_receipt_is_required_before_a_mother_hub_can_receive(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_MOTHER_HUB', 'expected_status' => 'picked_up'])->assertConflict();
        $this->assertSame('picked_up', $parcel->fresh()->status);
    }

    public function test_an_unassigned_account_cannot_receive_at_a_hub(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $outsider = $this->createApprovedUser('logistics');
        $this->actingAs($outsider)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
            'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_MOTHER_HUB', 'expected_status' => $parcel->status])->assertForbidden();
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
    }

    public function test_both_required_mother_hubs_must_receive_in_order(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $company = LogisticsCompany::where('code', 'ACCEPTANCE')->sole();
        $second = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Cavite Mother Hub',
            'code' => 'DIRECT-CAV-MH', 'tier' => 'regional_mother_hub', 'province' => 'Cavite',
            'city_municipality' => 'Dasmariñas', 'address' => '100 Cavite Hub Road', 'is_active' => true]);
        $destination = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Dasmariñas Bayan Hub',
            'code' => 'DIRECT-CAV-BH', 'tier' => 'local_bayan_hub', 'province' => 'Cavite',
            'city_municipality' => 'Dasmariñas', 'address' => '100 Bayan Hub Road', 'is_active' => true]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'ready_for_pickup',
            ['shipping_province' => 'Cavite', 'shipping_city' => 'Dasmariñas', 'shipping_postal_code' => '4114', 'destination_barangay' => 'Paliparan I']);
        $parcel = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $this->assertSame($second->id, $parcel->destination_mother_hub_id);
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        foreach ([$mother, $second, $destination] as $hub) {
            $handler = $this->flowHandler($hub);
            $this->actingAs($handler)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
                'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => app(HubScanTransferService::class)->action($hub),
                'expected_status' => $parcel->fresh()->status])->assertOk()->assertJsonPath('confirmed', true);
            $this->assertSame($hub->id, $parcel->fresh()->current_hub_id);
        }
        $this->assertDatabaseCount('logistics_manifests', 0);
        $this->assertSame([$destination->id, $second->id, $mother->id, $parcel->origin_bayan_hub_id],
            app(DeliveryReturnService::class)->evidencedForwardRoute($parcel->fresh(), PHP_INT_MAX)['hub_ids']);
    }

    public function test_missing_source_evidence_cannot_be_replaced_by_a_status_or_assignment(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $parcel->update(['assigned_rider_id' => $this->flowRider(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id))->id]);
        $count = $parcel->checkpoints()->count();
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_MOTHER_HUB', 'expected_status' => $parcel->status])->assertConflict();
        $this->assertSame($count, $parcel->checkpoints()->count());
    }
}
