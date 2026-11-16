<?php

namespace Tests\Feature\Buyer;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Product cards show a live shop voucher that applies to them, so buyers see the saving before checkout. */
class ProductVoucherHintTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_cards_carry_the_shop_voucher_that_applies_to_them(): void
    {
        $this->withoutVite();
        $shop = Shop::factory()->approved()->create();
        $other = Shop::factory()->approved()->create();
        $mine = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id, 'price' => '400.00', 'stock' => 5, 'status' => 'active']);
        $plain = Product::factory()->create(['shop_id' => $other->id, 'category_id' => $other->root_category_id, 'price' => '400.00', 'stock' => 5, 'status' => 'active']);
        $service = app(VoucherCampaignService::class);
        $voucher = $service->saveDraft($shop->user, ['code' => 'SHOP'.strtoupper(Str::random(6)), 'name' => 'Shop savings', 'description' => 'Fifty off.',
            'discount_type' => 'fixed', 'discount_value' => '50.00', 'min_spend' => '300.00', 'max_discount' => '50.00', 'budget' => '5000.00',
            'usage_limit' => 50, 'per_buyer_limit' => 1, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(),
            'source_version' => 0, 'request_key' => (string) Str::uuid()], shop: $shop);
        $service->act($shop->user, $voucher, ['action' => 'publish', 'source_version' => $voucher->fresh()->campaign_version, 'request_key' => (string) Str::uuid()], $shop);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('feedProducts.data', fn ($rows) => collect($rows)->firstWhere('id', $mine->id)['voucher_hint'] === ['label' => '₱50 off', 'min_spend' => 300]
                && collect($rows)->firstWhere('id', $plain->id)['voucher_hint'] === null));
    }
}
