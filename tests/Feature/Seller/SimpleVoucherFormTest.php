<?php

namespace Tests\Feature\Seller;

use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The voucher form only asks for the essentials. Blank optional fields get safe defaults on the
 * server, and whoever creates the voucher funds it: a shop voucher is paid by that shop, a Bagoo
 * voucher by the platform.
 */
class SimpleVoucherFormTest extends TestCase
{
    use RefreshDatabase;

    private function essentials(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Weekend treat', 'discount_type' => 'fixed', 'discount_value' => '50', 'usage_limit' => '20',
            'starts_at' => now()->addMinute()->toIso8601String(), 'ends_at' => now()->addWeek()->toIso8601String(),
            'source_version' => 0, 'request_key' => (string) Str::uuid(), 'publish_request_key' => (string) Str::uuid(),
        ];
    }

    public function test_seller_publishes_with_only_essentials_and_the_shop_funds_it(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);

        $this->actingAs($seller)->post('/seller/vouchers', $this->essentials(['min_spend' => '100', 'publish_after_save' => true]))
            ->assertSessionHasNoErrors();

        $revision = Voucher::sole()->currentRevision;
        $this->assertNotNull($revision);
        $this->assertSame('shop', $revision->issuer_type);
        $this->assertSame('shop', $revision->funding_party);
        $this->assertSame($shop->id, $revision->shop_id);
        $this->assertSame(5000, $revision->value_cents);
        $this->assertSame(5000, $revision->maximum_cents, 'A fixed discount caps itself at its own amount.');
        $this->assertSame(100000, $revision->budget_cents, 'Ceiling defaults to max saving x number of vouchers.');
        $this->assertSame(1, $revision->per_buyer_limit, 'One use per buyer unless the seller allows more.');
        $this->assertSame(20, $revision->global_limit);
    }

    public function test_seller_minimum_must_still_cover_the_discount_from_the_seller_share(): void
    {
        $seller = User::factory()->seller()->create();
        Shop::factory()->approved()->create(['user_id' => $seller->id]);

        $this->actingAs($seller)->post('/seller/vouchers', $this->essentials())
            ->assertSessionHasErrors('min_spend');
        $this->assertDatabaseCount('voucher_revisions', 0);
    }

    public function test_percent_discount_still_needs_a_cap(): void
    {
        $seller = User::factory()->seller()->create();
        Shop::factory()->approved()->create(['user_id' => $seller->id]);

        $this->actingAs($seller)->post('/seller/vouchers', $this->essentials(['discount_type' => 'percent', 'discount_value' => '10', 'min_spend' => '500']))
            ->assertSessionHasErrors('max_discount');
    }

    public function test_admin_voucher_is_funded_by_the_platform(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/vouchers', $this->essentials(['name' => 'Bagoo payday', 'publish_after_save' => true]))
            ->assertSessionHasNoErrors();

        $revision = Voucher::sole()->currentRevision;
        $this->assertSame('platform', $revision->issuer_type);
        $this->assertSame('platform', $revision->funding_party);
        $this->assertNull($revision->shop_id);
        $this->assertSame(100000, $revision->budget_cents);
    }
}
