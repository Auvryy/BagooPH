<?php

namespace App\Services\Logistics;

use App\Models\Address;
use App\Models\Delivery;

class ProofOfDeliveryValidator
{
    /**
     * Maximum permissible distance in meters between rider POD submission and customer pin.
     */
    public const GEOFENCE_TOLERANCE_METERS = 100.0;

    /**
     * Calculate great-circle distance between two geographic coordinates using the Haversine formula.
     *
     * @return float Distance in meters
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0; // Earth's radius in meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Validate Proof of Delivery telemetry against destination coordinates.
     */
    public function validatePod(
        Delivery $delivery,
        ?float $riderLat,
        ?float $riderLong,
        ?string $photoPath = null
    ): array {
        $warnings = [];
        $geofencePassed = false;
        $distanceMeters = null;

        // 1. Resolve destination pin coordinates
        $order = $delivery->order;
        $destAddress = Address::where('user_id', $order->buyer_id)
            ->where('is_default', true)
            ->first();

        $destLat = $destAddress?->latitude;
        $destLong = $destAddress?->longitude;

        // 2. Geofence evaluation
        if ($riderLat !== null && $riderLong !== null && $destLat !== null && $destLong !== null) {
            $distanceMeters = $this->calculateDistance($riderLat, $riderLong, $destLat, $destLong);

            if ($distanceMeters <= self::GEOFENCE_TOLERANCE_METERS) {
                $geofencePassed = true;
            } else {
                $warnings[] = "Rider coordinates are {$distanceMeters}m away from the buyer's pinned address (tolerance: " . self::GEOFENCE_TOLERANCE_METERS . "m).";
            }
        } elseif ($destLat === null || $destLong === null) {
            // Customer did not drop an interactive pin; allow with cautionary notice
            $geofencePassed = true;
            $warnings[] = 'Customer address lacks exact map pin coordinates; geofence check bypassed with landmark fallback.';
        } else {
            $warnings[] = 'Rider mobile terminal did not transmit GPS telemetry at the time of delivery.';
        }

        // 3. Photo validation
        $hasPhoto = !empty($photoPath);
        if (!$hasPhoto) {
            $warnings[] = 'Doorstep Proof of Delivery photo is missing.';
        }

        $isValid = $hasPhoto && ($geofencePassed || count($warnings) === 1);

        return [
            'is_valid' => $isValid,
            'geofence_passed' => $geofencePassed,
            'distance_meters' => $distanceMeters,
            'tolerance_meters' => self::GEOFENCE_TOLERANCE_METERS,
            'rider_coordinates' => ($riderLat && $riderLong) ? ['lat' => $riderLat, 'long' => $riderLong] : null,
            'destination_coordinates' => ($destLat && $destLong) ? ['lat' => $destLat, 'long' => $destLong] : null,
            'has_photo' => $hasPhoto,
            'warnings' => $warnings,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
