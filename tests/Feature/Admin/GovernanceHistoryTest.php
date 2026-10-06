<?php

namespace Tests\Feature\Admin;

use App\Models\AccountClosure;
use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\Product;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\AccountRestrictionService;
use App\Services\GovernanceHistoryService;
use App\Services\IdentityCorrectionService;
use App\Services\ProductModerationService;
use App\Services\ResourceRestrictionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class GovernanceHistoryTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['password' => 'Password1234', 'birthday' => '1990-01-01']);
    }

    private function records(): array
    {
        $actor = $this->admin();
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id]);
        $moderation = app(ProductModerationService::class);
        $moderation->decide($actor, $product, ['action' => 'remove', 'reason' => 'Correct the product description before reinstatement.',
            'source_token' => app(AccountRestrictionService::class)->token($moderation->state($product))]);
        $buyer = User::factory()->buyer()->create(['name' => 'Maria Santos', 'status' => 'pending_approval', 'kyc_status' => 'pending_approval',
            'birthday' => '2000-01-01', 'phone' => '+639171234567', 'address' => '123 Rizal Street', 'city' => 'Manila']);
        $this->actingAs($actor)->post('/admin/kyc/'.$buyer->id.'/approve', $this->prepareKycReview($actor, $buyer))->assertSessionHasNoErrors();
        $buyer = $buyer->fresh();
        $corrections = app(IdentityCorrectionService::class);
        $this->actingAs($buyer)->post('/account/identity-corrections', ['source_token' => $corrections->form($buyer, $buyer)['source_token'],
            'changes' => ['birthday' => '1999-04-03'], 'reason' => 'Correct the birthday shown on the retained identity document.'])->assertSessionHasNoErrors();
        $request = IdentityCorrectionRequest::sole();
        foreach ($corrections->presentation($request)['documents'] as $url) {
            $this->actingAs($actor)->get($url)->assertOk();
        }
        $this->actingAs($actor)->post('/admin/identity-corrections/'.$request->id.'/decision', ['action' => 'approve',
            'reason' => 'The reviewed evidence confirms the corrected birthday.', 'affected_work_confirmed' => true,
            'review_token' => $corrections->reviewToken($request)])->assertSessionHasNoErrors();
        $restrictions = app(AccountRestrictionService::class);
        $restrictions->decide($actor, $buyer, ['action' => 'suspend', 'reason' => 'Review the current account responsibilities.',
            'affected_work_confirmed' => true, 'source_token' => $restrictions->token($restrictions->state($buyer->fresh()))]);
        $unused = User::factory()->buyer()->create();
        $closure = app(AccountClosureService::class);
        $closure->close($actor, $unused->id, ['password' => 'Password1234', 'reason' => 'Close this unused account after reviewing its references.',
            'source_token' => $closure->presentation($actor, $unused->id)['source_token']]);

        return [$actor, $buyer, $shop, $product, $unused->id];
    }

    private function network(string $code): array
    {
        $owner = User::factory()->logistics()->create();
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo '.$code.' Network', 'slug' => strtolower($code),
            'code' => $code, 'status' => 'active', 'is_active' => true]);
        $hub = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo '.$code.' Hub', 'code' => $code.'-HUB',
            'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => '123 Test Street', 'is_active' => true]);
        $handler = HubHandler::create(['user_id' => User::factory()->logistics()->create()->id, 'hub_id' => $hub->id, 'is_active' => true]);
        $fleet = LogisticsFleet::create(['logistics_company_id' => $company->id, 'hub_id' => $hub->id, 'plate_number' => $code.'-TEST',
            'vehicle_type' => 'motorcycle', 'status' => 'active']);

        return [$owner, $company, $hub, $handler, $fleet];
    }

    public function test_six_real_sources_keep_their_own_provenance_and_recorded_actor(): void
    {
        [$actor, $buyer, $shop, $product, $closedId] = $this->records();
        $actorName = $actor->name;
        $actor->update(['name' => 'Updated Reviewer Name']);
        $results = app(GovernanceHistoryService::class)->search($actor, []);
        $this->assertSame(6, $results->total());
        $this->assertSame(array_keys(GovernanceHistoryService::SOURCES), collect($results->items())->pluck('source')->sort()->values()->sortBy(fn ($value) => array_search($value, array_keys(GovernanceHistoryService::SOURCES)))->values()->all());
        foreach ($results->items() as $event) {
            $this->assertArrayHasKey('before', $event);
            $this->assertArrayHasKey('after', $event);
            $this->assertNotEmpty($event['occurred_at']);
            $this->assertNotEmpty($event['source_label']);
            if ($event['source'] !== 'shop_review') {
                $this->assertSame($actorName, $event['actor']['name']);
            }
        }
        $this->assertDatabaseCount('kyc_decisions', 1);
        $this->assertDatabaseCount('identity_correction_decisions', 1);
        $this->assertDatabaseCount('product_moderation_decisions', 1);
        $events = collect($results->items())->keyBy('source');
        $this->assertSame('pending_approval', $events['kyc_review']['before']['account']['status']);
        $this->assertSame('approved', $events['kyc_review']['after']['account']['kyc_status']);
        $this->assertSame('active', $events['restriction']['before']['subject']['status']);
        $this->assertSame('suspended', $events['restriction']['after']['subject']['status']);
        $this->assertSame(false, $events['product_moderation']['before']['product']['compliance_restricted']);
        $this->assertSame(true, $events['product_moderation']['after']['product']['compliance_restricted']);
        $this->assertSame('2000-01-01', $events['identity_correction']['before']['identity']['values']['birthday']);
        $this->assertSame('1999-04-03', $events['identity_correction']['after']['identity']['values']['birthday']);
        $this->assertNull($events['identity_correction']['actor']['role']);
        $this->assertSame(['source' => 'kyc_review', 'id' => KycDecision::sole()->id], $events['identity_correction']['prior_decision']);
        $this->actingAs($actor)->get('/governance-history')->assertInertia(fn (Assert $page) => $page->component('Governance/History')->has('events.data', 6)->where('scope', 'platform'));
        $this->get('/admin/users/'.$buyer->id.'/context')->assertInertia(fn (Assert $page) => $page->component('Admin/AccountContext')
            ->where('context.account.kyc_status', 'approved')->where('context.account.status', 'suspended')
            ->where('context.account.birthday', '1999-04-03')->where('context.identity_provenance', 'recorded_reviewed_correction'));
        $this->assertFalse(User::whereKey($closedId)->exists());
    }

    public function test_context_distinguishes_current_shop_company_placement_duty_and_legacy(): void
    {
        $admin = $this->admin();
        [$owner, $company, $hub, $handler] = $this->network('CTX');
        $rider = User::factory()->courier()->create();
        CourierProfile::factory()->create(['user_id' => $rider->id, 'logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id, 'is_available' => false]);
        $this->actingAs($admin)->get('/admin/users/'.$rider->id.'/context')->assertInertia(fn (Assert $page) => $page
            ->where('context.account.role', 'courier')->where('context.account.status', 'active')
            ->where('context.approval_provenance', 'legacy_without_recorded_review')
            ->where('context.scope.courier.logistics_company_id', $company->id)->where('context.scope.courier.assigned_hub_id', $hub->id)
            ->where('context.scope.courier.is_available', false));
        $this->get('/admin/users/'.$owner->id.'/context')->assertInertia(fn (Assert $page) => $page->where('context.scope.companies.0.id', $company->id));
        $this->get('/admin/users/'.$handler->user_id.'/context')->assertInertia(fn (Assert $page) => $page->where('context.scope.handlers.0.hub_id', $hub->id));
        $shop = Shop::factory()->approved()->create();
        $shop->update(['status' => 'inactive']);
        $this->get('/admin/users/'.$shop->user_id.'/context')->assertInertia(fn (Assert $page) => $page->where('context.shops.0.status', 'inactive')
            ->where('context.shops.0.review_status', 'approved')->where('context.shops.0.eligible', false));
    }

    public function test_subject_actor_source_and_philippine_date_filters_are_precise(): void
    {
        [$actor, $buyer] = $this->records();
        $results = app(GovernanceHistoryService::class)->search($actor, ['subject_type' => 'account', 'subject_id' => $buyer->id, 'actor_id' => $actor->id, 'source' => 'restriction']);
        $this->assertSame(1, $results->total());
        $this->assertSame($buyer->id, $results->items()[0]['subject']['id']);
        $this->assertSame('suspend', $results->items()[0]['action']);
        $this->assertSame(0, app(GovernanceHistoryService::class)->search($actor, ['actor_id' => 99999])->total());
        $before = User::factory()->buyer()->create();
        $inside = User::factory()->buyer()->create();
        $after = User::factory()->buyer()->create();
        foreach ([[$before, '2026-10-05 15:59:59'], [$inside, '2026-10-05 16:00:00'], [$after, '2026-10-06 16:00:00']] as [$subject, $time]) {
            AccountClosure::create(['subject_id' => $subject->id, 'subject_role' => 'buyer', 'subject_name' => $subject->name,
                'actor_id' => $actor->id, 'actor_role' => 'admin', 'actor_name' => $actor->name, 'source_token' => hash('sha256', 'date-'.$subject->id),
                'outcome' => 'retained', 'reason' => 'Explicit dated history fixture.', 'before_state' => [], 'closed_at' => CarbonImmutable::parse($time, 'UTC')]);
        }
        $dates = app(GovernanceHistoryService::class)->search($actor, ['source' => 'account_closure', 'from' => '2026-10-06', 'to' => '2026-10-06']);
        $ids = collect($dates->items())->pluck('subject.id')->all();
        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_equal_timestamp_pagination_is_stable_and_nonoverlapping(): void
    {
        $actor = $this->admin();
        $time = CarbonImmutable::parse('2026-10-06 01:00:00', 'UTC');
        for ($index = 0; $index < 18; $index++) {
            $subject = User::factory()->buyer()->create();
            AccountClosure::create(['subject_id' => $subject->id, 'subject_role' => 'buyer', 'subject_name' => $subject->name,
                'actor_id' => $actor->id, 'actor_role' => 'admin', 'actor_name' => $actor->name, 'source_token' => hash('sha256', 'page-'.$index),
                'outcome' => 'retained', 'reason' => 'Explicit tied-time history fixture.', 'before_state' => [], 'closed_at' => $time]);
        }
        $service = app(GovernanceHistoryService::class);
        $first = collect($service->search($actor, ['page' => 1])->items())->pluck('id')->all();
        $second = collect($service->search($actor, ['page' => 2])->items())->pluck('id')->all();
        $this->assertSame(range(18, 4), $first);
        $this->assertSame([3, 2, 1], $second);
        $this->assertSame($first, collect($service->search($actor, ['page' => 1])->items())->pluck('id')->all());
        $this->assertSame([], array_intersect($first, $second));
    }

    public static function invalidFilters(): array
    {
        return [['source', 'invented'], ['source', ['restriction']], ['subject_type', 'unknown'], ['subject_id', 'bad'],
            ['actor_id', -1], ['from', '2026-02-30'], ['to', 'tomorrow'], ['page', 0], ['page', 1000001], ['per_page', 999], ['export', 'all']];
    }

    #[DataProvider('invalidFilters')]
    public function test_bad_filters_reject_without_returning_history(string $field, mixed $value): void
    {
        $this->actingAs($this->admin())->getJson('/governance-history?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_reversed_dates_and_ambiguous_subject_id_reject(): void
    {
        $this->actingAs($this->admin())->getJson('/governance-history?from=2026-10-10&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/governance-history?subject_id=1')->assertUnprocessable()->assertJsonValidationErrors('subject_type');
    }

    public function test_company_views_only_own_resource_history_without_account_or_document_access(): void
    {
        $admin = $this->admin();
        [$owner, , $hub, $handler, $fleet] = $this->network('OWN');
        [, , $foreign] = $this->network('OTHER');
        $service = app(ResourceRestrictionService::class);
        foreach ([['hub', $hub], ['handler', $handler], ['fleet', $fleet], ['hub', $foreign]] as [$type, $resource]) {
            $service->decide($admin, $type, $resource->id, ['action' => 'suspend', 'reason' => 'Review these resource responsibilities.', 'affected_work_confirmed' => true,
                'source_token' => $service->presentation($admin, $type, $resource->id)['source_token']]);
        }
        $this->actingAs($owner)->get('/governance-history')->assertInertia(fn (Assert $page) => $page->has('events.data', 3)
            ->where('scope', 'own_company_resources')->where('subjectTypes', ['hub', 'handler', 'fleet'])->has('sources', 1));
        $own = RestrictionDecision::where('subject_type', 'hub')->where('subject_id', $hub->id)->firstOrFail();
        $other = RestrictionDecision::where('subject_type', 'hub')->where('subject_id', $foreign->id)->firstOrFail();
        $this->get('/governance-history/restriction/'.$own->id)->assertInertia(fn (Assert $page) => $page
            ->where('event.documents', [])->missing('event.before.work')->missing('event.before.owner')->where('event.subject.id', $hub->id));
        $this->get('/governance-history/restriction/'.$other->id)->assertNotFound();
        $this->get('/governance-history/kyc_review/99999')->assertForbidden();
        $this->get('/admin/users/'.$admin->id.'/context')->assertForbidden();
        $this->getJson('/governance-history?source=kyc_review')->assertUnprocessable();
        $this->get('/verification-documents/'.$admin->id.'/id.pdf')->assertForbidden();
    }

    public function test_historical_evidence_links_recheck_hash_and_current_privilege_without_exposing_secrets(): void
    {
        [$admin] = $this->records();
        $decision = KycDecision::sole();
        $detail = app(GovernanceHistoryService::class)->detail($admin, 'kyc_review', $decision->id);
        $url = $detail['documents'][0]['url'];
        $encoded = json_encode($detail);
        $this->assertStringNotContainsString('kyc_documents/', $encoded);
        $this->assertStringNotContainsString('source_token', $encoded);
        $this->assertStringNotContainsString('submission_token', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->actingAs($admin)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Storage::disk('local')->put($decision->submission['documents']['id']['path'], "%PDF-1.4\nChanged retained evidence\n%%EOF");
        $this->get($url)->assertConflict();
        $admin->update(['status' => 'inactive']);
        $this->actingAs($admin)->get($url)->assertForbidden();
    }

    public function test_snapshot_projection_removes_nested_passwords_paths_tokens_and_claim_codes(): void
    {
        $result = app(GovernanceHistoryService::class)->safeSnapshot(['account' => ['name' => 'Maria Santos', 'password' => 'secret-password',
            'id_document_path' => 'private/secret.pdf', 'source_token' => 'secret-token'], 'work' => [['order_id' => 4, 'parcel' => ['id' => 2, 'claim_code' => 'secret-claim']]],
            'documents' => ['id' => ['path' => 'private/secret.pdf']]]);
        $this->assertSame(['account' => ['name' => 'Maria Santos'], 'work' => [['order_id' => 4, 'parcel' => ['id' => 2]]]], $result);
        $this->assertStringNotContainsString('secret', json_encode($result));
    }

    public function test_deleted_and_retained_closed_subjects_keep_history_and_honest_current_context(): void
    {
        [$admin, , , , $deletedId] = $this->records();
        $closure = AccountClosure::where('subject_id', $deletedId)->firstOrFail();
        $this->actingAs($admin)->get('/admin/users/'.$deletedId.'/context')->assertInertia(fn (Assert $page) => $page
            ->where('context.record_state', 'deleted_after_safe_closure')->where('context.account.status', null)
            ->where('context.approval_provenance', 'current_account_unavailable')->where('context.closure_url', '/governance-history/account_closure/'.$closure->id));
        $this->get('/governance-history/account_closure/'.$closure->id)->assertInertia(fn (Assert $page) => $page->where('event.subject.name', $closure->subject_name));
        $subject = User::factory()->buyer()->create();
        $this->addKycEvidence($subject);
        $service = app(AccountClosureService::class);
        $retained = $service->close($admin, $subject->id, ['password' => 'Password1234', 'reason' => 'Close while preserving recorded private evidence.',
            'source_token' => $service->presentation($admin, $subject->id)['source_token']]);
        $originalName = $subject->name;
        $subject->fresh()->update(['name' => 'Closed account']);
        $this->get('/admin/users/'.$subject->id.'/context')->assertInertia(fn (Assert $page) => $page->where('context.record_state', 'closed_and_retained')->where('context.account.name', 'Closed account'));
        $this->get('/governance-history/account_closure/'.$retained->id)->assertInertia(fn (Assert $page) => $page->where('event.subject.name', $originalName));
    }

    public static function deniedActors(): array
    {
        return [['buyer', 'active'], ['seller', 'active'], ['courier', 'active'], ['logistics', 'active'],
            ['admin', 'inactive'], ['admin', 'suspended'], ['admin', 'unknown']];
    }

    #[DataProvider('deniedActors')]
    public function test_list_and_detail_deny_ineligible_actors_on_root_and_subdomain(string $role, string $status): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => $status]);
        foreach (['http://localhost', 'http://admin.localhost'] as $host) {
            $this->actingAs($actor)->get($host.'/governance-history')->assertForbidden();
            $this->actingAs($actor)->get($host.'/governance-history/account_closure/999')->assertForbidden();
        }
    }

    public function test_stale_actor_and_suspended_company_cannot_keep_history_access(): void
    {
        $admin = $this->admin();
        $stale = $admin->fresh();
        $admin->update(['status' => 'inactive']);
        $this->actingAs($stale)->get('/governance-history')->assertForbidden();
        [$owner, $company] = $this->network('SUSP');
        $company->update(['is_active' => false]);
        $this->actingAs($owner)->get('/governance-history')->assertForbidden();
    }

    public function test_empty_missing_and_read_only_requests_do_not_invent_or_modify_history(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/governance-history')->assertInertia(fn (Assert $page) => $page->where('events.data', [])->where('events.total', 0));
        $this->get('/governance-history/unknown/1')->assertNotFound();
        $this->get('/governance-history/kyc_review/999')->assertNotFound();
        $this->get('/admin/users/999/context')->assertNotFound();
        $this->post('/governance-history')->assertStatus(405);
        $this->delete('/governance-history/kyc_review/1')->assertStatus(405);
        $this->patch('/governance-history/kyc_review/1', ['decision' => 'approved'])->assertStatus(405);
        foreach (['kyc_decisions', 'shop_review_decisions', 'restriction_decisions', 'identity_correction_decisions', 'account_closures', 'product_moderation_decisions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_reading_every_source_preserves_all_decision_records(): void
    {
        [$admin] = $this->records();
        $before = [];
        foreach (GovernanceHistoryService::SOURCES as $source => $definition) {
            $table = (new $definition['model'])->getTable();
            $before[$table] = DB::table($table)->get()->toArray();
            $decision = $definition['model']::sole();
            $this->actingAs($admin)->get('/governance-history/'.$source.'/'.$decision->id)->assertOk();
        }
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->toArray());
        }
    }
}
