<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 4);
        $unitPrice = fake()->randomFloat(2, 100, 1500);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'shop_id' => Shop::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $quantity * $unitPrice,
            'color' => fake()->randomElement(['Midnight Blue', 'Emerald Green', 'Charcoal Black', 'Ivory White', null]),
            'size' => fake()->randomElement(['S', 'M', 'L', 'XL', null]),
            'sku_snapshot' => 'SKU-' . strtoupper(fake()->bothify('???-####')),
        ];
    }
}
