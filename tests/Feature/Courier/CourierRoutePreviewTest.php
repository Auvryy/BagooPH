<?php

namespace Tests\Feature\Courier;

use App\Models\DeliveryCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class CourierRoutePreviewTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function fixture(): array
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = $parcel->originBayanHub;
        $hub->update(['latitude' => 14.17, 'longitude' => 121.24]);
        config(['services.geoapify.key' => 'test-provider-key']);
        Http::preventStrayRequests();

        return [$parcel, ['latitude' => 14.16, 'longitude' => 121.23, 'accuracy' => 10, 'recorded_at' => now()->toIso8601String(), 'expected_status' => 'picked_up']];
    }

    private function geometry(array $extra = []): array
    {
        return ['features' => [['geometry' => ['type' => 'LineString', 'coordinates' => [[121.23, 14.16], [121.235, 14.165], [121.24, 14.17]]], 'properties' => $extra]]];
    }

    public function test_route_preview_uses_authorized_hub_and_returns_only_verified_geometry_without_mutation(): void
    {
        [$parcel, $input] = $this->fixture();
        Http::fake(['api.geoapify.com/*' => Http::response($this->geometry())]);
        $count = DeliveryCheckpoint::count();
        foreach (['/courier', 'http://courier.localhost'] as $base) {
            $this->actingAs($parcel->courier)->postJson($base.'/route-preview/pickup-'.$parcel->id, $input)->assertOk()
                ->assertJsonPath('waybill', $parcel->tracking_number)->assertJsonPath('lines.0.1', [14.165, 121.235])
                ->assertJsonPath('destination', [14.17, 121.24])->assertHeader('Cache-Control', 'no-store, private')
                ->assertDontSee('test-provider-key');
        }
        Http::assertSent(fn ($request) => $request['waypoints'] === '14.16,121.23|14.1700000,121.2400000'
            || str_ends_with($request['waypoints'], '|14.17,121.24'));
        $this->assertSame($count, DeliveryCheckpoint::count());
        $this->assertSame('picked_up', $parcel->fresh()->status);
    }

    public function test_final_mile_uses_checkout_destination_and_cannot_route_a_foreign_assignment(): void
    {
        $order = $this->newFlowOrder(shipping: ['shipping_latitude' => 14.2789, 'shipping_longitude' => 121.4172]);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $input = ['latitude' => 14.16, 'longitude' => 121.23, 'accuracy' => 10,
            'recorded_at' => now()->toIso8601String(), 'expected_status' => 'out_for_delivery'];
        config(['services.geoapify.key' => 'test-provider-key']);
        Http::preventStrayRequests();
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => [['geometry' => [
            'type' => 'LineString', 'coordinates' => [[121.23, 14.16], [121.3, 14.2], [121.4172, 14.2789]],
        ]]]])]);
        $rider = $parcel->assignedRider;
        $rider->update(['address' => 'Different current profile address']);
        $this->actingAs($rider)->postJson('/courier/route-preview/final_mile-'.$parcel->id, $input)->assertOk()
            ->assertJsonPath('destination', [14.2789, 121.4172]);
        $this->actingAs($parcel->courier)->postJson('/courier/route-preview/final_mile-'.$parcel->id, $input)->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_foreign_rider_cannot_request_a_parcel_route_or_send_a_destination(): void
    {
        [$parcel, $input] = $this->fixture();
        $this->actingAs($this->createApprovedUser('courier'))->postJson('/courier/route-preview/pickup-'.$parcel->id, $input)->assertNotFound();
        $this->actingAs($parcel->courier)->postJson('/courier/route-preview/pickup-'.$parcel->id, $input + ['destination' => [14, 121]])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_inaccurate_or_stale_location_and_changed_status_do_not_contact_provider(): void
    {
        [$parcel, $input] = $this->fixture();
        $this->actingAs($parcel->courier);
        foreach ([['accuracy' => 51], ['recorded_at' => now()->subMinutes(2)->toIso8601String()], ['latitude' => 0]] as $changes) {
            $this->postJson('/courier/route-preview/pickup-'.$parcel->id, array_replace($input, $changes))->assertUnprocessable();
        }
        $this->postJson('/courier/route-preview/pickup-'.$parcel->id, array_replace($input, ['expected_status' => 'assigned_pickup']))->assertConflict();
        Http::assertNothingSent();
    }

    public function test_missing_configuration_has_an_honest_failure_without_provider_or_custody_write(): void
    {
        [$parcel, $input] = $this->fixture();
        config(['services.geoapify.key' => null]);
        $this->actingAs($parcel->courier)->postJson('/courier/route-preview/pickup-'.$parcel->id, $input)->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_ferry_or_unrelated_geometry_cannot_be_shown_as_a_valid_road_route(): void
    {
        [$parcel, $input] = $this->fixture();
        $this->actingAs($parcel->courier);
        Http::fake(['api.geoapify.com/*' => Http::response($this->geometry(['ferry' => true]))]);
        $this->postJson('/courier/route-preview/pickup-'.$parcel->id, $input)->assertStatus(502);
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => [['geometry' => ['type' => 'LineString', 'coordinates' => [[121.23, 14.16], [123, 16]]]]]])]);
        $this->postJson('/courier/route-preview/pickup-'.$parcel->id, $input)->assertStatus(502);
    }

    public function test_malformed_coordinate_objects_fail_without_a_server_error(): void
    {
        [$parcel, $input] = $this->fixture();
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => [['geometry' => [
            'type' => 'LineString', 'coordinates' => [['longitude' => 121.23, 'latitude' => 14.16], [121.24, 14.17]],
        ]]]])]);
        $this->actingAs($parcel->courier)->postJson('/courier/route-preview/pickup-'.$parcel->id, $input)->assertStatus(502);
    }

    public function test_assignment_is_rechecked_after_provider_response(): void
    {
        [$parcel, $input] = $this->fixture();
        Http::fake(function () use ($parcel) {
            $parcel->update(['status' => 'arrived_at_origin_hub']);

            return Http::response($this->geometry());
        });
        $this->actingAs($parcel->courier)->postJson('/courier/route-preview/pickup-'.$parcel->id, $input)->assertNotFound();
    }
}
