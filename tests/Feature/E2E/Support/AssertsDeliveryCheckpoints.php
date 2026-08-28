<?php

namespace Tests\Feature\E2E\Support;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use PHPUnit\Framework\Assert;

trait AssertsDeliveryCheckpoints
{
    public function assertCheckpointLogged(Delivery $delivery, string $checkpointType, ?string $location = null): void
    {
        $query = DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('checkpoint_type', $checkpointType);

        if ($location !== null) {
            $query->where('location_name', 'like', "%{$location}%");
        }

        Assert::assertTrue(
            $query->exists(),
            "Failed asserting that checkpoint of type '{$checkpointType}'" .
            ($location ? " at location '{$location}'" : '') .
            " was logged for delivery #{$delivery->tracking_number}."
        );
    }

    public function assertCheckpointSequence(Delivery $delivery, array $expectedTypes): void
    {
        $actualTypes = DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->orderBy('id', 'asc')
            ->pluck('checkpoint_type')
            ->toArray();

        Assert::assertEquals(
            $expectedTypes,
            $actualTypes,
            "Delivery #{$delivery->tracking_number} checkpoint sequence does not match expected order."
        );
    }

    public function assertBarcodeScanned(Delivery $delivery, string $barcode): void
    {
        $exists = DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('barcode_scanned', $barcode)
            ->exists();

        Assert::assertTrue(
            $exists,
            "Failed asserting that barcode '{$barcode}' was scanned for delivery #{$delivery->tracking_number}."
        );
    }
}
