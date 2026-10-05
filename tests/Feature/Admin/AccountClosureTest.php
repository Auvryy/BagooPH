<?php

namespace Tests\Feature\Admin;

use App\Models\AccountClosure;
use App\Models\CommissionLedger;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\HubHandler;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RestrictionAffectedWork;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\BuyerAccessService;
use App\Services\KycDecisionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['birthday' => '1990-01-01']);
    }

    private function payload(User $actor, User $subject): array
    {
        return ['password' => 'password', 'reason' => 'The account has no unresolved responsibilities and can close.',
            'source_token' => app(AccountClosureService::class)->presentation($actor, $subject->id)['source_token']];
    }

    private function network(User $owner): array
    {
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Closure Network', 'slug' => 'closure-'.$owner->id,
            'code' => 'CLOSE'.$owner->id, 'status' => 'inactive', 'is_active' => false]);
        $hub = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo Closure Hub', 'code' => 'HUB'.$owner->id,
            'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => 'Bagoo test facility', 'is_active' => false]);

        return [$company, $hub];
    }

    private function review(User $subject, User $reviewer): KycDecision
    {
        Storage::disk('local')->put('kyc_documents/retained-'.$subject->id.'.pdf', '%PDF-1.4 Bagoo private evidence');
        $subject->update(['id_document_path' => 'kyc_documents/retained-'.$subject->id.'.pdf']);
        $submission = app(KycDecisionService::class)->submission($subject);

        return KycDecision::create(['user_id' => $subject->id, 'reviewer_id' => $reviewer->id, 'reviewer_role' => $reviewer->role,
            'reviewer_name' => $reviewer->name, 'subject_role' => $subject->role, 'submission_token' => hash('sha256', 'review-'.$subject->id),
            'decision' => 'approved', 'reason' => null, 'submission' => $submission, 'before_state' => [], 'after_state' => [], 'reviewed_at' => now()]);
    }

    public static function rolesAndHosts(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['/admin', 'http://admin.localhost'] as $base) {
                $cases[] = [$role, $base];
            }
        }

        return $cases;
    }

    #[DataProvider('rolesAndHosts')]
    public function test_unreferenced_account_closes_with_independent_provenance_and_idempotent_retry(string $role, string $base): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create(['role' => $role, 'status' => 'inactive', 'birthday' => '1990-01-01']);
        $other = User::factory()->create();
        $otherBefore = $other->fresh()->getAttributes();
        $payload = $this->payload($actor, $subject);
        $this->actingAs($actor)->postJson($base.'/users/'.$subject->id.'/closure', $payload)->assertOk()->assertJsonPath('outcome', 'deleted');
        $this->assertNull($subject->fresh());
        $record = AccountClosure::sole()->getAttributes();
        $this->assertSame($subject->id, AccountClosure::sole()->subject_id);
        $this->assertSame($role, AccountClosure::sole()->subject_role);
        $this->assertSame($actor->name, AccountClosure::sole()->actor_name);
        $this->postJson($base.'/users/'.$subject->id.'/closure', $payload)->assertOk();
        $this->assertSame($record, AccountClosure::sole()->getAttributes());
        $this->postJson($base.'/users/'.$subject->id.'/closure', array_replace($payload, ['reason' => 'A competing closure reason.']))->assertConflict();
        $this->get($base.'/users/'.$subject->id.'/closure')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/AccountClosure')->where('closure.closure.outcome', 'deleted')->missing('closure.before_state'));
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
    }

    public static function responsibilities(): array
    {
        return [['buyer'], ['seller'], ['pickup'], ['delivery'], ['owner'], ['handler'], ['recovery_admin']];
    }

    #[DataProvider('responsibilities')]
    public function test_orders_and_parcel_custody_block_every_responsible_role(string $kind): void
    {
        $actor = $this->admin();
        $role = match ($kind) {
            'pickup', 'delivery' => 'courier', 'owner', 'handler' => 'logistics', 'recovery_admin' => 'admin', default => $kind
        };
        $subject = User::factory()->create(['role' => $role, 'birthday' => '1990-01-01']);
        $order = Order::factory()->create(['buyer_id' => $kind === 'buyer' ? $subject->id : User::factory(), 'status' => 'picked_up']);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'picked_up', 'courier_id' => $kind === 'pickup' ? $subject->id : null,
            'assigned_rider_id' => $kind === 'delivery' ? $subject->id : null, 'proof_image' => 'delivery-proofs/retained.png']);
        $product = null;
        if ($kind === 'seller') {
            $shop = Shop::factory()->create(['user_id' => $subject->id, 'status' => 'inactive']);
            $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 7]);
            OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $shop->id, 'product_id' => $product->id]);
        } elseif (in_array($kind, ['owner', 'handler'], true)) {
            [$company, $hub] = $this->network($kind === 'owner' ? $subject : User::factory()->logistics()->create());
            $parcel->update(['logistics_company_id' => $company->id, 'current_hub_id' => $hub->id]);
            if ($kind === 'handler') {
                HubHandler::create(['user_id' => $subject->id, 'hub_id' => $hub->id, 'is_active' => false]);
            }
        } elseif ($kind === 'recovery_admin') {
            $decision = RestrictionDecision::create(['subject_type' => 'account', 'subject_id' => $order->buyer_id, 'actor_id' => $actor->id,
                'actor_role' => 'admin', 'actor_name' => $actor->name, 'source_token' => str_repeat('c', 64), 'action' => 'suspend', 'reason' => 'Parcel recovery required.',
                'before_state' => [], 'after_state' => [], 'decided_at' => now()]);
            RestrictionAffectedWork::create(['restriction_decision_id' => $decision->id, 'order_id' => $order->id,
                'delivery_id' => $parcel->id, 'responsible_user_id' => $subject->id, 'snapshot' => [], 'recorded_at' => now()]);
        }
        $before = [$subject->fresh()->getAttributes(), $order->fresh()->getAttributes(), $parcel->fresh()->getAttributes()];
        $payload = $this->payload($actor, $subject);
        $state = app(AccountClosureService::class)->presentation($actor, $subject->id);
        $this->assertContains('active_order', array_column($state['blockers'], 'code'));
        $this->assertContains('parcel_custody', array_column($state['blockers'], 'code'));
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', $payload)->assertUnprocessable()->assertJsonValidationErrors('closure');
        $this->assertSame($before, [$subject->fresh()->getAttributes(), $order->fresh()->getAttributes(), $parcel->fresh()->getAttributes()]);
        if ($product) {
            $this->assertSame(7, $product->fresh()->stock);
        }
        $this->assertDatabaseCount('account_closures', 0);
    }

    public static function ledgerStates(): array
    {
        return [['pending'], ['settled'], ['refunded']];
    }

    #[DataProvider('ledgerStates')]
    public function test_completed_cod_and_legacy_ledger_labels_never_prove_cash_reconciliation(string $ledgerState): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $subject->id, 'status' => 'completed', 'payment_status' => 'paid']);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        $ledger = CommissionLedger::create(['order_id' => $order->id, 'gross_amount' => 1000, 'seller_amount' => 900, 'platform_commission' => 100, 'delivery_fee' => 60, 'status' => $ledgerState]);
        $before = [$order->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $ledger->fresh()->getAttributes()];
        $review = app(AccountClosureService::class)->presentation($actor, $subject->id);
        $this->assertContains('cash_unverified', array_column($review['blockers'], 'code'));
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', $this->payload($actor, $subject))->assertUnprocessable();
        $this->assertSame($before, [$order->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $ledger->fresh()->getAttributes()]);
    }

    public function test_missing_settlement_and_direct_pending_proceeds_block_closure(): void
    {
        $actor = $this->admin();
        $buyer = User::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed', 'payment_method' => 'card', 'payment_status' => 'paid']);
        $this->assertContains('settlement_unverified', array_column(app(AccountClosureService::class)->presentation($actor, $buyer->id)['blockers'], 'code'));
        $seller = User::factory()->seller()->create();
        $ledger = CommissionLedger::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'gross_amount' => 1000, 'seller_amount' => 900, 'platform_commission' => 100, 'status' => 'pending']);
        $before = $ledger->fresh()->getAttributes();
        $this->actingAs($actor)->postJson('/admin/users/'.$seller->id.'/closure', $this->payload($actor, $seller))->assertUnprocessable();
        $this->assertSame($before, $ledger->fresh()->getAttributes());
    }

    public function test_returned_paid_order_and_changed_identity_require_a_fresh_resolution(): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create();
        $payload = $this->payload($actor, $subject);
        $subject->update(['phone' => '09171234567']);
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', $payload)->assertConflict();
        $order = Order::factory()->create(['buyer_id' => $subject->id, 'status' => 'returned', 'payment_method' => 'card', 'payment_status' => 'paid']);
        $this->assertContains('returned_payment', array_column(app(AccountClosureService::class)->presentation($actor, $subject->id)['blockers'], 'code'));
        $this->postJson('/admin/users/'.$subject->id.'/closure', $this->payload($actor, $subject))->assertUnprocessable();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('account_closures', 0);
    }

    public function test_retained_closure_preserves_identity_reviews_files_and_reviewer_provenance(): void
    {
        $actor = $this->admin();
        $reviewer = $this->admin();
        $subject = User::factory()->create(['status' => 'inactive']);
        $decision = $this->review($subject, $reviewer);
        $history = $decision->fresh()->getRawOriginal();
        $before = $subject->fresh()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', $this->payload($actor, $subject))->assertOk()->assertJsonPath('outcome', 'retained');
        $after = $subject->fresh()->getAttributes();
        foreach (['closed_at', 'updated_at', 'remember_token'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($history, $decision->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertFalse($subject->fresh()->canAccessPortal());
        $this->get('/verification-documents/'.$subject->id.'/id.pdf?decision='.$decision->id)->assertOk();
        $this->postJson('/admin/users/'.$reviewer->id.'/closure', $this->payload($actor, $reviewer))->assertOk()->assertJsonPath('outcome', 'retained');
        $this->assertSame($reviewer->id, $decision->fresh()->reviewer_id);
        $this->assertNotNull($decision->fresh()->reviewer);
    }

    public function test_resolved_recorded_non_cod_history_is_retained_without_altering_amounts_or_messages(): void
    {
        $actor = $this->admin();
        $buyer = User::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed', 'payment_method' => 'card', 'payment_status' => 'paid']);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        $ledger = CommissionLedger::create(['order_id' => $order->id, 'gross_amount' => 1000, 'seller_amount' => 900, 'platform_commission' => 100, 'status' => 'settled']);
        $message = Message::create(['sender_id' => $buyer->id, 'receiver_id' => $actor->id, 'message' => 'Bagoo order handover confirmed.']);
        $before = [$order->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $ledger->fresh()->getAttributes(), $message->fresh()->getAttributes()];
        $this->actingAs($actor)->postJson('/admin/users/'.$buyer->id.'/closure', $this->payload($actor, $buyer))->assertOk()->assertJsonPath('outcome', 'retained');
        $this->assertSame($before, [$order->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $ledger->fresh()->getAttributes(), $message->fresh()->getAttributes()]);
        $this->assertFalse(app(BuyerAccessService::class)->canAccessExistingOrders($buyer->fresh()));
    }

    public function test_operating_network_shops_handlers_and_fleet_require_separate_resolution(): void
    {
        $actor = $this->admin();
        $owner = User::factory()->logistics()->create();
        [$company, $hub] = $this->network($owner);
        $hub->update(['is_active' => true]);
        $rider = User::factory()->courier()->create();
        CourierProfile::factory()->create(['user_id' => $rider->id, 'logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id, 'is_available' => false]);
        $handler = HubHandler::create(['user_id' => User::factory()->logistics()->create()->id, 'hub_id' => $hub->id, 'is_active' => true]);
        $fleet = LogisticsFleet::create(['logistics_company_id' => $company->id, 'hub_id' => $hub->id, 'plate_number' => 'BAG-CLOSE', 'vehicle_type' => 'motorcycle', 'status' => 'active', 'assigned_driver_id' => $rider->id]);
        $codes = array_column(app(AccountClosureService::class)->presentation($actor, $owner->id)['blockers'], 'code');
        foreach (['active_hubs', 'active_handlers', 'active_riders', 'active_fleet'] as $code) {
            $this->assertContains($code, $codes);
        }
        $before = [$hub->fresh()->getAttributes(), $handler->fresh()->getAttributes(), $fleet->fresh()->getAttributes()];
        $this->actingAs($actor)->postJson('/admin/users/'.$owner->id.'/closure', $this->payload($actor, $owner))->assertUnprocessable();
        $this->assertSame($before, [$hub->fresh()->getAttributes(), $handler->fresh()->getAttributes(), $fleet->fresh()->getAttributes()]);
        $seller = User::factory()->seller()->create();
        Shop::factory()->create(['user_id' => $seller->id, 'status' => 'active']);
        $this->assertContains('active_shops', array_column(app(AccountClosureService::class)->presentation($actor, $seller->id)['blockers'], 'code'));
        $this->assertContains('active_riders', array_column(app(AccountClosureService::class)->presentation($actor, $rider->id)['blockers'], 'code'));
    }

    public function test_last_eligible_admin_is_protected_against_sequential_competing_closures(): void
    {
        $actor = $this->admin();
        User::factory()->admin()->create(['status' => 'suspended']);
        $this->actingAs($actor)->postJson('/admin/users/'.$actor->id.'/closure', $this->payload($actor, $actor))->assertUnprocessable();
        $replacement = $this->admin();
        $this->postJson('/admin/users/'.$replacement->id.'/closure', $this->payload($actor, $replacement))->assertOk();
        $this->postJson('/admin/users/'.$actor->id.'/closure', $this->payload($actor, $actor))->assertUnprocessable();
        $this->assertTrue($actor->fresh()->canAccessPortal());
        $this->assertDatabaseCount('account_closures', 1);
    }

    public function test_new_work_invalidates_the_review_and_is_rechecked_inside_the_transaction(): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create();
        $payload = $this->payload($actor, $subject);
        $inserted = false;
        DB::listen(function (QueryExecuted $query) use ($subject, &$inserted): void {
            if (! $inserted && str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'order by "id"')) {
                $inserted = true;
                Order::factory()->create(['buyer_id' => $subject->id, 'status' => 'placed']);
            }
        });
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', $payload)->assertConflict();
        $this->assertTrue($inserted);
        // The injected write participates in this simulated transaction and must roll back with it.
        $this->assertNotNull($subject->fresh());
        $this->assertDatabaseCount('account_closures', 0);
        $order = Order::factory()->create(['buyer_id' => $subject->id, 'status' => 'placed']);
        $before = $order->fresh()->getAttributes();
        $this->postJson('/admin/users/'.$subject->id.'/closure', $payload)->assertConflict();
        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    public function test_failed_identity_write_rolls_back_closure_and_keeps_authentication(): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create();
        $this->review($subject, $actor);
        $before = $subject->fresh()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $payload = $this->payload($actor, $subject);
        Event::listen('eloquent.updated: '.User::class, function (User $user) use ($subject) {
            if ($user->id === $subject->id) {
                throw new RuntimeException('Closure write failed.');
            }
        });
        $this->actingAs($actor)->withoutExceptionHandling();
        try {
            $this->postJson('/admin/users/'.$subject->id.'/closure', $payload);
            $this->fail('Expected closure failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Closure write failed.', $error->getMessage());
        }
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('account_closures', 0);
        $this->assertAuthenticatedAs($actor);
    }

    public function test_closed_identity_blocks_stale_sessions_reactivation_and_new_corrections(): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create();
        $this->review($subject, $actor);
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', $this->payload($actor, $subject))->assertOk();
        $this->postJson('/admin/users/'.$subject->id.'/activity', ['action' => 'reactivate', 'reason' => 'Attempt to reopen closed identity.', 'source_token' => str_repeat('a', 64), 'affected_work_confirmed' => true])->assertConflict();
        $this->get('/admin/users/'.$subject->id.'/identity-corrections')->assertConflict();
        $this->actingAs($subject)->patchJson('/profile', ['name' => $subject->name, 'email' => 'changed@bagoo.test'])->assertForbidden();
        $this->assertGuest();
        $this->assertSame($subject->email, $subject->fresh()->email);
        $this->assertFalse($subject->fresh()->canAccessPortal());
        $this->post('/login', ['email' => $subject->email, 'password' => 'password'])->assertRedirect('/login');
        $this->assertGuest();
    }

    public static function sqlChanges(): array
    {
        return [['history_update'], ['history_delete'], ['reopen'], ['user_delete']];
    }

    #[DataProvider('sqlChanges')]
    public function test_database_guards_preserve_closure_history_and_closed_identity(string $action): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create(['avatar' => 'avatars/retained.png']);
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', $this->payload($actor, $subject))->assertOk();
        $this->expectException(QueryException::class);
        match ($action) {
            'history_update' => DB::table('account_closures')->update(['reason' => 'Changed closure.']),
            'history_delete' => DB::table('account_closures')->delete(),
            'reopen' => DB::table('users')->where('id', $subject->id)->update(['closed_at' => null, 'status' => 'active']),
            'user_delete' => DB::table('users')->where('id', $subject->id)->delete(),
        };
    }

    public function test_ordinary_model_deletion_cannot_trigger_order_cascades(): void
    {
        $subject = User::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $subject->id]);
        try {
            $subject->delete();
            $this->fail('Referenced account deletion must be blocked.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('must be retained', $error->getMessage());
        }
        $this->assertNotNull($subject->fresh());
        $this->assertNotNull($order->fresh());
    }

    public function test_foreign_actor_wrong_password_and_injected_role_leave_all_records_untouched(): void
    {
        $actor = $this->admin();
        $subject = User::factory()->create();
        $before = $subject->fresh()->getAttributes();
        $payload = $this->payload($actor, $subject);
        $this->actingAs(User::factory()->create())->postJson('/admin/users/'.$subject->id.'/closure', $payload)->assertForbidden();
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/closure', array_replace($payload, ['password' => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/admin/users/'.$subject->id.'/closure', $payload + ['role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
        User::whereKey($actor->id)->update(['status' => 'suspended']);
        try {
            app(AccountClosureService::class)->close($actor, $subject->id, $payload);
            $this->fail('Stale admin must be denied.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertDatabaseCount('account_closures', 0);
    }

    public function test_profile_explains_why_referenced_self_deletion_is_unavailable(): void
    {
        $subject = User::factory()->create();
        Order::factory()->create(['buyer_id' => $subject->id, 'status' => 'placed']);
        $this->actingAs($subject)->get('/profile')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Profile/Edit')
            ->where('closure.allowed', false)->where('closure.outcome', 'retained')->has('closure.blockers')->missing('closure.references.0.digest'));
        $this->delete('/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertAuthenticatedAs($subject);
        $this->assertDatabaseCount('account_closures', 0);
    }

    public function test_stale_self_review_returns_reload_feedback_without_signing_out(): void
    {
        $subject = User::factory()->create();
        $source = app(AccountClosureService::class)->presentation($subject, $subject->id, self: true)['source_token'];
        Order::factory()->create(['buyer_id' => $subject->id, 'status' => 'placed']);
        $this->actingAs($subject)->from('/profile')->delete('/profile', ['password' => 'password', 'source_token' => $source])->assertRedirect('/profile')->assertSessionHasErrors('source_token');
        $this->assertAuthenticatedAs($subject);
        $this->assertDatabaseCount('account_closures', 0);
    }

    public function test_failed_self_deletion_rolls_back_before_signing_out(): void
    {
        $subject = User::factory()->create();
        $before = $subject->fresh()->getAttributes();
        Event::listen('eloquent.deleted: '.User::class, fn () => throw new RuntimeException('Deletion could not finish.'));
        $this->actingAs($subject)->withoutExceptionHandling();
        try {
            $this->delete('/profile', ['password' => 'password']);
            $this->fail('Expected deletion failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Deletion could not finish.', $error->getMessage());
        }
        $this->assertAuthenticatedAs($subject);
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertDatabaseCount('account_closures', 0);
    }
}
