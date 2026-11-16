<?php

namespace Tests\Feature\Promotions;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SaleEvent;
use App\Models\SaleEventClaim;
use App\Models\SaleEventVoucher;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRevision;
use App\Services\Commerce\SalePriceService;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Promotions\EventVoucherGate;
use App\Services\Promotions\SaleEventService;
use App\Services\Vouchers\VoucherCampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class SaleEventTest extends TestCase
{
    use InteractsWithRoles, RefreshDatabase;

    private User $admin;

    private Shop $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-16T02:00:00Z'));
        $this->admin = User::factory()->admin()->create();
        $this->shop = Shop::factory()->approved()->create();
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id, 'price' => '200.00', 'stock' => 20, 'status' => 'active']);
    }

    private function manila(string $modify): string
    {
        return CarbonImmutable::now('Asia/Manila')->modify($modify)->format('Y-m-d\TH:i');
    }

    private function event(string $start = '+0 minutes', string $end = '+2 days'): SaleEvent
    {
        return app(SaleEventService::class)->save($this->admin, ['title' => '11.11 Mega Sale', 'badge' => '11.11 deal', 'starts_at' => $this->manila($start), 'ends_at' => $this->manila($end)], null);
    }

    private function voucher(?Shop $shop = null): Voucher
    {
        $service = app(VoucherCampaignService::class);
        $actor = $shop?->user ?? $this->admin;
        $voucher = $service->saveDraft($actor, ['code' => ($shop ? 'SHOP' : 'BGO').strtoupper(Str::random(8)), 'name' => 'Event savings', 'description' => 'Savings for the event.',
            'discount_type' => 'fixed', 'discount_value' => '50.00', 'min_spend' => '100.00', 'max_discount' => '50.00', 'budget' => '5000.00',
            'usage_limit' => 50, 'per_buyer_limit' => 2, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(),
            'source_version' => 0, 'request_key' => (string) Str::uuid()], shop: $shop);
        $service->act($actor, $voucher, ['action' => 'publish', 'source_version' => $voucher->fresh()->campaign_version, 'request_key' => (string) Str::uuid()], $shop);

        return $voucher->fresh();
    }

    public function test_admin_schedules_an_event_with_a_banner_and_others_cannot(): void
    {
        $this->actingAs($this->admin)->post('/admin/sale-events', ['title' => '11.11 Mega Sale', 'badge' => '11.11 deal',
            'starts_at' => $this->manila('+1 hour'), 'ends_at' => $this->manila('+1 day'), 'banner' => UploadedFile::fake()->image('banner.jpg', 1880, 880)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $event = SaleEvent::sole();
        $this->assertSame(['11-11-mega-sale', '11.11 DEAL', 'upcoming'], [$event->slug, $event->badge, $event->status()]);
        Storage::disk('public')->assertExists($event->banner_path);

        $this->actingAs($this->admin)->post('/admin/sale-events', ['title' => 'Bad', 'badge' => 'X', 'starts_at' => $this->manila('+2 hours'), 'ends_at' => $this->manila('+1 hour')])
            ->assertSessionHasErrors('ends_at');
        $this->actingAs($this->shop->user)->post('/admin/sale-events', ['title' => 'Mine', 'badge' => 'X', 'starts_at' => $this->manila('+1 hour'), 'ends_at' => $this->manila('+1 day')])
            ->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/sale-events')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/SaleEvents/Index')->has('events.data', 1));
    }

    public function test_seller_discounts_products_for_the_event_window_and_checkout_charges_that_price(): void
    {
        $event = $this->event();
        $other = Product::factory()->create(['shop_id' => Shop::factory()->approved()->create()->id, 'price' => '100.00', 'status' => 'active']);

        $this->actingAs($this->shop->user)->post("/seller/sale-events/{$event->id}/products", ['product_ids' => [$other->id], 'percentage' => 20])
            ->assertSessionHasErrors('product_ids');
        $this->actingAs($this->shop->user)->post("/seller/sale-events/{$event->id}/products", ['product_ids' => [$this->product->id], 'percentage' => 25])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $sale = ProductSale::sole();
        $this->assertSame([$event->id, 25], [$sale->sale_event_id, $sale->percentage]);
        $this->assertTrue($sale->ends_at->equalTo($event->ends_at));
        $this->assertSame('150.00', app(SalePriceService::class)->unitPrice($this->product->fresh(), '200.00'));

        $this->get('/campaign/'.$event->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Buyer/Campaign')
            ->where('campaign.status', 'live')->where('products.0.price', 150)->where('products.0.regular_price', 200)->where('products.0.discount_pct', 25));

        // Joining twice is refused; ending the event stops the discount at once.
        $this->actingAs($this->shop->user)->post("/seller/sale-events/{$event->id}/products", ['product_ids' => [$this->product->id], 'percentage' => 10])
            ->assertSessionHasErrors('product_ids');
        $this->actingAs($this->admin)->post("/admin/sale-events/{$event->id}/end")->assertSessionHas('success');
        $this->assertSame('200.00', app(SalePriceService::class)->unitPrice($this->product->fresh(), '200.00'));
        $this->assertSame('ended', $event->fresh()->status());
    }

    public function test_limited_voucher_goes_to_the_first_buyers_once_each_and_only_they_can_use_it(): void
    {
        $event = $this->event();
        $voucher = $this->voucher();
        $this->actingAs($this->admin)->post("/admin/sale-events/{$event->id}/vouchers", ['voucher_id' => $voucher->id, 'claim_limit' => 1])->assertSessionHas('success');
        $entry = SaleEventVoucher::sole();
        [$first, $second] = [$this->createApprovedUser('buyer'), $this->createApprovedUser('buyer')];
        $revision = VoucherRevision::findOrFail($voucher->current_revision_id);

        $this->assertTrue(EventVoucherGate::blocked($revision, $first));
        $this->actingAs($first)->post("/campaign/vouchers/{$entry->id}/claim")->assertSessionHas('success', 'Voucher claimed. It applies at checkout.');
        $this->actingAs($first)->post("/campaign/vouchers/{$entry->id}/claim")->assertSessionHas('success', 'Already claimed. It applies at checkout.');
        $this->actingAs($second)->post("/campaign/vouchers/{$entry->id}/claim")->assertSessionHas('error', 'All vouchers have been claimed.');
        $this->assertSame(1, $entry->fresh()->claimed_count);
        $this->assertSame(1, SaleEventClaim::count());

        $this->assertFalse(EventVoucherGate::blocked($revision, $first));
        $this->assertTrue(EventVoucherGate::blocked($revision, $second));
        $available = fn (User $buyer) => app(CheckoutQuoteService::class)->availableQuery(buyer: $buyer)->pluck('voucher_revisions.id')->all();
        $this->assertContains($revision->id, $available($first));
        $this->assertNotContains($revision->id, $available($second));

        // Two requests that slip past the counter still cannot store a second claim for one buyer.
        $this->expectException(UniqueConstraintViolationException::class);
        SaleEventClaim::create(['sale_event_voucher_id' => $entry->id, 'buyer_id' => $first->id]);
    }

    public function test_open_vouchers_stay_usable_by_everyone_and_claim_all_claims_each_once(): void
    {
        $event = $this->event();
        $this->voucher(); // an unrelated voucher, so voucher ids and event-entry ids differ
        $platform = $this->voucher();
        $shopVoucher = $this->voucher($this->shop);
        app(SaleEventService::class)->addVoucher($this->admin, $event, ['voucher_id' => $platform->id]);
        $this->actingAs($this->shop->user)->post("/seller/sale-events/{$event->id}/vouchers", ['voucher_id' => $shopVoucher->id, 'claim_limit' => 10])->assertSessionHas('success');
        $this->actingAs($this->shop->user)->post("/seller/sale-events/{$event->id}/vouchers", ['voucher_id' => $platform->id])->assertSessionHasErrors('voucher_id');

        $buyer = $this->createApprovedUser('buyer');
        $this->assertFalse(EventVoucherGate::blocked(VoucherRevision::findOrFail($platform->current_revision_id), $buyer));
        // The page hands out event-entry ids, never voucher ids, so Claim targets the right voucher.
        $entries = SaleEventVoucher::orderBy('id')->get();
        $this->assertNotSame($entries[1]->id, $entries[1]->voucher_id);
        $this->actingAs($buyer)->get('/campaign/'.$event->slug)->assertInertia(fn (Assert $page) => $page
            ->where('vouchers.0.id', $entries[0]->id)->where('vouchers.0.voucher_id', $entries[0]->voucher_id)
            ->where('vouchers.1.id', $entries[1]->id)->where('vouchers.1.voucher_id', $entries[1]->voucher_id));
        $this->actingAs($buyer)->post("/campaign/{$event->id}/claim-all")->assertSessionHas('success');
        $this->actingAs($buyer)->post("/campaign/{$event->id}/claim-all")->assertSessionHas('success');
        $this->assertSame(2, SaleEventClaim::where('buyer_id', $buyer->id)->count());
        $this->assertSame([1, 1], SaleEventVoucher::orderBy('id')->pluck('claimed_count')->all());

        // A claimed voucher stays in the event; an unclaimed one can be removed.
        $entry = SaleEventVoucher::where('voucher_id', $shopVoucher->id)->sole();
        $this->actingAs($this->shop->user)->delete("/seller/sale-events/{$event->id}/vouchers/{$entry->id}")->assertSessionHasErrors('voucher');
    }

    public function test_claims_wait_for_the_event_and_the_marketplace_shows_live_events_only(): void
    {
        $upcoming = $this->event('+1 day', '+2 days');
        $upcoming->update(['banner_path' => '/images/buyer/advertisements/christmas.webp']);
        app(SaleEventService::class)->addVoucher($this->admin, $upcoming, ['voucher_id' => $this->voucher()->id]);
        $buyer = $this->createApprovedUser('buyer');
        $this->actingAs($buyer)->post('/campaign/vouchers/'.SaleEventVoucher::sole()->id.'/claim')->assertSessionHas('error', 'This event has not started yet.');
        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('banners', fn ($banners) => collect($banners)->where('slot', 'event')->isEmpty()));

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('banners.0.slot', 'event')->where('banners.0.href', '/campaign/'.$upcoming->slug));
        $this->get('/campaign/no-such-event')->assertNotFound();
    }
}
