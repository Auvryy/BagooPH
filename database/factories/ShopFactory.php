<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        $name = fake()->company() . ' Boutique';

        return [
            'user_id' => User::factory()->seller(),
            'name' => $name,
            'slug' => Str::slug($name . '-' . fake()->unique()->numerify('####')),
            'description' => fake()->paragraph(),
            'logo' => null,
            'banner' => null,
            'phone' => '+63 9' . fake()->numerify('## ### ####'),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Manila', 'Quezon City', 'Makati', 'Taguig', 'Pasig']),
            'rating' => 5.00,
            'status' => 'active',
        ];
    }
}
