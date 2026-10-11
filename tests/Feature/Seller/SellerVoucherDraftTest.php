<?php

namespace Tests\Feature\Seller;

use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerVoucherDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_shop_draft_cannot_publish_unfunded_terms(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $this->actingAs($seller)->post('/seller/vouchers', ['code' => 'SHOP_DRAFT', 'name' => 'Shop draft',
            'discount_type' => 'fixed', 'discount_value' => 100, 'min_spend' => 500, 'max_discount' => 100,
            'budget' => 1000, 'usage_limit' => 10, 'per_buyer_limit' => 1, 'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addWeek()->toIso8601String(), 'source_version' => 0, 'request_key' => (string) Str::uuid()])->assertSessionHas('success');
        $draft = Voucher::sole();
        $this->assertSame('shop', $draft->issuer_type);
        $this->assertSame($shop->id, $draft->shop_id);
        $this->assertFalse($draft->is_active);
        $this->assertNull($draft->current_revision_id);
        $this->patch('/seller/vouchers/'.$draft->id.'/toggle')->assertSessionHas('error');
        $this->assertFalse($draft->fresh()->is_active);
        $this->assertDatabaseCount('voucher_revisions', 0);
        $this->delete('/seller/vouchers/'.$draft->id, ['source_version' => $draft->campaign_version, 'request_key' => (string) Str::uuid()])->assertSessionHas('success');
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertNotNull($draft->fresh()->archived_at);
    }

    public function test_published_shop_terms_are_archived_with_their_original_history(): void
    {
        $shop = Shop::factory()->approved()->create();
        $voucher = Voucher::factory()->funded()->create(['shop_id' => $shop->id]);
        $before = $voucher->currentRevision->getRawOriginal();
        $this->actingAs($shop->user)->delete('/seller/vouchers/'.$voucher->id, ['source_version' => 0, 'request_key' => (string) Str::uuid()])->assertSessionHas('success');
        $this->assertFalse($voucher->fresh()->is_active);
        $this->assertNotNull($voucher->fresh()->archived_at);
        $this->assertSame($before, $voucher->currentRevision->fresh()->getRawOriginal());
        $this->patch('/seller/vouchers/'.$voucher->id.'/toggle')->assertSessionHas('error');
        $this->assertFalse($voucher->fresh()->is_active);
    }

    public function test_legacy_usage_is_retained_and_another_seller_cannot_change_a_campaign(): void
    {
        $shop = Shop::factory()->approved()->create();
        $legacy = Voucher::factory()->create(['shop_id' => $shop->id, 'used_count' => 7]);
        $other = Shop::factory()->approved()->create();
        $this->actingAs($other->user)->delete('/seller/vouchers/'.$legacy->id, ['source_version' => 0, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->patch('/seller/vouchers/'.$legacy->id.'/toggle')->assertForbidden();
        $this->actingAs($shop->user)->delete('/seller/vouchers/'.$legacy->id, ['source_version' => 0, 'request_key' => (string) Str::uuid()])->assertSessionHas('success');
        $this->assertSame(7, $legacy->fresh()->used_count);
        $this->assertNotNull($legacy->fresh()->archived_at);
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }
}
