<?php

namespace Database\Factories;

use App\Models\CourierProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierProfile>
 */
class CourierProfileFactory extends Factory
{
    protected $model = CourierProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->courier(),
            'vehicle_type' => 'Motorcycle (Express Dispatch)',
            'plate_number' => 'NC-' . fake()->numerify('####'),
            'license_number' => 'N02-' . fake()->numerify('##-######'),
            'or_cr_status' => 'Verified & Registered',
            'is_available' => true,
        ];
    }
}
