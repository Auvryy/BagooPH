<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryCheckpoint>
 */
class DeliveryCheckpointFactory extends Factory
{
    protected $model = DeliveryCheckpoint::class;

    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'checkpoint_type' => 'courier_pickup',
            'location_name' => 'Metro Manila Central Sorting Station',
            'barcode_scanned' => 'BGO-' . strtoupper(fake()->bothify('??????????')),
            'notes' => fake()->sentence(),
            'scanned_by_id' => User::factory()->courier(),
            'proof_image' => null,
        ];
    }
}
