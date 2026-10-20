<?php

namespace Tests\Feature\Seller;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCampaignAction;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoucherCampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function draft(array $changes = []): array
    {
        return array_replace(['code' => 'SHOP'.strtoupper(Str::random(8)), 'name' => 'Bagoo shop savings',
            'description' => 'A real offer from this shop.', 'discount_type' => 'fixed', 'discount_value' => '100.00',
            'min_spend' => '500.00', 'max_discount' => '100.00', 'budget' => '5000.00',
            'usage_limit' => 50, 'per_buyer_limit' => 2, 'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addDays(7)->toIso8601String(), 'source_version' => 0, 'request_key' => (string) Str::uuid()], $changes);
    }

    private function action(string $action, Voucher $voucher, array $changes = []): array
    {
        return array_replace(['action' => $action, 'source_version' => $voucher->fresh()->campaign_version,
            'request_key' => (string) Str::uuid()], $changes);
    }

    private function published(?Shop $shop = null, array $changes = []): array
    {
        $shop ??= Shop::factory()->approved()->create();
        $payload = $this->draft($changes);
        $this->actingAs($shop->user)->post('/seller/vouchers', $payload)->assertSessionHas('success');
        $voucher = Voucher::where('code', strtoupper(trim($payload['code'])))->sole();
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('publish', $voucher))->assertSessionHas('success');

        return [$shop, $voucher->fresh(), $payload];
    }

    public function test_seller_can_save_publish_pause_resume_and_archive_complete_owned_terms(): void
    {
        [$shop, $voucher] = $this->published();
        $revision = $voucher->currentRevision;
        $this->assertSame('shop', $revision->funding_party);
        $this->assertSame(500000, $revision->budget_cents);
        $this->assertSame('active', app(VoucherCampaignService::class)->state($voucher));
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('pause', $voucher))->assertSessionHas('success');
        $this->assertSame('paused', app(VoucherCampaignService::class)->state($voucher->fresh()));
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('resume', $voucher))->assertSessionHas('success');
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('archive', $voucher))->assertSessionHas('success');
        $this->assertSame('archived', app(VoucherCampaignService::class)->state($voucher->fresh()));
        $this->assertSame($revision->getRawOriginal(), $revision->fresh()->getRawOriginal());
        $this->assertDatabaseCount('voucher_campaign_actions', 5);
        $this->assertSame($shop->id, $voucher->fresh()->shop_id);
    }

    public function test_code_normalization_precedes_uniqueness_and_generated_codes_are_valid(): void
    {
        $shop = Shop::factory()->approved()->create();
        $this->actingAs($shop->user)->post('/seller/vouchers', $this->draft(['code' => '  shopsave  ']))->assertSessionHas('success');
        $this->assertSame('SHOPSAVE', Voucher::sole()->code);
        $this->postJson('/seller/vouchers', $this->draft(['code' => 'shopsave']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->post('/seller/vouchers', $this->draft(['code' => '']))->assertSessionHas('success');
        $this->assertMatchesRegularExpression('/\ABGO[A-F0-9]{9}\z/', Voucher::latest('id')->first()->code);
    }

    public static function codeBoundaries(): array
    {
        return [['ABC', false], ['ABCD', true], ['ABCDEFGHIJKL', true], ['ABCDEFGHIJKLM', false],
            ['MCH 50', false], ['MCH-50', false], ['MCH_50', false], ['mch75', true]];
    }

    #[DataProvider('codeBoundaries')]
    public function test_new_codes_accept_only_four_to_twelve_letters_and_numbers(string $code, bool $accepted): void
    {
        $shop = Shop::factory()->approved()->create();
        $response = $this->actingAs($shop->user)->post('/seller/vouchers', $this->draft(['code' => $code]));
        if ($accepted) {
            $response->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame(strtoupper($code), Voucher::sole()->code);
        } else {
            $response->assertSessionHasErrors('code');
            $this->assertDatabaseCount('vouchers', 0);
            $this->assertDatabaseCount('voucher_campaign_actions', 0);
        }
    }

    public function test_existing_long_symbol_code_keeps_its_original_terms_and_can_save_a_new_draft(): void
    {
        $shop = Shop::factory()->approved()->create();
        $voucher = Voucher::factory()->funded()->create(['shop_id' => $shop->id, 'code' => 'OLDER_CODE-LONG12345']);
        $original = $voucher->currentRevision->getRawOriginal();
        $this->actingAs($shop->user)->patch('/seller/vouchers/'.$voucher->id,
            $this->draft(['code' => $voucher->code, 'source_version' => $voucher->fresh()->campaign_version]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('OLDER_CODE-LONG12345', $voucher->fresh()->code);
        $this->assertSame($original, $voucher->currentRevision->fresh()->getRawOriginal());
    }

    public function test_saved_draft_and_publication_requests_are_idempotent_and_changed_retries_conflict(): void
    {
        $shop = Shop::factory()->approved()->create();
        $data = $this->draft();
        $this->actingAs($shop->user)->post('/seller/vouchers', $data)->assertSessionHas('success');
        $this->post('/seller/vouchers', $data)->assertSessionHas('success');
        $this->assertDatabaseCount('vouchers', 1);
        $voucher = Voucher::sole();
        $publish = $this->action('publish', $voucher);
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $publish)->assertSessionHas('success');
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $publish)->assertSessionHas('success');
        $this->assertDatabaseCount('voucher_revisions', 1);
        $this->assertDatabaseCount('voucher_campaign_actions', 2);
        $this->postJson('/seller/vouchers/'.$voucher->id.'/actions', [...$publish, 'action' => 'pause'])->assertConflict();
        $this->postJson('/seller/vouchers', [...$data, 'budget' => '6000.00'])->assertConflict();
    }

    public function test_editing_published_terms_retains_the_old_revision_and_creates_a_separate_draft(): void
    {
        [, $voucher, $payload] = $this->published();
        $old = $voucher->currentRevision;
        $raw = $old->getRawOriginal();
        $edit = array_replace($payload, ['name' => 'A revised shop offer', 'discount_value' => '75.00',
            'max_discount' => '75.00', 'source_version' => $voucher->campaign_version, 'request_key' => (string) Str::uuid()]);
        $this->patch('/seller/vouchers/'.$voucher->id, $edit)->assertSessionHas('success');
        $this->assertSame($old->id, $voucher->fresh()->current_revision_id);
        $this->assertTrue($voucher->fresh()->is_active);
        $this->assertSame(7500, $voucher->fresh()->draft->terms['value_cents']);
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('publish', $voucher))->assertSessionHas('success');
        $this->assertSame($raw, $old->fresh()->getRawOriginal());
        $this->assertSame(2, $voucher->fresh()->currentRevision->revision_number);
        $this->assertSame(7500, $voucher->fresh()->currentRevision->value_cents);
        $this->assertNull($voucher->fresh()->draft);
    }

    public function test_budget_increases_retain_terms_uses_and_record_the_actor_reason_and_exact_ceiling(): void
    {
        [, $voucher] = $this->published(changes: ['budget' => '100.00']);
        $revision = $voucher->currentRevision;
        $raw = $revision->getRawOriginal();
        $revision->balance->update(['committed_cents' => 10000, 'redemption_count' => 1]);
        $this->assertSame('exhausted', app(VoucherCampaignService::class)->state($voucher->fresh()));
        $data = $this->action('increase_budget', $voucher, ['budget_increase' => '200.00', 'reason' => 'Extend this shop promotion.']);
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $data)->assertSessionHas('success');
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $data)->assertSessionHas('success');
        $this->assertSame(30000, $revision->fresh()->approvedBudget());
        $this->assertSame($raw, $revision->fresh()->getRawOriginal());
        $this->assertSame(1, $revision->balance->fresh()->redemption_count);
        $event = VoucherCampaignAction::where('action', 'budget_increased')->sole();
        $this->assertSame(10000, $event->before_state['approved_budget_cents']);
        $this->assertSame(30000, $event->after_state['approved_budget_cents']);
        $this->assertSame('active', $event->after_state['state']);
        $this->assertSame('Extend this shop promotion.', $event->reason);
        $this->assertSame($voucher->shop->user_id, $event->actor_id);
        $this->postJson('/seller/vouchers/'.$voucher->id.'/actions', $this->action('increase_budget', $voucher, ['budget_increase' => '10.00']))->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_scheduled_expired_and_exhausted_states_cannot_be_overridden_by_an_active_flag(): void
    {
        [, $scheduled] = $this->published(changes: ['starts_at' => now()->addDay()->toIso8601String()]);
        $this->assertSame('scheduled', app(VoucherCampaignService::class)->state($scheduled));
        $this->travel(8)->days();
        $this->assertSame('expired', app(VoucherCampaignService::class)->state($scheduled->fresh()));
        $this->postJson('/seller/vouchers/'.$scheduled->id.'/actions', $this->action('resume', $scheduled))->assertUnprocessable();
        $this->travelBack();
        [, $exhausted] = $this->published();
        $exhausted->currentRevision->balance->update(['redemption_count' => 50]);
        $this->assertSame('exhausted', app(VoucherCampaignService::class)->state($exhausted->fresh()));
        $this->postJson('/seller/vouchers/'.$exhausted->id.'/actions', $this->action('resume', $exhausted))->assertUnprocessable();
    }

    public function test_cross_shop_private_reads_and_writes_are_denied(): void
    {
        [, $voucher, $data] = $this->published();
        $other = Shop::factory()->approved()->create();
        $this->actingAs($other->user)->get('/seller/vouchers/'.$voucher->id)->assertNotFound();
        $this->patchJson('/seller/vouchers/'.$voucher->id, $data)->assertForbidden();
        $this->postJson('/seller/vouchers/'.$voucher->id.'/actions', $this->action('pause', $voucher))->assertForbidden();
        $this->assertTrue($voucher->fresh()->is_active);
    }

    public function test_pending_seller_and_restricted_shop_cannot_publish(): void
    {
        [$shop, $voucher] = $this->published();
        $shop->update(['status' => 'suspended']);
        $this->assertSame('unavailable', app(VoucherCampaignService::class)->state($voucher->fresh()));
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('pause', $voucher))->assertRedirect(route('seller.shops.index'));
        $this->assertTrue($voucher->fresh()->is_active);
        $shop->user->update(['kyc_status' => 'pending']);
        $this->post('/seller/vouchers', $this->draft())->assertRedirect('/pending-approval');
        $this->assertDatabaseCount('vouchers', 1);
    }

    #[DataProvider('unsafeTerms')]
    public function test_unsafe_terms_cannot_create_campaigns(array $change, string $error): void
    {
        $shop = Shop::factory()->approved()->create();
        $this->actingAs($shop->user)->postJson('/seller/vouchers', $this->draft($change))->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseCount('voucher_campaign_actions', 0);
    }

    public static function unsafeTerms(): array
    {
        return [
            [['discount_type' => 'percent', 'discount_value' => '91'], 'discount_value'],
            [['discount_type' => 'percent', 'discount_value' => '0'], 'discount_value'],
            [['discount_value' => '1e2'], 'discount_value'],
            [['discount_value' => '0.001'], 'discount_value'],
            [['min_spend' => '0'], 'min_spend'],
            [['budget' => '0'], 'budget'],
            [['max_discount' => '6000.00'], 'budget'],
            [['per_buyer_limit' => 51], 'per_buyer_limit'],
            [['usage_limit' => 0], 'usage_limit'],
            [['code' => 'bad code'], 'code'],
            [['code' => 'ＳＨＯＰ'], 'code'],
            [['starts_at' => 'invalid'], 'starts_at'],
            [['ends_at' => '2000-01-01'], 'ends_at'],
            [['discount_type' => 'free_shipping'], 'discount_type'],
            [['funding_party' => 'platform'], 'funding_party'],
            [['issuer_type' => 'platform'], 'issuer_type'],
        ];
    }

    public function test_platform_admin_can_publish_capped_shipping_but_cannot_rewrite_a_seller_campaign(): void
    {
        [$shop, $voucher, $payload] = $this->published();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/vouchers', $this->draft(['code' => 'BGOSHIPPING', 'discount_type' => 'free_shipping', 'discount_value' => '0', 'max_discount' => '40.00']))->assertSessionHas('success');
        $platform = Voucher::where('code', 'BGOSHIPPING')->sole();
        $this->post('/admin/vouchers/'.$platform->id.'/actions', $this->action('publish', $platform))->assertSessionHas('success');
        $this->assertNull($platform->shop_id);
        $this->assertSame('platform', $platform->fresh()->currentRevision->funding_party);
        $this->patchJson('/admin/vouchers/'.$voucher->id, $payload)->assertForbidden();
        $this->postJson('/admin/vouchers/'.$voucher->id.'/actions', $this->action('publish', $voucher))->assertForbidden();
        $this->actingAs($shop->user)->postJson('/seller/vouchers/'.$platform->id.'/actions', $this->action('pause', $platform))->assertForbidden();
    }

    public function test_admin_suspension_needs_a_reason_notifies_the_seller_and_cannot_be_resumed_by_the_seller(): void
    {
        [$shop, $voucher] = $this->published();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/admin/vouchers/'.$voucher->id.'/actions', $this->action('suspend', $voucher))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->post('/admin/vouchers/'.$voucher->id.'/actions', $this->action('suspend', $voucher, ['reason' => 'Review the advertised promotion.']))->assertSessionHas('success');
        $this->assertNotNull($voucher->fresh()->suspended_at);
        $this->assertDatabaseHas('notification_deliveries', ['recipient_id' => $shop->user_id, 'type' => 'governance-event']);
        $this->actingAs($shop->user)->postJson('/seller/vouchers/'.$voucher->id.'/actions', $this->action('resume', $voucher))->assertUnprocessable();
        $this->assertFalse($voucher->fresh()->is_active);
        $this->actingAs($admin)->post('/admin/vouchers/'.$voucher->id.'/actions', $this->action('reinstate', $voucher, ['reason' => 'The seller clarified the offer.']))->assertSessionHas('success');
        $this->assertNull($voucher->fresh()->suspended_at);
        $this->assertFalse($voucher->fresh()->is_active);
        $this->actingAs($shop->user)->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('resume', $voucher))->assertSessionHas('success');
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_publish_platform_or_shop_campaigns(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor)->postJson('/admin/vouchers', $this->draft())->assertForbidden();
        $this->postJson('/seller/vouchers', $this->draft())->assertForbidden();
        $this->assertDatabaseCount('vouchers', 0);
    }

    public static function otherRoles(): array
    {
        return [['buyer'], ['courier'], ['logistics']];
    }

    public function test_reports_span_all_authorized_campaigns_instead_of_only_the_first_page(): void
    {
        $shop = Shop::factory()->approved()->create();
        Voucher::factory()->funded()->count(16)->create(['shop_id' => $shop->id]);
        Voucher::factory()->funded()->count(3)->create();
        $this->actingAs($shop->user)->get('/seller/vouchers')->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Vouchers')->has('campaigns.data', 15)->where('stats.campaigns', 16)->where('stats.active', 16)
            ->where('stats.saves', 0)->where('stats.redemptions', 0));
        $this->actingAs(User::factory()->admin()->create())->get('/admin/vouchers')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Vouchers', false)->where('stats.campaigns', 19));
    }

    public function test_new_quota_checks_use_the_approved_budget_increase_and_campaign_audit_is_immutable(): void
    {
        [$shop, $voucher] = $this->published(changes: ['budget' => '100.00']);
        $revision = $voucher->currentRevision;
        $revision->balance->update(['spent_cents' => 10000]);
        $buyer = User::factory()->create();
        $cart = Cart::create(['user_id' => $buyer->id]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => '500.00']);
        $items = collect([$cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '500.00'])->load('product.shop')]);
        $quotes = app(CheckoutQuoteService::class);
        $this->assertSame(0, $quotes->offers($items, buyer: $buyer)['count']);
        $this->post('/seller/vouchers/'.$voucher->id.'/actions', $this->action('increase_budget', $voucher, ['budget_increase' => '100.00', 'reason' => 'Fund one more affordable saving.']))->assertSessionHas('success');
        $this->assertSame(1, $quotes->offers($items, buyer: $buyer)['count']);
        $this->assertEquals(100, $quotes->quote($items, selection: collect([$revision->fresh()]), buyer: $buyer)['discount']);
        $this->expectException(QueryException::class);
        DB::table('voucher_campaign_actions')->where('action', 'budget_increased')->update(['budget_increase_cents' => 999]);
    }

    public function test_expired_draft_publication_and_stale_changes_cannot_create_a_revision(): void
    {
        $shop = Shop::factory()->approved()->create();
        $data = $this->draft(['starts_at' => now()->subDays(3)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()]);
        $this->actingAs($shop->user)->post('/seller/vouchers', $data)->assertSessionHas('success');
        $voucher = Voucher::sole();
        $this->postJson('/seller/vouchers/'.$voucher->id.'/actions', $this->action('publish', $voucher))->assertUnprocessable();
        $this->postJson('/seller/vouchers/'.$voucher->id.'/actions', $this->action('archive', $voucher, ['source_version' => 0]))->assertConflict();
        $this->assertDatabaseCount('voucher_revisions', 0);
        $this->assertDatabaseCount('voucher_campaign_actions', 1);
    }
}
