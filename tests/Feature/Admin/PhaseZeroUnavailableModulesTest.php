<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseZeroUnavailableModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_buyer_return_queue_does_not_invent_claims_from_a_delivered_status_label(): void
    {
        $buyer = User::factory()->create(['address' => null, 'city' => null]);
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.'/buyer/disputes')->assertInertia(fn (Assert $page) => $page
                ->component('Returns/Index')->where('cases.total', 0)->where('cases.data', [])->missing('eligibleOrders'));
        }
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_seller_return_queue_is_empty_on_both_portals_without_sample_claims(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        foreach (['http://localhost/seller/disputes', 'http://seller.localhost/disputes'] as $url) {
            $this->actingAs($seller)->get($url)->assertInertia(fn (Assert $page) => $page
                ->component('Returns/Index')->where('cases.total', 0)->where('cases.data', []));
        }
    }

    public function test_buyer_profile_does_not_invent_wallet_balance_identity_or_transactions(): void
    {
        $buyer = User::factory()->create(['address' => null, 'city' => null]);
        $this->actingAs($buyer)->get('/buyer/profile?tab=wallet')->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/Profile')->where('wallet.available', true)->where('wallet.balance_cents', 0)
            ->missing('wallet.account_number')->where('wallet.transactions.total', 0)->where('wallet.currency', 'PHP'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public static function removedWrites(): array
    {
        $cases = [];
        foreach (['guest', 'buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach ([
                ['POST', 'http://localhost/buyer/disputes', 405],
                ['POST', 'http://buyer.localhost/buyer/disputes', 405],
                ['PATCH', 'http://localhost/seller/disputes/DSP-TEST/respond', 404],
                ['PATCH', 'http://seller.localhost/disputes/DSP-TEST/respond', 404],
            ] as [$method, $url, $status]) {
                $cases[$role.' '.$method.' '.$url] = [$role, $method, $url, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('removedWrites')]
    public function test_deferred_dispute_writes_are_unavailable_for_every_role_without_business_changes(string $role, string $method, string $url, int $status): void
    {
        $buyer = User::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed', 'payment_status' => 'pending']);
        if ($role !== 'guest') {
            $this->actingAs(User::factory()->create(['role' => $role, 'birthday' => '1990-01-01']));
        }
        $before = $this->businessSnapshot();
        $this->json($method, $url, ['order_id' => $order->id, 'reason' => 'Damaged parcel', 'description' => 'Review the damaged item.',
            'action' => 'accept_refund', 'explanation' => 'Refund requested.'])->assertStatus($status)->assertSessionMissing('success');
        $this->assertSame($before, $this->businessSnapshot());
    }

    private function businessSnapshot(): array
    {
        $result = [];
        foreach (['users', 'orders', 'order_items', 'deliveries', 'delivery_checkpoints', 'commission_ledgers', 'messages', 'kyc_decisions'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }
}
