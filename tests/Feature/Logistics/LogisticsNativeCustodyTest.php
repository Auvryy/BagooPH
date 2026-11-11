<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\MotherBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class LogisticsNativeCustodyTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_native_operator_scans_use_real_inspection_and_cannot_skip_custody(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $handler->update(['password' => 'Scanner-Test!4832']);
        $token = $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $handler->email,
            'password' => 'Scanner-Test!4832', 'device_name' => 'Test custody scanner'])->assertOk()->json('data.token');
        $before = $parcel->checkpoints()->count();
        $input = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id];
        $preview = $this->withToken($token)->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'inspect'])->assertOk();
        $preview->assertJsonPath('success', true)->assertJsonPath('prompt.requires_confirmation', true);
        $this->assertSame('picked_up', $parcel->fresh()->status);
        $this->assertSame($before, $parcel->checkpoints()->count());
        $this->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'confirm',
            'action' => $preview->json('prompt.action'), 'expected_status' => 'arrived_at_mother_hub'])->assertConflict();
        $confirm = [...$input, 'mode' => 'confirm', 'action' => $preview->json('prompt.action'),
            'expected_status' => $preview->json('prompt.expected_status')];
        $this->postJson('/api/v1/logistics/scan', $confirm)->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('prompt.manifest_url', '/hub/manifests?hub_id='.$hub->id);
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $after = $parcel->checkpoints()->count();
        $this->assertGreaterThan($before, $after);
        $this->postJson('/api/v1/logistics/scan', $confirm)->assertOk();
        $this->assertSame($after, $parcel->checkpoints()->count());
        $this->postJson('/api/v1/logistics/scan', [...$input, 'barcode' => 'bag-test-receipt', 'mode' => 'confirm',
            'action' => 'RECEIVE_AT_ORIGIN_HUB', 'expected_status' => 'picked_up'])->assertConflict();
        $this->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'confirm', 'action' => 'AUTO_PROGRESS'])
            ->assertUnprocessable();
    }

    public function test_native_bag_identification_links_to_the_real_hub_portal_without_receiving_members(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $bag = MotherBag::where('manifest_id', $custody['manifest_id'])->sole();
        $hub = $bag->destination;
        $handler = $this->flowHandler($hub);
        $handler->update(['password' => 'Scanner-Test!4832']);
        $token = $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $handler->email, 'password' => 'Scanner-Test!4832',
            'device_name' => 'Test bag identification'])->assertOk()->json('data.token');
        $before = [$bag->getAttributes(), $parcel->fresh()->getAttributes(), $parcel->checkpoints()->count()];
        $input = ['barcode' => $bag->reference, 'hub_id' => $hub->id];
        $this->withToken($token)->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'inspect'])->assertOk()
            ->assertJsonPath('confirmed', false)->assertJsonPath('prompt.requires_confirmation', false)
            ->assertJsonPath('bag.receiving_url', '/hub/mother-bags?hub_id='.$hub->id.'&bag='.$bag->id)
            ->assertJsonPath('bag.manifest_url', '/hub/manifests?hub_id='.$hub->id);
        $this->postJson('/api/v1/logistics/scan', [...$input, 'mode' => 'confirm', 'action' => 'INGEST_MASTER_BAG', 'expected_status' => 'in_transit'])->assertConflict();
        $this->assertSame($before, [$bag->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $parcel->checkpoints()->count()]);
    }
}
