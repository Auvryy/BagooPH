<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\CheckoutSubmission;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherFundingEntry;
use App\Models\VoucherOrderAllocation;
use App\Models\VoucherRedemption;
use App\Models\VoucherRevision;
use App\Models\VoucherRevisionBalance;
use App\Services\Commerce\CommerceInputService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\Vouchers\SavedVoucherService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class VoucherFundingFoundationTest extends TestCase
{
    use InteractsWithCheckoutNetwork, RefreshDatabase;

    private function checkout(array $prices = ['1000.00'], ?User $buyer = null): array
    {
        $buyer ??= User::factory()->create();
        $cart = Cart::firstOrCreate(['user_id' => $buyer->id]);
        $items = collect();
        foreach ($prices as $price) {
            $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
            $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => $price, 'stock' => 10]);
            $items->push($cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $price])->load('product.shop'));
        }
        if (! LogisticsCompany::where('slug', 'bagoo-checkout-network')->exists()) {
            $this->createCheckoutNetwork($items->first()->product->shop, ['Pasig' => 'Metro Manila']);
        }
        $payload = ['checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart), 'item_ids' => $items->pluck('id')->all(),
            'recipient_name' => $buyer->name, 'recipient_phone' => '09171234567', 'shipping_address' => '123 Mabini Street',
            'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1600',
            'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep', 'payment_method' => 'cod'];
        $this->actingAs($buyer);

        return [$buyer, $cart, $items, $payload];
    }

    private function offer(?Shop $shop = null, array $terms = [], array $attributes = []): VoucherRevision
    {
        return Voucher::factory()->funded($terms)->create(array_replace(['shop_id' => $shop?->id, 'code' => 'BAGOO_'.strtoupper(Str::random(8)),
            'min_spend' => 0, 'discount_value' => 100, 'max_discount' => null], $attributes))->currentRevision;
    }

    private function preview(array $payload)
    {
        return $this->postJson(route('buyer.vouchers.apply'), $payload);
    }

    public function test_two_shop_offers_and_one_platform_offer_share_exact_immutable_allocations(): void
    {
        [$buyer, , $items, $input] = $this->checkout(['1000.00', '500.01']);
        $first = $this->offer($items[0]->product->shop);
        $second = $this->offer($items[1]->product->shop, attributes: ['discount_value' => 50]);
        $platform = $this->offer(terms: ['percentage_bps' => 1000], attributes: ['discount_type' => 'percent', 'discount_value' => 10]);
        $input += ['platform_voucher_revision_id' => $platform->id,
            'shop_voucher_revision_ids' => [$second->shop_id => $second->id, $first->shop_id => $first->id]];
        $quote = $this->preview($input)->assertOk()->assertJsonPath('quote.discount', 285)->assertJsonPath('quote.total', 1315.01)->json('quote');
        $this->post('/checkout', $input)->assertSessionHas('success');
        $orders = Order::with('items')->get()->keyBy(fn ($o) => $o->items->first()->shop_id);
        foreach ($quote['shops'] as $shop) {
            $this->assertEquals($shop['total'], $orders[$shop['shop_id']]->total_amount);
            $this->assertNull($orders[$shop['shop_id']]->voucher_id);
        }
        $this->assertDatabaseCount('voucher_redemptions', 3);
        $this->assertDatabaseCount('voucher_order_allocations', 4);
        $this->assertDatabaseCount('voucher_funding_entries', 4);
        foreach ([$first, $second, $platform] as $revision) {
            $this->assertSame(1, $revision->balance->fresh()->redemption_count);
            $this->assertSame(1, $revision->voucher->fresh()->used_count);
            $use = VoucherRedemption::where('voucher_revision_id', $revision->id)->sole();
            $this->assertSame($buyer->id, $use->buyer_id);
            $this->assertEquals($revision->terms(), $use->terms);
        }
        $this->assertSame(28500, (int) VoucherFundingEntry::sum('amount_cents'));
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->assertDatabaseCount('voucher_redemptions', 3);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_documented_funding_example_reconciles_without_changing_the_gross_split(): void
    {
        [, , $items, $input] = $this->checkout();
        $shop = $this->offer($items[0]->product->shop);
        $platform = $this->offer(attributes: ['discount_value' => 50]);
        $input += ['shop_voucher_revision_ids' => [$shop->shop_id => $shop->id], 'platform_voucher_revision_id' => $platform->id];
        $this->post('/checkout', $input)->assertSessionHas('success');
        $order = Order::with('items', 'delivery')->sole();
        $basis = app(SellerSettlementService::class)->eligibility($order);
        $this->assertSame('900.00', $order->total_amount);
        $this->assertSame(90000, $basis['seller_cents']);
        $this->assertSame(10000, $basis['commission_cents']);
        $this->assertSame(80000, $basis['net_seller_cents']);
        $this->assertSame(5000, $basis['net_commission_cents']);
        $this->assertSame(90000, $basis['net_seller_cents'] + $basis['net_commission_cents'] + $basis['shipping_cents']);
        $this->assertFalse($basis['eligible']);
        $this->assertContains('The buyer has not confirmed receipt through the documented order flow.', $basis['blockers']);
    }

    public function test_offer_cards_quote_their_own_saving_with_the_other_selected_slots(): void
    {
        [, , $items, $input] = $this->checkout();
        $shop = $this->offer($items[0]->product->shop);
        $platform = $this->offer(terms: ['percentage_bps' => 1000], attributes: ['discount_type' => 'percent', 'discount_value' => 10]);
        $input['shop_voucher_revision_ids'] = [$shop->shop_id => $shop->id];
        $response = $this->preview($input)->assertOk();
        $card = collect($response->json('availableVouchers'))->firstWhere('revision_id', $platform->id);
        $this->assertEquals(90, $card['saving']);
        $input['platform_voucher_revision_id'] = $platform->id;
        $this->preview($input)->assertJsonPath('quote.discount', 190);
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_original_stacked_checkout_replays_after_expiry_pause_and_exhaustion(): void
    {
        [, , $items, $input] = $this->checkout();
        $shop = $this->offer($items[0]->product->shop, ['global_limit' => 1, 'budget_cents' => 10000, 'ends_at' => now()->addHour()]);
        $platform = $this->offer(terms: ['global_limit' => 1, 'budget_cents' => 10000, 'ends_at' => now()->addHour()]);
        $input += ['shop_voucher_revision_ids' => [$shop->shop_id => $shop->id], 'platform_voucher_revision_id' => $platform->id];
        $this->post('/checkout', $input)->assertSessionHas('success');
        $before = Order::sole()->getRawOriginal();
        $shop->voucher->update(['is_active' => false]);
        $platform->voucher->update(['is_active' => false]);
        $this->travel(2)->days();
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->assertSame($before, Order::sole()->getRawOriginal());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('voucher_redemptions', 2);
        $input['platform_voucher_revision_id'] = null;
        $this->postJson('/checkout', $input)->assertConflict();
    }

    public static function incompleteTerms(): array
    {
        return [['budget_cents', null], ['budget_cents', 0], ['maximum_cents', null], ['global_limit', null],
            ['per_buyer_limit', null], ['per_buyer_limit', 101], ['ends_at', null], ['value_cents', 0]];
    }

    #[DataProvider('incompleteTerms')]
    public function test_incomplete_funding_terms_cannot_create_a_revision(string $field, mixed $value): void
    {
        $revision = $this->offer();
        $data = array_diff_key($revision->getAttributes(), array_flip(['id', 'created_at', 'updated_at']));
        $data['revision_number'] = 2;
        $data[$field] = $value;
        $this->expectException(ValidationException::class);
        VoucherRevision::create($data);
    }

    public function test_issuer_cannot_be_changed_or_escalated_by_deleting_the_shop(): void
    {
        [, , $items] = $this->checkout();
        $shop = $items[0]->product->shop;
        $revision = $this->offer($shop);
        foreach (['issuer', 'delete_shop'] as $attempt) {
            try {
                $attempt === 'issuer'
                    ? DB::table('vouchers')->where('id', $revision->voucher_id)->update(['issuer_type' => 'platform', 'shop_id' => null])
                    : $shop->delete();
                $this->fail('Shop funding cannot become platform funding.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertSame('shop', $revision->voucher->fresh()->issuer_type);
        $this->assertSame($shop->id, $revision->voucher->fresh()->shop_id);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_failed_funding_release_rolls_back_cancellation_stock_and_all_sibling_allocations(): void
    {
        [, , $items, $input] = $this->checkout(['500.00', '500.00']);
        $revision = $this->offer();
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        $orders = Order::orderBy('id')->get();
        $before = $orders->map->getRawOriginal()->all();
        $balance = $revision->balance->fresh()->getRawOriginal();
        Event::listen('eloquent.created: '.VoucherFundingEntry::class, fn ($entry) => $entry->event_type === 'release'
            ? throw new RuntimeException('Simulated funding release failure') : null);
        try {
            $this->actingAs($items[0]->product->shop->user)->post(route('seller.orders.cancel', $orders[0]),
                ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('error');
        } finally {
            Event::forget('eloquent.created: '.VoucherFundingEntry::class);
        }
        $this->assertSame($before, Order::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($balance, $revision->balance->fresh()->getRawOriginal());
        $this->assertSame(9, $items[0]->product->fresh()->stock);
        $this->assertSame(0, VoucherFundingEntry::where('event_type', 'release')->count());
        $this->post(route('seller.orders.cancel', $orders[0]), ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('success');
    }

    public function test_another_seller_cannot_release_a_funded_order_allocation(): void
    {
        [, , $items, $input] = $this->checkout(['500.00', '500.00']);
        $revision = $this->offer();
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->actingAs($items[1]->product->shop->user)->post(route('seller.orders.cancel', Order::orderBy('id')->first()),
            ['reason' => 'Out of stock / Inventory shortage'])->assertForbidden();
        $this->assertSame(10000, $revision->balance->fresh()->committed_cents);
        $this->assertSame(0, VoucherFundingEntry::where('event_type', 'release')->count());
    }

    public function test_claimed_custody_and_physical_return_do_not_release_funding_or_restore_uses(): void
    {
        [, , $items, $input] = $this->checkout();
        $revision = $this->offer();
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        $order = Order::with('delivery')->sole();
        $order->delivery->update(['courier_id' => User::factory()->courier()->create()->id]);
        $this->actingAs($items[0]->product->shop->user)->post(route('seller.orders.cancel', $order),
            ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('error');
        foreach (['delivery_failed', 'returned'] as $status) {
            $order->update(['status' => $status]);
            $this->post(route('seller.orders.cancel', $order), ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('error');
            $this->assertSame(10000, $revision->balance->fresh()->committed_cents);
            $this->assertSame(1, $revision->balance->fresh()->redemption_count);
        }
        $this->assertSame(0, VoucherFundingEntry::where('event_type', 'release')->count());
    }

    public function test_database_uniqueness_rejects_duplicate_redemption_allocation_and_commitment(): void
    {
        [, , , $input] = $this->checkout();
        $revision = $this->offer();
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        foreach (['voucher_redemptions', 'voucher_order_allocations', 'voucher_funding_entries'] as $table) {
            $row = (array) DB::table($table)->first();
            unset($row['id']);
            if ($table === 'voucher_funding_entries') {
                $row['reference'] = 'VFD-DUPLICATE-COMMITMENT';
            }
            try {
                DB::table($table)->insert($row);
                $this->fail('Duplicate checkout evidence must not be accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('UNIQUE', $exception->getMessage());
            }
            $this->assertDatabaseCount($table, 1);
        }
    }

    public function test_shipping_subsidy_uses_actual_fees_and_can_exceed_commission_within_budget(): void
    {
        [, , $items, $input] = $this->checkout(['100.00', '2000.00']);
        $revision = $this->offer(terms: ['budget_cents' => 5000], attributes: ['discount_type' => 'free_shipping', 'discount_value' => 50]);
        $input['platform_voucher_revision_id'] = $revision->id;
        $quote = $this->preview($input)->assertOk()->assertJsonPath('quote.shipping_discount', 50)->json('quote');
        $this->assertEquals([50, 0], array_column($quote['shops'], 'shipping_discount'));
        $this->post('/checkout', $input)->assertSessionHas('success');
        $order = Order::with('items', 'delivery')->orderBy('id')->first();
        $basis = app(SellerSettlementService::class)->eligibility($order);
        $this->assertSame(9000, $basis['net_seller_cents']);
        $this->assertSame(-4000, $basis['net_commission_cents']);
        $this->assertSame(5000, $basis['shipping_cents']);
        $this->assertSame('100.00', $order->total_amount);
    }

    public function test_budget_reserves_actual_savings_and_per_buyer_limit_counts_checkouts(): void
    {
        [$buyer, , , $input] = $this->checkout(['100.00', '100.00']);
        $revision = $this->offer(terms: ['budget_cents' => 10000, 'per_buyer_limit' => 1], attributes: ['discount_value' => 1000]);
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->preview($input)->assertUnprocessable()->assertJsonPath('message', 'This voucher has reached its available funding limit.');
        $other = $this->offer(terms: ['budget_cents' => 20000], attributes: ['discount_value' => 1000]);
        $input['platform_voucher_revision_id'] = $other->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->assertSame(20000, $other->balance->fresh()->committed_cents);
        $this->assertDatabaseCount('voucher_redemptions', 1);
        [, , , $next] = $this->checkout(['100.00'], $buyer);
        $next['platform_voucher_revision_id'] = $other->id;
        $this->preview($next)->assertUnprocessable()->assertJsonPath('message', 'You have reached your usage limit for this voucher.');
        $this->assertSame(1, $other->balance->fresh()->redemption_count);
    }

    public function test_global_limit_blocks_another_buyer_after_one_multi_shop_checkout(): void
    {
        [, , , $input] = $this->checkout(['100.00', '100.00']);
        $revision = $this->offer(terms: ['global_limit' => 1], attributes: ['discount_value' => 20]);
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        [, , , $next] = $this->checkout();
        $next['platform_voucher_revision_id'] = $revision->id;
        $this->preview($next)->assertUnprocessable()->assertJsonPath('message', 'This voucher has reached its usage limit.');
        $this->assertDatabaseCount('voucher_redemptions', 1);
    }

    public function test_saving_is_owned_removable_and_reserves_no_quota_or_budget(): void
    {
        [$buyer] = $this->checkout();
        $revision = $this->offer();
        $service = app(SavedVoucherService::class);
        $before = $revision->balance->getAttributes();
        $saved = $service->save($buyer, $revision);
        $this->assertSame($saved->id, $service->save($buyer, $revision)->id);
        $this->assertSame($before, $revision->balance->fresh()->getAttributes());
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertDatabaseCount('voucher_funding_entries', 0);
        try {
            $service->remove(User::factory()->create(), $saved);
            $this->fail('Another buyer cannot remove the bookmark.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $service->remove($buyer, $saved);
        $this->assertDatabaseCount('saved_vouchers', 0);
        $this->assertSame($before, $revision->balance->fresh()->getAttributes());
    }

    public function test_selection_maps_are_canonical_and_changed_input_does_not_redeem_again(): void
    {
        [, , $items, $input] = $this->checkout(['500.00', '500.00']);
        $a = $this->offer($items[0]->product->shop);
        $b = $this->offer($items[1]->product->shop);
        $input['shop_voucher_revision_ids'] = [$b->shop_id => (string) $b->id, $a->shop_id => (string) $a->id];
        $this->post('/checkout', $input)->assertSessionHas('success');
        $input['shop_voucher_revision_ids'] = [$a->shop_id => $a->id, $b->shop_id => $b->id];
        $this->post('/checkout', $input)->assertSessionHas('success');
        $input['shop_voucher_revision_ids'][$a->shop_id] = null;
        $this->postJson('/checkout', $input)->assertConflict();
        $this->assertDatabaseCount('voucher_redemptions', 2);
    }

    public static function invalidSelections(): array
    {
        return [['platform_array'], ['wrong_shop'], ['foreign_shop_key'], ['platform_in_shop'], ['duplicate'], ['two_platforms'], ['two_shop_offers']];
    }

    #[DataProvider('invalidSelections')]
    public function test_wrong_scope_and_excess_stacking_are_rejected_without_writes(string $kind): void
    {
        [, , $items, $input] = $this->checkout();
        $shop = $items[0]->product->shop;
        $a = $this->offer($shop);
        $platform = $this->offer();
        $other = $this->offer(Shop::factory()->approved()->create());
        $input += match ($kind) {
            'platform_array' => ['platform_voucher_revision_id' => [$platform->id]],
            'wrong_shop' => ['shop_voucher_revision_ids' => [$shop->id => $other->id]],
            'foreign_shop_key' => ['shop_voucher_revision_ids' => [$other->shop_id => null]],
            'platform_in_shop' => ['shop_voucher_revision_ids' => [$shop->id => $platform->id]],
            'duplicate' => ['platform_voucher_revision_id' => $platform->id, 'voucher_code' => $platform->code],
            'two_platforms' => ['platform_voucher_revision_id' => $platform->id, 'voucher_code' => $this->offer()->code],
            'two_shop_offers' => ['shop_voucher_revision_ids' => [$shop->id => $a->id], 'voucher_code' => $this->offer($shop)->code],
        };
        if ($kind === 'platform_array') {
            $this->postJson('/checkout', $input)->assertUnprocessable()->assertJsonValidationErrors('platform_voucher_revision_id');
        } else {
            $this->post('/checkout', $input)->assertSessionHas('error');
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertSame(0, (int) VoucherRevisionBalance::sum('committed_cents'));
    }

    public static function failureStages(): array
    {
        return [['redemption'], ['allocation'], ['commitment'], ['balance'], ['usage']];
    }

    #[DataProvider('failureStages')]
    public function test_late_failures_roll_back_orders_stock_redemptions_funding_and_result_links(string $stage): void
    {
        [, $cart, $items, $input] = $this->checkout();
        $revision = $this->offer();
        $input['platform_voucher_revision_id'] = $revision->id;
        $event = match ($stage) {
            'redemption' => 'eloquent.created: '.VoucherRedemption::class,
            'allocation' => 'eloquent.created: '.VoucherOrderAllocation::class,
            'commitment' => 'eloquent.created: '.VoucherFundingEntry::class,
            'balance' => 'eloquent.updating: '.VoucherRevisionBalance::class,
            'usage' => 'eloquent.updating: '.Voucher::class,
        };
        Event::listen($event, fn () => throw new RuntimeException('Simulated storage failure'));
        try {
            $this->post('/checkout', $input)->assertSessionHas('error');
        } finally {
            Event::forget($event);
        }
        foreach (['orders', 'order_items', 'deliveries', 'checkout_submissions', 'checkout_submission_orders', 'voucher_redemptions', 'voucher_order_allocations', 'voucher_funding_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(10, $items[0]->product->fresh()->stock);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(0, $revision->balance->fresh()->committed_cents);
        $this->assertSame(0, $revision->voucher->fresh()->used_count);
        $this->post('/checkout', $input)->assertSessionHas('success');
    }

    public function test_partial_cancellation_releases_only_the_cancelled_order_and_never_restores_uses(): void
    {
        [$buyer, , $items, $input] = $this->checkout(['500.00', '500.00']);
        $revision = $this->offer(terms: ['budget_cents' => 10000]);
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        $orders = Order::orderBy('id')->get();
        $sibling = $orders[1]->getAttributes();
        $seller = $items[0]->product->shop->user;
        $this->actingAs($seller)->post(route('seller.orders.cancel', $orders[0]), ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('success');
        $balance = $revision->balance->fresh();
        $this->assertSame(5000, $balance->committed_cents);
        $this->assertSame(5000, $balance->released_cents);
        $this->assertSame(1, $balance->redemption_count);
        $this->assertSame(1, $revision->voucher->fresh()->used_count);
        $this->assertSame($sibling, $orders[1]->fresh()->getAttributes());
        $this->post(route('seller.orders.cancel', $orders[0]), ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('error');
        $this->assertSame(1, VoucherFundingEntry::where('event_type', 'release')->count());
        $this->actingAs($buyer)->post('/checkout', $input)->assertSessionHas('success');
        $this->assertSame('cancelled', $orders[0]->fresh()->status);
        $this->assertSame(10, $items[0]->product->fresh()->stock);
    }

    public function test_buyer_cancellation_releases_only_its_order_funding_once_with_the_buyer_as_actor(): void
    {
        [$buyer, , $items, $input] = $this->checkout(['1000.00', '500.00']);
        $offer = $this->offer(attributes: ['discount_value' => 90, 'max_discount' => 90]);
        $input['platform_voucher_revision_id'] = $offer->id;
        $this->post(route('checkout.store'), $input)->assertSessionHas('success');
        $orders = Order::orderBy('id')->get();
        $order = $orders->first();
        $sibling = $orders->last();
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $allocation = VoucherOrderAllocation::where('order_id', $order->id)->firstOrFail();
        $release = $allocation->fundingEntries()->where('event_type', 'release')->sole();
        $this->assertSame($buyer->id, $release->actor_id);
        $this->assertSame(1, $offer->voucher->fresh()->used_count);
        $this->assertSame('placed', $sibling->fresh()->status);
        $this->assertSame(10, $items[0]->product->fresh()->stock);
        $this->assertSame(9, $items[1]->product->fresh()->stock);
        $this->assertSame(0, VoucherOrderAllocation::where('order_id', $sibling->id)->firstOrFail()->fundingEntries()->where('event_type', 'release')->count());
        $this->assertSame(3000, $offer->balance->fresh()->committed_cents);
    }

    public function test_shop_savings_cannot_exceed_the_original_seller_share(): void
    {
        [, , $items, $input] = $this->checkout(['100.00']);
        $offer = $this->offer($items[0]->product->shop, attributes: ['discount_value' => 1000]);
        $input['shop_voucher_revision_ids'] = [$offer->shop_id => $offer->id];
        $this->preview($input)->assertOk()->assertJsonPath('quote.discount', 90)->assertJsonPath('quote.total', 60);
        $this->post('/checkout', $input)->assertSessionHas('success');
        $basis = app(SellerSettlementService::class)->eligibility(Order::with('items', 'delivery')->sole());
        $this->assertSame(0, $basis['net_seller_cents']);
        $this->assertSame(1000, $basis['commission_cents']);
    }

    public function test_funding_terms_are_required_and_shop_shipping_is_deferred(): void
    {
        [, , $items] = $this->checkout();
        $this->expectException(ValidationException::class);
        $this->offer($items[0]->product->shop, attributes: ['discount_type' => 'free_shipping']);
    }

    public function test_funding_history_and_campaign_terms_cannot_be_rewritten_or_rolled_back(): void
    {
        [, , , $input] = $this->checkout();
        $revision = $this->offer();
        $input['platform_voucher_revision_id'] = $revision->id;
        $this->post('/checkout', $input)->assertSessionHas('success');
        foreach (['voucher_revisions', 'voucher_redemptions', 'voucher_order_allocations', 'voucher_funding_entries'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $operation === 'update' ? DB::table($table)->update(['created_at' => now()->subYear()]) : DB::table($table)->delete();
                    $this->fail('Voucher evidence must be immutable.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('immutable', $exception->getMessage());
                }
            }
        }
        $migration = require database_path('migrations/2026_10_11_100000_create_voucher_funding_records.php');
        $this->expectException(LogicException::class);
        $migration->down();
    }

    public function test_existing_orders_usage_and_version_one_submission_survive_migration_without_invented_funding(): void
    {
        [$buyer, $cart, $items, $input] = $this->checkout();
        $migration = require database_path('migrations/2026_10_11_100000_create_voucher_funding_records.php');
        $migration->down();
        $legacy = Voucher::factory()->create(['code' => 'OLDER_OFFER', 'shop_id' => $items[0]->product->shop_id, 'used_count' => 7]);
        $input['voucher_code'] = 'OLDER_OFFER';
        $service = app(CheckoutSubmissionService::class);
        $normalized = app(CommerceInputService::class)->checkout($input, $input['item_ids']);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'voucher_id' => $legacy->id,
            'subtotal' => 1000, 'shipping_fee' => 50, 'voucher_discount' => 100, 'total_amount' => 950]);
        $before = $order->fresh()->getRawOriginal();
        $submission = CheckoutSubmission::create(['buyer_id' => $buyer->id, 'cart_id' => $cart->id,
            'token_hash' => $service->tokenHash($buyer, $cart, $input['checkout_token']),
            'request_hash' => $service->fingerprint($normalized, 1), 'order_count' => 1, 'created_at' => now()]);
        $submission->orders()->attach($order->id);
        $migration->up();
        $this->assertSame($before, array_diff_key($order->fresh()->getRawOriginal(), ['voucher_accounting_version' => true]));
        $this->assertSame(7, $legacy->fresh()->used_count);
        $this->assertFalse($legacy->fresh()->is_active);
        $this->assertSame('legacy_unknown', $legacy->fresh()->issuer_type);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertDatabaseCount('voucher_funding_entries', 0);
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(7, $legacy->fresh()->used_count);
        $input['platform_voucher_revision_id'] = 999;
        $this->postJson('/checkout', $input)->assertConflict();
    }
}
