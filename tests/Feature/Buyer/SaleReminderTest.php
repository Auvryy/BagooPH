<?php

namespace Tests\Feature\Buyer;

use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\SaleReminder;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\SaleReminderService;
use App\Services\Notifications\NotificationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SaleReminderTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $minutes = 30): array
    {
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 500]);
        $sale = $product->sales()->create(['kind' => 'flash', 'name' => 'Bagoo morning sale', 'price' => 299, 'quantity' => 10,
            'starts_at' => now()->addMinutes($minutes), 'ends_at' => now()->addHour()]);
        $this->actingAs($buyer);

        return [$buyer, $shop, $product, $sale];
    }

    public function test_owned_reminder_is_sent_before_start_once_and_links_to_the_current_product(): void
    {
        [$buyer, , $product, $sale] = $this->fixture();
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHas('success');
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHas('success');
        $this->assertDatabaseCount('sale_reminders', 1);
        $this->assertSame(0, DB::table('notification_deliveries')->where('type', 'sale-reminder')->count());
        $this->get(route('buyer.products.show', $product->slug))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('saleReminder.sale_id', $sale->id)->where('saleReminder.subscribed', true)->where('saleReminder.notified', false));
        $this->travel(20)->minutes();
        $this->artisan('sales:send-reminders')->assertSuccessful();
        $this->artisan('sales:send-reminders')->assertSuccessful();
        $this->assertSame(1, DB::table('notification_deliveries')->where('type', 'sale-reminder')->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'sale-reminder')->count());
        $this->assertTrue(SaleReminder::sole()->notified_at->lessThan($sale->starts_at));
        $notice = $buyer->notifications()->sole();
        $data = app(NotificationCenterService::class)->present($notice, $buyer);
        $this->assertSame(route('buyer.products.show', $product->slug, absolute: false), $data['href']);
        $this->assertStringContainsString($sale->name, $data['data']['body']);
        $this->assertSame(1, app(NotificationCenterService::class)->summary($buyer)['unread']);
        $other = User::factory()->create();
        $this->actingAs($other)->patchJson(route('notifications.read', $notice->id))->assertNotFound();
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_unsubscribe_is_owned_idempotent_and_does_not_remove_another_buyers_subscription(): void
    {
        [$buyer, , , $sale] = $this->fixture();
        $this->post(route('buyer.sale-reminders.store', $sale));
        $other = User::factory()->create();
        $this->actingAs($other)->delete(route('buyer.sale-reminders.destroy', $sale))->assertSessionHas('success');
        $this->assertTrue(SaleReminder::sole()->subscribed);
        $this->actingAs($buyer)->delete(route('buyer.sale-reminders.destroy', $sale))->assertSessionHas('success');
        $this->delete(route('buyer.sale-reminders.destroy', $sale))->assertSessionHas('success');
        $this->travel(21)->minutes();
        $this->artisan('sales:send-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'sale-reminder')->count());
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHas('success');
        $this->assertSame(1, DB::table('notifications')->where('type', 'sale-reminder')->count());
        $this->delete(route('buyer.sale-reminders.destroy', $sale));
        $this->post(route('buyer.sale-reminders.store', $sale));
        $this->assertSame(1, DB::table('notifications')->where('type', 'sale-reminder')->count());
    }

    public function test_imminent_slot_notifies_immediately_and_invalid_or_started_slots_cannot_be_subscribed(): void
    {
        [, , , $sale] = $this->fixture(5);
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHas('success');
        $this->assertSame(1, DB::table('notifications')->where('type', 'sale-reminder')->count());
        $this->travel(5)->minutes();
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHasErrors('sale_reminder');
        $this->artisan('sales:send-reminders')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications')->where('type', 'sale-reminder')->count());
    }

    public function test_ended_slots_vacation_and_restricted_accounts_do_not_create_misleading_notices(): void
    {
        [$buyer, $shop, , $sale] = $this->fixture();
        $this->post(route('buyer.sale-reminders.store', $sale));
        $this->travel(21)->minutes();
        $shop->update(['vacation_mode' => true]);
        $this->artisan('sales:send-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('notification_deliveries')->where('type', 'sale-reminder')->count());
        $shop->update(['vacation_mode' => false]);
        $buyer->update(['status' => 'suspended']);
        $this->artisan('sales:send-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('notification_deliveries')->where('type', 'sale-reminder')->count());
        $buyer->update(['status' => 'active']);
        $sale->update(['ended_at' => now()]);
        $this->artisan('sales:send-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'sale-reminder')->count());
    }

    public function test_failed_delivery_is_discarded_after_opt_out_and_does_not_send_on_retry(): void
    {
        [, , , $sale] = $this->fixture(5);
        DB::unprepared("CREATE TRIGGER sale_notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Temporary notice storage failure'); END;");
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHas('success');
        $delivery = NotificationDelivery::where('type', 'sale-reminder')->sole();
        $this->assertNull($delivery->delivered_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull(SaleReminder::sole()->notified_at);
        $this->delete(route('buyer.sale-reminders.destroy', $sale));
        DB::unprepared('DROP TRIGGER sale_notice_outage');
        $this->travel(2)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->assertNotNull($delivery->fresh()->discarded_at);
        $this->assertNull($delivery->fresh()->delivered_at);
        $this->assertSame(0, DB::table('notifications')->where('type', 'sale-reminder')->count());
    }

    public function test_failed_delivery_never_arrives_late_after_the_slot_starts(): void
    {
        [, , , $sale] = $this->fixture(5);
        DB::unprepared("CREATE TRIGGER sale_notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Temporary notice storage failure'); END;");
        $this->post(route('buyer.sale-reminders.store', $sale));
        DB::unprepared('DROP TRIGGER sale_notice_outage');
        $this->travel(6)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->assertNotNull(NotificationDelivery::where('type', 'sale-reminder')->sole()->discarded_at);
        $this->assertSame(0, DB::table('notifications')->where('type', 'sale-reminder')->count());
    }

    public function test_buyer_cannot_subscribe_to_store_sales_or_while_unapproved_and_seller_cannot_subscribe(): void
    {
        [$buyer, $shop, , $sale] = $this->fixture();
        $sale->update(['kind' => 'store', 'quantity' => null]);
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHasErrors('sale_reminder');
        $sale->update(['kind' => 'flash', 'quantity' => 10]);
        $buyer->update(['kyc_status' => 'pending_approval']);
        $this->post(route('buyer.sale-reminders.store', $sale))->assertRedirect();
        $this->assertDatabaseCount('sale_reminders', 0);
        $this->actingAs($shop->user)->post(route('buyer.sale-reminders.store', $sale))->assertForbidden();
        $this->assertDatabaseCount('sale_reminders', 0);
    }

    public function test_schedule_with_a_sent_reminder_cannot_silently_change_its_times(): void
    {
        [, $shop, $product, $sale] = $this->fixture(5);
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHas('success');
        $this->actingAs($shop->user)->put(route('seller.products.sales.update', [$product, $sale]), [
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('sale');
        $this->assertTrue($sale->fresh()->starts_at->equalTo(now()->addMinutes(5)));
    }

    public function test_ineligible_earlier_subscription_does_not_starve_the_next_due_buyer(): void
    {
        [$buyer, , , $sale] = $this->fixture();
        $this->post(route('buyer.sale-reminders.store', $sale));
        $buyer->update(['status' => 'suspended']);
        $eligible = User::factory()->create();
        $this->actingAs($eligible)->post(route('buyer.sale-reminders.store', $sale));
        $this->travel(21)->minutes();
        $this->artisan('sales:send-reminders', ['--limit' => '1'])->assertSuccessful();
        $this->assertSame(0, $buyer->notifications()->count());
        $this->assertSame(1, $eligible->notifications()->count());
        $this->artisan('sales:send-reminders', ['--limit' => '0'])->assertExitCode(2);
    }

    public function test_rejoining_before_start_resumes_an_undelivered_intent_without_creating_duplicates(): void
    {
        [$buyer, , $product, $sale] = $this->fixture(5);
        DB::unprepared("CREATE TRIGGER sale_notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Temporary notice storage failure'); END;");
        $this->post(route('buyer.sale-reminders.store', $sale));
        $delivery = NotificationDelivery::where('type', 'sale-reminder')->sole();
        $status = app(SaleReminderService::class)->status($buyer, $sale->id);
        $this->assertFalse($status['delivered']);
        $this->delete(route('buyer.sale-reminders.destroy', $sale));
        DB::unprepared('DROP TRIGGER sale_notice_outage');
        $this->travel(2)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->assertNotNull($delivery->fresh()->discarded_at);
        $this->post(route('buyer.sale-reminders.store', $sale))->assertSessionHas('success');
        $this->assertSame(1, DB::table('notifications')->where('type', 'sale-reminder')->count());
        $this->assertSame($delivery->id, NotificationDelivery::where('type', 'sale-reminder')->sole()->id);
        $this->assertNotNull($delivery->fresh()->delivered_at);
        $this->get(route('buyer.products.show', $product->slug))->assertInertia(fn (Assert $p) => $p->where('saleReminder.delivered', true));
    }
}
