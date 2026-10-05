<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerApplicationService
{
    public function __construct(private MasterCategoryService $categories) {}

    public function register(array $account, string $shopName, int $categoryId): User
    {
        return DB::transaction(function () use ($account, $shopName, $categoryId) {
            // Recheck the category under lock before creating either application record.
            $this->categories->requireEligible($categoryId);
            $user = User::create(array_replace($account, [
                'role' => 'seller', 'status' => 'pending_approval', 'kyc_status' => 'pending_approval',
            ]));
            Shop::create([
                'user_id' => $user->id,
                'root_category_id' => $categoryId,
                'name' => $shopName,
                'slug' => (Str::substr(Str::slug($shopName), 0, 220) ?: 'shop').'-'.$user->id,
                'phone' => $user->phone,
                'address' => $user->address,
                'city' => $user->city,
                'status' => 'pending',
                'review_status' => 'pending_approval',
                'review_submitted_at' => now(),
            ]);

            return $user;
        });
    }
}
