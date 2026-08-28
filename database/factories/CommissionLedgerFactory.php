<?php

namespace Database\Factories;

use App\Models\CommissionLedger;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionLedger>
 */
class CommissionLedgerFactory extends Factory
{
    protected $model = CommissionLedger::class;

    public function definition(): array
    {
        $gross = fake()->randomFloat(2, 200, 3000);
        $sellerAmount = round($gross * 0.90, 2);
        $platformCommission = round($gross * 0.10, 2);

        return [
            'order_id' => Order::factory(),
            'seller_id' => User::factory()->seller(),
            'courier_id' => User::factory()->courier(),
            'gross_amount' => $gross,
            'seller_amount' => $sellerAmount,
            'platform_commission' => $platformCommission,
            'delivery_fee' => 60.00,
            'status' => 'settled',
        ];
    }
}
