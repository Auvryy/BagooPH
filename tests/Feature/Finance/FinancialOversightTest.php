<?php

namespace Tests\Feature\Finance;

use App\Models\CodAccount;
use App\Models\CommissionLedger;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\SellerSettlement;
use App\Models\SellerSettlementEvent;
use App\Models\User;
use App\Services\Finance\CodCashService;
use App\Services\Finance\FinancialOversightService;
use App\Services\Finance\SellerSettlementService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use UnexpectedValueException;

class FinancialOversightTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function collected(): CodAccount
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'delivered');

        return CodAccount::where('delivery_id', $parcel->id)->sole();
    }

    private function cashCommand(CodAccount $cash, User $actor, string $action, array $input = [])
    {
        return app(CodCashService::class)->command($actor, $cash, $action, $input + [
            'request_token' => (string) Str::uuid(), 'expected_version' => $cash->fresh()->version,
            'evidence_reference' => 'FINANCE-COUNT-'.$cash->id, 'receipt_confirmed' => true,
            'reason' => 'The actual cash was counted and checked against the original handover.',
        ]);
    }

    private function remit(CodAccount $cash, User $admin, int $extra = 0): ?string
    {
        $handler = $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id));
        $rider = User::findOrFail($cash->collector_id);
        foreach ([[$rider, $handler], [$handler, $admin]] as [$holder, $recipient]) {
            $offer = $this->cashCommand($cash, $holder, 'offer', ['holder_id' => $holder->id, 'recipient_id' => $recipient->id,
                'amount' => SellerSettlementService::pesos($cash->expected_cents)]);
            $receipt = $this->cashCommand($cash, $recipient, 'receive', ['offer_reference' => $offer->reference,
                'received_amount' => SellerSettlementService::pesos($cash->expected_cents + ($recipient->isAdmin() ? $extra : 0))]);
        }

        return $receipt->reference;
    }

    private function release(CodAccount $cash, User $admin, bool $pay = true): void
    {
        $this->completeFlowOrder($cash->order);
        $this->remit($cash, $admin);
        $this->cashCommand($cash, $admin, 'reconcile');
        $this->actingAs($admin)->postJson('/seller-settlements/'.$cash->order_id.'/authorize', [
            'expected_version' => 0, 'request_token' => (string) Str::uuid(),
            'reason' => 'The original buyer confirmation and platform cash reconciliation match.'])->assertOk();
        if ($pay) {
            $this->postJson('/seller-settlements/'.$cash->order_id.'/record-payment', [
                'expected_version' => 1, 'request_token' => (string) Str::uuid(), 'payment_reference' => 'ACTUAL-SELLER-RECEIPT-'.$cash->id,
                'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('seller-receipt.png'),
                'reason' => 'The original seller received the product proceeds shown on the receipt.'])->assertOk();
        }
    }

    private function totals(User $actor, string $query = ''): array
    {
        return array_column($this->actingAs($actor)->getJson('/financial-oversight'.$query)->assertOk()->json('totals'), 'amount_cents', 'key');
    }

    public function test_real_cash_stages_and_product_payment_are_separate_and_traceable(): void
    {
        $admin = $this->createApprovedUser('admin');
        $cash = $this->collected();
        $due = (string) $cash->expected_cents;
        $totals = $this->totals($admin);
        $this->assertSame($due, $totals['collected']);
        $this->assertSame($due, $totals['held']);
        $this->assertSame('0', $totals['remitted']);
        $this->assertSame('0', $totals['reconciled']);
        $this->assertSame('0', $totals['settled']);
        $this->release($cash, $admin);
        $record = SellerSettlement::sole();
        $totals = $this->totals($admin);
        $this->assertSame($due, $totals['collected']);
        $this->assertSame('0', $totals['held']);
        $this->assertSame($due, $totals['remitted']);
        $this->assertSame($due, $totals['reconciled']);
        $this->assertSame((string) $record->seller_cents, $totals['settled']);
        $this->assertSame((string) $record->commission_cents, $totals['commission']);
        $this->assertSame((string) $cash->shipping_cents, $totals['shipping_charges']);
        $this->assertNull($totals['shipping_income']);
        $this->assertNull($totals['rider_paid']);
        $evidence = $this->getJson('/financial-oversight/'.$cash->order_id)->assertOk()->json('record');
        $this->assertSame($cash->reference, $evidence['cash']['reference']);
        $this->assertSame($cash->fresh()->events()->latest('sequence')->value('reference'), $evidence['cash']['journal_reference']);
        $this->assertSame($record->reference, $evidence['proceeds']['reference']);
        $this->assertSame(DeliveryCheckpoint::findOrFail($record->buyer_checkpoint_id)->record_reference, $evidence['proceeds']['buyer_reference']);
        $encoded = json_encode($evidence);
        foreach (['proof_path', 'proof_hash', 'request_token', 'request_fingerprint', 'private_evidence', 'can_reconcile', 'can_release'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('finance.0.amount_cents', (string) $record->commission_cents)
            ->where('finance.1.amount_cents', $due)->where('finance.2.amount_cents', (string) $record->seller_cents)
            ->where('finance.3.amount', null)->where('finance.4.amount', null));
        $this->get('/admin/logistics')->assertInertia(fn (Assert $page) => $page->where('finance.2.amount_cents', (string) $record->seller_cents));
    }

    public function test_pending_eligible_authorized_and_settled_require_their_distinct_sources(): void
    {
        $admin = $this->createApprovedUser('admin');
        $cash = $this->collected();
        $share = (string) SellerSettlementService::split($cash->product_subtotal_cents)['seller_cents'];
        $this->assertSame($share, $this->totals($admin)['pending']);
        $this->assertSame('0', $this->totals($admin)['eligible']);
        $this->completeFlowOrder($cash->order);
        $this->remit($cash, $admin);
        $this->cashCommand($cash, $admin, 'reconcile');
        $totals = $this->totals($admin);
        $this->assertSame($share, $totals['eligible']);
        $this->assertSame($share, $totals['pending']);
        $this->assertSame('0', $totals['authorized']);
        $this->actingAs($admin)->postJson('/seller-settlements/'.$cash->order_id.'/authorize', [
            'expected_version' => 0, 'request_token' => (string) Str::uuid(),
            'reason' => 'The buyer confirmation and cash receipt match the original product proceeds.'])->assertOk();
        $totals = $this->totals($admin);
        $this->assertSame($share, $totals['authorized']);
        $this->assertSame('0', $totals['eligible']);
        $this->assertSame('0', $totals['settled']);
    }

    public function test_total_links_and_state_filters_reproduce_only_the_included_sources(): void
    {
        $admin = $this->createApprovedUser('admin');
        $paid = $this->collected();
        $this->release($paid, $admin);
        $held = $this->collected();
        $page = $this->actingAs($admin)->getJson('/financial-oversight')->assertOk();
        $this->assertSame((string) ($paid->expected_cents + $held->expected_cents), collect($page->json('totals'))->firstWhere('key', 'collected')['amount_cents']);
        foreach (['settled', 'held', 'commission', 'reconciled'] as $key) {
            $total = collect($page->json('totals'))->firstWhere('key', $key);
            $rows = $this->getJson($total['url'])->assertOk()->json('records.data');
            $sum = array_sum(array_column(array_column($rows, 'metrics'), $key));
            $this->assertSame($total['amount_cents'], (string) $sum);
            $this->assertCount(1, $rows);
        }
        $this->getJson('/financial-oversight?state=settled')->assertJsonPath('records.total', 1)
            ->assertJsonPath('records.data.0.order_id', $paid->order_id);
        $this->getJson('/financial-oversight?state=cash_held')->assertJsonPath('records.data.0.order_id', $held->order_id);
        $this->release($held, $admin);
        $totals = $this->totals($admin);
        $this->assertSame((string) SellerSettlement::sum('seller_cents'), $totals['settled']);
        $this->assertSame((string) SellerSettlement::sum('commission_cents'), $totals['commission']);
    }

    public function test_seller_and_company_scopes_hide_foreign_finances_and_proofs(): void
    {
        $admin = $this->createApprovedUser('admin');
        $cash = $this->collected();
        $this->release($cash, $admin);
        $seller = User::findOrFail(SellerSettlement::sole()->seller_id);
        $foreignSeller = $this->createApprovedUser('seller');
        $company = LogisticsCompany::findOrFail($cash->logistics_company_id);
        $companyAdmin = $company->user;
        $foreignCompany = LogisticsCompany::create(['user_id' => $this->createApprovedUser('logistics')->id,
            'name' => 'Bagoo Other Network', 'slug' => 'other-financial-network', 'code' => 'OTHER-FINANCE', 'status' => 'active', 'is_active' => true]);
        $this->actingAs($foreignSeller)->getJson('/financial-oversight/'.$cash->order_id)->assertNotFound();
        $this->actingAs($foreignCompany->user)->getJson('/financial-oversight/'.$cash->order_id)->assertNotFound();
        $response = $this->actingAs($seller)->getJson('/financial-oversight/'.$cash->order_id)->assertOk();
        $response->assertJsonPath('record.cash', null)->assertJsonPath('record.proceeds.status', 'settled');
        $this->assertArrayNotHasKey('held', $response->json('record.metrics'));
        $this->getJson('/financial-oversight?recipient='.$foreignSeller->id)->assertForbidden();
        $this->getJson('/financial-oversight?company='.$company->id)->assertForbidden();
        $response = $this->actingAs($companyAdmin)->getJson('/financial-oversight/'.$cash->order_id)->assertOk();
        $response->assertJsonPath('record.proceeds', null);
        $this->assertArrayNotHasKey('settled', $response->json('record.metrics'));
        $this->assertStringNotContainsString('/proof/', $response->getContent());
        $this->getJson('/financial-oversight?company='.$foreignCompany->id)->assertForbidden();
        $this->getJson('/financial-oversight?recipient='.$seller->id)->assertForbidden();
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($cash->hub_id)))->getJson('/financial-oversight')->assertForbidden();
        $this->actingAs($cash->order->buyer)->getJson('/financial-oversight')->assertForbidden();
        $this->actingAs(User::findOrFail($cash->collector_id))->getJson('/financial-oversight')->assertForbidden();
        $admin->update(['status' => 'suspended']);
        $this->actingAs($admin)->getJson('/financial-oversight')->assertForbidden();
    }

    public function test_current_admin_filters_by_original_company_and_recipient_without_scope_escape(): void
    {
        $admin = $this->createApprovedUser('admin');
        $cash = $this->collected();
        $this->release($cash, $admin);
        $recipient = SellerSettlement::sole()->seller_id;
        $foreign = $this->createApprovedUser('seller');
        $this->actingAs($admin)->getJson('/financial-oversight?company='.$cash->logistics_company_id.'&recipient='.$recipient)
            ->assertOk()->assertJsonPath('records.total', 1);
        $this->getJson('/financial-oversight?company='.$cash->logistics_company_id.'&recipient='.$foreign->id)
            ->assertOk()->assertJsonPath('records.total', 0);
    }

    public function test_manila_collection_date_bounds_and_ordered_pagination_are_stable(): void
    {
        $admin = $this->createApprovedUser('admin');
        $this->travelTo(Carbon::parse('2026-10-08 15:59:59', 'UTC'));
        $before = $this->collected();
        $this->travelTo(Carbon::parse('2026-10-08 16:00:00', 'UTC'));
        $included = $this->collected();
        $this->travelTo(Carbon::parse('2026-10-09 16:00:00', 'UTC'));
        $after = $this->collected();
        $this->actingAs($admin)->getJson('/financial-oversight?from=2026-10-09&to=2026-10-09')
            ->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.order_id', $included->order_id);
        $this->getJson('/financial-oversight?to=2026-10-08')->assertOk()->assertJsonPath('records.total', 1)
            ->assertJsonPath('records.data.0.order_id', $before->order_id);
        for ($i = 0; $i < 11; $i++) {
            $this->newFlowOrder();
        }
        $first = $this->actingAs($admin)->getJson('/financial-oversight')->assertOk()->json('records');
        $second = $this->getJson($first['next_page_url'])->assertOk()->json('records');
        $this->assertSame(14, $first['total']);
        $this->assertCount(12, $first['data']);
        $this->assertCount(2, $second['data']);
        $this->assertSame([], array_intersect(array_column($first['data'], 'order_id'), array_column($second['data'], 'order_id')));
        $this->assertSame($after->order_id, $first['data'][11]['order_id']);
        $this->travelBack();
    }

    public function test_bad_filters_are_rejected_instead_of_silently_changing_the_scope(): void
    {
        $admin = $this->createApprovedUser('admin');
        foreach (['from=2026-02-30', 'to=tomorrow', 'from=2026-10-10&to=2026-10-09', 'state=paid',
            'company=not-a-company', 'recipient=999999', 'page=0', 'metric=revenue', 'metric=shipping_income'] as $query) {
            $this->actingAs($admin)->getJson('/financial-oversight?'.$query)->assertUnprocessable();
        }
    }

    public function test_closed_subject_and_changed_current_order_do_not_erase_recorded_paid_history(): void
    {
        $admin = $this->createApprovedUser('admin');
        $cash = $this->collected();
        $this->release($cash, $admin);
        $record = SellerSettlement::sole();
        User::findOrFail($record->seller_id)->update(['status' => 'inactive', 'name' => 'Closed account', 'closed_at' => now()]);
        $cash->order->update(['status' => 'returned', 'subtotal' => '-1.00', 'payment_method' => 'card', 'created_at' => now()->subYears(5)]);
        $response = $this->actingAs($admin)->getJson('/financial-oversight?state=settled&recipient='.$record->seller_id)->assertOk();
        $response->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.proceeds.seller_name', $record->snapshot['seller_name'])
            ->assertJsonPath('records.data.0.recorded_at', $cash->created_at->toIso8601String());
        $this->assertSame((string) $record->seller_cents, collect($response->json('totals'))->firstWhere('key', 'settled')['amount_cents']);
    }

    public function test_company_history_survives_company_restriction_without_cash_write_authority(): void
    {
        $cash = $this->collected();
        $company = LogisticsCompany::findOrFail($cash->logistics_company_id);
        $company->update(['status' => 'suspended', 'is_active' => false]);
        $cash->delivery->update(['logistics_company_id' => null]);
        $this->actingAs($company->user)->getJson('/financial-oversight')->assertOk()
            ->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.cash.company_id', $company->id);
        $this->postJson('/financial-oversight/'.$cash->order_id, ['status' => 'settled'])->assertStatus(405);
    }

    public function test_unverified_legacy_labels_are_unavailable_while_empty_supported_sources_are_true_zero(): void
    {
        $admin = $this->createApprovedUser('admin');
        $totals = $this->totals($admin);
        $this->assertSame('0', $totals['collected']);
        $this->assertSame('0', $totals['settled']);
        $order = $this->newFlowOrder();
        $order->update(['status' => 'completed', 'payment_status' => 'paid']);
        CommissionLedger::factory()->create(['order_id' => $order->id, 'status' => 'settled']);
        $totals = $this->totals($admin);
        foreach (['collected', 'held', 'remitted', 'reconciled', 'settled', 'commission'] as $key) {
            $this->assertNull($totals[$key]);
        }
        $this->getJson('/financial-oversight')->assertJsonPath('records.data.0.proceeds.status', 'unverified');
    }

    public function test_missing_journal_and_query_failure_never_become_zero_totals(): void
    {
        $admin = $this->createApprovedUser('admin');
        $cash = $this->collected();
        $missing = $cash->fresh()->setRelation('events', collect());
        try {
            app(FinancialOversightService::class)->row($cash->order->setRelation('codAccount', $missing), $admin);
            $this->fail('A missing financial journal must remain unavailable.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString('unavailable', $exception->getMessage());
        }
        $failSource = true;
        DB::listen(function (QueryExecuted $query) use (&$failSource) {
            if ($failSource && str_contains($query->sql, 'cod_cash_events')) {
                throw new QueryException('sqlite', 'Controlled cash source failure', [], new RuntimeException('Controlled unavailable source'));
            }
        });
        $response = $this->actingAs($admin)->getJson('/financial-oversight')->assertStatus(503);
        $this->getJson('/financial-oversight/'.$cash->order_id)->assertStatus(503)
            ->assertJsonPath('message', 'Original financial evidence is unavailable. Try again after the source is restored.');
        $failSource = false;
        foreach ($response->json('totals') as $total) {
            $this->assertNull($total['amount_cents']);
        }
        $this->assertNotEmpty($response->json('error'));
        $this->assertStringNotContainsString('Controlled cash source failure', $response->getContent());
    }

    public function test_linked_cash_and_payment_corrections_keep_before_after_and_original_totals(): void
    {
        $admin = $this->createApprovedUser('admin');
        $cash = $this->collected();
        $source = $this->remit($cash, $admin, 100);
        $adjustment = $this->cashCommand($cash, $admin, 'adjust', ['source_reference' => $source,
            'adjustment_type' => 'overage_separated', 'amount' => '1.00']);
        $response = $this->actingAs($admin)->getJson('/financial-oversight/'.$cash->order_id)->assertOk();
        $entry = collect($response->json('record.cash.history'))->firstWhere('reference', $adjustment->reference);
        $this->assertSame($source, $entry['source_reference']);
        $this->assertSame('100', $entry['after']['excess']);
        $this->assertSame((string) $cash->expected_cents, $entry['after']['collected']);
        $this->assertSame('100', $this->totals($admin)['excess']);
        $paidCash = $this->collected();
        $this->release($paidCash, $admin);
        $original = SellerSettlementEvent::where('event_type', 'payment_recorded')->sole();
        $before = $this->totals($admin)['settled'];
        $this->actingAs($admin)->postJson('/seller-settlements/'.$paidCash->order_id.'/adjust-reference', [
            'expected_version' => 2, 'request_token' => (string) Str::uuid(), 'source_event_id' => $original->id,
            'payment_reference' => 'CORRECTED-PAYMENT-REFERENCE', 'payment_confirmed' => true,
            'proof' => UploadedFile::fake()->image('corrected-receipt.png'),
            'reason' => 'The receipt reference was corrected while preserving the original payment.'])->assertOk();
        $history = $this->getJson('/financial-oversight/'.$paidCash->order_id)->assertOk()->json('record.proceeds.history');
        $this->assertSame($original->reference, $history[2]['source_reference']);
        $this->assertSame(0, $history[2]['amount_cents']);
        $this->assertSame($before, $this->totals($admin)['settled']);
    }

    public function test_oversight_has_no_financial_mutations_and_reads_leave_sources_unchanged(): void
    {
        $cash = $this->collected();
        $admin = $this->createApprovedUser('admin');
        $before = $cash->fresh()->getAttributes();
        $eventCount = $cash->events()->count();
        $this->actingAs($admin)->getJson('/financial-oversight')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/financial-oversight/'.$cash->order_id)->assertOk();
        foreach (['postJson', 'patchJson', 'deleteJson'] as $method) {
            $this->$method('/financial-oversight/'.$cash->order_id, ['amount' => '1.00'])->assertStatus(405);
        }
        $this->assertSame($before, $cash->fresh()->getAttributes());
        $this->assertSame($eventCount, $cash->events()->count());
        $this->assertDatabaseCount('seller_settlements', 0);
    }

    public function test_cancelled_order_is_not_an_unpaid_seller_obligation(): void
    {
        $order = $this->newFlowOrder();
        $this->actingAs($order->items->first()->shop->user)->post(route('seller.orders.cancel', $order), ['reason' => 'Buyer requested cancellation via chat', 'notes' => 'The buyer requested cancellation before preparation.'])->assertSessionHas('success');
        $this->assertSame('cancelled', $order->fresh()->status);
        $admin = $this->createApprovedUser('admin');
        $this->assertSame('0', $this->totals($admin)['pending']);
        $this->getJson('/financial-oversight?state=void')->assertJsonPath('records.total', 1);
    }
}
