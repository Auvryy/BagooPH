<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRevision;
use App\Services\Finance\CodMoney;
use Illuminate\Database\Eloquent\Factories\Factory;

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

    public function funded(array $terms = []): static
    {
        return $this->afterMaking(fn (Voucher $voucher) => $voucher->issuer_type = $voucher->shop_id === null ? 'platform' : 'shop')
            ->afterCreating(function (Voucher $voucher) use ($terms) {
                $creator = $voucher->shop?->user ?? User::factory()->admin()->create();
                $revision = VoucherRevision::create(array_replace([
                    'voucher_id' => $voucher->id, 'revision_number' => 1, 'issuer_type' => $voucher->issuer_type,
                    'shop_id' => $voucher->shop_id, 'funding_party' => $voucher->issuer_type, 'created_by_id' => $creator->id,
                    'code' => $voucher->code, 'name' => $voucher->name ?? 'Bagoo savings', 'description' => $voucher->description,
                    'discount_type' => $voucher->discount_type, 'value_cents' => $voucher->discount_type === 'percent' ? 0 : CodMoney::cents($voucher->discount_value),
                    'percentage_bps' => $voucher->discount_type === 'percent' ? CodMoney::cents($voucher->discount_value) : null,
                    'minimum_cents' => CodMoney::cents($voucher->min_spend), 'maximum_cents' => $voucher->max_discount === null ? 99999999999 : CodMoney::cents($voucher->max_discount),
                    'budget_cents' => 99999999999, 'global_limit' => $voucher->usage_limit ?? 100,
                    'per_buyer_limit' => 1, 'starts_at' => now()->subYear(), 'ends_at' => $voucher->expires_at ?? now()->addMonth(),
                ], $terms));
                $voucher->update(['current_revision_id' => $revision->id]);
                if ($voucher->used_count > 0) {
                    $revision->balance->update(['redemption_count' => $voucher->used_count]);
                }
            });
    }
}
