<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'courier_id' => null,
            'tracking_number' => 'BGO-' . strtoupper(Str::random(10)),
            'logistics_partner' => 'Bagoo Express Dispatch Fleet',
            'status' => 'unassigned',
            'pickup_store_name' => fake()->company() . ' Flagship Store',
            'pickup_address' => fake()->streetAddress() . ', Manila',
            'pickup_phone' => '+63 9' . fake()->numerify('## ### ####'),
            'delivery_recipient_name' => fake()->name(),
            'delivery_address' => fake()->streetAddress() . ', Quezon City',
            'delivery_phone' => '+63 9' . fake()->numerify('## ### ####'),
            'estimated_delivery_at' => now()->addDays(3),
            'assigned_at' => null,
            'picked_up_at' => null,
            'delivered_at' => null,
            'proof_image' => null,
            'courier_notes' => null,
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'unassigned',
            'courier_id' => null,
            'assigned_at' => null,
        ]);
    }

    public function assigned(?User $courier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'assigned',
            'courier_id' => $courier?->id ?? User::factory()->courier(),
            'assigned_at' => now(),
        ]);
    }

    public function pickedUp(?User $courier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'picked_up',
            'courier_id' => $courier?->id ?? User::factory()->courier(),
            'assigned_at' => now()->subHour(),
            'picked_up_at' => now(),
        ]);
    }

    public function inTransit(?User $courier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_transit',
            'courier_id' => $courier?->id ?? User::factory()->courier(),
            'assigned_at' => now()->subHours(2),
            'picked_up_at' => now()->subHour(),
        ]);
    }

    public function outForDelivery(?User $courier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'out_for_delivery',
            'courier_id' => $courier?->id ?? User::factory()->courier(),
            'assigned_at' => now()->subHours(3),
            'picked_up_at' => now()->subHours(2),
        ]);
    }

    public function delivered(?User $courier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'courier_id' => $courier?->id ?? User::factory()->courier(),
            'assigned_at' => now()->subHours(4),
            'picked_up_at' => now()->subHours(3),
            'delivered_at' => now(),
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);
    }
}
