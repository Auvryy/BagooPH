<?php

namespace Tests\Feature\Seeders;

use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\CheckoutQuoteService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\VoucherDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_campaigns_use_the_real_writer_without_invented_bookmarks_or_uses(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::where('email', 'buyer@bagoo.test')->sole();
        $this->assertTrue(password_verify('Password1234', $buyer->password));
        $this->assertSame(3, app(CheckoutQuoteService::class)->offers(buyer: $buyer)['count']);
        $this->assertDatabaseCount('voucher_campaign_actions', 6);
        $this->assertDatabaseCount('saved_vouchers', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertDatabaseCount('voucher_funding_entries', 0);
        $voucher = Voucher::where('code', 'DEMOSHOP100')->sole();
        $original = $voucher->currentRevision->getRawOriginal();
        $voucher->update(['is_active' => false, 'archived_at' => now()]);
        $this->seed(VoucherDemoSeeder::class);
        $this->assertDatabaseCount('vouchers', 3);
        $this->assertDatabaseCount('voucher_campaign_actions', 6);
        $this->assertFalse($voucher->fresh()->is_active);
        $this->assertSame($original, $voucher->currentRevision->fresh()->getRawOriginal());
    }

    public function test_legacy_demo_codes_are_not_duplicated_renamed_or_revived(): void
    {
        User::factory()->admin()->create(['email' => 'admin@bagoo.test']);
        $seller = User::factory()->seller()->create(['email' => 'seller@bagoo.test']);
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $voucher = Voucher::factory()->funded()->create(['shop_id' => $shop->id, 'code' => 'DEMO_SHOP100']);
        $original = $voucher->currentRevision->getRawOriginal();
        $voucher->update(['is_active' => false, 'archived_at' => now()]);
        $this->seed(VoucherDemoSeeder::class);
        $this->assertDatabaseCount('vouchers', 3);
        $this->assertFalse(Voucher::where('code', 'DEMOSHOP100')->exists());
        $this->assertSame('DEMO_SHOP100', $voucher->fresh()->code);
        $this->assertFalse($voucher->fresh()->is_active);
        $this->assertSame($original, $voucher->currentRevision->fresh()->getRawOriginal());
    }
}
