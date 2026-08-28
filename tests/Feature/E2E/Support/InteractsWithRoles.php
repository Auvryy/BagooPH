<?php

namespace Tests\Feature\E2E\Support;

use App\Models\CourierProfile;
use App\Models\Shop;
use App\Models\User;

trait InteractsWithRoles
{
    public function actingAsBuyer(?User $user = null): static
    {
        $user = $user ?? $this->createApprovedUser('buyer');
        return $this->actingAs($user);
    }

    public function actingAsSeller(?User $user = null): static
    {
        $user = $user ?? $this->createApprovedUser('seller');
        return $this->actingAs($user);
    }

    public function actingAsCourier(?User $user = null): static
    {
        $user = $user ?? $this->createApprovedUser('courier');
        return $this->actingAs($user);
    }

    public function actingAsLogistics(?User $user = null): static
    {
        $user = $user ?? $this->createApprovedUser('logistics');
        return $this->actingAs($user);
    }

    public function actingAsAdmin(?User $user = null): static
    {
        $user = $user ?? $this->createApprovedUser('admin');
        return $this->actingAs($user);
    }

    public function createApprovedUser(string $role = 'buyer', array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => $role,
            'status' => 'active',
            'kyc_status' => 'approved',
            'kyc_reviewed_at' => now(),
        ], $attributes));

        if ($role === 'seller' && ! $user->shop) {
            Shop::factory()->create([
                'user_id' => $user->id,
                'name' => $user->name . "'s Store",
                'status' => 'active',
            ]);
        }

        if ($role === 'courier' && ! $user->courierProfile) {
            CourierProfile::factory()->create([
                'user_id' => $user->id,
                'is_available' => true,
                'or_cr_status' => 'Verified & Registered',
            ]);
        }

        return $user->fresh();
    }

    public function createPendingUser(string $role = 'buyer', array $attributes = []): User
    {
        $user = User::factory()->pendingKyc()->create(array_merge([
            'role' => $role,
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ], $attributes));

        if ($role === 'seller' && ! $user->shop) {
            Shop::factory()->create([
                'user_id' => $user->id,
                'name' => $user->name . "'s Store",
                'status' => 'pending',
            ]);
        }

        if ($role === 'courier' && ! $user->courierProfile) {
            CourierProfile::factory()->create([
                'user_id' => $user->id,
                'is_available' => false,
                'or_cr_status' => 'Pending Verification',
            ]);
        }

        return $user->fresh();
    }

    public function createRejectedUser(string $role = 'buyer', string $feedback = 'Invalid identification document', array $attributes = []): User
    {
        $user = User::factory()->rejectedKyc($feedback)->create(array_merge([
            'role' => $role,
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => $feedback,
        ], $attributes));

        if ($role === 'seller' && ! $user->shop) {
            Shop::factory()->create([
                'user_id' => $user->id,
                'name' => $user->name . "'s Store",
                'status' => 'pending',
            ]);
        }

        if ($role === 'courier' && ! $user->courierProfile) {
            CourierProfile::factory()->create([
                'user_id' => $user->id,
                'is_available' => false,
            ]);
        }

        return $user->fresh();
    }
}
