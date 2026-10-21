<?php

namespace Tests\Feature\Auth;

use App\Models\AccountReviewDecision;
use App\Models\AccountReviewRequest;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\RestrictionDecision;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\AccountReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(string $role = 'buyer', string $state = 'suspended'): User
    {
        Mail::fake();

        return User::factory()->create(['role' => $role, 'status' => $state === 'rejected' ? 'pending_approval' : $state,
            'kyc_status' => $state === 'rejected' ? 'rejected' : 'approved', 'birthday' => '1990-01-01',
            'kyc_feedback' => $state === 'rejected' ? 'Please provide a readable identity document.' : null,
            'password' => 'AccountPassword1!']);
    }

    private function payload(User $owner, string $type = 'account'): array
    {
        $source = collect(app(AccountReviewService::class)->presentation($owner)['sources'])->firstWhere('type', $type);
        $this->assertNotNull($source);

        return ['subject_type' => $source['type'], 'subject_id' => $source['id'], 'source_token' => $source['source_token'],
            'message' => 'I have checked the feedback and would like another review of my account.'];
    }

    private function submit(User $owner, string $type = 'account'): AccountReviewRequest
    {
        $this->actingAs($owner)->postJson('/account/review-requests', $this->payload($owner, $type))->assertOk();

        return AccountReviewRequest::where('user_id', $owner->id)->latest('id')->firstOrFail();
    }

    private function decision(User $admin, AccountReviewRequest $item, string $action): array
    {
        $review = app(AccountReviewService::class)->review($admin, $item->fresh());

        return ['action' => $action, 'review_token' => $review['review_token'], 'reason' => 'Reviewed the explanation and current account details.',
            'source_token' => $review['activity']['source_token'] ?? null, 'affected_work_confirmed' => true];
    }

    public static function restrictedStates(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['suspended', 'inactive', ...($role === 'admin' ? [] : ['rejected'])] as $state) {
                $cases[] = [$role, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('restrictedStates')]
    public function test_every_role_can_sign_in_to_its_own_status_and_request_review_while_operations_stay_blocked(string $role, string $state): void
    {
        $owner = $this->owner($role, $state);
        $before = $owner->fresh()->getAttributes();
        $this->post('/login', ['email' => $owner->email, 'password' => 'AccountPassword1!', 'role' => $role])->assertRedirect(route('account.status'));
        $this->assertAuthenticatedAs($owner);
        $this->get('/account/status')->assertInertia(fn (Assert $page) => $page->component('Auth/AccountStatus')
            ->where('status.account.id', $owner->id)->where('status.sources.0.status', $state === 'inactive' ? 'inactive' : $state));
        $payload = $this->payload($owner);
        $first = $this->postJson('/account/review-requests', $payload)->assertOk()->json('id');
        $this->postJson('/account/review-requests', $payload)->assertOk()->assertJsonPath('id', $first);
        $this->assertDatabaseCount('account_review_requests', 1);
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $path = match ($role) {
            'seller' => '/seller/dashboard', 'courier' => '/courier/deliveries', 'logistics' => '/hub', 'admin' => '/admin/users', default => '/checkout'
        };
        if ($role === 'buyer') {
            $this->postJson($path)->assertRedirect(route('kyc.pending'));
            $this->assertDatabaseCount('orders', 0);
        } else {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_approved_login_shows_a_plain_status_message_without_sending_login_email(): void
    {
        $owner = $this->owner('buyer', 'active');
        $this->post('/login', ['email' => $owner->email, 'password' => 'AccountPassword1!', 'role' => 'buyer'])
            ->assertSessionHas('success', 'Your buyer account is approved. Welcome back.');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_foreign_owners_and_approved_accounts_cannot_create_review_requests(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->actingAs($other)->postJson('/account/review-requests', $this->payload($owner))->assertForbidden();
        $approved = $this->owner('buyer', 'active');
        $payload = $this->payload($owner);
        $payload['subject_id'] = $approved->id;
        $this->actingAs($approved)->postJson('/account/review-requests', $payload)->assertForbidden();
        $this->assertDatabaseCount('account_review_requests', 0);
    }

    public static function invalidMessages(): array
    {
        return [[null], [''], ['   '], ['Too short'], ['          A          '], [str_repeat('a', 2001)], [['Please review this account.']],
            ['<script>Please review my account.</script>'], ["Please review my\u{200B}account again."]];
    }

    #[DataProvider('invalidMessages')]
    public function test_invalid_explanations_are_rejected_without_a_request(mixed $message): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/account/review-requests', array_replace($this->payload($owner), ['message' => $message]))
            ->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('account_review_requests', 0);
    }

    public function test_pending_request_blocks_different_duplicate_text_and_database_races(): void
    {
        $owner = $this->owner();
        $item = $this->submit($owner);
        $this->postJson('/account/review-requests', array_replace($this->payload($owner), ['message' => 'A second explanation that should not create another pending request.']))
            ->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('account_review_requests', 1);
        $copy = $item->getAttributes();
        unset($copy['id']);
        $this->expectException(QueryException::class);
        DB::table('account_review_requests')->insert($copy);
    }

    public function test_a_changed_status_supersedes_the_older_pending_request_without_erasing_it(): void
    {
        $owner = $this->owner();
        $old = $this->submit($owner);
        $owner->update(['status' => 'inactive']);
        $next = $this->submit($owner->fresh());
        $this->assertNotSame($old->id, $next->id);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertSame('pending', $next->status);
        $this->assertSame($old->message, $old->fresh()->message);
    }

    public function test_request_source_must_be_current_and_owner_cannot_choose_approval_fields(): void
    {
        $owner = $this->owner();
        $data = $this->payload($owner);
        $owner->update(['status' => 'inactive']);
        $this->actingAs($owner)->postJson('/account/review-requests', $data)->assertConflict();
        $this->postJson('/account/review-requests', $this->payload($owner->fresh()) + ['status' => 'accepted', 'role' => 'admin'])->assertJsonValidationErrors(['status', 'role']);
        $this->assertDatabaseCount('account_review_requests', 0);
    }

    public function test_admin_can_reopen_a_rejected_application_without_approving_or_clearing_a_suspension(): void
    {
        $owner = $this->owner('buyer', 'rejected');
        $owner->update(['status' => 'suspended']);
        $admin = User::factory()->admin()->create();
        $original = KycDecision::create(['user_id' => $owner->id, 'reviewer_id' => $admin->id, 'reviewer_role' => 'admin', 'reviewer_name' => $admin->name,
            'subject_role' => 'buyer', 'submission_token' => str_repeat('a', 64), 'decision' => 'rejected', 'reason' => 'Please provide a readable document.',
            'submission' => [], 'before_state' => [], 'after_state' => [], 'reviewed_at' => now()]);
        $before = $original->fresh()->getAttributes();
        $item = $this->submit($owner);
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', $this->decision($admin, $item, 'reopen_application'))->assertOk();
        $this->assertSame('pending_approval', $owner->fresh()->kyc_status);
        $this->assertSame('suspended', $owner->fresh()->status);
        $this->assertFalse($owner->fresh()->canAccessPortal());
        $this->assertSame($before, $original->fresh()->getAttributes());
        $this->assertSame('accepted', $item->fresh()->status);
        $this->assertSame('pending_approval', AccountReviewDecision::sole()->after_state['owner']['kyc_status']);
    }

    public function test_admin_reactivation_uses_existing_eligibility_and_an_old_retry_cannot_lift_a_later_restriction(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->admin()->create();
        $item = $this->submit($owner);
        $data = $this->decision($admin, $item, 'reactivate');
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', $data)->assertOk();
        $this->assertSame('active', $owner->fresh()->status);
        $this->assertSame('accepted', $item->fresh()->status);
        $this->assertSame(1, RestrictionDecision::count());
        $this->assertSame(RestrictionDecision::sole()->id, AccountReviewDecision::sole()->restriction_decision_id);
        $this->assertSame('active', AccountReviewDecision::sole()->after_state['owner']['status']);
        $restriction = app(AccountRestrictionService::class);
        $restriction->decide($admin, $owner->fresh(), ['action' => 'suspend', 'reason' => 'A different issue needs review.',
            'source_token' => $restriction->presentation($admin, $owner->fresh())['source_token'], 'affected_work_confirmed' => true]);
        $this->postJson('/admin/account-reviews/'.$item->id.'/decision', $data)->assertOk();
        $this->assertSame('suspended', $owner->fresh()->status);
        $this->assertDatabaseCount('account_review_decisions', 1);
        $this->assertDatabaseCount('restriction_decisions', 2);
    }

    public function test_reactivation_cannot_bypass_rejected_identity_or_affected_work_confirmation(): void
    {
        $owner = $this->owner('buyer', 'rejected');
        $owner->update(['status' => 'suspended']);
        $admin = User::factory()->admin()->create();
        $item = $this->submit($owner);
        $data = $this->decision($admin, $item, 'reactivate');
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', array_replace($data, ['affected_work_confirmed' => false]))->assertJsonValidationErrors('affected_work_confirmed');
        $this->postJson('/admin/account-reviews/'.$item->id.'/decision', $data)->assertJsonValidationErrors('action');
        $this->assertSame('suspended', $owner->fresh()->status);
        $this->assertSame('rejected', $owner->fresh()->kyc_status);
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertDatabaseCount('account_review_decisions', 0);
    }

    public function test_a_declined_request_keeps_restrictions_and_requires_a_wait_before_resending_the_same_case(): void
    {
        config(['session.lifetime' => 60 * 48]);
        $owner = $this->owner();
        $admin = User::factory()->admin()->create();
        $item = $this->submit($owner);
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', $this->decision($admin, $item, 'decline'))->assertOk();
        $this->assertSame('suspended', $owner->fresh()->status);
        $this->assertSame('declined', $item->fresh()->status);
        $this->actingAs($owner)->postJson('/account/review-requests', $this->payload($owner))->assertJsonValidationErrors('message');
        $this->travel(25)->hours();
        $this->postJson('/account/review-requests', $this->payload($owner))->assertOk();
        $this->assertDatabaseCount('account_review_requests', 2);
    }

    public static function restrictedCompanies(): array
    {
        return [['suspended'], ['inactive'], ['active']];
    }

    #[DataProvider('restrictedCompanies')]
    public function test_company_owner_can_appeal_its_restriction_and_admin_restores_only_the_company(string $status): void
    {
        $owner = $this->owner('logistics', 'active');
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Review Company', 'code' => 'BRC', 'slug' => 'review-company', 'status' => $status, 'is_active' => false]);
        $admin = User::factory()->admin()->create();
        $item = $this->submit($owner, 'company');
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', $this->decision($admin, $item, 'reactivate'))->assertOk();
        $this->assertSame('active', $company->fresh()->status);
        $this->assertTrue($company->fresh()->is_active);
        $this->assertSame('active', $owner->fresh()->status);
        $this->assertSame('company', RestrictionDecision::sole()->subject_type);
    }

    public function test_review_notifications_are_visible_and_link_to_the_authorized_request_and_owned_outcome(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->admin()->create();
        $item = $this->submit($owner);
        $this->actingAs($admin)->getJson('/notifications/preview')->assertOk()
            ->assertJsonPath('notices.0.href', '/admin/account-reviews/'.$item->id);
        $this->postJson('/admin/account-reviews/'.$item->id.'/decision', $this->decision($admin, $item, 'decline'))->assertOk();
        $this->actingAs($owner)->getJson('/notifications/preview')->assertOk()->assertJsonPath('notices.0.href', '/account/status');
        $this->assertDatabaseHas('notification_deliveries', ['recipient_id' => $owner->id, 'source_key' => 'account-review:outcome:'.AccountReviewDecision::sole()->id, 'email_required' => true]);
        $this->actingAs($owner)->getJson('/admin/account-reviews/'.$item->id)->assertForbidden();
    }

    public function test_an_admin_cannot_review_its_own_request_after_reactivation(): void
    {
        $owner = $this->owner('admin');
        $item = $this->submit($owner);
        $owner->update(['status' => 'active']);
        $this->actingAs($owner)->postJson('/admin/account-reviews/'.$item->id.'/decision', $this->decision($owner, $item, 'close_stale'))->assertForbidden();
        $this->assertDatabaseCount('account_review_decisions', 0);
        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_only_explicit_owner_feedback_is_shared_with_restricted_owners(): void
    {
        $owner = $this->owner('buyer', 'active');
        $admin = User::factory()->admin()->create();
        $service = app(AccountRestrictionService::class);
        $service->decide($admin, $owner, ['action' => 'suspend', 'reason' => 'Private investigation note should remain internal.',
            'owner_feedback' => 'Please provide a clearer copy of your identity document.',
            'source_token' => $service->presentation($admin, $owner)['source_token'], 'affected_work_confirmed' => true]);
        $this->actingAs($owner)->get('/account/status')->assertInertia(fn (Assert $page) => $page
            ->where('status.sources.0.reason', 'Please provide a clearer copy of your identity document.'));
        $this->assertStringNotContainsString('Private investigation', json_encode($owner->notifications()->sole()->data));
    }

    public static function retainedReviewRecords(): array
    {
        return [['account_review_requests', 'update'], ['account_review_requests', 'delete'],
            ['account_review_decisions', 'update'], ['account_review_decisions', 'delete']];
    }

    #[DataProvider('retainedReviewRecords')]
    public function test_original_requests_and_review_decisions_cannot_be_rewritten_or_deleted(string $table, string $operation): void
    {
        $owner = $this->owner();
        $admin = User::factory()->admin()->create();
        $item = $this->submit($owner);
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', $this->decision($admin, $item, 'decline'))->assertOk();
        $before = DB::table($table)->sole();
        try {
            $query = DB::table($table)->where('id', $before->id);
            $operation === 'delete' ? $query->delete() : $query->update([$table === 'account_review_requests' ? 'message' : 'reason' => 'Altered original record']);
            $this->fail('Original review evidence must remain unchanged.');
        } catch (QueryException) {
            $this->assertEquals($before, DB::table($table)->sole());
        }
    }

    public function test_stale_reviews_cannot_change_a_newer_account_state(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->admin()->create();
        $item = $this->submit($owner);
        $data = $this->decision($admin, $item, 'reactivate');
        $owner->update(['status' => 'inactive']);
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', $data)->assertConflict();
        $this->assertSame('inactive', $owner->fresh()->status);
        $this->assertDatabaseCount('account_review_decisions', 0);
        $this->postJson('/admin/account-reviews/'.$item->id.'/decision', $this->decision($admin, $item, 'close_stale'))->assertOk();
        $this->assertSame('superseded', $item->fresh()->status);
    }

    public function test_only_eligible_other_admins_can_read_and_decide_review_requests_on_both_routes(): void
    {
        $owner = $this->owner();
        $item = $this->submit($owner);
        $admin = User::factory()->admin()->create();
        $data = $this->decision($admin, $item, 'decline');
        foreach (['/admin/account-reviews', 'http://admin.localhost/account-reviews'] as $path) {
            $this->actingAs($owner)->getJson($path)->assertForbidden();
            $this->actingAs($admin)->get($path)->assertOk();
        }
        $this->actingAs($admin)->get('/admin/account-reviews/'.str_repeat('9', 60))->assertNotFound();
        $this->postJson('/admin/account-reviews/'.str_repeat('9', 60).'/decision', $data)->assertNotFound();
        $admin->update(['status' => 'suspended']);
        $this->actingAs($admin)->postJson('/admin/account-reviews/'.$item->id.'/decision', $data)->assertForbidden();
        $this->assertDatabaseCount('account_review_decisions', 0);
    }

    public function test_owned_status_hides_another_roles_request_even_when_the_email_is_shared(): void
    {
        $buyer = $this->owner();
        $seller = User::factory()->create(['role' => 'seller', 'email' => $buyer->email, 'status' => 'suspended', 'kyc_status' => 'approved']);
        $item = $this->submit($buyer);
        $this->actingAs($seller)->get('/account/status')->assertInertia(fn (Assert $page) => $page->has('status.requests', 0));
        $this->actingAs($buyer)->get('/account/status')->assertInertia(fn (Assert $page) => $page->has('status.requests', 1)->where('status.requests.0.id', $item->id));
    }

    public function test_closed_accounts_cannot_sign_in_or_request_reopening_through_an_appeal(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['status' => 'inactive', 'closed_at' => now()])->save();
        $this->post('/login', ['email' => $owner->email, 'password' => 'AccountPassword1!', 'role' => 'buyer'])->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($owner)->getJson('/account/status')->assertForbidden();
        $this->assertDatabaseCount('account_review_requests', 0);
    }
}
