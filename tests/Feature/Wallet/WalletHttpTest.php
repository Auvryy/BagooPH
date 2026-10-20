<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletTopUpRequest;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class WalletHttpTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function creditInput(): array
    {
        return ['amount' => '1000.00', 'reason' => 'Demo credits to purchase a Bagoo item.', 'request_token' => (string) Str::uuid()];
    }

    public function test_admin_review_is_discoverable_and_an_approved_request_changes_only_the_owned_virtual_balance(): void
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($buyer)->get('/buyer/profile?tab=wallet')->assertInertia(fn (Assert $page) => $page
            ->where('wallet.available', true)->where('wallet.balance_cents', 0)->where('wallet.transactions.total', 0));
        $this->postJson('/wallet/credits', $this->creditInput())->assertOk()->assertJsonPath('status', 'pending');
        $credit = WalletTopUpRequest::sole();
        $this->get('/wallet/admin')->assertForbidden();
        $this->actingAs($admin)->get('/wallet/admin')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/WalletCredits')->where('pendingCount', 1)->where('creditRequests.data.0.buyer.id', $buyer->id));
        $data = ['decision' => 'approved', 'reason' => 'Approved the original demo credit request.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()];
        $this->postJson('/wallet/credits/'.$credit->id, $data)->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/wallet/credits/'.$credit->id, $data)->assertOk();
        $this->actingAs($buyer)->get('/buyer/profile?tab=wallet')->assertInertia(fn (Assert $page) => $page
            ->where('wallet.balance_cents', 100000)->where('wallet.transactions.total', 1)->where('wallet.credit_requests.0.status', 'approved'));
        $other = User::factory()->create();
        $this->actingAs($other)->get('/buyer/profile?tab=wallet')->assertInertia(fn (Assert $page) => $page
            ->where('wallet.balance_cents', 0)->where('wallet.transactions.total', 0)->has('wallet.credit_requests', 0));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_wallet_checkout_has_a_separate_to_pay_stage_and_exposes_only_an_owned_payment_page(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, shipping: ['payment_method' => 'wallet']);
        $payment = $order->walletPayment;
        $this->actingAs($buyer)->get('/buyer/orders?order_status=to_pay')->assertInertia(fn (Assert $page) => $page
            ->where('orderCounts.to_pay', 1)->where('orderCounts.to_ship', 0)->has('orders.data', 1)->where('orders.data.0.wallet_payment.id', $payment->id));
        $this->get('/wallet/payments/'.$payment->id)->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/WalletPayment')->where('payment.can_pay', true)->where('payment.balance_cents', 0)
            ->where('payment.payment_reference', null)->missing('payment.source_hash')->missing('payment.request_token_hash'));
        $this->actingAs(User::factory()->create())->get('/wallet/payments/'.$payment->id)->assertForbidden();
        $this->postJson('/wallet/payments/'.$payment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_failed_wallet_payment_has_clear_retry_feedback_and_preserves_the_order(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $order = $this->checkoutFlowOrder($buyer, $shop, shipping: ['payment_method' => 'wallet']);
        $payment = $order->walletPayment;
        $this->actingAs($buyer)->postJson('/wallet/payments/'.$payment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('outcome', 'failed')->assertJsonPath('message', fn ($message) => str_contains($message, 'not have enough'));
        $this->get('/wallet/payments/'.$payment->id)->assertInertia(fn (Assert $page) => $page->where('payment.last_attempt.outcome', 'failed')->where('payment.can_pay', true));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $flags = app(OrderLifecycleService::class)->sellerActionFlags($order->fresh(['delivery', 'items']), true, true);
        $this->assertFalse($flags['can_fulfill']);
        $this->assertFalse($flags['can_accept_and_pack']);
    }

    public function test_guest_and_restricted_accounts_cannot_request_or_approve_credits(): void
    {
        $this->post('/wallet/credits', $this->creditInput())->assertRedirect(route('login'));
        foreach ([['role' => 'seller'], ['role' => 'courier'], ['status' => 'suspended'], ['kyc_status' => 'pending_approval']] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->actingAs($user)->postJson('/wallet/credits', $this->creditInput())->assertForbidden();
        }
        $this->assertDatabaseCount('wallet_top_up_requests', 0);
    }

    public function test_a_changed_balance_is_unavailable_instead_of_displayed_as_genuine_money(): void
    {
        $buyer = User::factory()->create();
        $account = app(WalletLedger::class)->user($buyer);
        DB::table('wallet_accounts')->where('id', $account->id)->update(['balance_cents' => 900000]);
        $this->actingAs($buyer)->get('/buyer/profile?tab=wallet')->assertInertia(fn (Assert $page) => $page
            ->where('wallet.available', false)->where('wallet.balance_cents', null)->where('wallet.can_request', false)
            ->where('wallet.transactions.total', 0));
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_stale_credit_review_returns_human_feedback_without_hiding_json_conflicts(): void
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $request = app(WalletCreditService::class)->request($buyer, $this->creditInput());
        $this->actingAs($admin)->post('/wallet/credits/'.$request->id, ['decision' => 'approved', 'reason' => 'Approved the original buyer demo amount.',
            'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $payload = ['decision' => 'rejected', 'reason' => 'This stale review must not change a saved grant.',
            'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()];
        $this->from('/wallet/admin')->post('/wallet/credits/'.$request->id, $payload)->assertRedirect('/wallet/admin')
            ->assertSessionHasErrors(['reason' => 'This credit request has already been reviewed.']);
        $this->postJson('/wallet/credits/'.$request->id, $payload)->assertConflict();
        $this->assertSame(100000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
    }
}
