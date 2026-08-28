<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SAVE##')),
            'name' => 'Special Discount Voucher',
            'description' => fake()->sentence(),
            'shop_id' => null,
            'discount_type' => 'fixed',
            'discount_value' => 100.00,
            'min_spend' => 500.00,
            'max_discount' => 500.00,
            'usage_limit' => 100,
            'used_count' => 0,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
        ];
    }

    public function percentage(float $percent = 10.0, float $minSpend = 500.0): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => 'percent',
            'discount_value' => $percent,
            'min_spend' => $minSpend,
            'max_discount' => 500.00,
        ]);
    }

    public function fixed(float $amount = 100.0, float $minSpend = 500.0): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => 'fixed',
            'discount_value' => $amount,
            'min_spend' => $minSpend,
        ]);
    }
}
