<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRevision;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\Vouchers\VoucherCampaignService;
use App\Services\Vouchers\VoucherChoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class BuyerVoucherSelectionTest extends TestCase
{
    use InteractsWithCheckoutNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function bag(array $prices = ['1000.00', '500.01']): array
    {
        $buyer = User::factory()->create();
        $cart = Cart::create(['user_id' => $buyer->id]);
        $items = collect();
        foreach ($prices as $price) {
            $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
            $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => $price, 'stock' => 10]);
            $items->push($cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00'])->load('product.shop'));
        }
        $this->createCheckoutNetwork($items->first()->product->shop, ['Pasig' => 'Metro Manila']);
        $this->actingAs($buyer);
        $payload = ['item_ids' => $items->pluck('id')->all(), 'delivery_type' => 'doorstep',
            'checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart),
            'recipient_name' => $buyer->name, 'recipient_phone' => '09171234567', 'shipping_address' => '123 Mabini Street',
            'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila', 'destination_barangay' => 'San Antonio', 'shipping_postal_code' => '1600'];

        return [$buyer, $items, $payload];
    }

    private function offer(?Shop $shop = null, array $terms = [], array $attributes = []): VoucherRevision
    {
        return Voucher::factory()->funded($terms)->create(array_replace(['shop_id' => $shop?->id, 'code' => 'BGO_'.strtoupper(Str::random(8)),
            'min_spend' => 0, 'discount_value' => 50, 'max_discount' => 50], $attributes))->currentRevision;
    }

    public function test_saving_removing_and_foreign_bookmarks_are_owned_idempotent_and_never_reserve(): void
    {
        [$buyer] = $this->bag();
        $offer = $this->offer();
        $id = $this->postJson(route('buyer.vouchers.save', $offer), [])->assertOk()->json('saved_id');
        $this->postJson(route('buyer.vouchers.save', $offer), [])->assertOk()->assertJsonPath('saved_id', $id);
        $this->assertDatabaseCount('saved_vouchers', 1);
        $this->assertSame(0, $offer->balance->fresh()->redemption_count);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertDatabaseCount('voucher_funding_entries', 0);
        $this->actingAs(User::factory()->create())->deleteJson(route('buyer.vouchers.remove', $id))->assertForbidden();
        $this->actingAs($buyer)->deleteJson(route('buyer.vouchers.remove', $id))->assertOk();
        $this->deleteJson(route('buyer.vouchers.remove', $id))->assertOk();
        $this->assertDatabaseCount('saved_vouchers', 0);
    }

    public function test_collection_counts_paginate_real_records_and_retain_archived_saved_terms(): void
    {
        [$buyer] = $this->bag();
        $offers = collect(range(1, 14))->map(fn () => $this->offer());
        $saved = $offers->first();
        $this->postJson(route('buyer.vouchers.save', $saved))->assertOk();
        $saved->voucher->update(['archived_at' => now(), 'is_active' => false]);
        $expired = $this->offer(terms: ['ends_at' => now()->subMinute()]);
        $expired->savedVouchers()->create(['buyer_id' => $buyer->id]);
        $other = User::factory()->create();
        $offers->last()->savedVouchers()->create(['buyer_id' => $other->id]);
        $this->get(route('buyer.profile', ['tab' => 'vouchers']))->assertInertia(fn (Assert $p) => $p
            ->where('voucherCollection.counts', ['available' => 13, 'saved' => 2, 'used' => 0, 'expired' => 1])
            ->has('voucherCollection.offers.data', 12)->where('voucherCollection.offers.total', 13));
        $this->get(route('buyer.profile', ['tab' => 'vouchers', 'voucher_tab' => 'saved']))->assertInertia(fn (Assert $p) => $p
            ->where('voucherCollection.tab', 'saved')->has('voucherCollection.offers.data', 2)
            ->where('voucherCollection.offers.data.1.code', $saved->code)
            ->where('voucherCollection.offers.data.1.eligible', false)
            ->where('voucherCollection.offers.data.1.unavailable_reason', 'This campaign has ended. Your saved terms remain available.')
            ->missing('voucherCollection.offers.data.1.budget_cents')->missing('voucherCollection.offers.data.1.funding_party'));
    }

    public function test_invalid_shop_selection_preserves_other_shop_platform_and_explicit_none(): void
    {
        [, $items, $payload] = $this->bag(['1000.00', '500.01', '400.00']);
        $first = $this->offer($items[0]->product->shop);
        $second = $this->offer($items[1]->product->shop);
        $platform = $this->offer();
        $first->voucher->update(['is_active' => false]);
        $payload += ['platform_voucher_revision_id' => $platform->id, 'shop_voucher_revision_ids' => [
            $first->shop_id => $first->id, $second->shop_id => $second->id, $items[2]->product->shop_id => null]];
        $this->postJson(route('buyer.vouchers.apply'), $payload)->assertUnprocessable()->assertJsonPath('quote.discount', 100)
            ->assertJsonPath('validSelection.platform_voucher_revision_id', $platform->id)
            ->assertJsonPath('validSelection.shop_voucher_revision_ids.'.$first->shop_id, null)
            ->assertJsonPath('validSelection.shop_voucher_revision_ids.'.$second->shop_id, $second->id)
            ->assertJsonPath('validSelection.shop_voucher_revision_ids.'.$items[2]->product->shop_id, null)
            ->assertJsonPath('selectionErrors.shop:'.$first->shop_id, 'This voucher is paused.');
        $this->post('/checkout', $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_code_replaces_its_own_slot_and_bad_code_retains_manual_choices(): void
    {
        [, $items, $payload] = $this->bag();
        $shop = $this->offer($items[0]->product->shop);
        $platform = $this->offer();
        $replacement = $this->offer(attributes: ['discount_value' => 30, 'max_discount' => 30]);
        $payload += ['platform_voucher_revision_id' => $platform->id, 'shop_voucher_revision_ids' => [$shop->shop_id => $shop->id]];
        $this->postJson(route('buyer.vouchers.apply'), $payload + ['code' => $replacement->code])->assertOk()
            ->assertJsonPath('validSelection.platform_voucher_revision_id', $replacement->id)->assertJsonPath('quote.discount', 80);
        $this->postJson(route('buyer.vouchers.apply'), $payload + ['code' => 'MISSING'])->assertUnprocessable()
            ->assertJsonPath('quote.discount', 100)->assertJsonPath('validSelection.platform_voucher_revision_id', $platform->id);
    }

    public function test_picker_explains_scheduled_expired_minimum_and_zero_shipping_without_private_funding(): void
    {
        [, $items, $payload] = $this->bag(['2000.00']);
        $this->offer(terms: ['starts_at' => now()->addDay()]);
        $this->offer(terms: ['ends_at' => now()->subMinute()]);
        $this->offer(attributes: ['min_spend' => 3000]);
        $this->offer(attributes: ['discount_type' => 'free_shipping']);
        $response = $this->postJson(route('buyer.vouchers.choices'), $payload)->assertOk()->assertJsonCount(4, 'offers.data');
        $this->assertEqualsCanonicalizing(['This voucher is not available yet.', 'This voucher has expired.',
            'Add ₱1,000.00 in selected products to use this voucher.', 'There is no shipping fee for this voucher to reduce.'],
            array_column($response->json('offers.data'), 'unavailable_reason'));
        $this->assertStringNotContainsString('budget_cents', $response->getContent());
        $this->postJson(route('buyer.vouchers.choices'), $payload + ['shop_id' => 99999])->assertUnprocessable();
    }

    public function test_best_combination_matches_exhaustive_caps_shipping_and_cent_remainders(): void
    {
        [$buyer, $items, $payload] = $this->bag(['1000.01', '500.02', '2000.00']);
        $a = [$this->offer($items[0]->product->shop), $this->offer($items[0]->product->shop, attributes: ['discount_value' => 30, 'max_discount' => 30])];
        $b = [$this->offer($items[1]->product->shop, attributes: ['discount_type' => 'percent', 'discount_value' => 15, 'max_discount' => 70])];
        $p = [$this->offer(attributes: ['discount_type' => 'percent', 'discount_value' => 10, 'max_discount' => 500]),
            $this->offer(attributes: ['discount_type' => 'free_shipping', 'discount_value' => 100, 'max_discount' => 75])];
        $expected = 0;
        foreach ([null, ...$a] as $first) {
            foreach ([null, ...$b] as $second) {
                foreach ([null, ...$p] as $platform) {
                    $quote = app(CheckoutQuoteService::class)->quote($items, 'doorstep', collect([$first, $second, $platform])->filter(), $buyer);
                    if (! $quote['voucher_error']) {
                        $expected = max($expected, array_sum(array_column($quote['redemptions'], 'discount_cents')));
                    }
                }
            }
        }
        $response = $this->postJson(route('buyer.vouchers.best'), $payload)->assertOk()->assertJsonPath('combinationsChecked', 18);
        $this->assertSame($expected, array_sum(array_column($response->json('quote.redemptions'), 'discount_cents')));
        $this->assertSame((int) round($response->json('quote.total') * 100), array_sum(array_map(fn ($s) => (int) round($s['total'] * 100), $response->json('quote.shops'))));
        $this->postJson(route('buyer.vouchers.best'), $payload)->assertOk()->assertJsonPath('selection', $response->json('selection'));
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertDatabaseCount('voucher_funding_entries', 0);
    }

    public function test_best_handles_budget_dependency_and_stable_equal_savings(): void
    {
        [, $items, $payload] = $this->bag(['100.00']);
        $first = $this->offer($items[0]->product->shop, attributes: ['discount_value' => 40, 'max_discount' => 40]);
        $this->offer($items[0]->product->shop, attributes: ['discount_value' => 40, 'max_discount' => 40]);
        $platform = $this->offer(terms: ['budget_cents' => 3000], attributes: ['discount_type' => 'percent', 'discount_value' => 50]);
        // This platform offer fails alone, but exactly fits its budget after the shop discount.
        $this->postJson(route('buyer.vouchers.best'), $payload)->assertOk()->assertJsonPath('quote.discount', 70)
            ->assertJsonPath('selection.shop_voucher_revision_ids.'.$first->shop_id, $first->id)
            ->assertJsonPath('selection.platform_voucher_revision_id', $platform->id);
    }

    public function test_best_does_not_claim_best_when_campaign_bound_is_exceeded(): void
    {
        [, , $payload] = $this->bag(['100.00']);
        foreach (range(1, VoucherChoiceService::MAX_CAMPAIGNS + 1) as $i) {
            $this->offer();
        }
        $this->postJson(route('buyer.vouchers.best'), $payload)->assertUnprocessable()->assertJsonMissingPath('selection');
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_best_rejects_excessive_combinations_without_truncating_eligible_campaigns(): void
    {
        [, $items, $payload] = $this->bag(array_fill(0, 8, '100.00'));
        foreach ($items as $item) {
            $this->offer($item->product->shop);
            $this->offer($item->product->shop);
        }
        $this->postJson(route('buyer.vouchers.best'), $payload)->assertUnprocessable()->assertJsonMissingPath('selection');
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_one_cent_shipping_remainder_stays_on_paid_parcels_and_zero_fee_shop_keeps_its_merchandise(): void
    {
        [, $items, $payload] = $this->bag(['100.00', '100.00', '2000.00']);
        $offer = $this->offer(attributes: ['discount_type' => 'free_shipping', 'discount_value' => '0.01', 'max_discount' => '0.01']);
        $this->postJson(route('buyer.vouchers.best'), $payload)->assertOk()->assertJsonPath('quote.discount', 0.01)
            ->assertJsonPath('quote.shops.0.shipping_discount', 0.01)->assertJsonPath('quote.shops.1.shipping_discount', 0)
            ->assertJsonPath('quote.shops.2.total', 2000)->assertJsonPath('quote.shops.2.discount', 0);
        $this->postJson(route('buyer.vouchers.best'), array_replace($payload, ['delivery_type' => 'hub_self_pickup']))->assertOk()
            ->assertJsonPath('selection.platform_voucher_revision_id', null)->assertJsonPath('quote.discount', 0);
    }

    public function test_product_shop_and_selected_bag_discover_only_their_shop_offers(): void
    {
        [, $items, $payload] = $this->bag();
        $first = $this->offer($items[0]->product->shop);
        $other = $this->offer($items[1]->product->shop);
        $this->get(route('products.show', $items[0]->product->slug))->assertInertia(fn (Assert $p) => $p
            ->has('shopOffers', 1)->where('shopOffers.0.revision_id', $first->id));
        $this->get(route('shop.show', $items[0]->product->shop->slug))->assertInertia(fn (Assert $p) => $p
            ->has('shopOffers', 1)->where('shopOffers.0.revision_id', $first->id));
        $response = $this->getJson(route('cart.index', ['item_ids' => [$items[0]->id]]))->assertOk();
        $this->assertSame([$first->id], array_column($response->json('availableVouchers'), 'revision_id'));
    }

    public function test_published_campaign_receipt_and_used_history_survive_pause_and_same_token_replay(): void
    {
        [$buyer, $items, $payload] = $this->bag(['1000.00']);
        $shop = $items[0]->product->shop;
        $service = app(VoucherCampaignService::class);
        $voucher = $service->saveDraft($shop->user, ['code' => 'SHOP_RECORDED', 'name' => 'Recorded offer', 'discount_type' => 'fixed',
            'discount_value' => '100.00', 'min_spend' => '500.00', 'max_discount' => '100.00', 'budget' => '1000.00',
            'usage_limit' => 10, 'per_buyer_limit' => 1, 'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addDay()->toIso8601String(), 'request_key' => (string) Str::uuid(), 'source_version' => 0], shop: $shop);
        $voucher = $service->act($shop->user, $voucher, ['action' => 'publish', 'request_key' => (string) Str::uuid(), 'source_version' => $voucher->campaign_version], $shop);
        $payload['shop_voucher_revision_ids'] = [$shop->id => $voucher->current_revision_id];
        $this->postJson(route('buyer.vouchers.save', $voucher->current_revision_id))->assertOk();
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $order = Order::sole();
        $service->act($shop->user, $voucher, ['action' => 'pause', 'request_key' => (string) Str::uuid(), 'source_version' => $voucher->fresh()->campaign_version], $shop);
        $voucher = $service->saveDraft($shop->user, ['code' => 'SHOP_RECORDED', 'name' => 'New campaign terms', 'discount_type' => 'fixed',
            'discount_value' => '50.00', 'min_spend' => '500.00', 'max_discount' => '50.00', 'budget' => '1000.00',
            'usage_limit' => 10, 'per_buyer_limit' => 1, 'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addDay()->toIso8601String(), 'request_key' => (string) Str::uuid(),
            'source_version' => $voucher->fresh()->campaign_version], $voucher, $shop);
        $service->act($shop->user, $voucher, ['action' => 'publish', 'request_key' => (string) Str::uuid(), 'source_version' => $voucher->campaign_version], $shop);
        $this->post('/checkout', $payload)->assertSessionHas('success');
        $this->assertDatabaseCount('orders', 1);
        $this->get(route('buyer.orders.show', $order))->assertInertia(fn (Assert $p) => $p
            ->where('order.voucher_receipt.discounts.0.code', 'SHOP_RECORDED')
            ->where('order.voucher_receipt.discounts.0.name', 'Recorded offer')
            ->where('order.voucher_receipt.discounts.0.product_discount', 100)
            ->where('order.voucher_receipt.total_savings', 100)->where('order.voucher_receipt.total', 950));
        $this->get(route('buyer.profile', ['tab' => 'vouchers', 'voucher_tab' => 'used']))->assertInertia(fn (Assert $p) => $p
            ->where('voucherCollection.counts.used', 1)->where('voucherCollection.offers.data.0.uses.0.saving', 100)
            ->where('voucherCollection.offers.data.0.uses.0.code', 'SHOP_RECORDED'));
        $this->actingAs(User::factory()->create())->get(route('buyer.orders.show', $order))->assertForbidden();
    }
}
