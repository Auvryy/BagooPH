<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true) . ' Collection';

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name . '-' . fake()->unique()->numerify('####')),
            'icon' => 'Sparkles',
            'image' => null,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
