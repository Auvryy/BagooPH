<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class HandlerManifestJourneyTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return [['/hub', false], ['http://hub.localhost', false], ['/hub', true]];
    }

    #[DataProvider('portals')]
    public function test_existing_hub_handlers_complete_real_manifest_transport_and_buyer_receipt(string $base, bool $distinctMothers): void
    {
        if ($distinctMothers) {
            $shop = $this->createE2EShop($this->createApprovedUser('seller'));
            $this->flowNetwork($shop);
            $companyId = LogisticsCompany::where('code', 'ACCEPTANCE')->sole()->id;
            LogisticsHub::create(['logistics_company_id' => $companyId, 'name' => 'Cavite Mother Hub',
                'code' => 'HAND-CAV-MH', 'tier' => 'regional_mother_hub', 'province' => 'Cavite',
                'city_municipality' => 'Dasmariñas', 'address' => '100 Cavite Hub Road', 'is_active' => true]);
            LogisticsHub::create(['logistics_company_id' => $companyId, 'name' => 'Dasmariñas Bayan Hub',
                'code' => 'HAND-CAV-BH', 'tier' => 'local_bayan_hub', 'province' => 'Cavite',
                'city_municipality' => 'Dasmariñas', 'address' => '100 Bayan Hub Road', 'is_active' => true]);
            $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [], 'ready_for_pickup',
                ['shipping_province' => 'Cavite', 'shipping_city' => 'Dasmariñas', 'shipping_postal_code' => '4114',
                    'destination_barangay' => 'Paliparan I']);
        } else {
            $order = $this->newFlowOrder();
        }
        $waybill = $order->delivery->tracking_number;
        $parcel = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->transport($parcel, $origin, $mother, $base);
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
        $this->confirmFlowScan($parcel, $mother, 'SORT_TO_LINE_HAUL');
        if ($distinctMothers) {
            $destinationMother = LogisticsHub::findOrFail($parcel->destination_mother_hub_id);
            $this->assertNotSame($mother->id, $destinationMother->id);
            $this->transport($parcel, $mother, $destinationMother, $base);
            $this->confirmFlowScan($parcel, $destinationMother, 'SORT_TO_LINE_HAUL');
            $mother = $destinationMother;
        }
        $this->transport($parcel, $mother, $destination, $base);
        $this->assertSame('arrived_at_destination_hub', $parcel->fresh()->status);
        $this->assertSame($waybill, $parcel->fresh()->tracking_number);
        $this->assertSame($distinctMothers ? 3 : 2, LogisticsManifest::where('status', 'closed')->count());
        $companyOwnerId = $origin->company->user_id;
        $this->assertSame(0, LogisticsManifestEvent::where('actor_id', $companyOwnerId)->count());
        $this->actingAs($this->flowHandler($destination))->postJson(route('hub.sort'), [
            'delivery_id' => $parcel->id, 'barangay' => $order->destination_barangay,
        ])->assertOk();
        $rider = $this->flowRider($destination);
        $rider->courierProfile->update(['assigned_barangay' => $order->destination_barangay]);
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $rider->id])->assertOk();
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), [
            'status' => 'out_for_delivery', 'barcode' => $waybill,
        ])->assertSessionHas('success');
        Storage::fake('local');
        $this->patch(route('courier.updateStatus', $parcel), [...$this->codCollectionInput($parcel->fresh()),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertSessionHas('success');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->actingAs($order->buyer)->post('http://localhost'.route('buyer.orders.confirm', $order, false))->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($waybill, $parcel->fresh()->tracking_number);
    }

    private function transport(Delivery $parcel, LogisticsHub $source, LogisticsHub $destination, string $base): void
    {
        $handler = $this->flowHandler($source);
        $receiver = $this->flowHandler($destination);
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create([
            'logistics_company_id' => $source->logistics_company_id, 'hub_id' => $source->id,
            'plate_number' => 'HND-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT),
            'vehicle_type' => $source->isMotherHub() && $destination->isMotherHub() ? 'wing_truck' : 'l300_van',
            'model' => 'Bagoo Transport Van', 'capacity_kg' => 1000,
            'assigned_driver_id' => $driver->id, 'status' => 'active',
        ]);
        $driver->courierProfile->update(['logistics_company_id' => $source->logistics_company_id,
            'assigned_hub_id' => $source->id, 'vehicle_id' => $vehicle->id, 'is_available' => true]);
        $this->actingAs($handler)->get($base.'/manifests')->assertOk();
        $creation = ['source_hub_id' => $source->id, 'destination_hub_id' => $destination->id,
            'vehicle_id' => $vehicle->id, 'creation_token' => session()->get('manifest_creation_token')];
        $response = $this->postJson($base.'/manifests', $creation)->assertCreated();
        $manifest = LogisticsManifest::findOrFail($response->json('manifest.id'));
        $this->postJson($base.'/manifests', $creation)->assertOk()->assertJsonPath('manifest.id', $manifest->id);
        $path = $base.'/manifests/'.$manifest->id;
        $count = $parcel->checkpoints()->count();
        $this->actingAs($receiver)->postJson($path.'/seal', $this->flowManifestPayload($manifest))->assertForbidden();
        $this->assertSame($count, $parcel->checkpoints()->count());
        $this->actingAs($handler)->postJson($path.'/load', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->postJson($path.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $dispatch = $this->flowManifestPayload($manifest);
        $this->postJson($path.'/dispatch', $dispatch)->assertOk();
        $checkpointCount = $parcel->checkpoints()->count();
        $this->postJson($path.'/dispatch', $dispatch)->assertOk();
        $this->assertSame($checkpointCount, $parcel->checkpoints()->count());
        $this->assertSame('manifest', DeliveryCheckpoint::lastCustody($parcel->fresh())['kind']);
        $this->postJson($path.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertForbidden();
        $this->actingAs($receiver)->postJson($path.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->postJson($path.'/close', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertSame($destination->id, $parcel->fresh()->current_hub_id);
        $this->assertSame('closed', $manifest->fresh()->status);
    }
}
