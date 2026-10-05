<?php

namespace Tests\Feature\Admin;

use App\Models\CommissionLedger;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestrictionAffectedWork;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function input(User $subject, string $action = 'suspend', string $reason = 'Review the current account responsibilities.'): array
    {
        return ['action' => $action, 'reason' => $reason, 'affected_work_confirmed' => true,
            'source_token' => app(AccountRestrictionService::class)->token(app(AccountRestrictionService::class)->state($subject->fresh()))];
    }

    public static function rolePortals(): array
    {
        $cases = [];
        foreach (['http://localhost/admin', 'http://admin.localhost'] as $host) {
            foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
                foreach (['suspend', 'deactivate'] as $action) {
                    $cases[$host.' '.$role.' '.$action] = [$host, $role, $action];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('rolePortals')]
    public function test_reasoned_restrictions_apply_for_every_role_on_both_portals(string $host, string $role, string $action): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->create(['role' => $role]);
        $this->actingAs($actor)->postJson($host.'/users/'.$subject->id.'/activity', $this->input($subject, $action))->assertOk();
        $this->assertSame($action === 'suspend' ? 'suspended' : 'inactive', $subject->fresh()->status);
        $this->assertFalse($subject->fresh()->canAccessPortal());
        $this->assertSame('approved', $subject->fresh()->kyc_status);
        $this->assertSame($role, $subject->fresh()->role);
        $decision = RestrictionDecision::sole();
        $this->assertSame($actor->id, $decision->actor_id);
        $this->assertSame('active', $decision->before_state['subject']['status']);
        $this->assertSame(1, $subject->fresh()->restriction_version);
    }

    public static function invalidInputs(): array
    {
        return [
            ['reason', null], ['reason', ''], ['reason', '    '], ['reason', 'No'],
            ['reason', '<script>bad</script>'], ['reason', "Hidden\u{200b}reason"], ['reason', "Wrong\0reason"],
            ['reason', 'javascript:alert(1)'], ['reason', str_repeat('a', 1001)],
            ['source_token', null], ['source_token', 'unknown'], ['source_token', []],
            ['action', 'approve'], ['action', null], ['affected_work_confirmed', false],
            ['role', 'admin'], ['status', 'active'], ['kyc_status', 'approved'],
            ['is_available', true], ['restriction_version', 20], ['actor_id', 999],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_injected_fields_leave_account_and_history_unchanged(string $field, mixed $value): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        $before = $subject->fresh()->getAttributes();
        $input = [...$this->input($subject), $field => $value];
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/activity', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    public function test_canonical_unicode_reason_is_preserved_and_identical_retry_does_not_duplicate_work(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        Order::factory()->create(['buyer_id' => $subject->id]);
        $input = $this->input($subject, 'suspend', '  Suriin ang pag-abot ng parsela kay José.  ');
        $first = app(AccountRestrictionService::class)->decide($actor, $subject, $input);
        $this->travel(2)->minutes();
        $second = app(AccountRestrictionService::class)->decide($actor, $subject, $input);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->decided_at->toISOString(), $second->decided_at->toISOString());
        $this->assertSame('Suriin ang pag-abot ng parsela kay José.', $second->reason);
        $this->assertDatabaseCount('restriction_decisions', 1);
        $this->assertDatabaseCount('restriction_affected_work', 1);
    }

    public function test_changed_reason_actor_or_state_conflicts_with_current_permitted_actions(): void
    {
        $actor = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        $input = $this->input($subject);
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/activity', $input)->assertOk();
        foreach ([$input + [], [...$input, 'reason' => 'A different reason needs a separate decision.'], [...$input, 'action' => 'deactivate']] as $index => $competing) {
            $this->actingAs($index === 0 ? $other : $actor)->postJson('/admin/users/'.$subject->id.'/activity', $competing)
                ->assertConflict()->assertJsonPath('current_state.status', 'suspended')->assertJsonPath('permitted_actions', ['deactivate', 'reactivate']);
        }
        $this->assertDatabaseCount('restriction_decisions', 1);
    }

    public function test_new_work_after_preview_and_unknown_source_state_conflict_without_mutation(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        $input = $this->input($subject);
        Order::factory()->create(['buyer_id' => $subject->id]);
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/activity', $input)->assertConflict();
        $subject->update(['status' => 'unknown']);
        $this->postJson('/admin/users/'.$subject->id.'/activity', $this->input($subject))->assertConflict()->assertJsonPath('permitted_actions', []);
        $this->assertSame('unknown', $subject->fresh()->status);
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    public function test_active_parcel_checkpoints_proof_and_cash_labels_survive_with_responsibility(): void
    {
        $actor = User::factory()->admin()->create();
        $rider = User::factory()->courier()->create();
        $order = Order::factory()->create(['status' => 'out_for_delivery', 'payment_status' => 'paid']);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'assigned_rider_id' => $rider->id, 'status' => 'out_for_delivery', 'proof_image' => 'private-proof.jpg']);
        $checkpoint = DeliveryCheckpoint::record($parcel, 'destination_bayan_hub_outbound', actor: $rider);
        $before = [$order->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $checkpoint->fresh()->getAttributes()];
        app(AccountRestrictionService::class)->decide($actor, $rider, $this->input($rider));
        $record = RestrictionAffectedWork::sole();
        $this->assertSame($actor->id, $record->responsible_user_id);
        $this->assertSame($rider->id, $record->snapshot['parcel']['assigned_rider_id']);
        $this->assertSame('unverified', $record->snapshot['cash']['evidence']);
        $this->assertNull($record->snapshot['cash']['holder']);
        $this->assertTrue($record->snapshot['proof_recorded']);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $checkpoint->fresh()->getAttributes()]);
    }

    public function test_reactivation_preserves_reviewed_shop_restrictions_and_old_retry_never_reapplies(): void
    {
        $actor = User::factory()->admin()->create();
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id, 'status' => 'suspended']);
        $input = $this->input($seller);
        $first = app(AccountRestrictionService::class)->decide($actor, $seller, $input);
        app(AccountRestrictionService::class)->decide($actor, $seller, $this->input($seller, 'reactivate'));
        $retry = app(AccountRestrictionService::class)->decide($actor, $seller, $input);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame('active', $seller->fresh()->status);
        $this->assertSame('suspended', $shop->fresh()->status);
        $this->assertDatabaseCount('restriction_decisions', 2);
    }

    public function test_reactivation_does_not_change_rider_duty_or_assignment(): void
    {
        $actor = User::factory()->admin()->create();
        $rider = User::factory()->courier()->create(['status' => 'suspended']);
        $profile = CourierProfile::factory()->create(['user_id' => $rider->id, 'is_available' => false]);
        $before = $profile->fresh()->getAttributes();
        app(AccountRestrictionService::class)->decide($actor, $rider, $this->input($rider, 'reactivate'));
        $this->assertSame('active', $rider->fresh()->status);
        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    public static function ineligibleReactivations(): array
    {
        return [['buyer', 'rejected', null], ['buyer', 'pending_approval', null], ['courier', 'approved', '2020-01-01'],
            ['seller', 'approved', null], ['courier', 'approved', null], ['admin', 'approved', '2099-01-01']];
    }

    #[DataProvider('ineligibleReactivations')]
    public function test_reactivation_requires_reviewed_identity_age_and_profile_scope(string $role, string $kyc, ?string $birthday): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->create(['role' => $role, 'status' => 'suspended', 'kyc_status' => $kyc, 'birthday' => $birthday]);
        $this->actingAs($actor)->postJson('/admin/users/'.$subject->id.'/activity', $this->input($subject, 'reactivate'))->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertSame('suspended', $subject->fresh()->status);
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    public function test_last_admin_uses_privileged_eligibility_and_competing_restrictions_cannot_remove_every_admin(): void
    {
        $actor = User::factory()->admin()->create();
        User::factory()->admin()->create(['status' => 'pending_approval']);
        User::factory()->admin()->create(['birthday' => '2020-01-01']);
        $this->actingAs($actor)->postJson('/admin/users/'.$actor->id.'/activity', $this->input($actor))->assertConflict();
        $second = User::factory()->admin()->create();
        $selfInput = $this->input($actor);
        $this->postJson('/admin/users/'.$second->id.'/activity', $this->input($second))->assertOk();
        $this->postJson('/admin/users/'.$actor->id.'/activity', $selfInput)->assertConflict();
        $this->assertTrue($actor->fresh()->canAccessPortal());
        $this->assertFalse($second->fresh()->canAccessPortal());
        $this->actingAs($second)->postJson('/admin/users/'.$actor->id.'/activity', $selfInput)->assertForbidden();
        $this->assertDatabaseCount('restriction_decisions', 1);
    }

    public function test_inactive_cached_reviewer_and_wrong_roles_deny_direct_service_and_both_portals(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        $input = $this->input($subject);
        $actor->update(['status' => 'suspended']);
        foreach (['http://localhost/admin', 'http://admin.localhost'] as $host) {
            $this->actingAs($actor)->getJson($host.'/users/'.$subject->id.'/activity')->assertForbidden();
            $this->assertGuest();
            $this->actingAs($actor)->postJson($host.'/users/'.$subject->id.'/activity', $input)->assertForbidden();
            $this->assertGuest();
            $this->actingAs($subject)->postJson($host.'/users/'.$subject->id.'/activity', $input)->assertForbidden();
        }
        $this->expectException(HttpException::class);
        app(AccountRestrictionService::class)->decide($actor, $subject, $input);
    }

    public static function writeFailures(): array
    {
        return [[RestrictionDecision::class], [RestrictionAffectedWork::class]];
    }

    #[DataProvider('writeFailures')]
    public function test_audit_or_responsibility_failure_rolls_back_every_change(string $model): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        Order::factory()->create(['buyer_id' => $subject->id]);
        $input = $this->input($subject);
        Event::listen('eloquent.creating: '.$model, fn () => throw new RuntimeException('Simulated audit failure'));
        try {
            app(AccountRestrictionService::class)->decide($actor, $subject, $input);
            $this->fail('The write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        }
        $this->assertSame('active', $subject->fresh()->status);
        $this->assertSame(0, $subject->fresh()->restriction_version);
        $this->assertDatabaseCount('restriction_decisions', 0);
        $this->assertDatabaseCount('restriction_affected_work', 0);
    }

    public function test_history_is_immutable_and_referenced_subject_cannot_self_delete(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        Order::factory()->create(['buyer_id' => $subject->id]);
        $decision = app(AccountRestrictionService::class)->decide($actor, $subject, $this->input($subject));
        foreach ([$decision, RestrictionAffectedWork::sole()] as $record) {
            try {
                $record->delete();
                $this->fail('Recorded history cannot be deleted.');
            } catch (LogicException) {
            }
            try {
                $record->forceFill([$record instanceof RestrictionDecision ? 'reason' : 'responsible_user_id' => $record instanceof RestrictionDecision ? 'Changed reason' : $subject->id])->save();
                $this->fail('Recorded history cannot change.');
            } catch (LogicException) {
            }
        }
        $this->assertFalse($subject->fresh()->canDeleteOwnAccount());
        $this->assertDatabaseCount('restriction_decisions', 1);
        $this->assertDatabaseCount('restriction_affected_work', 1);
    }

    public function test_suspended_buyer_can_complete_owned_delivered_order_but_cannot_checkout_or_read_foreign_order(): void
    {
        $actor = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'delivered']);
        Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered', 'proof_image' => 'private-proof.jpg']);
        $foreign = Order::factory()->create();
        app(AccountRestrictionService::class)->decide($actor, $buyer, $this->input($buyer));
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.'/buyer/orders/'.$order->id)->assertOk();
            $this->get($host.'/buyer/orders/'.$foreign->id)->assertForbidden();
            $this->post($host.'/checkout', [])->assertRedirect(route('kyc.pending'));
        }
        app(OrderLifecycleService::class)->buyerComplete($order, $buyer);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseCount('restriction_affected_work', 1);
    }

    public function test_suspended_seller_cannot_use_a_cached_direct_fulfillment_service(): void
    {
        $actor = User::factory()->admin()->create();
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->create(['user_id' => $seller->id]);
        $order = Order::factory()->create(['status' => 'placed']);
        app(AccountRestrictionService::class)->decide($actor, $seller, $this->input($seller));
        $this->expectException(AuthorizationException::class);
        app(OrderLifecycleService::class)->sellerTransition($order, $shop, $seller, 'confirmed');
    }

    public function test_account_screen_has_separate_approval_activity_work_and_private_history(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create(['id_document_path' => 'private/identity.pdf']);
        $this->actingAs($actor)->get('/admin/users/'.$subject->id.'/activity')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AccountRestriction')->where('subject.status', 'active')->where('subject.approval', 'approved')
            ->where('subject.legacy_activity', true)->has('subject.source_token')->has('subject.affected_work', 0)->has('subject.history', 0));
        $this->assertStringNotContainsString('private/identity.pdf', $this->get('/admin/users/'.$subject->id.'/activity')->getContent());
    }

    public static function workRoles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['company_owner'], ['hub_handler']];
    }

    #[DataProvider('workRoles')]
    public function test_each_affected_work_scope_records_only_its_own_orders(string $kind): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->create(['role' => in_array($kind, ['company_owner', 'hub_handler'], true) ? 'logistics' : $kind]);
        $order = Order::factory()->create(['buyer_id' => $kind === 'buyer' ? $subject->id : User::factory()->buyer()->create()->id]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id]);
        if ($kind === 'seller') {
            $shop = Shop::factory()->create(['user_id' => $subject->id]);
            OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $shop->id]);
        } elseif ($kind === 'courier') {
            $parcel->update(['courier_id' => $subject->id, 'status' => 'picked_up']);
        } elseif (in_array($kind, ['company_owner', 'hub_handler'], true)) {
            $owner = $kind === 'company_owner' ? $subject : User::factory()->logistics()->create();
            $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Test Network', 'slug' => 'test-network', 'code' => 'B06-NET', 'status' => 'active', 'is_active' => true]);
            $hub = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo Test Hub', 'code' => 'B06-HUB', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => 'Test hub', 'is_active' => true]);
            $parcel->update(['logistics_company_id' => $company->id, 'current_hub_id' => $hub->id]);
            if ($kind === 'hub_handler') {
                HubHandler::create(['user_id' => $subject->id, 'hub_id' => $hub->id, 'is_active' => true]);
            }
        }
        $foreign = Order::factory()->create();
        $decision = app(AccountRestrictionService::class)->decide($actor, $subject, $this->input($subject));
        $this->assertSame([$order->id], $decision->affectedWork()->pluck('order_id')->all());
        $this->assertFalse($decision->affectedWork()->where('order_id', $foreign->id)->exists());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame($subject->id, $kind === 'courier' ? $parcel->fresh()->courier_id : $subject->id);
    }

    public function test_completed_paid_or_settled_labels_do_not_become_proven_cash_handover(): void
    {
        $actor = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed', 'payment_status' => 'paid']);
        $ledger = CommissionLedger::factory()->create(['order_id' => $order->id, 'status' => 'settled']);
        $before = $ledger->fresh()->getAttributes();
        $decision = app(AccountRestrictionService::class)->decide($actor, $buyer, $this->input($buyer));
        $cash = $decision->affectedWork()->sole()->snapshot['cash'];
        $this->assertSame('settled', $cash['ledger_state']);
        $this->assertSame('unverified', $cash['evidence']);
        $this->assertSame($before, $ledger->fresh()->getAttributes());
    }

    public static function reactivationRoles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics'], ['admin']];
    }

    #[DataProvider('reactivationRoles')]
    public function test_valid_separate_reactivation_for_every_role_keeps_legacy_missing_date_compatibility(string $role): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->create(['role' => $role, 'status' => 'inactive']);
        if ($role === 'seller') {
            Shop::factory()->approved()->create(['user_id' => $subject->id]);
        }
        if ($role === 'courier') {
            CourierProfile::factory()->create(['user_id' => $subject->id]);
        }
        app(AccountRestrictionService::class)->decide($actor, $subject, $this->input($subject, 'reactivate'));
        $this->assertSame('active', $subject->fresh()->status);
        $this->assertSame('approved', $subject->fresh()->kyc_status);
        $this->assertNull($subject->fresh()->birthday);
        $this->assertSame($role, $subject->fresh()->role);
    }

    public function test_restricting_an_admin_keeps_its_existing_recovery_work_with_an_eligible_reviewer(): void
    {
        $firstAdmin = User::factory()->admin()->create();
        $nextAdmin = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $service = app(AccountRestrictionService::class);
        $first = $service->decide($firstAdmin, $buyer, $this->input($buyer));
        $next = $service->decide($nextAdmin, $firstAdmin, $this->input($firstAdmin));
        $this->assertSame($firstAdmin->id, $first->affectedWork()->sole()->responsible_user_id);
        $this->assertSame($nextAdmin->id, $next->affectedWork()->sole()->responsible_user_id);
        $this->assertSame($order->id, $next->affectedWork()->sole()->order_id);
        $this->assertTrue($nextAdmin->fresh()->canAccessPortal());
    }

    public function test_migration_rollback_cannot_erase_recorded_decisions(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->buyer()->create();
        app(AccountRestrictionService::class)->decide($actor, $subject, $this->input($subject));
        $migration = require database_path('migrations/2026_10_05_110000_create_restriction_decisions.php');
        try {
            $migration->down();
            $this->fail('Recorded decisions cannot be erased by a rollback.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('retained', $exception->getMessage());
        }
        $this->assertDatabaseCount('restriction_decisions', 1);
        $this->assertSame('suspended', $subject->fresh()->status);
    }

    public function test_anonymous_decision_is_rejected_before_any_mutation(): void
    {
        $subject = User::factory()->buyer()->create();
        $this->postJson('/admin/users/'.$subject->id.'/activity', $this->input($subject))->assertUnauthorized();
        $this->assertSame('active', $subject->fresh()->status);
        $this->assertDatabaseCount('restriction_decisions', 0);
    }
}
