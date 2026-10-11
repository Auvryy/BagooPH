<?php

namespace Tests\Feature\Seeders;

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
        $voucher = Voucher::where('code', 'DEMO_SHOP100')->sole();
        $original = $voucher->currentRevision->getRawOriginal();
        $voucher->update(['is_active' => false, 'archived_at' => now()]);
        $this->seed(VoucherDemoSeeder::class);
        $this->assertDatabaseCount('vouchers', 3);
        $this->assertDatabaseCount('voucher_campaign_actions', 6);
        $this->assertFalse($voucher->fresh()->is_active);
        $this->assertSame($original, $voucher->currentRevision->fresh()->getRawOriginal());
    }
}
