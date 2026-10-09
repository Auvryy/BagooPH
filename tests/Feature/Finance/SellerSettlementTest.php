<?php

namespace Tests\Feature\Finance;

use App\Models\CodAccount;
use App\Models\CommissionLedger;
use App\Models\LogisticsHub;
use App\Models\SellerSettlement;
use App\Models\SellerSettlementEvent;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class SellerSettlementTest extends TestCase
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
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'delivered');

        return CodAccount::where('delivery_id', $parcel->id)->sole();
    }

    private function reconcile(CodAccount $cash, User $admin, int $extraCents = 0): void
    {
        $rider = User::findOrFail($cash->collector_id);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id));
        $this->handover($cash, $rider, $handler);
        $receipt = $this->handover($cash, $handler, $admin, $extraCents);
        if ($extraCents > 0) {
            $this->postJson('/cash-handover/'.$cash->id.'/adjust', [
                'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(),
                'source_reference' => $receipt, 'adjustment_type' => 'overage_separated',
                'amount' => SellerSettlementService::pesos($extraCents), 'evidence_reference' => 'SEPARATE-EXTRA-CASH-001',
                'reason' => 'The extra money remains separately accountable to its holder.'])->assertOk();
        }
        $this->postJson('/cash-handover/'.$cash->id.'/reconcile', [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(),
            'evidence_reference' => 'COUNTED-COD-001', 'reason' => 'The original receipt and the counted platform money match.'])->assertOk();
    }

    private function handover(CodAccount $cash, User $holder, User $recipient, int $extraCents = 0): string
    {
        $offer = $this->actingAs($holder)->postJson('/cash-handover/'.$cash->id.'/offer', [
            'holder_id' => $holder->id, 'recipient_id' => $recipient->id,
            'amount' => SellerSettlementService::pesos($cash->expected_cents),
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(),
            'evidence_reference' => 'PLATFORM-HANDOVER-001'])->assertOk()->json('reference');

        return $this->actingAs($recipient)->postJson('/cash-handover/'.$cash->id.'/receive', [
            'offer_reference' => $offer, 'received_amount' => SellerSettlementService::pesos($cash->expected_cents + $extraCents),
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(),
            'evidence_reference' => 'PLATFORM-RECEIPT-001', 'receipt_confirmed' => true,
            'reason' => 'The counted platform receipt preserves the actual amount.'])->assertOk()->json('reference');
    }

    private function eligible(): array
    {
        $cash = $this->collected();
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($cash->order->buyer)->post('/buyer/orders/'.$cash->order_id.'/confirm')->assertSessionHas('success');
        $this->reconcile($cash, $admin);

        return [$cash, $admin];
    }

    private function input(int $version = 0, bool $payment = false): array
    {
        return ['expected_version' => $version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The original order, named recipient and payment evidence were reviewed.']
            + ($payment ? ['payment_reference' => 'SELLER-RECEIPT-001', 'payment_confirmed' => true,
                'proof' => UploadedFile::fake()->image('receipt.png')] : []);
    }

    public function test_authorization_is_not_payment_and_payment_retains_exact_sources_and_shipping(): void
    {
        [$cash, $admin] = $this->eligible();
        $order = $cash->order;
        $cashBefore = $cash->fresh()->getAttributes();
        $this->actingAs($admin)->getJson('/seller-settlements/'.$order->id)->assertOk()->assertJsonPath('record.status', 'eligible');
        $authorization = $this->input();
        $ref = $this->postJson('/seller-settlements/'.$order->id.'/authorize', $authorization)->assertOk()->json('reference');
        $record = SellerSettlement::sole();
        $this->assertSame($cash->id, $record->cod_account_id);
        $this->assertSame($cash->product_subtotal_cents, $record->seller_cents + $record->commission_cents);
        $this->assertSame($cash->shipping_cents, $record->shipping_cents);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->getJson('/seller-settlements/'.$order->id)->assertJsonPath('record.status', 'authorized');
        $this->postJson('/seller-settlements/'.$order->id.'/authorize', $authorization)->assertOk()->assertJsonPath('reference', $ref);
        $this->assertDatabaseCount('seller_settlements', 1);
        $payment = $this->input(1, true);
        $paid = $this->postJson('/seller-settlements/'.$order->id.'/record-payment', $payment)->assertOk()->json('reference');
        $this->postJson('/seller-settlements/'.$order->id.'/record-payment', $payment)->assertOk()->assertJsonPath('reference', $paid);
        $this->assertDatabaseCount('commission_ledgers', 1);
        $this->assertDatabaseCount('seller_settlement_events', 2);
        $this->assertSame(4, DB::table('notification_deliveries')->where('type', 'settlement-event')->count());
        $this->getJson('/seller-settlements/'.$order->id)->assertJsonPath('record.status', 'settled')
            ->assertJsonPath('record.legacy_ledger', null)
            ->assertJsonPath('record.history.1.amount_cents', $record->seller_cents)
            ->assertJsonPath('record.history.1.context.recipient_id', $record->seller_id);
        $this->assertSame($cashBefore, $cash->fresh()->getAttributes());
        $this->assertSame('completed', $order->fresh()->status);
        $event = SellerSettlementEvent::where('reference', $paid)->sole();
        $this->assertSame($event->proof_hash, hash_file('sha256', Storage::disk('local')->path($event->proof_path)));
        $this->assertSame(SellerSettlementService::pesos($record->seller_cents), CommissionLedger::sole()->seller_amount);
        $this->assertSame(2, DB::table('notification_deliveries')->where('type', 'settlement-event')->where('recipient_id', $record->seller_id)->count());
        $this->actingAs(User::findOrFail($record->seller_id))->get('/seller-settlements/'.$order->id.'/proof/'.$event->id)->assertOk();
    }

    public static function missingGates(): array
    {
        return [[false, false], [true, false], [false, true]];
    }

    #[DataProvider('missingGates')]
    public function test_missing_completion_or_reconciliation_cannot_authorize(bool $completed, bool $reconciled): void
    {
        $cash = $this->collected();
        $admin = $this->createApprovedUser('admin');
        if ($completed) {
            $this->actingAs($cash->order->buyer)->post('/buyer/orders/'.$cash->order_id.'/confirm')->assertSessionHas('success');
        }
        if ($reconciled) {
            $this->reconcile($cash, $admin);
        }
        $this->actingAs($admin)->postJson('/seller-settlements/'.$cash->order_id.'/authorize', $this->input())->assertConflict();
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public static function rounding(): array
    {
        return [[1, 0, 1], [5, 1, 4], [15, 2, 13], [10005, 1001, 9004], [99999999, 10000000, 89999999]];
    }

    #[DataProvider('rounding')]
    public function test_product_split_rounds_commission_once_and_preserves_every_cent(int $gross, int $commission, int $seller): void
    {
        $this->assertSame(['product_cents' => $gross, 'commission_cents' => $commission, 'seller_cents' => $seller], SellerSettlementService::split($gross));
    }

    public function test_status_only_completion_and_admin_confirmation_do_not_manufacture_buyer_evidence(): void
    {
        $cash = $this->collected();
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->post('/buyer/orders/'.$cash->order_id.'/confirm')->assertForbidden();
        $cash->order->update(['status' => 'completed']);
        $this->reconcile($cash, $admin);
        $this->postJson('/seller-settlements/'.$cash->order_id.'/authorize', $this->input())->assertConflict();
        $this->assertDatabaseCount('seller_settlements', 0);
    }

    public function test_separately_held_extra_cash_blocks_release_even_after_order_cod_reconciliation(): void
    {
        $cash = $this->collected();
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($cash->order->buyer)->post('/buyer/orders/'.$cash->order_id.'/confirm')->assertSessionHas('success');
        $this->reconcile($cash, $admin, 100);
        $this->assertNotNull($cash->fresh()->reconciled_at);
        $this->actingAs($admin)->postJson('/seller-settlements/'.$cash->order_id.'/authorize', $this->input())->assertConflict();
        $this->assertDatabaseCount('seller_settlements', 0);
    }

    public function test_duplicate_conflicting_stale_and_unproven_payment_requests_cannot_pay_twice(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/record-payment', $this->input(0, true))->assertConflict();
        $input = $this->input();
        $this->postJson($base.'/authorize', $input)->assertOk();
        foreach (['amount' => '1.00', 'commission_rate' => 0, 'recipient_id' => $admin->id, 'seller_id' => $admin->id,
            'status' => 'settled', 'seller_cents' => 1, 'shop_id' => 1, 'shipping_fee' => '0.00'] as $field => $value) {
            $this->postJson($base.'/authorize', $input + [$field => $value])->assertUnprocessable();
        }
        $this->postJson($base.'/authorize', array_replace($input, ['reason' => 'A different decision using the same request.']))->assertConflict();
        $this->postJson($base.'/authorize', $this->input(1))->assertConflict();
        $this->postJson($base.'/record-payment', $this->input(0, true))->assertConflict();
        $missing = $this->input(1, true);
        unset($missing['proof']);
        $this->postJson($base.'/record-payment', $missing)->assertUnprocessable();
        $missing = $this->input(1, true);
        unset($missing['payment_reference']);
        $this->postJson($base.'/record-payment', $missing)->assertUnprocessable();
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertOk();
        $this->postJson($base.'/record-payment', $this->input(2, true))->assertConflict();
        $this->assertDatabaseCount('commission_ledgers', 1);
    }

    public function test_restricted_recipient_and_changed_order_basis_block_the_recorded_release(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertOk();
        $seller = User::findOrFail(SellerSettlement::sole()->seller_id);
        $seller->update(['status' => 'suspended']);
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertConflict();
        $this->actingAs($seller)->getJson($base)->assertOk()->assertJsonPath('record.status', 'authorized');
        $seller->update(['status' => 'active']);
        $cash->order->update(['subtotal' => '0.01']);
        $this->actingAs($admin)->postJson($base.'/record-payment', $this->input(1, true))->assertConflict();
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_foreign_seller_company_and_unapproved_admin_cannot_read_or_mutate_proceeds(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertOk();
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertOk();
        $event = SellerSettlementEvent::where('event_type', 'payment_recorded')->sole();
        $foreign = $this->createApprovedUser('seller');
        $this->actingAs($foreign)->getJson($base)->assertNotFound();
        $this->get($base.'/proof/'.$event->id)->assertNotFound();
        $this->postJson($base.'/record-payment', $this->input(2, true))->assertForbidden();
        $this->actingAs($this->createApprovedUser('logistics'))->getJson($base)->assertForbidden();
        $this->postJson($base.'/authorize', $this->input())->assertForbidden();
        $admin->update(['status' => 'suspended']);
        $this->actingAs($admin)->getJson($base)->assertForbidden();
        $this->postJson($base.'/record-payment', $this->input(2, true))->assertForbidden();
    }

    public function test_audit_failure_rolls_back_payment_ledger_and_only_the_new_proof(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertOk();
        Storage::disk('local')->put('retained.txt', 'Original unrelated evidence.');
        $this->mock(NotificationDeliveryService::class)->shouldReceive('record')->andThrow(new RuntimeException('Controlled audit failure.'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($base.'/record-payment', $this->input(1, true));
            $this->fail('The forced audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled audit failure.', $exception->getMessage());
        }
        $this->assertDatabaseCount('seller_settlement_events', 1);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertSame(['retained.txt'], Storage::disk('local')->allFiles());
    }

    public function test_reference_correction_appends_to_payment_without_moving_money_or_rewriting_history(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertOk();
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertOk();
        $original = SellerSettlementEvent::where('event_type', 'payment_recorded')->sole();
        $before = $original->getAttributes();
        $input = array_replace($this->input(2, true), ['source_event_id' => $original->id, 'payment_reference' => 'CORRECTED-RECEIPT-002']);
        $ref = $this->postJson($base.'/adjust-reference', $input)->assertOk()->json('reference');
        $this->postJson($base.'/adjust-reference', $input)->assertOk()->assertJsonPath('reference', $ref);
        $correction = SellerSettlementEvent::where('reference', $ref)->sole();
        $this->assertSame($original->id, $correction->source_event_id);
        $this->assertSame(0, $correction->amount_cents);
        $this->assertSame('SELLER-RECEIPT-001', $correction->context['previous_reference']);
        $this->assertSame($before, $original->fresh()->getAttributes());
        $this->assertDatabaseCount('commission_ledgers', 1);
        $this->getJson($base)->assertJsonPath('record.history.2.source_reference', $original->reference);
        $this->assertNotContains('settlement_unverified', array_column(app(AccountClosureService::class)->state($cash->order->buyer)['blockers'], 'code'));
    }

    public function test_legacy_settled_label_is_not_proof_and_original_pending_ledger_is_preserved(): void
    {
        [$cash, $admin] = $this->eligible();
        $split = SellerSettlementService::split($cash->product_subtotal_cents);
        $ledger = CommissionLedger::create(['order_id' => $cash->order_id, 'seller_id' => $cash->order->items->first()->shop->user_id,
            'gross_amount' => SellerSettlementService::pesos($split['product_cents']),
            'seller_amount' => SellerSettlementService::pesos($split['seller_cents']),
            'platform_commission' => SellerSettlementService::pesos($split['commission_cents']),
            'delivery_fee' => SellerSettlementService::pesos($cash->shipping_cents), 'status' => 'settled']);
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertConflict();
        $this->getJson($base)->assertJsonPath('record.status', 'pending');
        $ledger->update(['status' => 'pending']);
        $before = $ledger->fresh()->getAttributes();
        $this->postJson($base.'/authorize', $this->input())->assertOk();
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertOk();
        $this->assertSame($before, $ledger->fresh()->getAttributes());
        $this->assertSame($ledger->id, SellerSettlement::sole()->legacy_ledger_id);
        $this->getJson($base)->assertJsonPath('record.status', 'settled')->assertJsonPath('record.legacy_ledger.status', 'pending');
    }

    public function test_sql_updates_cannot_change_original_settlement_payment_or_ledger(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertOk();
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertOk();
        foreach (['seller_settlements' => ['seller_cents' => 1], 'seller_settlement_events' => ['amount_cents' => 1],
            'commission_ledgers' => ['seller_amount' => '0.01']] as $table => $change) {
            try {
                DB::table($table)->update($change);
                $this->fail('An immutable financial source was updated.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_paid_history_uses_retained_basis_and_identity_after_current_records_change(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertOk();
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertOk();
        $record = SellerSettlement::sole();
        $seller = User::findOrFail($record->seller_id);
        $seller->update(['status' => 'inactive', 'name' => 'Retained account', 'closed_at' => now()]);
        $cash->order->update(['subtotal' => '-1.00', 'payment_method' => 'card']);
        $this->getJson($base)->assertOk()->assertJsonPath('record.status', 'settled')
            ->assertJsonPath('record.seller_name', $record->snapshot['seller_name'])
            ->assertJsonPath('record.product_cents', $record->product_cents)->assertJsonPath('record.buyer_completed', true);
    }

    public function test_unverifiable_or_changed_private_proof_cannot_be_served_as_original_evidence(): void
    {
        [$cash, $admin] = $this->eligible();
        $base = '/seller-settlements/'.$cash->order_id;
        $this->actingAs($admin)->postJson($base.'/authorize', $this->input())->assertOk();
        $this->postJson($base.'/record-payment', $this->input(1, true))->assertOk();
        $event = SellerSettlementEvent::where('event_type', 'payment_recorded')->sole();
        Storage::disk('local')->put($event->proof_path, 'Different content cannot replace the original receipt.');
        $this->get($base.'/proof/'.$event->id)->assertNotFound();
    }
}
