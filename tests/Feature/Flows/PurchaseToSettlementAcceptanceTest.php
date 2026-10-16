<?php

namespace Tests\Feature\Flows;

use App\Models\Cart;
use App\Models\CodAccount;
use App\Models\CommissionLedger;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherFundingEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class PurchaseToSettlementAcceptanceTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public static function completionOrderings(): array
    {
        return [
            'buyer confirms before cash arrives' => [true, false],
            'cash arrives before buyer confirms' => [false, false],
            'funded offers, buyer first' => [true, true],
            'funded offers, cash first' => [false, true],
        ];
    }

    #[DataProvider('completionOrderings')]
    public function test_http_purchase_custody_completion_and_cash_evidence_produce_one_exact_seller_payment(bool $buyerFirst, bool $funded): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $admin = $this->createApprovedUser('admin');
        $shop = $this->createE2EShop($seller);
        $first = $this->createE2EProduct($shop, ['price' => '120.05', 'stock' => 9]);
        $second = $this->createE2EProduct($shop, ['price' => '79.97', 'stock' => 6]);
        $this->flowNetwork($shop);

        foreach ([[$first, 2], [$second, 1]] as [$product, $quantity]) {
            $this->actingAs($buyer)->post(route('cart.store'), [
                'product_id' => $product->id, 'quantity' => $quantity,
                'unit_price' => '0.01', 'subtotal' => '0.01',
            ])->assertSessionHasNoErrors()->assertRedirect();
        }
        $cart = Cart::where('user_id', $buyer->id)->sole();
        $itemIds = $cart->items()->orderBy('id')->pluck('id')->all();
        $checkoutPage = $this->get('/checkout?'.http_build_query(['items' => $itemIds]))->assertOk();
        $checkoutPage->assertInertia(fn (Assert $page) => $page->component('Checkout/Index')
            ->where('quote.subtotal', 320.07)->where('quote.shipping_fee', 50)->where('quote.total', 370.07));
        $checkout = [
            'checkout_token' => $checkoutPage->inertiaProps('checkoutToken'), 'item_ids' => $itemIds,
            'recipient_name' => $buyer->name, 'recipient_phone' => '09171234567',
            'shipping_address' => '100 Market Road', 'shipping_city' => 'Santa Cruz',
            'shipping_province' => 'Laguna', 'shipping_postal_code' => '4009',
            'destination_barangay' => 'Poblacion III', 'delivery_type' => 'doorstep', 'payment_method' => 'cod',
            'subtotal' => '0.01', 'shipping_fee' => '0.00', 'total_amount' => '0.01',
        ];
        $offers = [];
        if ($funded) {
            $offers = [
                Voucher::factory()->funded()->fixed(20, 0)->create(['shop_id' => $shop->id]),
                Voucher::factory()->funded()->fixed(10, 0)->create(),
            ];
            $checkout += ['shop_voucher_revision_ids' => [$shop->id => $offers[0]->current_revision_id],
                'platform_voucher_revision_id' => $offers[1]->current_revision_id];
        }
        $this->post(route('checkout.store'), $checkout)->assertSessionHasNoErrors()
            ->assertRedirect(route('buyer.orders.index'));
        $order = Order::with('delivery', 'items')->sole();
        $parcel = $order->delivery;
        $this->assertSame(['320.07', '50.00', $funded ? '30.00' : '0.00', $funded ? '340.07' : '370.07'],
            [$order->subtotal, $order->shipping_fee, $order->voucher_discount, $order->total_amount]);
        $this->assertSame([7, 5], [$first->fresh()->stock, $second->fresh()->stock]);
        $this->assertSame(0, $cart->items()->count());
        $this->assertSame(['placed', 'pending', 'unassigned'], [$order->status, $order->payment_status, $parcel->status]);

        $this->assertReleaseBlocked($admin, $order);
        $this->actingAs($buyer)->post(route('buyer.orders.confirm', $order))->assertSessionHas('error');
        $this->assertSame('placed', $order->fresh()->status);
        $this->sellerFlowStage($order, 'ready_for_pickup');
        // These helpers perform the actual claim, pickup, hub scans, two manifests,
        // destination sorting, rider assignment and departure HTTP requests.
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertSame(2, LogisticsManifest::where('status', 'closed')->count());
        $this->assertNotSame($parcel->courier_id, $parcel->assigned_rider_id);
        $this->assertSame(['arrived_at_origin_hub', 'arrived_at_mother_hub', 'arrived_at_destination_hub'],
            $parcel->checkpoints()->whereIn('checkpoint_type', ['arrived_at_origin_hub', 'arrived_at_mother_hub', 'arrived_at_destination_hub'])
                ->orderBy('id')->pluck('checkpoint_type')->all());
        $this->assertReleaseBlocked($admin, $order);
        $this->assertDatabaseCount('cod_accounts', 0);

        $finalRider = User::findOrFail($parcel->assigned_rider_id);
        $deliveryInput = [...$this->codCollectionInput($parcel), 'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->image('recipient-receipt.jpg')];
        $this->actingAs($finalRider)->patch(route('courier.updateStatus', $parcel), $deliveryInput)
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $cash = CodAccount::where('order_id', $order->id)->sole();
        $cashCents = $funded ? 34007 : 37007;
        $this->assertSame($cashCents, $cash->expected_cents);
        $this->assertSame([32007, 5000, $funded ? 3000 : 0],
            [$cash->product_subtotal_cents, $cash->shipping_cents, $cash->discount_cents]);
        $this->assertSame(['delivered', 'pending'], [$order->fresh()->status, $order->fresh()->payment_status]);
        $this->assertSame(['rider:'.$finalRider->id => $cashCents], $cash->state['balances']);
        $this->assertTrue(Storage::disk('local')->exists($parcel->fresh()->proof_image));
        $this->assertReleaseBlocked($admin, $order);

        // Delivery retries return the original proof/collection, even after the
        // cash moves. They must never create a second recipient or cash receipt.
        $deliveryState = $parcel->fresh()->getRawOriginal();
        $collection = $cash->events()->sole()->getRawOriginal();
        $this->actingAs($finalRider)->patch(route('courier.updateStatus', $parcel), $deliveryInput)
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($deliveryState, $parcel->fresh()->getRawOriginal());
        $this->assertSame($collection, $cash->events()->sole()->getRawOriginal());

        if ($buyerFirst) {
            $this->confirmReceipt($buyer, $order);
            $this->assertSame('pending', $order->fresh()->payment_status);
            $this->assertReleaseBlocked($admin, $order);
        }
        $handler = $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id));
        $this->handover($cash, $finalRider, $handler);
        $this->assertSame($cashCents, $cash->fresh()->state['balances']['hub:'.$handler->id]);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertReleaseBlocked($admin, $order);
        $this->handover($cash, $handler, $admin);
        $this->assertSame($cashCents, $cash->fresh()->state['balances']['platform:'.$admin->id]);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertReleaseBlocked($admin, $order);
        $reconciliation = $this->cashInput($cash) + ['evidence_reference' => 'PLATFORM-COUNTED-CASH',
            'reason' => 'The original collection matches both counted handovers to the platform.'];
        $reference = $this->actingAs($admin)->postJson('/cash-handover/'.$cash->id.'/reconcile', $reconciliation)
            ->assertOk()->json('reference');
        $this->postJson('/cash-handover/'.$cash->id.'/reconcile', $reconciliation)
            ->assertOk()->assertJsonPath('reference', $reference);
        $this->assertSame('paid', $order->fresh()->payment_status);
        if (! $buyerFirst) {
            $this->assertSame('delivered', $order->fresh()->status);
            $this->assertReleaseBlocked($admin, $order);
            $this->confirmReceipt($buyer, $order);
        }
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $parcel->fresh()->status);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->actingAs($seller)->getJson('/seller-settlements/'.$order->id)->assertOk()
            ->assertJsonPath('record.status', 'eligible')->assertJsonPath('record.commission_cents', 3201)
            ->assertJsonPath('record.seller_cents', 28806)->assertJsonPath('record.shipping_cents', 5000)
            ->assertJsonPath('record.net_seller_cents', $funded ? 26806 : 28806);

        $before = $this->financialSnapshot($order);
        $this->actingAs($seller)->postJson('/seller-settlements/'.$order->id.'/authorize', $this->settlementInput(0))->assertForbidden();
        $foreignBuyer = $this->createApprovedUser('buyer');
        $this->actingAs($foreignBuyer)->post(route('buyer.orders.confirm', $order))->assertForbidden();
        $this->assertSame($before, $this->financialSnapshot($order));

        $authorization = $this->settlementInput(0);
        $reference = $this->actingAs($admin)->postJson('/seller-settlements/'.$order->id.'/authorize', $authorization)
            ->assertOk()->json('reference');
        $this->postJson('/seller-settlements/'.$order->id.'/authorize', $authorization)
            ->assertOk()->assertJsonPath('reference', $reference);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $payment = $this->settlementInput(1) + ['payment_reference' => 'SELLER-COUNTED-PAYMENT', 'payment_confirmed' => true,
            'proof' => UploadedFile::fake()->image('seller-payment.png')];
        $before = $this->financialSnapshot($order);
        $this->postJson('/seller-settlements/'.$order->id.'/record-payment', $payment + ['seller_amount' => '0.01'])
            ->assertUnprocessable()->assertJsonValidationErrors('seller_amount');
        $this->assertSame($before, $this->financialSnapshot($order));
        if ($funded && $buyerFirst) {
            // Failure after the evidence and promotional expenses were written
            // must restore the complete transaction before the same request retries.
            $inject = true;
            CommissionLedger::creating(function () use (&$inject): void {
                if ($inject) {
                    throw new RuntimeException('The proceeds writer is temporarily unavailable.');
                }
            });
            try {
                $this->withoutExceptionHandling()->postJson('/seller-settlements/'.$order->id.'/record-payment', $payment);
                $this->fail('A failed proceeds writer must not retain a partial seller payment.');
            } catch (RuntimeException $error) {
                $this->assertSame('The proceeds writer is temporarily unavailable.', $error->getMessage());
            } finally {
                $inject = false;
                $this->withExceptionHandling();
            }
            $this->assertSame($before, $this->financialSnapshot($order));
            foreach ($offers as $offer) {
                $balance = $offer->currentRevision->balance->fresh();
                $this->assertSame($offer->shop_id ? 2000 : 1000, $balance->committed_cents);
                $this->assertSame(0, $balance->spent_cents);
            }
        }
        $reference = $this->postJson('/seller-settlements/'.$order->id.'/record-payment', $payment)
            ->assertOk()->json('reference');
        $this->postJson('/seller-settlements/'.$order->id.'/record-payment', $payment)
            ->assertOk()->assertJsonPath('reference', $reference);
        $record = SellerSettlement::sole();
        $this->assertSame($seller->id, $record->seller_id);
        $this->assertSame(['release_authorized', 'payment_recorded'], $record->events()->orderBy('sequence')->pluck('event_type')->all());
        $paymentEvent = $record->events()->where('event_type', 'payment_recorded')->sole();
        $this->assertSame($funded ? 26806 : 28806, $paymentEvent->amount_cents);
        $this->assertTrue(Storage::disk('local')->exists($paymentEvent->proof_path));
        $ledger = CommissionLedger::sole();
        $this->assertSame(['320.07', '32.01', '288.06', '50.00', 'settled'],
            [$ledger->gross_amount, $ledger->platform_commission, $ledger->seller_amount, $ledger->delivery_fee, $ledger->status]);
        $this->assertSame(1, $cash->events()->where('event_type', 'rider_collection')->count());
        $this->assertSame(1, $cash->events()->where('event_type', 'platform_reconciled')->count());
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'buyer_completed')->count());
        foreach ($offers as $offer) {
            $balance = $offer->currentRevision->balance->fresh();
            $this->assertSame(0, $balance->committed_cents);
            $this->assertSame($offer->shop_id ? 2000 : 1000, $balance->spent_cents);
        }
        $this->assertSame($funded ? 2 : 0, VoucherFundingEntry::where('event_type', 'expense')->count());
        $this->actingAs($seller)->getJson('/seller-settlements/'.$order->id)->assertOk()
            ->assertJsonPath('record.status', 'settled')->assertJsonPath('record.buyer_completed', true)
            ->assertJsonPath('record.cash_reconciled', true)->assertJsonCount(2, 'record.history');
        $this->get('/seller/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.completedCount', 1)->where('stats.deliveredCount', 0)->where('stats.completedGrossSales', 320.07));
        $this->actingAs($buyer)->get('/buyer/orders?order_status=completed')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.total', 1)->where('orders.data.0.id', $order->id)->where('orders.data.0.status', 'completed'));

        $saved = [$order->fresh()->getRawOriginal(), $parcel->fresh()->getRawOriginal(), $cash->fresh()->getRawOriginal(),
            $cash->events()->count(), $record->events()->count(), DeliveryCheckpoint::count()];
        $this->actingAs($finalRider)->patch(route('courier.updateStatus', $parcel), $deliveryInput)
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->actingAs($buyer)->post(route('checkout.store'), $checkout)->assertRedirect(route('buyer.orders.index'));
        $this->post(route('buyer.orders.confirm', $order))->assertSessionHas('success');
        $this->assertSame($saved, [$order->fresh()->getRawOriginal(), $parcel->fresh()->getRawOriginal(), $cash->fresh()->getRawOriginal(),
            $cash->events()->count(), $record->events()->count(), DeliveryCheckpoint::count()]);
        $this->assertSame([7, 5], [$first->fresh()->stock, $second->fresh()->stock]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('checkout_submissions', 1);
        $this->assertDatabaseCount('commission_ledgers', 1);
    }

    private function assertReleaseBlocked(User $admin, Order $order): void
    {
        $this->actingAs($admin)->postJson('/seller-settlements/'.$order->id.'/authorize', $this->settlementInput(0))->assertConflict();
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    private function financialSnapshot(Order $order): array
    {
        $order = $order->fresh(['delivery.checkpoints', 'codAccount.events', 'sellerSettlement.events']);

        return [$order->getRawOriginal(), $order->delivery->getRawOriginal(),
            $order->delivery->checkpoints->toArray(), $order->codAccount?->getRawOriginal(),
            $order->codAccount?->events->toArray(), $order->sellerSettlement?->getRawOriginal(),
            $order->sellerSettlement?->events->toArray(), CommissionLedger::where('order_id', $order->id)->get()->toArray(),
            VoucherFundingEntry::orderBy('id')->get()->toArray(), Storage::disk('local')->allFiles()];
    }

    private function confirmReceipt(User $buyer, Order $order): void
    {
        $this->actingAs($buyer)->post(route('buyer.orders.confirm', $order))->assertSessionHasNoErrors()->assertSessionHas('success');
        $checkpoint = $order->delivery->checkpoints()->where('checkpoint_type', 'buyer_completed')->sole();
        $this->assertSame($buyer->id, $checkpoint->scanned_by_id);
        $this->assertSame('buyer', $checkpoint->actor_role);
        $this->assertSame('delivered', $checkpoint->source_state['order_status']);
        $this->assertSame('completed', $checkpoint->target_state['order_status']);
    }

    private function settlementInput(int $version): array
    {
        return ['expected_version' => $version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The buyer receipt, platform cash and original seller payment were checked.'];
    }

    private function cashInput(CodAccount $cash): array
    {
        return ['expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid()];
    }

    private function handover(CodAccount $cash, User $holder, User $recipient): void
    {
        $amount = $cash->order->total_amount;
        $offer = $this->cashInput($cash) + ['holder_id' => $holder->id, 'recipient_id' => $recipient->id,
            'amount' => $amount, 'evidence_reference' => 'COUNTED-CASH-OFFER'];
        $reference = $this->actingAs($holder)->postJson('/cash-handover/'.$cash->id.'/offer', $offer)
            ->assertOk()->json('reference');
        $this->postJson('/cash-handover/'.$cash->id.'/offer', $offer)->assertOk()->assertJsonPath('reference', $reference);
        $receipt = $this->cashInput($cash) + ['offer_reference' => $reference, 'received_amount' => $amount,
            'receipt_confirmed' => true, 'evidence_reference' => 'COUNTED-CASH-RECEIPT'];
        $reference = $this->actingAs($recipient)->postJson('/cash-handover/'.$cash->id.'/receive', $receipt)
            ->assertOk()->json('reference');
        $this->postJson('/cash-handover/'.$cash->id.'/receive', $receipt)->assertOk()->assertJsonPath('reference', $reference);
    }
}
