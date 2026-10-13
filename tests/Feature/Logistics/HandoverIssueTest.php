<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\HandoverIssue;
use App\Models\HandoverIssueEvent;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Logistics\HandoverIssueService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class HandoverIssueTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function input(Delivery $parcel, array $overrides = []): array
    {
        return array_replace(['barcode' => $parcel->tracking_number, 'kind' => 'missed_scan', 'reported_holder' => 'Pickup rider Ana',
            'reported_location' => 'At the shop pickup desk', 'notes' => 'The parcel was handed over but the waybill step appears missing.',
            'evidence' => UploadedFile::fake()->image('handover.png'), 'request_token' => (string) Str::uuid()], $overrides);
    }

    private function seller(Delivery $parcel): User
    {
        return $parcel->order->shop->user;
    }

    private function report(Delivery $parcel, ?User $actor = null, array $input = []): HandoverIssue
    {
        $this->actingAs($actor ?? $this->seller($parcel))->postJson('/handover-issues', $this->input($parcel, $input))->assertOk();

        return HandoverIssue::where('delivery_id', $parcel->id)->latest('id')->firstOrFail();
    }

    private function reviewer(Delivery $parcel): User
    {
        return LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
    }

    private function decision(HandoverIssue $issue, User $actor, string $decision = 'confirm'): array
    {
        $detail = $this->actingAs($actor)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');

        return ['decision' => $decision, 'notes' => 'I checked the supporting document and the recorded parcel facts.', 'evidence_reviewed' => true,
            'expected_version' => $detail['version'], 'source_token' => $detail['source_token'], 'request_token' => (string) Str::uuid()];
    }

    private function snapshot(Delivery $parcel): array
    {
        $result = ['parcel' => $parcel->fresh()->getAttributes(), 'order' => $parcel->order->fresh()->getAttributes()];
        foreach (['products', 'order_items', 'delivery_checkpoints', 'delivery_attempts', 'cod_custody_entries', 'logistics_manifests', 'logistics_manifest_events', 'logistics_manifest_parcels'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public static function reportingRoles(): array
    {
        return ['seller root' => ['seller', 'unassigned', 'http://localhost'], 'seller subdomain' => ['seller', 'unassigned', 'http://seller.localhost'],
            'pickup rider root' => ['courier', 'assigned_pickup', 'http://localhost'], 'pickup rider subdomain' => ['courier', 'assigned_pickup', 'http://courier.localhost'],
            'delivery rider' => ['courier', 'out_for_delivery', 'http://localhost'], 'handler root' => ['handler', 'arrived_at_origin_hub', 'http://localhost'],
            'handler subdomain' => ['handler', 'arrived_at_origin_hub', 'http://hub.localhost'], 'company manager' => ['manager', 'picked_up', 'http://hub.localhost']];
    }

    #[DataProvider('reportingRoles')]
    public function test_involved_roles_can_report_without_changing_commerce_custody_or_cash(string $role, string $stage, string $host): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $stage);
        $actor = match ($role) {
            'seller' => $this->seller($parcel),
            'courier' => User::findOrFail($stage === 'out_for_delivery' ? $parcel->assigned_rider_id : $parcel->courier_id),
            'handler' => $this->flowHandler(LogisticsHub::findOrFail($parcel->current_hub_id)),
            'manager' => $this->reviewer($parcel),
        };
        $before = $this->snapshot($parcel);
        $this->actingAs($actor)->postJson($host.'/handover-issues', $this->input($parcel))->assertOk()->assertJsonPath('state', 'pending_review');
        $issue = HandoverIssue::sole();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertSame($actor->id, $issue->reporter_id);
        $this->assertSame($stage, $issue->source_snapshot['delivery_status']);
        $this->assertDatabaseCount('handover_issue_events', 1);
        Storage::disk('local')->assertExists($issue->evidence_path);
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->get($host.'/handover-issues/'.$issue->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Handover/Show')->where('issue.reporter_name', $actor->name)->where('issue.state', 'pending_review')
            ->missing('issue.evidence_path')->missing('issue.request_token')->missing('issue.request_fingerprint'));
    }

    public function test_seller_can_report_a_missed_readiness_step_with_server_owned_original_facts(): void
    {
        $parcel = $this->newFlowOrder('preparing')->delivery;
        $before = $this->snapshot($parcel);
        $issue = $this->report($parcel, input: ['kind' => 'missed_ready', 'barcode' => ' '.strtolower($parcel->tracking_number).' ',
            'state' => 'dismissed', 'source_snapshot' => ['custody' => ['kind' => 'courier']], 'evidence_path' => 'public/forged.pdf']);
        $this->assertSame('pending_review', $issue->state);
        $this->assertSame('preparing', $issue->source_snapshot['order_status']);
        $this->assertNull($issue->source_snapshot['pickup_rider_id']);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_unknown_unowned_and_unassigned_parcels_do_not_leak_through_reporting(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $before = $this->snapshot($parcel);
        foreach (['seller', 'courier', 'logistics'] as $role) {
            $this->actingAs($this->createApprovedUser($role))->postJson('/handover-issues', $this->input($parcel))->assertNotFound();
        }
        $this->actingAs($this->seller($parcel))->postJson('/handover-issues', $this->input($parcel, ['barcode' => 'UNKNOWN-WAYBILL']))->assertNotFound();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertDatabaseCount('handover_issues', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_an_unrelated_hub_handler_cannot_use_its_company_scope_to_report(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $unrelated = $this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id));
        $this->actingAs($unrelated)->postJson('/handover-issues', $this->input($parcel))->assertNotFound();
        $this->assertDatabaseCount('handover_issues', 0);
    }

    public static function barredAccounts(): array
    {
        return ['buyer' => ['buyer', 'active', 'approved'], 'pending seller' => ['seller', 'pending_approval', 'pending_approval'],
            'suspended rider' => ['courier', 'suspended', 'approved'], 'inactive manager' => ['logistics', 'inactive', 'approved']];
    }

    #[DataProvider('barredAccounts')]
    public function test_ineligible_accounts_are_rejected_even_if_loaded_before_the_change(string $role, string $status, string $kyc): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $actor = $this->createApprovedUser($role);
        $stale = clone $actor;
        $actor->update(['status' => $status, 'kyc_status' => $kyc]);
        $this->actingAs($stale)->getJson('/handover-issues')->assertForbidden();
        $this->postJson('/handover-issues', $this->input($parcel))->assertForbidden();
    }

    public function test_guest_and_admin_cannot_create_observations_and_guest_cannot_read(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $this->app['auth']->forgetGuards();
        $this->postJson('/handover-issues', $this->input($parcel))->assertUnauthorized();
        $this->getJson('/handover-issues')->assertUnauthorized();
        $this->actingAs($this->createApprovedUser('admin'))->postJson('/handover-issues', $this->input($parcel))->assertForbidden();
    }

    public function test_readiness_report_requires_the_owning_seller_before_the_ready_stage(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        foreach ([$rider, $this->seller($parcel)] as $actor) {
            $this->actingAs($actor)->postJson('/handover-issues', $this->input($parcel, ['kind' => 'missed_ready']))->assertUnprocessable()->assertJsonValidationErrors('kind');
        }
        $this->assertDatabaseCount('handover_issues', 0);
    }

    public function test_report_retries_return_the_original_result_and_conflicts_or_second_open_reports_fail(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $input = $this->input($parcel);
        $this->actingAs($this->seller($parcel))->postJson('/handover-issues', $input)->assertOk();
        $original = HandoverIssue::sole();
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues', $input)->assertOk()->assertJsonPath('reference', $original->reference);
        $this->postJson('/handover-issues', array_replace($input, ['notes' => 'A different observation uses the original request.']))->assertConflict();
        $this->postJson('/handover-issues', array_replace($input, ['request_token' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->assertDatabaseCount('handover_issues', 1);
        $this->assertDatabaseCount('handover_issue_events', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_a_request_token_cannot_be_reused_for_another_parcel(): void
    {
        $first = $this->newFlowOrder()->delivery;
        $second = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $first->order->shop)->delivery;
        $input = $this->input($first);
        $this->actingAs($this->seller($first))->postJson('/handover-issues', $input)->assertOk();
        $this->postJson('/handover-issues', array_replace($input, ['barcode' => $second->tracking_number]))->assertConflict();
        $this->assertDatabaseCount('handover_issues', 1);
    }

    public static function invalidInputs(): array
    {
        return ['blank holder' => ['reported_holder', '    '], 'short holder' => ['reported_holder', 'A'], 'long holder' => ['reported_holder', str_repeat('a', 101)],
            'short location' => ['reported_location', 'Desk'], 'long location' => ['reported_location', str_repeat('a', 256)],
            'short notes' => ['notes', 'Missing'], 'long notes' => ['notes', str_repeat('a', 1001)], 'hidden character' => ['notes', "Observed\u{200B} handover"],
            'markup' => ['notes', '<script>unsafe handover</script>'], 'invalid kind' => ['kind', 'delivered'], 'no token' => ['request_token', null],
            'bad token' => ['request_token', 'guess'], 'barcode control' => ['barcode', "BGO-FAKE\n"], 'oversized barcode' => ['barcode', str_repeat('A', 256)],
            'array input' => ['reported_holder', ['Unsafe holder']], 'missing evidence' => ['evidence', null]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_report_inputs_are_caught_without_any_partial_writes(string $field, mixed $value): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $before = $this->snapshot($parcel);
        $this->actingAs($this->seller($parcel))->postJson('/handover-issues', $this->input($parcel, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('handover_issues', 0);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public static function invalidUploads(): array
    {
        return ['video' => ['mp4', 'video'], 'text disguised as image' => ['png', 'forged'], 'image disguised as PDF' => ['pdf', 'image'], 'text disguised as PDF' => ['pdf', 'forged'],
            'empty PDF' => ['pdf', 'empty'], 'oversized PDF' => ['pdf', 'oversized']];
    }

    #[DataProvider('invalidUploads')]
    public function test_evidence_requires_real_supported_content_with_a_bounded_size(string $extension, string $contents): void
    {
        $original = $contents === 'image' ? UploadedFile::fake()->image('original.png')
            : UploadedFile::fake()->createWithContent('original.'.$extension, match ($contents) {
                'empty' => '', 'oversized' => '%PDF-1.4'.str_repeat(' ', 5 * 1024 * 1024), default => 'Plain text is not a verification image.',
            });
        $file = new UploadedFile($original->getPathname(), 'document.'.$extension, null, null, true);
        $parcel = $this->newFlowOrder()->delivery;
        $this->actingAs($this->seller($parcel))->postJson('/handover-issues', $this->input($parcel, ['evidence' => $file]))->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->assertDatabaseCount('handover_issues', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public static function additionalImageFormats(): array
    {
        return ['JPEG' => ['jpeg'], 'WEBP' => ['webp']];
    }

    #[DataProvider('additionalImageFormats')]
    public function test_supported_image_formats_accept_actual_image_contents(string $extension): void
    {
        $original = $extension === 'webp'
            ? UploadedFile::fake()->createWithContent('original.webp', base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAgA0JaQAA3AA/vuUAAA='))
            : UploadedFile::fake()->image('original.jpeg');
        $file = new UploadedFile($original->getPathname(), 'evidence.'.$extension, null, null, true);
        $issue = $this->report($this->newFlowOrder()->delivery, input: ['evidence' => $file]);
        $this->assertSame('image/'.$extension, $issue->evidence_mime);
        Storage::disk('local')->assertExists($issue->evidence_path);
    }

    public function test_exact_size_and_text_boundaries_accept_a_matching_pdf_and_normalize_outer_spaces(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $pdf = '%PDF-1.4'.str_repeat(' ', 5 * 1024 * 1024 - 8);
        $issue = $this->report($parcel, input: ['evidence' => UploadedFile::fake()->createWithContent('evidence.PDF', $pdf),
            'reported_holder' => ' '.str_repeat('a', 100).' ', 'reported_location' => str_repeat('a', 255), 'notes' => str_repeat('a', 1000)]);
        $this->assertSame(str_repeat('a', 100), $issue->reported_holder);
        $this->assertSame('application/pdf', $issue->evidence_mime);
        $this->assertSame(5 * 1024 * 1024, Storage::disk('local')->size($issue->evidence_path));
    }

    public function test_company_review_is_independent_and_retry_safe_without_creating_custody(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $manager = $this->reviewer($parcel);
        $input = $this->decision($issue, $manager);
        $before = $this->snapshot($parcel);
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertOk()->assertJsonPath('state', 'needs_recovery');
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertOk()->assertJsonPath('state', 'needs_recovery');
        $this->postJson('/handover-issues/'.$issue->id.'/review', array_replace($input, ['decision' => 'dismiss']))->assertConflict();
        $this->postJson('/handover-issues/'.$issue->id.'/review', array_replace($input, ['request_token' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseCount('handover_issue_events', 2);
        $this->assertSame($parcel->id, $issue->fresh()->active_delivery_id);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_platform_admin_can_review_a_managers_report_but_a_reporter_cannot_review_themself(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $manager = $this->reviewer($parcel);
        $issue = $this->report($parcel, $manager);
        $input = $this->decision($issue, $manager);
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertForbidden();
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->postJson('http://admin.localhost/handover-issues/'.$issue->id.'/review', $this->decision($issue, $admin))->assertOk();
        $this->assertDatabaseCount('handover_issue_events', 2);
    }

    public function test_handler_and_rider_cannot_review_and_another_company_cannot_read_or_review(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $issue = $this->report($parcel);
        $input = $this->decision($issue, $this->reviewer($parcel));
        $this->actingAs(User::findOrFail($parcel->courier_id))->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertForbidden();
        $arrived = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hubIssue = $this->report($arrived);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($arrived->current_hub_id));
        $this->actingAs($handler)->postJson('/handover-issues/'.$hubIssue->id.'/review', $input)->assertForbidden();
        $foreign = $this->createApprovedUser('logistics');
        $this->actingAs($foreign)->get('/handover-issues/'.$issue->id)->assertNotFound();
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertNotFound();
        $this->get('/handover-issues/'.$issue->id.'/evidence')->assertNotFound();
    }

    public function test_reviewer_losing_company_authority_cannot_save_an_old_review_page(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $manager = $this->reviewer($parcel);
        $input = $this->decision($issue, $manager);
        LogisticsCompany::findOrFail($parcel->logistics_company_id)->update(['status' => 'inactive']);
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertNotFound();
        $this->assertSame('pending_review', $issue->fresh()->state);
        $this->assertDatabaseCount('handover_issue_events', 1);
    }

    public function test_changed_custody_requires_fresh_review_and_preserves_original_report_snapshot(): void
    {
        $parcel = $this->newFlowOrder('preparing')->delivery;
        $issue = $this->report($parcel, input: ['kind' => 'missed_ready']);
        $manager = $this->reviewer($parcel);
        $input = $this->decision($issue, $manager);
        $this->actingAs($this->seller($parcel))->withSession(['active_seller_shop_id' => $parcel->order->shop->id])
            ->post(route('seller.orders.ready', $parcel->order))->assertSessionHasNoErrors()->assertSessionHas('success');
        $before = $this->snapshot($parcel);
        $this->actingAs($manager)->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertConflict();
        $fresh = $this->decision($issue, $manager);
        $this->postJson('/handover-issues/'.$issue->id.'/review', $fresh)->assertOk();
        $this->assertSame('preparing', $issue->fresh()->source_snapshot['order_status']);
        $this->assertSame('ready_for_pickup', $issue->events()->where('event_type', 'confirmed')->sole()->parcel_snapshot['order_status']);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_review_requires_supporting_evidence_acknowledgement_and_valid_plain_notes(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $input = $this->decision($issue, $this->reviewer($parcel));
        $this->postJson('/handover-issues/'.$issue->id.'/review', array_replace($input, ['evidence_reviewed' => false]))->assertUnprocessable()->assertJsonValidationErrors('evidence_reviewed');
        $this->postJson('/handover-issues/'.$issue->id.'/review', array_replace($input, ['notes' => '   ']))->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->assertSame('pending_review', $issue->fresh()->state);
        $this->assertDatabaseCount('handover_issue_events', 1);
    }

    public function test_dismissal_retains_history_and_allows_one_new_open_report(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $input = $this->decision($issue, $this->reviewer($parcel), 'dismiss');
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertOk()->assertJsonPath('state', 'dismissed');
        $this->assertNull($issue->fresh()->active_delivery_id);
        $second = $this->report($parcel);
        $this->assertNotSame($issue->id, $second->id);
        $this->assertDatabaseCount('handover_issues', 2);
        $this->assertDatabaseCount('handover_issue_events', 3);
        $this->actingAs($this->seller($parcel))->get('/handover-issues?status=dismissed')->assertInertia(fn (Assert $page) => $page
            ->has('issues.data', 1)->where('issues.data.0.id', $issue->id));
    }

    public function test_original_reporter_retains_history_after_assignment_ends_without_permission_for_new_reports(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $rider = User::findOrFail($parcel->courier_id);
        $issue = $this->report($parcel, $rider);
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->confirmFlowScan($parcel, $origin, 'RECEIVE_FROM_PICKUP_RIDER');
        $this->actingAs($rider)->get('/handover-issues/'.$issue->id)->assertOk();
        $this->get('/handover-issues/'.$issue->id.'/evidence')->assertOk();
        $this->postJson('/handover-issues', $this->input($parcel))->assertNotFound();
    }

    public function test_evidence_download_rechecks_scope_and_hash_and_never_exposes_storage_paths(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $this->get('/handover-issues/'.$issue->id.'/evidence')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png')->assertDownload($issue->reference.'.png');
        $this->actingAs($this->createApprovedUser('seller'))->get('/handover-issues/'.$issue->id.'/evidence')->assertNotFound();
        $this->actingAs($this->seller($parcel));
        Storage::disk('local')->put($issue->evidence_path, 'Altered evidence');
        $this->get('/handover-issues/'.$issue->id.'/evidence')->assertConflict();
        Storage::disk('local')->delete($issue->evidence_path);
        $this->get('/handover-issues/'.$issue->id.'/evidence')->assertNotFound();
    }

    public static function immutableWrites(): array
    {
        return ['case model change' => ['case', 'model_update'], 'case raw change' => ['case', 'raw_update'], 'case model delete' => ['case', 'model_delete'],
            'case raw delete' => ['case', 'raw_delete'], 'event model change' => ['event', 'model_update'], 'event raw change' => ['event', 'raw_update'],
            'event model delete' => ['event', 'model_delete'], 'event raw delete' => ['event', 'raw_delete']];
    }

    #[DataProvider('immutableWrites')]
    public function test_original_facts_and_every_event_resist_model_and_direct_database_changes(string $record, string $action): void
    {
        $issue = $this->report($this->newFlowOrder()->delivery);
        $model = $record === 'case' ? $issue : $issue->events()->first();
        $this->expectException(str_starts_with($action, 'model') ? LogicException::class : QueryException::class);
        match ($action) {
            'model_update' => $model->update(['notes' => 'Replacement report facts']), 'model_delete' => $model->delete(),
            'raw_update' => DB::table($model->getTable())->where('id', $model->id)->update(['notes' => 'Replacement report facts']),
            'raw_delete' => DB::table($model->getTable())->where('id', $model->id)->delete(),
        };
    }

    public function test_failed_report_rolls_back_database_and_removes_its_private_upload(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $before = $this->snapshot($parcel);
        $event = 'eloquent.created: '.HandoverIssue::class;
        Event::listen($event, fn () => throw new RuntimeException('Simulated report write failure'));
        try {
            app(HandoverIssueService::class)->report($this->seller($parcel), $this->input($parcel));
            $this->fail('The simulated database failure did not occur.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated report write failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('handover_issues', 0);
        $this->assertDatabaseCount('handover_issue_events', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_failed_review_rolls_back_decision_and_history_together(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $manager = $this->reviewer($parcel);
        $input = $this->decision($issue, $manager);
        $event = 'eloquent.created: '.HandoverIssueEvent::class;
        Event::listen($event, fn () => throw new RuntimeException('Simulated review write failure'));
        try {
            app(HandoverIssueService::class)->review($manager, $issue, $input);
            $this->fail('The simulated review failure did not occur.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated review write failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('pending_review', $issue->fresh()->state);
        $this->assertSame(1, $issue->fresh()->version);
        $this->assertDatabaseCount('handover_issue_events', 1);
    }

    public function test_empty_filtered_and_invalid_list_requests_are_clear_and_scoped(): void
    {
        $this->actingAs($this->createApprovedUser('admin'))->get('/handover-issues')->assertInertia(fn (Assert $page) => $page
            ->component('Handover/Index')->where('canReport', false)->where('status', 'open')->has('issues.data', 0));
        $this->getJson('/handover-issues?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/handover-issues?page=-1')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_competing_reporters_cannot_create_two_open_reports_for_one_parcel(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $first = $this->report($parcel);
        $this->actingAs(User::findOrFail($parcel->courier_id))->postJson('/handover-issues', $this->input($parcel))
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->get('/handover-issues/'.$first->id)->assertOk();
        $this->assertDatabaseCount('handover_issues', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_another_reviewers_stale_decision_cannot_overwrite_the_first_decision(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $manager = $this->reviewer($parcel);
        $admin = $this->createApprovedUser('admin');
        $managerInput = $this->decision($issue, $manager, 'dismiss');
        $adminInput = $this->decision($issue, $admin);
        $this->actingAs($manager)->postJson('/handover-issues/'.$issue->id.'/review', $managerInput)->assertOk();
        $this->actingAs($admin)->postJson('/handover-issues/'.$issue->id.'/review', $adminInput)->assertConflict();
        $this->assertSame('dismissed', $issue->fresh()->state);
        $this->assertDatabaseCount('handover_issue_events', 2);
    }

    public function test_a_relocated_rider_cannot_report_original_work(): void
    {
        $pickup = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($pickup->courier_id);
        $rider->courierProfile->update(['assigned_hub_id' => $pickup->destination_bayan_hub_id]);
        $this->actingAs($rider)->postJson('/handover-issues', $this->input($pickup))->assertNotFound();
        $this->assertDatabaseCount('handover_issues', 0);
    }

    public function test_a_removed_handler_cannot_report_original_work(): void
    {
        $arrived = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $handler = $this->flowHandler(LogisticsHub::findOrFail($arrived->current_hub_id));
        HubHandler::where('user_id', $handler->id)->update(['is_active' => false]);
        $this->actingAs($handler)->postJson('/handover-issues', $this->input($arrived))->assertNotFound();
        $this->assertDatabaseCount('handover_issues', 0);
    }

    public function test_a_legacy_mixed_shop_order_cannot_be_reported_as_a_wholly_owned_parcel(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $foreign = $this->createE2EProduct($this->createApprovedUser('seller')->shop);
        OrderItem::factory()->create(['order_id' => $parcel->order_id, 'shop_id' => $foreign->shop_id, 'product_id' => $foreign->id]);
        $this->actingAs($this->seller($parcel))->postJson('/handover-issues', $this->input($parcel))->assertNotFound();
        $this->assertDatabaseCount('handover_issues', 0);
    }

    public function test_terminal_orders_reject_new_reports(): void
    {
        $order = $this->newFlowOrder();
        $this->actingAs($this->seller($order->delivery))->post(route('seller.orders.cancel', $order), ['reason' => OrderLifecycleService::SELLER_CANCELLATION_REASONS[0]])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->postJson('/handover-issues', $this->input($order->delivery))->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->assertDatabaseCount('handover_issues', 0);
    }

    public function test_reports_leave_normal_pickup_readiness_checks_in_place(): void
    {
        $parcel = $this->newFlowOrder('preparing')->delivery;
        $this->report($parcel, input: ['kind' => 'missed_ready']);
        $rider = $this->flowRider(LogisticsHub::findOrFail($parcel->origin_bayan_hub_id));
        $before = $this->snapshot($parcel);
        $this->actingAs($rider)->post(route('courier.claim', $parcel))->assertSessionHas('error', 'This parcel is not ready for pickup.');
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_list_pagination_keeps_filters_and_excludes_another_sellers_reports(): void
    {
        $order = $this->newFlowOrder();
        $shop = $order->shop;
        $this->report($order->delivery);
        for ($index = 0; $index < 15; $index++) {
            $next = $this->checkoutFlowOrder($order->buyer, $shop);
            app(HandoverIssueService::class)->report($shop->user, $this->input($next->delivery));
        }
        $this->report($this->newFlowOrder()->delivery);
        $this->actingAs($shop->user)->get('/handover-issues?status=all&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('issues.total', 16)->where('issues.current_page', 2)->has('issues.data', 1)->where('issues.data.0.id', 1)
            ->where('status', 'all')->where('issues.prev_page_url', fn ($url) => str_contains($url, 'status=all') && str_contains($url, 'page=1')));
    }

    public function test_migration_rollback_cannot_erase_existing_handover_evidence(): void
    {
        $this->report($this->newFlowOrder()->delivery);
        $migration = require database_path('migrations/2026_10_13_210000_create_handover_issues.php');
        $this->expectException(LogicException::class);
        $migration->down();
    }

    public function test_missing_or_changed_evidence_prevents_a_new_review_decision(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $issue = $this->report($parcel);
        $input = $this->decision($issue, $this->reviewer($parcel));
        Storage::disk('local')->put($issue->evidence_path, 'Different document');
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertConflict();
        Storage::disk('local')->delete($issue->evidence_path);
        $this->postJson('/handover-issues/'.$issue->id.'/review', $input)->assertNotFound();
        $this->assertSame('pending_review', $issue->fresh()->state);
        $this->assertDatabaseCount('handover_issue_events', 1);
    }
}
