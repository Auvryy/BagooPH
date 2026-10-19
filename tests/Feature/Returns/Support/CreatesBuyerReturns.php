<?php

namespace Tests\Feature\Returns\Support;

use App\Models\BuyerReturnCase;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\User;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

trait CreatesBuyerReturns
{
    private function approvedReturn(?Order $original = null, ?User $finalRider = null): BuyerReturnCase
    {
        $order = $this->flowDelivery($original ?? $this->newFlowOrder('ready_for_pickup'), 'delivered', $finalRider)->order->fresh(['buyer', 'items']);
        $service = app(BuyerReturnService::class);
        $case = $service->request($order->buyer, $order, ['request_token' => $service->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The delivered item has a broken fastening.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('damage.png')]);
        $seller = $order->shop->user;
        $service->command($seller, $case, 'approve', ['request_token' => (string) Str::uuid(), 'expected_version' => 1,
            'reason' => 'The original item evidence supports an actual return for review.']);

        return $case->fresh(['parcel', 'order.delivery', 'order.buyer']);
    }

    private function custodyPayload(BuyerReturnCase $case, string $action, array $extra = []): array
    {
        return ['action' => $action, 'expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'barcode' => $case->parcel->barcode, 'reason' => 'The named account checked the actual return parcel and its recorded contents.', ...$extra];
    }

    private function intake(BuyerReturnCase $case): void
    {
        $hub = LogisticsHub::findOrFail($case->parcel->hub_ids[0]);
        $payload = $this->custodyPayload($case, 'intake', ['hub_id' => $hub->id, 'weight_kg' => '2.500', 'condition' => 'intact', 'photo' => UploadedFile::fake()->image('intake.png')]);
        $this->actingAs($this->flowHandler($hub))->post('/buyer-returns/'.$case->id.'/custody', $payload)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/custody', $payload)->assertSessionHasNoErrors();
        $this->assertSame('at_destination_hub', $case->fresh()->status);
        $this->assertSame(2500, $case->parcel->fresh()->weight_grams);
        $this->assertSame(1, $case->events()->where('event_type', 'return-intake')->count());
    }

    private function prepareLeg(BuyerReturnCase $case, float $capacity = 1000): LogisticsManifest
    {
        $parcel = $case->parcel->fresh();
        $source = LogisticsHub::findOrFail($parcel->hub_ids[$parcel->route_position]);
        $destination = LogisticsHub::findOrFail($parcel->hub_ids[$parcel->route_position + 1]);
        $company = LogisticsCompany::findOrFail($parcel->logistics_company_id);
        $manager = $company->user;
        $driver = $this->createApprovedUser('courier');
        $fleet = LogisticsFleet::create(['logistics_company_id' => $company->id, 'hub_id' => $source->id,
            'plate_number' => 'BRT-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT),
            'vehicle_type' => $source->isMotherHub() && $destination->isMotherHub() ? 'wing_truck' : 'l300_van',
            'model' => 'Bagoo Return Transport', 'capacity_kg' => $capacity, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $company->id, 'assigned_hub_id' => $source->id, 'vehicle_id' => $fleet->id, 'is_available' => true]);
        $this->actingAs($manager)->get('/hub/manifests')->assertOk();
        $payload = ['source_hub_id' => $source->id, 'destination_hub_id' => $destination->id, 'vehicle_id' => $fleet->id,
            'direction' => 'buyer_return', 'buyer_return_case_id' => $case->id, 'creation_token' => session()->get('manifest_creation_token')];
        $response = $this->postJson('/hub/manifests', $payload)->assertCreated();
        $manifest = LogisticsManifest::findOrFail($response->json('manifest.id'));
        $this->postJson('/hub/manifests', $payload)->assertOk();
        $this->assertSame('buyer_return', $manifest->direction);

        return $manifest;
    }

    private function leg(BuyerReturnCase $case, bool $close = true): LogisticsManifest
    {
        $manifest = $this->prepareLeg($case);
        $parcel = $case->parcel->fresh();
        $source = LogisticsHub::findOrFail($manifest->source_hub_id);
        $destination = LogisticsHub::findOrFail($manifest->destination_hub_id);
        $manager = LogisticsCompany::findOrFail($manifest->logistics_company_id)->user;
        $this->actingAs($this->flowHandler($source))->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $parcel->barcode]))->assertOk();
        $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->actingAs($this->flowHandler($source))->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertSame('in_transit', $parcel->fresh()->status);
        $receipt = $this->flowManifestPayload($manifest, ['barcode' => $parcel->barcode, 'condition' => 'intact']);
        $this->actingAs($this->flowHandler($destination))->postJson('/hub/manifests/'.$manifest->id.'/receive', $receipt)->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/receive', $receipt)->assertOk();
        $this->assertSame($destination->id, $parcel->fresh()->current_hub_id);
        $this->assertSame('at_hub', $parcel->fresh()->status);
        if ($close) {
            $this->actingAs($manager)->postJson('/hub/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
        }

        return $manifest->fresh();
    }
}
