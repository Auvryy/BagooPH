<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 200, 3000);
        $shippingFee = 50.00;

        return [
            'order_number' => 'BGO-' . strtoupper(Str::random(8)),
            'buyer_id' => User::factory()->buyer(),
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'total_amount' => $subtotal + $shippingFee,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => 'pending',
            'recipient_name' => fake()->name(),
            'recipient_phone' => '+63 9' . fake()->numerify('## ### ####'),
            'shipping_address' => fake()->streetAddress(),
            'shipping_city' => fake()->randomElement(['Manila', 'Quezon City', 'Makati', 'Taguig', 'Pasig']),
            'shipping_postal_code' => fake()->postcode(),
            'notes' => fake()->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);
    }

    public function packaging(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
        ]);
    }

    public function readyForPickup(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ready_for_pickup',
        ]);
    }

    public function pickedUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'shipped',
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'payment_status' => 'paid',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'paid',
        ]);
    }
}
