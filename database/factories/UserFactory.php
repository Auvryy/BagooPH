<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => 'buyer',
            'phone' => '+63 9' . fake()->numerify('## ### ####'),
            'avatar' => null,
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Manila', 'Quezon City', 'Makati', 'Taguig', 'Pasig']),
            'postal_code' => fake()->postcode(),
            'status' => 'active',
            'kyc_status' => 'approved',
            'kyc_reviewed_at' => now(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function buyer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'buyer',
        ]);
    }

    public function seller(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'seller',
        ]);
    }

    public function courier(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'courier',
        ]);
    }

    public function logistics(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'logistics',
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    public function pendingKyc(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
            'kyc_submitted_at' => now(),
            'kyc_reviewed_at' => null,
            'kyc_feedback' => null,
        ]);
    }

    public function approvedKyc(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'kyc_status' => 'approved',
            'kyc_reviewed_at' => now(),
            'kyc_feedback' => null,
        ]);
    }

    public function rejectedKyc(string $feedback = 'Invalid identification document'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => $feedback,
            'kyc_reviewed_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
