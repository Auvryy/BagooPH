<?php

namespace Tests\Feature\Buyer;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\OrderCancellationEvent;
use App\Models\OrderCancellationRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Courier\CourierOperationsService;
use App\Services\Orders\OrderCancellationService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class OrderCancellationRequestTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function requestFor($order, ?array $input = null)
    {
        $this->actingAs($order->buyer)->postJson(route('buyer.orders.cancellation-request', $order), $input ?? $this->input())
            ->assertCreated()->assertJsonPath('cancellation.status', 'pending');

        return OrderCancellationRequest::latest('id')->firstOrFail();
    }

    private function input(): array
    {
        return ['request_token' => (string) Str::uuid(), 'reason' => 'Ordered by mistake'];
    }

    private function decision($request, string $decision = 'approve', ?array $data = null)
    {
        return $this->actingAs(User::findOrFail($request->seller_id))->postJson(route('seller.orders.cancellation-review',
            [$request->order_id, $request->id, $decision]), $data ?? ['request_token' => (string) Str::uuid(),
                'expected_version' => $request->version, 'decision_reason' => 'The buyer request and original parcel were reviewed.']);
    }

    public function test_accepted_order_requires_review_and_holds_preparation_without_cancelling(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $this->assertSame(2, $order->shipping_quote['cancellation_policy_version']);
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), $order->items->first()->product->fresh()->stock];
        $this->actingAs($order->buyer)->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertUnprocessable();
        $request = $this->requestFor($order);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->items->first()->product->fresh()->stock]);
        $this->get(route('buyer.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('cancelOrderUrl', null)->where('cancellationRequestUrl', null)->where('cancellationFeedback.request.reference', $request->reference));
        $this->actingAs($order->shop->user)->postJson(route('seller.orders.pack', $order))->assertConflict();
        $this->get(route('seller.orders.index'))->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.can_pack', false)->where('orders.data.0.cancellation_request.can_review', true));
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(1, $request->events()->count());
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_approval_retries_restore_stock_once_with_real_non_scan_actor_evidence(): void
    {
        $order = $this->newFlowOrder('preparing');
        $product = $order->items->first()->product;
        $stock = $product->fresh()->stock;
        $request = $this->requestFor($order);
        $data = ['request_token' => (string) Str::uuid(), 'expected_version' => 1, 'decision_reason' => 'The parcel has not been released; cancellation approved.'];
        $this->decision($request, data: $data)->assertOk()->assertJsonPath('order.status', 'cancelled')->assertJsonPath('cancellation.status', 'approved');
        $this->decision($request, data: $data)->assertOk()->assertJsonPath('cancellation.status', 'approved');
        $this->assertSame($stock + $order->items->sum('quantity'), $product->fresh()->stock);
        $this->assertSame(2, $request->events()->count());
        $checkpoint = $order->delivery->checkpoints()->where('checkpoint_type', 'cancelled')->sole();
        $this->assertSame('not_scanned', $checkpoint->scan_provenance);
        $this->assertSame($request->seller_id, $checkpoint->scanned_by_id);
        $this->assertSame($checkpoint->custody_before, $checkpoint->custody_after);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_decline_retains_reason_and_original_token_replays_its_own_request(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $input = $this->input();
        $request = $this->requestFor($order, $input);
        $this->decision($request, 'reject')->assertOk()->assertJsonPath('cancellation.status', 'rejected');
        $next = $this->requestFor($order);
        $this->actingAs($order->buyer)->postJson(route('buyer.orders.cancellation-request', $order), $input)
            ->assertOk()->assertJsonPath('cancellation.reference', $request->reference)->assertJsonPath('cancellation.status', 'rejected')
            ->assertJsonPath('cancellation_feedback.request.reference', $next->reference);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(3, OrderCancellationEvent::count());
    }

    public function test_exact_deadline_is_read_only_then_scheduler_expires_without_money_or_order_changes(): void
    {
        $this->travelTo(now()->setMicrosecond(0));
        $order = $this->newFlowOrder('preparing');
        $request = $this->requestFor($order);
        $before = [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()];
        $this->travelTo($request->respond_by);
        $this->assertSame('expired', app(OrderCancellationService::class)->present($order)['status']);
        $this->assertSame('pending', $request->fresh()->status);
        $this->decision($request)->assertConflict();
        $this->artisan('orders:expire-cancellation-requests')->assertSuccessful();
        $this->artisan('orders:expire-cancellation-requests')->assertSuccessful();
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertNull($request->fresh()->active_order_id);
        $this->assertSame(2, $request->events()->count());
        $this->assertNull($request->events()->reorder('version', 'desc')->first()->actor_id);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()]);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->artisan('orders:expire-cancellation-requests', ['--limit' => '0'])->assertFailed();
    }

    public function test_wrong_owner_changed_token_and_stale_decision_cannot_mutate_request(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $input = $this->input();
        $request = $this->requestFor($order, $input);
        $url = route('buyer.orders.cancellation-request', $order);
        $this->actingAs($this->createApprovedUser('buyer'))->postJson($url, $input)->assertForbidden();
        $this->actingAs($order->buyer)->postJson($url, [...$input, 'notes' => 'Changed intent'])->assertConflict();
        $this->postJson($url, $this->input())->assertConflict();
        $this->postJson($url, [...$this->input(), 'buyer_id' => $order->buyer_id])->assertUnprocessable();
        $review = route('seller.orders.cancellation-review', [$order, $request, 'approve']);
        $data = ['request_token' => (string) Str::uuid(), 'expected_version' => 1, 'decision_reason' => 'The original purchase was reviewed carefully.'];
        $this->actingAs($this->createApprovedUser('seller'))->postJson($review, $data)->assertForbidden();
        $this->actingAs($order->shop->user)->postJson($review, [...$data, 'expected_version' => 2])->assertConflict();
        $this->postJson($review, [...$data, 'decision_reason' => 'short'])->assertUnprocessable();
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(1, $request->events()->count());
    }

    public function test_retained_custody_change_rejects_decision_and_ready_or_claimed_orders_cannot_request(): void
    {
        $order = $this->newFlowOrder('preparing');
        $request = $this->requestFor($order);
        DeliveryCheckpoint::record($order->delivery, 'courier_pickup', evidence: ['custody_after' => ['kind' => 'courier', 'user_id' => 777]]);
        $this->decision($request)->assertConflict();
        $this->assertSame('preparing', $order->fresh()->status);
        foreach ([['status' => 'ready_for_pickup'], ['status' => 'picked_up']] as $attrs) {
            $order->update($attrs);
            $this->actingAs($order->buyer)->postJson(route('buyer.orders.cancellation-request', $order), $this->input())->assertConflict();
        }
    }

    public function test_pending_request_blocks_rider_claim_before_any_assignment(): void
    {
        $order = $this->newFlowOrder('preparing');
        $this->requestFor($order);
        try {
            app(CourierOperationsService::class)->claimPickup($this->createApprovedUser('courier'), $order->delivery);
            $this->fail('A pending cancellation must hold pickup.');
        } catch (\DomainException $error) {
            $this->assertStringContainsString('on hold', $error->getMessage());
        }
        $this->assertNull($order->delivery->fresh()->courier_id);
    }

    public function test_event_failure_rolls_back_approval_stock_and_request_state(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $request = $this->requestFor($order);
        $stock = $order->items->first()->product->fresh()->stock;
        Event::listen('eloquent.creating: '.OrderCancellationEvent::class, fn () => throw new \RuntimeException('Private database detail'));
        $response = $this->decision($request)->assertStatus(500);
        $this->assertStringNotContainsString('Private database', $response->getContent());
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame($stock, $order->items->first()->product->fresh()->stock);
        $this->assertSame(1, $request->events()->count());
        $this->assertSame(0, $order->delivery->checkpoints()->where('checkpoint_type', 'cancelled')->count());
    }

    public function test_legacy_orders_keep_direct_cancellation_and_unknown_terms_fail_closed(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $quote = $order->shipping_quote;
        unset($quote['cancellation_policy_version']);
        $order->update(['shipping_quote' => $quote]);
        $this->actingAs($order->buyer)->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertOk();
        $other = $this->newFlowOrder();
        $other->update(['shipping_quote' => [...$other->shipping_quote, 'cancellation_policy_version' => 99]]);
        $this->actingAs($other->buyer)->postJson(route('buyer.orders.cancel', $other), ['reason' => 'Ordered by mistake'])->assertUnprocessable();
        $this->assertSame('placed', $other->fresh()->status);
    }

    public function test_paid_wallet_approval_refunds_the_actual_payment_once(): void
    {
        $order = $this->newFlowOrder(shipping: ['payment_method' => 'wallet']);
        $buyer = $order->buyer;
        $credits = app(WalletCreditService::class);
        $credit = $credits->request($buyer, ['amount' => '5000.00', 'reason' => 'Test credits for original purchase.', 'request_token' => (string) Str::uuid()]);
        $credits->review($this->createApprovedUser('admin'), $credit, ['decision' => 'approved', 'reason' => 'Approved isolated purchase credits.',
            'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        app(WalletPaymentService::class)->command($buyer, $order->walletPayment, 'pay', ['request_token' => (string) Str::uuid(), 'payment_confirmed' => true]);
        app(OrderLifecycleService::class)->sellerTransition($order->fresh(), $order->shop, $order->shop->user, 'confirmed');
        $request = $this->requestFor($order->fresh());
        $data = ['request_token' => (string) Str::uuid(), 'expected_version' => 1, 'decision_reason' => 'The original paid reservation can be released.'];
        $this->decision($request, data: $data)->assertOk()->assertJsonPath('order.payment_status', 'refunded');
        $this->decision($request, data: $data)->assertOk();
        $refund = WalletTransaction::where('kind', 'wallet_cancellation_refund')->sole();
        $this->assertSame((int) round((float) $order->total_amount * 100), $refund->amount_cents);
        $this->assertSame($refund->reference, app(OrderLifecycleService::class)->cancellationFeedback($order->fresh(), $buyer)['refund']['reference']);
    }

    public function test_original_request_and_event_history_are_protected_from_raw_sql_changes(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $request = $this->requestFor($order);
        foreach ([fn () => DB::table('order_cancellation_requests')->where('id', $request->id)->update(['reason' => 'Rewrite']),
            fn () => DB::table('order_cancellation_events')->delete(), fn () => DB::table('order_cancellation_requests')->delete()] as $write) {
            try {
                $write();
                $this->fail('Retained cancellation evidence must not be rewritten.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('retained', $error->getMessage());
            }
        }
    }

    public function test_new_request_validation_and_creation_failure_leave_no_orphan_slot(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $url = route('buyer.orders.cancellation-request', $order);
        $this->actingAs($order->buyer);
        foreach ([['reason' => 'Other reason'], ['request_token' => 'not-a-uuid'], ['notes' => str_repeat('x', 501)], ['notes' => '<script>cancel()</script>']] as $invalid) {
            $this->postJson($url, [...$this->input(), ...$invalid])->assertUnprocessable();
        }
        Event::listen('eloquent.creating: '.OrderCancellationEvent::class, fn () => throw new \RuntimeException('Private event failure'));
        $this->postJson($url, $this->input())->assertStatus(500);
        $this->assertDatabaseCount('order_cancellation_requests', 0);
        $this->assertDatabaseCount('order_cancellation_events', 0);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_either_serial_order_of_seller_cancel_and_actual_rider_claim_keeps_one_outcome(): void
    {
        $claimed = $this->newFlowOrder('ready_for_pickup');
        $rider = $this->flowRider(LogisticsHub::findOrFail($claimed->delivery->origin_bayan_hub_id));
        app(CourierOperationsService::class)->claimPickup($rider, $claimed->delivery);
        $this->actingAs($claimed->shop->user)->postJson(route('seller.orders.cancel', $claimed), ['reason' => 'Out of stock / Inventory shortage'])->assertConflict();
        $this->assertSame($rider->id, $claimed->delivery->fresh()->courier_id);
        $cancelled = $this->newFlowOrder('ready_for_pickup');
        $this->actingAs($cancelled->shop->user)->postJson(route('seller.orders.cancel', $cancelled), ['reason' => 'Out of stock / Inventory shortage'])->assertOk();
        $rider = $this->flowRider(LogisticsHub::findOrFail($cancelled->delivery->origin_bayan_hub_id));
        try {
            app(CourierOperationsService::class)->claimPickup($rider, $cancelled->delivery);
            $this->fail('A cancelled parcel cannot be claimed.');
        } catch (\DomainException $error) {
            $this->assertStringContainsString('unavailable', $error->getMessage());
        }
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertNull($cancelled->delivery->fresh()->courier_id);
    }

    public function test_expiry_releases_hold_and_an_old_request_replay_does_not_claim_a_new_window(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $input = $this->input();
        $request = $this->requestFor($order, $input);
        $this->travelTo($request->respond_by);
        // Start a fresh sign-in session after the 24-hour jump.
        $this->app['session']->driver()->invalidate();
        $this->actingAs($order->buyer)->postJson(route('buyer.orders.cancellation-request', $order), $input)
            ->assertOk()->assertJsonPath('cancellation.status', 'expired')->assertJsonPath('order.status', 'confirmed');
        $this->actingAs($order->shop->user)->postJson(route('seller.orders.pack', $order))->assertOk();
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertSame('preparing', $order->fresh()->status);
        $this->requestFor($order->fresh());
        $this->assertDatabaseCount('order_cancellation_requests', 2);
    }

    public function test_review_notices_use_original_participants_once_and_do_not_expose_private_details(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $request = $this->requestFor($order);
        $events = DB::table('notifications')->where('data->cancellation_reference', $request->reference)->get();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing([$request->buyer_id, $request->seller_id], $events->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all());
        $this->assertStringNotContainsString($order->shipping_address, $events->toJson());
        $this->assertStringNotContainsString($order->recipient_phone, $events->toJson());
    }

    public function test_inconsistent_mutable_request_requires_review_instead_of_appending_false_history(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $request = $this->requestFor($order);
        DB::table('order_cancellation_requests')->where('id', $request->id)->update(['version' => 9]);
        $presentation = app(OrderCancellationService::class)->present($order);
        $this->assertSame('review_required', $presentation['status']);
        $this->assertFalse($presentation['can_review']);
        $this->decision($request)->assertConflict();
        $this->travelTo($request->respond_by);
        try {
            app(OrderCancellationService::class)->expireDue();
            $this->fail('An inconsistent request requires review, not invented history.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(1, $request->events()->count());
        $migration = require database_path('migrations/2026_11_11_020000_create_order_cancellation_requests.php');
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
