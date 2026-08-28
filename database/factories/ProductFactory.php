<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);
        $price = fake()->randomFloat(2, 100, 2500);

        return [
            'shop_id' => Shop::factory(),
            'category_id' => Category::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name . '-' . fake()->unique()->numerify('#####')),
            'description' => fake()->paragraph(),
            'price' => $price,
            'compare_at_price' => $price * 1.25,
            'stock' => fake()->numberBetween(10, 100),
            'sku' => 'SKU-' . strtoupper(fake()->unique()->bothify('???-####')),
            'featured_image' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600',
            'weight_kg' => 0.50,
            'status' => 'active',
            'rating' => 5.00,
            'sales_count' => 0,
        ];
    }
}
