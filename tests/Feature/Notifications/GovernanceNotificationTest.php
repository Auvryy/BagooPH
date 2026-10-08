<?php

namespace Tests\Feature\Notifications;

use App\Models\AccountClosure;
use App\Models\CourierProfile;
use App\Models\CustodyRecoveryGrant;
use App\Models\ExceptionDecision;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\NotificationDelivery;
use App\Models\ProductModerationDecision;
use App\Models\RestrictionAffectedWork;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\AccountRestrictionService;
use App\Services\IdentityCorrectionService;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use App\Services\ProductModerationService;
use App\Services\ResourceRestrictionService;
use App\Services\ShopReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class GovernanceNotificationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithKycReviews, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function applicant(string $role = 'buyer'): User
    {
        $user = User::factory()->pendingKyc()->create(['role' => $role, 'birthday' => '2000-01-01',
            'phone' => '+639171234567', 'address' => 'Bagoo Test Street', 'city' => 'Makati']);
        if ($role === 'seller') {
            Shop::create(['user_id' => $user->id, 'root_category_id' => $this->validMasterCategory()->id,
                'name' => 'Bagoo Notice Shop', 'slug' => 'notice-shop-'.$user->id, 'status' => 'pending',
                'phone' => $user->phone, 'address' => $user->address, 'city' => $user->city]);
        } elseif ($role === 'courier') {
            CourierProfile::create(['user_id' => $user->id, 'vehicle_type' => 'Motorcycle', 'plate_number' => 'BAG-123',
                'or_cr_status' => 'Pending Verification', 'is_available' => false]);
        } elseif ($role === 'logistics') {
            LogisticsCompany::create(['user_id' => $user->id, 'name' => 'Bagoo Notice Network', 'slug' => 'notice-network-'.$user->id,
                'code' => 'NT'.$user->id, 'contact_email' => $user->email, 'contact_phone' => $user->phone,
                'address' => $user->address, 'status' => 'pending', 'is_active' => false]);
        }

        return $user->fresh();
    }

    public static function reviews(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            foreach (['/admin', 'http://admin.localhost'] as $host) {
                $cases[] = [$role, $host];
            }
        }

        return $cases;
    }

    #[DataProvider('reviews')]
    public function test_real_committed_review_notifies_subject_once_and_preserves_original_decision_on_retry(string $role, string $host): void
    {
        $subject = $this->applicant($role);
        $admin = $this->createApprovedUser('admin');
        $payload = $this->prepareKycReview($admin, $subject);
        $this->post($host.'/kyc/'.$subject->id.'/approve', $payload)->assertSessionHas('success');
        $decision = KycDecision::sole();
        $notice = $subject->notifications()->where('type', 'governance-event')->sole();
        $this->assertSame('governance:kyc_review:'.$decision->id, $notice->source_key);
        $this->assertSame('approved', $subject->fresh()->kyc_status);
        $this->assertSame($role, $subject->fresh()->role);
        $original = $decision->getAttributes();
        $this->travel(1)->minutes();
        $this->post($host.'/kyc/'.$subject->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertSame($original, $decision->fresh()->getAttributes());
        $this->assertSame($notice->id, $subject->notifications()->where('type', 'governance-event')->sole()->id);
        $this->assertSame(0, $admin->notifications()->where('type', 'governance-event')->count());
        $encoded = json_encode($notice->data);
        foreach (['submission_token', 'password', 'code_hash', 'kyc_documents/', 'sha256', $subject->email] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->actingAs($subject)->get('http://localhost/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notices.data', 1)->where('notices.data.0.id', $notice->id)->where('notices.data.0.href', '/pending-approval')
            ->where('notificationSummary.unread', 1));
    }

    public function test_actual_review_rollback_has_neither_decision_nor_notice(): void
    {
        $subject = $this->applicant();
        $admin = $this->createApprovedUser('admin');
        $payload = $this->prepareKycReview($admin, $subject);
        try {
            DB::transaction(function () use ($subject, $payload) {
                $this->post('/admin/kyc/'.$subject->id.'/approve', $payload)->assertSessionHas('success');
                $this->assertDatabaseCount('notification_deliveries', 1);
                $this->assertDatabaseCount('notifications', 0);
                throw new RuntimeException('Roll back the review');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('pending_approval', $subject->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notice_outage_preserves_real_review_and_retry_delivers_original_notice_once(): void
    {
        $subject = $this->applicant();
        $admin = $this->createApprovedUser('admin');
        $payload = $this->prepareKycReview($admin, $subject);
        DB::unprepared("CREATE TRIGGER review_notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Notice storage outage'); END;");
        $this->post('/admin/kyc/'.$subject->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertSame('approved', $subject->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 1);
        $intent = NotificationDelivery::sole();
        $this->assertNull($intent->delivered_at);
        $this->assertDatabaseCount('notifications', 0);
        DB::unprepared('DROP TRIGGER review_notice_outage');
        $this->travel(2)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->post('/admin/kyc/'.$subject->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertSame($intent->id, $subject->notifications()->sole()->id);
        $this->assertDatabaseCount('kyc_decisions', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public static function activityActions(): array
    {
        return [['suspend', 'active'], ['deactivate', 'active'], ['reactivate', 'suspended']];
    }

    #[DataProvider('activityActions')]
    public function test_real_activity_decision_delivers_owned_safe_notice_without_changing_other_permissions(string $action, string $status): void
    {
        $subject = $this->createApprovedUser('buyer');
        $subject->update(['status' => $status]);
        $admin = $this->createApprovedUser('admin');
        $service = app(AccountRestrictionService::class);
        $input = ['action' => $action, 'reason' => 'PRIVATE_REVIEW_REASON must remain in the governed record.',
            'affected_work_confirmed' => true, 'source_token' => $service->token($service->state($subject))];
        $this->actingAs($admin)->postJson('/admin/users/'.$subject->id.'/activity', $input)->assertOk();
        $this->postJson('/admin/users/'.$subject->id.'/activity', $input)->assertOk();
        $notice = $subject->notifications()->where('type', 'governance-event')->sole();
        $this->assertSame('governance:restriction:'.RestrictionDecision::sole()->id, $notice->source_key);
        $this->assertStringNotContainsString('PRIVATE_REVIEW_REASON', json_encode($notice->data));
        $this->actingAs($subject)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notices.data', 1)->where('notices.data.0.href', '/pending-approval'));
        $this->getJson('/admin/users')->assertForbidden();
        $this->actingAs($admin)->patchJson('/notifications/'.$notice->id.'/read')->assertNotFound();
    }

    public function test_rejected_applicant_reads_and_acknowledges_holding_notice_without_checkout_access(): void
    {
        $subject = $this->applicant();
        $admin = $this->createApprovedUser('admin');
        $payload = $this->prepareKycReview($admin, $subject) + ['reason' => 'The document needs a clearer replacement.'];
        $this->post('/admin/kyc/'.$subject->id.'/reject', $payload)->assertSessionHas('success');
        $notice = $subject->notifications()->sole();
        $this->actingAs($subject)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('notices.data.0.id', $notice->id)->where('notices.data.0.href', '/pending-approval'));
        $firstRead = $this->patchJson('/notifications/'.$notice->id.'/read')->assertOk()->json('read_at');
        $this->travel(1)->hours();
        $this->patchJson('/notifications/'.$notice->id.'/read')->assertJsonPath('read_at', $firstRead);
        $this->get('/checkout')->assertRedirect(route('kyc.pending'));
        $this->get('/pending-approval')->assertOk();
    }

    public function test_real_identity_correction_notice_links_only_to_owned_history(): void
    {
        $subject = $this->createApprovedUser('buyer');
        $subject->update(['birthday' => '2000-01-01', 'phone' => '+639171234567', 'address' => 'Bagoo Test Street', 'city' => 'Makati']);
        $this->addKycEvidence($subject);
        $service = app(IdentityCorrectionService::class);
        $this->actingAs($subject)->post('/account/identity-corrections', [
            'source_token' => $service->form($subject, $subject)['source_token'], 'changes' => ['birthday' => '1999-04-03'],
            'reason' => 'Correct the birth date shown on the submitted document.',
        ])->assertSessionHas('success');
        $request = IdentityCorrectionRequest::sole();
        $admin = $this->createApprovedUser('admin');
        foreach ($service->presentation($request)['documents'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $input = ['action' => 'approve', 'review_token' => $service->reviewToken($request),
            'reason' => 'The private evidence confirms the correction.', 'affected_work_confirmed' => true];
        $this->post('/admin/identity-corrections/'.$request->id.'/decision', $input)->assertSessionHas('success');
        $this->post('/admin/identity-corrections/'.$request->id.'/decision', $input)->assertSessionHas('success');
        $this->assertSame('1999-04-03', $subject->fresh()->birthday->toDateString());
        $notice = $subject->notifications()->sole();
        $this->assertStringContainsString('identity_correction', $notice->source_key);
        $this->actingAs($subject)->get('/notifications')->assertInertia(fn (Assert $page) => $page
            ->where('notices.data.0.href', '/account/identity-corrections')->has('notices.data', 1));
        $this->get('/account/identity-corrections')->assertOk();
    }

    public function test_closure_notice_does_not_prevent_hard_deletion_or_reopen_a_retained_account(): void
    {
        $admin = $this->createApprovedUser('admin');
        $deleted = $this->createApprovedUser('buyer');
        $deleted->update(['status' => 'inactive']);
        $closure = app(AccountClosureService::class);
        $input = ['password' => 'password', 'reason' => 'The account has no unresolved obligations.',
            'source_token' => $closure->presentation($admin, $deleted->id)['source_token']];
        $this->actingAs($admin)->postJson('/admin/users/'.$deleted->id.'/closure', $input)->assertOk()->assertJsonPath('outcome', 'deleted');
        $this->postJson('/admin/users/'.$deleted->id.'/closure', $input)->assertOk();
        $this->assertNull($deleted->fresh());
        $this->assertSame(1, $admin->notifications()->where('type', 'governance-event')->count());
        $this->assertSame(0, NotificationDelivery::where('recipient_id', $deleted->id)->count());

        $retained = $this->createApprovedUser('buyer');
        $restrictions = app(AccountRestrictionService::class);
        $restrictions->decide($admin, $retained, ['action' => 'deactivate', 'reason' => 'Prepare a reviewed account closure.',
            'affected_work_confirmed' => true, 'source_token' => $restrictions->token($restrictions->state($retained))]);
        $input['source_token'] = $closure->presentation($admin, $retained->id)['source_token'];
        $this->postJson('/admin/users/'.$retained->id.'/closure', $input)->assertOk()->assertJsonPath('outcome', 'retained');
        $this->assertNotNull($retained->fresh()->closed_at);
        $this->assertSame(2, AccountClosure::count());
        $this->assertSame(1, $retained->notifications()->where('data->governance_source', 'account_closure')->count());
        $this->actingAs($retained)->getJson('/notifications')->assertForbidden();
    }

    public function test_independent_shop_review_has_one_separate_owner_notice(): void
    {
        $seller = User::factory()->seller()->create(['birthday' => '1995-05-10']);
        $this->addKycEvidence($seller);
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'Bagoo Legacy Shop', 'slug' => 'legacy-notice-shop',
            'root_category_id' => $this->validMasterCategory()->id, 'phone' => '+639171234567', 'address' => 'Bagoo Test Street',
            'city' => 'Manila', 'status' => 'pending', 'review_status' => 'pending_approval', 'review_submitted_at' => now(),
            'business_permit_path' => $seller->getRawOriginal('business_permit_path')]);
        $admin = $this->createApprovedUser('admin');
        $originalNoticeCount = $seller->notifications()->where('data->governance_source', 'shop_review')->count();
        foreach (['id', 'permit'] as $document) {
            $this->actingAs($admin)->get('/shop-verification-documents/'.$shop->id.'/'.$document.'.pdf')->assertOk();
        }
        $service = app(ShopReviewService::class);
        $input = ['review_token' => $service->token($service->submission($shop)), 'evidence_confirmed' => true];
        $this->post('/admin/shops/'.$shop->id.'/approve', $input)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post('/admin/shops/'.$shop->id.'/approve', $input)->assertSessionHasNoErrors()->assertSessionHas('success');
        $decision = ShopReviewDecision::where('shop_id', $shop->id)->sole();
        $this->assertSame(1, $seller->notifications()->where('source_key', 'governance:shop_review:'.$decision->id)->count());
        $this->assertSame($originalNoticeCount + 1, $seller->notifications()->where('data->governance_source', 'shop_review')->count());
        $this->assertSame('approved', $shop->fresh()->review_status);
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_placement_resource_restriction_and_moderation_notify_only_their_actual_owners_and_members(): void
    {
        $order = $this->newFlowOrder();
        $hub = LogisticsHub::findOrFail($order->delivery->origin_bayan_hub_id);
        $manager = $hub->company->user;
        $member = $this->createApprovedUser('logistics');
        $assignment = app(LogisticsPlacementService::class)->assignHandler($manager, $hub, $member);
        $this->assertSame(1, $member->notifications()->where('data->governance_source', 'placement')->count());
        $resources = app(ResourceRestrictionService::class);
        $input = ['action' => 'suspend', 'reason' => 'Review the current handler assignment.', 'affected_work_confirmed' => true,
            'source_token' => $resources->presentation($manager, 'handler', $assignment->id)['source_token']];
        $decision = $resources->decide($manager, 'handler', $assignment->id, $input);
        $resources->decide($manager, 'handler', $assignment->id, $input);
        foreach ([$manager, $member] as $recipient) {
            $this->assertSame(1, $recipient->notifications()->where('source_key', 'governance:restriction:'.$decision->id)->count());
        }
        $this->assertFalse($assignment->fresh()->is_active);
        $this->assertSame(0, $order->buyer->notifications()->where('type', 'governance-event')->count());
        $product = $order->items->first()->product;
        $moderation = app(ProductModerationService::class);
        $admin = $this->createApprovedUser('admin');
        $input = ['action' => 'remove', 'reason' => 'The product needs a compliance correction.',
            'source_token' => app(AccountRestrictionService::class)->token($moderation->state($product))];
        $moderation->decide($admin, $product, $input);
        $moderation->decide($admin, $product, $input);
        $this->assertSame(1, $order->shop->user->notifications()->where('source_key', 'governance:product_moderation:'.ProductModerationDecision::sole()->id)->count());
        $this->assertTrue($product->fresh()->compliance_restricted);
        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_responsibility_grant_and_real_receipt_notify_only_owned_recovery_participants(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $buyerNoticesBefore = $parcel->order->buyer->notifications()->where('type', 'governance-event')->count();
        $sellerNoticesBefore = $parcel->order->shop->user->notifications()->where('type', 'governance-event')->count();
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->restrictFlowAccount($rider, 'suspend');
        $work = RestrictionAffectedWork::where('delivery_id', $parcel->id)->latest('id')->firstOrFail();
        $manager = $parcel->company->user;
        $handler = $this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id));
        $detail = $this->actingAs($manager)->getJson('/exceptions/restriction/'.$work->id)->assertOk()->json('exception');
        $input = ['source_token' => $detail['sourceToken'], 'request_token' => (string) Str::uuid(), 'action' => 'assign',
            'responsible_user_id' => $handler->id, 'reason' => 'Arrange receipt by the original destination hub handler.'];
        $this->postJson('/exceptions/restriction/'.$work->id, $input)->assertOk();
        $this->postJson('/exceptions/restriction/'.$work->id, $input)->assertOk();
        $this->assertSame(1, $handler->notifications()->where('source_key', 'governance:exception:'.ExceptionDecision::sole()->id)->count());
        $service = app(RestrictedCustodyRecoveryService::class);
        $grant = $service->grant($manager, $work, ['source_token' => $service->proposal($manager, $work)['source_token'],
            'request_token' => (string) Str::uuid(), 'reason' => 'Receive the original parcel through the owned recovery workflow.']);
        $this->assertSame(1, CustodyRecoveryGrant::count());
        $notice = $rider->notifications()->where('source_key', 'governance:custody_grant:'.$grant->id)->sole();
        $this->actingAs($rider)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('notices.data', fn ($items) => collect($items)->contains(fn ($item) => $item['id'] === $notice->id && $item['href'] === '/custody-recovery')));
        $this->getJson('/courier/deliveries')->assertForbidden();
        $this->post('/custody-recovery/sign-in', ['email' => $rider->email, 'password' => 'password'])->assertRedirect('/custody-recovery');
        $this->get('/custody-recovery')->assertOk();
        $receipt = ['barcode' => $parcel->tracking_number, 'notes' => 'The original parcel was counted and handed over.', 'request_token' => (string) Str::uuid()];
        $this->postJson('/custody-recovery/'.$grant->id.'/handover', $receipt)->assertOk();
        $this->actingAs($handler)->postJson('/custody-recovery/'.$grant->id.'/receipt', [...$receipt, 'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertSame(2, $rider->notifications()->where('data->governance_source', 'custody_receipt')->count());
        $this->assertSame('suspended', $rider->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertSame($buyerNoticesBefore, $parcel->order->buyer->notifications()->where('type', 'governance-event')->count());
        $this->assertSame($sellerNoticesBefore, $parcel->order->shop->user->notifications()->where('type', 'governance-event')->count());

        $managerNotice = $manager->notifications()->where('source_key', 'governance:exception:'.ExceptionDecision::sole()->id)->sole();
        $manager->update(['status' => 'suspended']);
        $this->actingAs($manager)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('notices.data', fn ($items) => collect($items)->contains(fn ($item) => $item['id'] === $managerNotice->id && $item['href'] === null)));
        $this->getJson('/exceptions/restriction/'.$work->id)->assertForbidden();
        $foreign = $this->createApprovedUser('logistics');
        $this->actingAs($foreign)->get('/notifications')->assertInertia(fn (Assert $page) => $page->has('notices.data', 0));
        $this->patchJson('/notifications/'.$notice->id.'/read')->assertNotFound();
    }
}
