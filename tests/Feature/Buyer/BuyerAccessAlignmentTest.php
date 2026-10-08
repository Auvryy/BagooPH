<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\KycSubmissionService;
use App\Services\Orders\CheckoutOrderService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class BuyerAccessAlignmentTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public static function portalStates(): array
    {
        return [
            ['active', 'none'], ['active', 'pending_approval'], ['active', 'rejected'],
            ['pending_approval', 'pending_approval'], ['inactive', 'approved'], ['suspended', 'verified'],
            ['unknown', 'approved'], ['active', 'unknown'],
        ];
    }

    #[DataProvider('portalStates')]
    public function test_each_private_buyer_entry_rechecks_the_current_account(string $status, string $kyc): void
    {
        $buyer = User::factory()->create(['status' => $status, 'kyc_status' => $kyc]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $known = $status !== 'unknown' && $kyc !== 'unknown';
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer);
            foreach (['/checkout', '/buyer/checkout', '/buyer/profile', '/messages', '/buyer/messages', '/cart', '/buyer/cart', '/buyer/disputes'] as $path) {
                $response = $this->get($host.$path);
                $known ? $response->assertRedirect(route('kyc.pending')) : $response->assertForbidden();
            }
            foreach (['/checkout', '/buyer/profile', '/buyer/addresses', '/buyer/reviews', '/buyer/vouchers/apply', '/buyer/support/assistant', '/cart'] as $path) {
                $response = $this->post($host.$path, ['name' => 'Unauthorized Change']);
                $known ? $response->assertRedirect(route('kyc.pending')) : $response->assertForbidden();
            }
            $this->get($host.'/profile')->assertForbidden();
            $this->post($host.'/buyer/disputes', ['reason' => 'Unavailable action'])->assertStatus(405)->assertSessionMissing('success');
            $this->patch($host.'/profile', ['name' => 'Changed Buyer', 'email' => 'changed@bagoo.test'])->assertForbidden();
            $this->get($host.'/chat/messages/999')->assertForbidden();
            $this->post($host.'/chat/send', ['message' => 'Hello'])->assertForbidden();
        }
        $this->assertSame($status, $buyer->fresh()->status);
        $this->assertSame($kyc, $buyer->fresh()->kyc_status);
        $this->assertDatabaseCount('carts', 1);
        $this->assertSame($buyer->id, $cart->fresh()->user_id);
        foreach (['orders', 'addresses', 'messages', 'reviews'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function signInStates(): array
    {
        return [
            ['active', 'none', 'kyc.pending'], ['active', 'pending_approval', 'kyc.pending'],
            ['active', 'rejected', 'kyc.pending'], ['active', 'approved', 'buyer.index'], ['active', 'verified', 'buyer.index'],
            ['inactive', 'approved', 'buyer.orders.index'], ['suspended', 'verified', 'buyer.orders.index'],
            ['pending_approval', 'pending_approval', 'kyc.pending'], ['unknown', 'approved', 'login'], ['active', 'unknown', 'login'],
        ];
    }

    #[DataProvider('signInStates')]
    public function test_password_sign_in_uses_the_permitted_current_destination(string $status, string $kyc, string $destination): void
    {
        $buyer = User::factory()->create(['status' => $status, 'kyc_status' => $kyc, 'password' => 'Password1234']);
        $response = $this->withSession(['url.intended' => '/checkout'])->post('/login', ['email' => $buyer->email, 'password' => 'Password1234']);
        $response->assertRedirect(in_array($kyc, ['approved', 'verified']) && $status === 'active' ? '/checkout' : route($destination));
        if ($destination === 'login') {
            $this->assertGuest();
            $response->assertSessionHasErrors('email');
        } else {
            $this->assertAuthenticatedAs($buyer);
            if ($destination !== 'buyer.index') {
                $response->assertSessionMissing('url.intended');
            }
        }
        $this->assertSame($status, $buyer->fresh()->status);
        $this->assertSame($kyc, $buyer->fresh()->kyc_status);
    }

    #[DataProvider('signInStates')]
    public function test_oauth_uses_the_same_destination_without_granting_approval(string $status, string $kyc, string $destination): void
    {
        $buyer = User::factory()->create(['status' => $status, 'kyc_status' => $kyc, 'google_id' => 'buyer-access-test']);
        $remote = Mockery::mock(SocialiteUser::class);
        $remote->shouldReceive('getId')->andReturn($buyer->google_id);
        $remote->shouldReceive('getEmail')->andReturn($buyer->email);
        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($remote);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $response = $this->withSession(['url.intended' => '/checkout'])->get('/auth/google/callback');
        $response->assertRedirect(in_array($kyc, ['approved', 'verified']) && $status === 'active' ? '/checkout' : route($destination));
        $destination === 'login' ? $this->assertGuest() : $this->assertAuthenticatedAs($buyer);
        if ($destination !== 'buyer.index') {
            $response->assertSessionMissing('url.intended');
        }
        $this->assertSame($status, $buyer->fresh()->status);
        $this->assertSame($kyc, $buyer->fresh()->kyc_status);
    }

    public function test_public_browsing_does_not_grant_a_private_portal(): void
    {
        foreach (['/', '/buyer', '/catalog'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/checkout')->assertRedirect(route('login'));
        $this->get('/pending-approval')->assertRedirect(route('login'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('carts', 0);
    }

    public static function unreviewedApplications(): array
    {
        $cases = [];
        foreach (['active', 'pending_approval', 'inactive', 'suspended'] as $status) {
            foreach (['none', 'pending_approval', 'rejected'] as $kyc) {
                $cases[] = [$status, $kyc];
            }
        }

        return $cases;
    }

    #[DataProvider('unreviewedApplications')]
    public function test_owned_application_and_document_correction_preserves_independent_activity(string $status, string $kyc): void
    {
        $buyer = User::factory()->create(['name' => 'Original Buyer', 'status' => $status, 'kyc_status' => $kyc, 'birthday' => null]);
        $this->addKycEvidence($buyer);
        $old = $buyer->fresh()->id_document_path;
        $minorBirthday = now()->timezone('Asia/Manila')->subYears(14)->toDateString();
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.'/pending-approval')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Auth/BuyerApproval')->where('application.can_correct', true)->where('user.id', $buyer->id)->missing('user.id_document_path'));
            $this->get($host.'/verification-documents/'.$buyer->id.'/id.pdf')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->post('/kyc/resubmit', [
            'name' => 'José Dela Cruz', 'phone' => '09171234567', 'postal_code' => '4030', 'birthday' => $minorBirthday,
            'id_document' => UploadedFile::fake()->createWithContent('buyer-correction.pdf', '%PDF-1.4 corrected buyer evidence'),
        ])->assertSessionHas('success');
        $current = $buyer->fresh();
        $this->assertSame('José Dela Cruz', $current->name);
        $this->assertSame('+639171234567', $current->phone);
        $this->assertSame($minorBirthday, $current->birthday->toDateString());
        $this->assertSame($status, $current->status);
        $this->assertSame('pending_approval', $current->kyc_status);
        $this->assertSame('buyer', $current->role);
        $this->assertNotSame($old, $current->id_document_path);
        Storage::disk('local')->assertExists($old);
        Storage::disk('local')->assertExists($current->id_document_path);
        Storage::disk('public')->assertMissing($current->id_document_path);
        $this->assertDatabaseCount('kyc_decisions', 0);
        $this->get('/buyer/checkout')->assertRedirect(route('kyc.pending'));
    }

    public function test_application_correction_invalidates_an_inspected_review_and_preserves_evidence(): void
    {
        $buyer = User::factory()->pendingKyc()->create(['role' => 'buyer', 'status' => 'suspended', 'name' => 'Original Buyer', 'birthday' => null]);
        $this->addKycEvidence($buyer);
        $buyer->refresh();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->inspectKycEvidence($admin, $buyer);
        $payload = $this->kycPayload($buyer);
        $oldPath = $buyer->id_document_path;
        $this->actingAs($buyer)->post('/kyc/resubmit', ['name' => 'Corrected Buyer'])->assertSessionHas('success');
        $this->actingAs($admin)->post('/admin/kyc/'.$buyer->id.'/approve', $payload)->assertConflict();
        $this->assertDatabaseCount('kyc_decisions', 0);
        $buyer->refresh();
        $this->inspectKycEvidence($admin, $buyer);
        $this->post('/admin/kyc/'.$buyer->id.'/approve', $this->kycPayload($buyer))->assertSessionHas('success');
        $this->assertSame('approved', $buyer->fresh()->kyc_status);
        $this->assertSame('suspended', $buyer->fresh()->status);
        $this->assertSame($oldPath, $buyer->fresh()->id_document_path);
        $this->assertDatabaseCount('kyc_decisions', 1);
    }

    public function test_unsafe_or_foreign_application_input_has_no_partial_changes(): void
    {
        $buyer = User::factory()->create(['name' => 'Original Buyer', 'kyc_status' => 'none']);
        $foreign = User::factory()->create();
        $this->addKycEvidence($foreign);
        $before = $buyer->fresh()->getRawOriginal();
        $this->actingAs($buyer)->post('/kyc/resubmit', ['name' => 'Buyer123', 'phone' => '12345'])->assertSessionHasErrors(['name', 'phone']);
        $this->post('/kyc/resubmit', ['user_id' => $foreign->id, 'role' => 'admin', 'root_category_id' => 1])->assertSessionHasErrors(['user_id', 'role', 'root_category_id']);
        $this->get('/verification-documents/'.$foreign->id.'/id.pdf')->assertForbidden();
        $this->assertSame($before, $buyer->fresh()->getRawOriginal());
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public static function existingOrderStates(): array
    {
        return [['active', 'approved'], ['inactive', 'approved'], ['suspended', 'approved'], ['suspended', 'verified']];
    }

    #[DataProvider('existingOrderStates')]
    public function test_existing_order_access_is_owned_read_only_and_receipt_is_idempotent(string $status, string $kyc): void
    {
        $buyer = User::factory()->create(['status' => $status, 'kyc_status' => $kyc]);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        $delivery = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        $foreign = Order::factory()->create(['status' => 'delivered']);
        $premature = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'out_for_delivery']);
        Delivery::factory()->create(['order_id' => $premature->id, 'status' => 'out_for_delivery']);
        $this->actingAs($buyer);
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            foreach (['/buyer/orders', '/my-orders'] as $prefix) {
                $this->get($host.$prefix)->assertOk()->assertInertia(function (Assert $page) use ($status) {
                    if ($status === 'active') {
                        $page->component('Buyer/Profile')->has('orders', 2)->where('initialTab', 'orders');
                    } else {
                        $page->component('Buyer/Orders')->has('orders.data', 2)->where('canUsePortal', false)->missing('addresses')->missing('wallet');
                    }
                });
                $this->get($host.$prefix.'/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('canUsePortal', $status === 'active')->where('canConfirmReceipt', true));
                $this->get($host.$prefix.'/'.$foreign->id)->assertForbidden();
            }
        }
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->post($host.'/buyer/orders/'.$foreign->id.'/confirm')->assertForbidden();
            $this->post($host.'/buyer/orders/'.$premature->id.'/confirm')->assertSessionHas('error');
            $this->assertSame('out_for_delivery', $premature->fresh()->status);
            $this->post($host.'/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
            $this->post($host.'/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        }
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 1);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertSame($status, $buyer->fresh()->status);
    }

    public function test_order_list_uses_stable_owned_pagination_and_real_snapshot_amounts(): void
    {
        $buyer = User::factory()->create(['status' => 'suspended']);
        $orders = Order::factory()->count(13)->create(['buyer_id' => $buyer->id, 'total_amount' => '321.45']);
        Order::factory()->create(['total_amount' => '999.99']);
        $this->actingAs($buyer)->get('/buyer/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 12)->where('orders.total', 13)->where('orders.data.0.id', $orders->last()->id)
            ->where('orders.data.0.total_amount', '321.45')->missing('orders.data.0.shipping_address'));
        $this->get('/buyer/orders?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.id', $orders->first()->id));
    }

    public function test_explicit_security_authorization_denial_is_honored_by_every_existing_order_path(): void
    {
        $buyer = User::factory()->create(['status' => 'suspended']);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        Gate::before(fn (User $user, string $ability) => $ability === 'buyer.existing-orders' ? false : null);
        $this->actingAs($buyer);
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            foreach (['/buyer/orders', '/my-orders', '/buyer/orders/'.$order->id, '/my-orders/'.$order->id] as $path) {
                $this->get($host.$path)->assertForbidden();
            }
            $this->post($host.'/buyer/orders/'.$order->id.'/confirm')->assertForbidden();
        }
        try {
            app(OrderLifecycleService::class)->buyerComplete($order, $buyer);
            $this->fail('Security authorization denial must also apply to the service.');
        } catch (AuthorizationException) {
            $this->assertSame('delivered', $order->fresh()->status);
        }
        $this->get('/pending-approval')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewOrders', false)->where('application.can_correct', false));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public function test_receipt_checkpoint_failure_rolls_back_completion(): void
    {
        $buyer = User::factory()->create(['status' => 'suspended']);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, fn () => throw new \RuntimeException('Checkpoint write failed.'));
        $this->actingAs($buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('error', 'Checkpoint write failed.');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public function test_submission_write_failure_retains_original_application_and_files(): void
    {
        $buyer = User::factory()->create(['name' => 'Original Buyer', 'kyc_status' => 'rejected']);
        $this->addKycEvidence($buyer);
        $buyer->refresh();
        $before = $buyer->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        Event::listen('eloquent.updating: '.User::class, fn () => throw new \RuntimeException('Submission write failed.'));
        try {
            app(KycSubmissionService::class)->submit($buyer, ['name' => 'Corrected Buyer', 'id_document' => UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 replacement')]);
            $this->fail('The submission should roll back on write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Submission write failed.', $exception->getMessage());
        }
        $this->assertSame($before, $buyer->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_current_suspension_blocks_direct_checkout_service_from_a_stale_approved_actor(): void
    {
        $buyer = User::factory()->create();
        $cart = Cart::create(['user_id' => $buyer->id]);
        $product = Product::factory()->create(['stock' => 20]);
        $item = $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => $product->price]);
        User::whereKey($buyer->id)->update(['status' => 'suspended']);
        try {
            app(CheckoutOrderService::class)->place($buyer, $cart, [$item->id], []);
            $this->fail('A stale actor cannot bypass current checkout eligibility.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Your account is not active and cannot place an order.', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('carts', 1);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_unreviewed_buyer_can_reach_the_own_holding_screen(): void
    {
        $buyer = User::factory()->create(['kyc_status' => 'none']);

        $this->actingAs($buyer)->get('/pending-approval')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/BuyerApproval')->where('user.id', $buyer->id));
    }

    public function test_holding_shared_account_data_uses_the_current_application(): void
    {
        $buyer = User::factory()->create(['kyc_status' => 'pending_approval']);
        User::whereKey($buyer->id)->update([
            'status' => 'suspended', 'kyc_status' => 'rejected',
            'name' => 'Current Buyer', 'kyc_feedback' => 'Please submit a clearer ID.',
        ]);

        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.'/pending-approval')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('auth.user.name', 'Current Buyer')
                    ->where('auth.user.status', 'suspended')
                    ->where('auth.user.kyc_status', 'rejected')
                    ->where('auth.user.kyc_feedback', 'Please submit a clearer ID.')
                    ->where('user.name', 'Current Buyer')
                    ->where('application.can_correct', true));
        }
    }

    public function test_current_restriction_denies_private_evidence_from_a_stale_actor(): void
    {
        $buyer = User::factory()->create();
        $this->addKycEvidence($buyer);
        User::whereKey($buyer->id)->update(['status' => 'suspended']);

        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.'/verification-documents/'.$buyer->id.'/id.pdf')->assertForbidden();
        }
        $this->assertSame('suspended', $buyer->fresh()->status);
        Storage::disk('local')->assertExists($buyer->fresh()->id_document_path);
    }

    public function test_unreviewed_password_login_does_not_follow_a_checkout_intention(): void
    {
        $buyer = User::factory()->create(['kyc_status' => 'pending_approval', 'password' => 'Password1234']);

        $this->withSession(['url.intended' => '/checkout'])->post('/login', [
            'email' => $buyer->email, 'password' => 'Password1234',
        ])->assertRedirect('/pending-approval')->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($buyer);
    }

    public function test_unreviewed_buyer_cannot_create_a_bag_through_a_direct_route(): void
    {
        $buyer = User::factory()->create(['kyc_status' => 'pending_approval']);

        $this->actingAs($buyer)->get('/cart')->assertRedirect('/pending-approval');
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_unknown_current_account_state_cannot_complete_an_order_from_a_stale_actor(): void
    {
        $buyer = User::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        User::whereKey($buyer->id)->update(['status' => 'unknown']);

        try {
            app(OrderLifecycleService::class)->buyerComplete($order, $buyer);
            $this->fail('Current account eligibility must be checked before confirming receipt.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }
}
