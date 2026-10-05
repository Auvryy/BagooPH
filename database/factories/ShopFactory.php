<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
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
        $name = fake()->company().' Boutique';

        return [
            'user_id' => User::factory()->seller(),
            'name' => $name,
            'slug' => Str::slug($name.'-'.fake()->unique()->numerify('####')),
            'description' => fake()->paragraph(),
            'logo' => null,
            'banner' => null,
            'phone' => '+63 9'.fake()->numerify('## ### ####'),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Manila', 'Quezon City', 'Makati', 'Taguig', 'Pasig']),
            'rating' => 5.00,
            'status' => 'active',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'root_category_id' => Category::firstOrCreate(['name' => 'Pet Supplies', 'parent_id' => null],
                ['slug' => 'pet-supplies', 'is_active' => true])->id,
        ])->afterCreating(fn (Shop $shop) => self::recordApprovalFixture($shop));
    }

    public static function recordApprovalFixture(Shop $shop): void
    {
        // An explicit synthetic reviewed fixture; default factory shops remain unreviewed legacy records.
        $admin = User::factory()->admin()->create();
        $decision = ShopReviewDecision::create([
            'shop_id' => $shop->id, 'seller_id' => $shop->user_id, 'reviewer_id' => $admin->id,
            'reviewer_role' => 'admin', 'reviewer_name' => $admin->name,
            'root_category_id' => $shop->root_category_id, 'submission_token' => hash('sha256', 'fixture-shop-'.$shop->id.'-'.$shop->reviewDecisions()->count()),
            'decision' => 'approved', 'reason' => null,
            'submission' => ['shop' => $shop->only(['id', 'user_id', 'name', 'phone', 'address', 'city', 'root_category_id']), 'documents' => []],
            'before_state' => ['status' => 'pending', 'review_status' => 'pending_approval'],
            'after_state' => ['status' => $shop->status, 'review_status' => 'approved'], 'reviewed_at' => now(),
        ]);
        $shop->update(['review_status' => 'approved', 'review_decision_id' => $decision->id, 'reviewed_at' => now()]);
    }
}
