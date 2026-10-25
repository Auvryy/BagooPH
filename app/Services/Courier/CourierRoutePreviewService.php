<?php

namespace App\Services\Courier;

use App\Models\DeliveryCheckpoint;
use App\Models\User;
use App\Services\Logistics\HandoverHoldService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CourierRoutePreviewService
{
    public function preview(User $actor, string $task, array $input): array
    {
        [$parcel, $phase] = app(RiderTaskService::class)->task($actor, $task);
        abort_if(app(HandoverHoldService::class)->held($parcel), 409, 'Review the handover hold before requesting ordinary directions.');
        abort_unless($parcel->status === $input['expected_status'], 409, 'The parcel changed. Refresh its current stop.');
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $carrying = ($custody['kind'] ?? null) === 'courier' && ($custody['user_id'] ?? null) === $actor->id;
        if ($phase === 'pickup') {
            abort_unless($carrying && $parcel->status === 'picked_up', 422, 'The seller has no saved map coordinates. Use its address for directions.');
            $hub = $parcel->originBayanHub;
            $destination = [$hub?->latitude, $hub?->longitude];
        } elseif (in_array($parcel->status, ['assigned_to_rider', 'delivery_failed'], true)) {
            abort_if($parcel->status === 'delivery_failed' && ! $carrying, 409, 'The current parcel holder needs review.');
            $hub = $parcel->destinationBayanHub;
            $destination = [$hub?->latitude, $hub?->longitude];
        } else {
            abort_unless($carrying && $parcel->status === 'out_for_delivery', 409, 'The current parcel holder needs review.');
            $destination = [$parcel->order->destination_latitude, $parcel->order->destination_longitude];
        }
        abort_unless($this->point($destination), 422, 'This stop has no saved coordinates. Use its address for directions.');
        $mode = match (strtolower(trim((string) $actor->courierProfile?->vehicle_type))) {
            'motorcycle' => 'motorcycle', 'scooter' => 'scooter',
            'sedan', 'van', 'closed van', 'sedan / van', 'l300_van' => 'drive',
            default => null,
        };
        abort_unless($mode, 422, 'Your assigned vehicle needs a supported navigation profile.');
        $version = app(RiderTaskService::class)->version($parcel);
        $key = config('services.geoapify.key');
        abort_unless(is_string($key) && $key !== '', 503, 'Road previews are not configured. The stop address and external directions remain available.');
        try {
            $response = Http::acceptJson()->timeout(10)->connectTimeout(4)->withoutRedirecting()->get('https://api.geoapify.com/v1/routing', [
                'apiKey' => $key, 'waypoints' => $input['latitude'].','.$input['longitude'].'|'.implode(',', $destination),
                'mode' => $mode, 'format' => 'geojson', 'avoid' => 'ferries', 'details' => 'route_details',
            ]);
            abort_unless($response->successful() && strlen($response->body()) <= 1000000, 502, 'Road routing is unavailable. Use the stop address or retry.');
            $body = $response->json();
        } catch (HttpException $error) {
            throw $error;
        } catch (Throwable) {
            abort(502, 'Road routing is unavailable. Use the stop address or retry.');
        }
        $geometry = data_get($body, 'features.0.geometry');
        abort_unless(is_array($geometry) && ! preg_match('/"ferry"\s*:\s*true/', json_encode($body)), 502, 'No supported road-only route was returned.');
        $lines = match ($geometry['type'] ?? null) {
            'LineString' => [$geometry['coordinates'] ?? []],
            'MultiLineString' => $geometry['coordinates'] ?? [],
            default => [],
        };
        $count = 0;
        abort_unless(is_array($lines) && array_is_list($lines) && count($lines) >= 1 && count($lines) <= 100, 502, 'The road geometry could not be verified.');
        $points = [];
        foreach ($lines as $line) {
            abort_unless(is_array($line) && array_is_list($line) && count($line) >= 2, 502, 'The road geometry could not be verified.');
            $converted = [];
            foreach ($line as $point) {
                abort_unless(is_array($point) && array_is_list($point) && count($point) >= 2 && $this->point([$point[1], $point[0]]) && ++$count <= 10000, 502, 'The road geometry could not be verified.');
                $converted[] = [(float) $point[1], (float) $point[0]];
            }
            $points[] = $converted;
        }
        abort_unless($this->near($points[0][0], [(float) $input['latitude'], (float) $input['longitude']]) && $this->near($points[array_key_last($points)][array_key_last($points[array_key_last($points)])], $destination), 502, 'The road route does not match the requested stops.');
        // A stale dispatch must not leave a response pointing at a former stop.
        [$current] = app(RiderTaskService::class)->task($actor->fresh('courierProfile'), $task);
        abort_unless(app(RiderTaskService::class)->version($current) === $version, 409, 'The parcel changed while calculating directions. Refresh its current stop.');

        return ['task' => $task, 'waybill' => $parcel->tracking_number, 'mode' => $mode, 'lines' => $points,
            'destination' => array_map('floatval', $destination), 'observed_at' => now()->toIso8601String(), 'live_tracking' => false];
    }

    private function near(array $a, array $b): bool
    {
        return abs($a[0] - $b[0]) <= 0.01 && abs($a[1] - $b[1]) <= 0.01;
    }

    private function point(array $point): bool
    {
        return count($point) === 2 && is_numeric($point[0]) && is_numeric($point[1])
            && is_finite((float) $point[0]) && is_finite((float) $point[1])
            && $point[0] >= 4 && $point[0] <= 22 && $point[1] >= 116 && $point[1] <= 128;
    }
}
