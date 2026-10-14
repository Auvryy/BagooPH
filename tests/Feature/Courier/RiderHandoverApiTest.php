<?php

namespace Tests\Feature\Courier;

use App\Models\CodAccount;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\HandoverIssue;
use App\Models\HandoverIssueEvent;
use App\Models\HandoverRecoveryGrant;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\RiderCommand;
use App\Models\User;
use App\Services\Courier\RiderTaskService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\RiderAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class RiderHandoverApiTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->flowStorageFaked = true;
        $this->flowAttemptStorageFaked = true;
    }

    private function native(User $actor, ?string $key = null): string
    {
        $session = app(RiderAccountService::class)->session($actor->fresh(), 'Handover acceptance');
        $this->withToken($session['token'])->withHeader('Idempotency-Key', $key ?? (string) Str::uuid());

        return $session['token'];
    }

    private function body(Delivery $parcel, array $extra = []): array
    {
        return array_replace(['barcode' => $parcel->tracking_number, 'kind' => 'missed_scan',
            'reported_holder' => 'The known rider holding this parcel', 'reported_location' => 'At the assigned hub receiving desk',
            'notes' => 'The actual handover happened without the expected waybill scan.',
            'evidence' => UploadedFile::fake()->image('evidence.png')], $extra);
    }

    private function report(Delivery $parcel): HandoverIssue
    {
        $this->actingAs($parcel->order->shop->user)->postJson('/handover-issues',
            [...$this->body($parcel), 'request_token' => (string) Str::uuid()])->assertOk();

        return HandoverIssue::where('delivery_id', $parcel->id)->sole();
    }

    private function held(Delivery $parcel): HandoverIssue
    {
        $issue = $this->report($parcel);
        $reviewer = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/review', ['decision' => 'confirm',
            'notes' => 'The original private evidence and actual known holder were independently checked.',
            'evidence_reviewed' => true, 'expected_version' => $view['version'], 'source_token' => $view['source_token'],
            'request_token' => (string) Str::uuid()])->assertOk();

        return $issue->fresh()->load('delivery.order');
    }

    private function authorize(HandoverIssue $issue, User $holder, ?string $mode = null, ?int $reference = null): HandoverRecoveryGrant
    {
        $reviewer = LogisticsCompany::findOrFail($issue->delivery->logistics_company_id)->user;
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $input = ['holder_id' => $holder->id, 'holder_verified' => true, 'evidence_reviewed' => true,
            'notes' => 'I independently checked this exact physical holder and original receiving route.',
            'expected_version' => $view['version'], 'request_token' => (string) Str::uuid()];
        if ($mode) {
            $option = collect($view['hub_recovery']['options'])->first(fn ($item) => $item['mode'] === $mode && $item['reference_id'] === $reference);
            $this->assertNotNull($option);
            $input += ['source_token' => $option['source_token'], 'mode' => $mode, 'reference_id' => $reference];
        } else {
            $input['source_token'] = $view['recovery']['source_token'];
        }
        $result = $this->postJson('/handover-issues/'.$issue->id.($mode ? '/authorize-hub-recovery' : '/authorize-recovery'), $input)->assertOk();

        return HandoverRecoveryGrant::findOrFail($result->json('id'));
    }

    private function acknowledgement(HandoverIssue $issue, User $holder): array
    {
        $this->native($holder);
        $view = $this->getJson('/api/v1/rider/handover-issues/'.$issue->id)->assertOk()->json('data.recovery');

        return ['expected_version' => $view['expected_version'], 'source_token' => $view['source_token'],
            'barcode' => $issue->delivery->tracking_number, 'notes' => 'I physically hold this exact parcel and will follow its authorized receiving route.',
            'possession_verified' => true];
    }

    private function receipt(HandoverIssue $issue, HandoverRecoveryGrant $grant): void
    {
        $handler = $this->flowHandler($grant->hub);
        $view = $this->actingAs($handler)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $source = $view['recovery']['grant'] ?? $view['hub_recovery']['grant'];
        $this->postJson('/handover-issues/recovery/'.$grant->id.'/receive', ['expected_version' => $view['version'],
            'source_token' => $source['source_token'], 'barcode' => $issue->delivery->tracking_number,
            'notes' => 'I actually received the matching parcel from its authorized holder at this facility.',
            'possession_verified' => true, 'request_token' => (string) Str::uuid()])->assertOk();
    }

    private function businessState(): array
    {
        $state = [];
        foreach (['orders', 'deliveries', 'products', 'order_items', 'delivery_checkpoints', 'handover_issues', 'handover_issue_events',
            'handover_recovery_grants', 'handover_manifest_receipts', 'cod_accounts', 'cod_custody_entries', 'rider_commands'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    private function capture(string $schema, $response, string $variant = ''): void
    {
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertTrue(Str::isUuid($response->json('request_id')));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        foreach (['evidence_path', 'evidence_hash', 'source_snapshot', 'parcel_snapshot', 'recipient_phone', 'shipping_address',
            'credential_fingerprint', 'reporter_id', 'actor_id', 'request_fingerprint'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'":', $response->getContent());
        }
        if (getenv('HANDOVER_CONTRACT_ARTIFACTS') === '1') {
            $directory = base_path('.codex/handover-api/contract-samples');
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($directory.'/'.$schema.($variant ? '--'.$variant : '').'.json', json_encode($response->json(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_native_report_is_only_an_observation_and_exact_retry_reconciles_the_original_result(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        $key = (string) Str::uuid();
        $this->native($rider, $key);
        $body = $this->body($parcel);
        $before = [$parcel->getRawOriginal(), $parcel->order->getRawOriginal(), $parcel->checkpoints()->count()];
        $first = $this->postJson('/api/v1/rider/handover-issues', $body)->assertOk()
            ->assertJsonPath('data.result.state', 'pending_review')->assertJsonPath('data.result.held', false);
        $this->capture('HandoverReportCommandEnvelope', $first);
        $this->postJson('/api/v1/rider/handover-issues', $body)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.result', $first->json('data.result'));
        $this->capture('CommandEnvelope', $this->getJson('/api/v1/rider/commands/'.$key)->assertJsonPath('data.result', $first->json('data.result')));
        $this->assertSame($before, [$parcel->fresh()->getRawOriginal(), $parcel->order->fresh()->getRawOriginal(), $parcel->checkpoints()->count()]);
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame(1, HandoverIssue::count());
        $this->assertSame(1, HandoverIssueEvent::count());
        $this->assertSame(1, RiderCommand::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('handover-issue-evidence'));
        $issue = HandoverIssue::sole();
        $this->capture('HandoverDetailEnvelope', $this->getJson('/api/v1/rider/handover-issues/'.$issue->id));
        $this->capture('HandoverListEnvelope', $this->getJson('/api/v1/rider/handover-issues'));
        $download = $this->get('/api/v1/rider/handover-issues/'.$issue->id.'/evidence')->assertOk();
        $this->assertSame('image/png', $download->headers->get('Content-Type'));
        $this->assertSame($issue->evidence_hash, hash_file('sha256', $download->baseResponse->getFile()->getPathname()));
        $this->postJson('/api/v1/rider/handover-issues', [...$body, 'notes' => 'This key now describes a different physical observation.'])
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_seller_readiness_recovery_uses_native_holder_acknowledgement_and_actual_hub_receipt(): void
    {
        $parcel = $this->newFlowOrder()->delivery;
        $holder = $this->flowRider($parcel->originBayanHub);
        $seller = $parcel->order->shop->user;
        $issue = $this->held($parcel);
        $view = $this->actingAs($seller)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/confirm-fulfillment', ['expected_version' => $view['version'],
            'source_token' => $view['recovery']['source_token'], 'barcode' => $parcel->tracking_number,
            'notes' => 'I accepted and packed this original order and confirm the actual omitted readiness step.',
            'fulfillment_verified' => true, 'request_token' => (string) Str::uuid()])->assertOk();
        $grant = $this->authorize($issue->fresh()->load('delivery.order'), $holder);
        $input = $this->acknowledgement($issue, $holder);
        $this->capture('HandoverDetailEnvelope', $this->getJson('/api/v1/rider/handover-issues/'.$issue->id), 'pickup');
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key);
        $first = $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $input)->assertOk()
            ->assertJsonPath('data.result.held', true)->assertJsonPath('data.result.event_type', 'holder_acknowledged');
        $this->capture('HandoverAcknowledgeCommandEnvelope', $first);
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame('picked_up', $parcel->fresh()->status);
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
        $this->receipt($issue, $grant);
        $this->assertSame('recovered', $issue->fresh()->state);
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $this->confirmFlowScan($parcel, $parcel->originBayanHub, 'DISPATCH_TO_FEEDER');
        $this->confirmFlowScan($parcel, $parcel->originMotherHub, 'RECEIVE_AT_MOTHER_HUB');
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertSame(0, DB::table('cod_accounts')->count());
        $this->native($holder);
        $this->getJson('/api/v1/rider/handover-issues?status=recovered')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/rider/handover-issues')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/v1/rider/handover-issues/'.$issue->id)->assertOk()->assertJsonPath('data.held', false)
            ->assertJsonPath('data.recovery.actions.acknowledge', false);
        $this->confirmFlowScan($parcel, $parcel->originMotherHub, 'SORT_TO_LINE_HAUL');
        $this->confirmFlowManifest($parcel, $parcel->originMotherHub, true);
        $this->confirmFlowManifest($parcel, $parcel->destinationBayanHub, false);
        $handler = $this->flowHandler($parcel->destinationBayanHub);
        $this->actingAs($handler)->postJson(route('hub.sort'), ['delivery_id' => $parcel->id, 'barangay' => $parcel->order->destination_barangay])->assertOk();
        $final = $this->flowRider($parcel->destinationBayanHub);
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $final->id])->assertOk();
        $this->native($final);
        $this->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/depart', ['expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])),
            'barcode' => $parcel->tracking_number])->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/deliver', [
            ...array_diff_key($this->codCollectionInput($parcel), ['request_token' => true]),
            'expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])), 'proof_image_file' => UploadedFile::fake()->image('actual-delivery.png'),
        ])->assertOk();
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $cash = CodAccount::where('delivery_id', $parcel->id)->sole();
        $this->assertNull($cash->reconciled_at);
        $this->actingAs($parcel->order->buyer)->post(route('buyer.orders.confirm', $parcel->order))->assertSessionHas('success');
        $this->assertSame('completed', $parcel->order->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertNull($cash->fresh()->reconciled_at);
        $this->assertTrue(Storage::disk('local')->exists($issue->evidence_path));
    }

    public function test_native_manifest_holder_acknowledgement_preserves_original_transport_until_actual_corrective_receipt(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel)['manifest_id']);
        $holder = $manifest->driver;
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder, 'hub_manifest', $manifest->id);
        $input = $this->acknowledgement($issue, $holder);
        $this->capture('HandoverDetailEnvelope', $this->getJson('/api/v1/rider/handover-issues/'.$issue->id), 'manifest');
        $before = $parcel->fresh()->getRawOriginal();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $input)->assertOk();
        $this->assertSame($before, $parcel->fresh()->getRawOriginal());
        $this->assertTrue(Delivery::riderHasActiveWork($holder->id));
        $this->receipt($issue, $grant);
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
        $this->assertSame('recovered', $issue->fresh()->state);
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame('dispatched', $manifest->fresh()->status);
        $this->assertNull($manifest->parcels()->sole()->received_at);
        $this->assertSame(1, $manifest->correctiveReceipts()->count());
        $this->assertSame(0, DB::table('cod_accounts')->count());
    }

    public function test_named_final_mile_holder_cannot_deliver_a_held_parcel_and_only_actual_hub_receipt_releases_it(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_to_rider');
        $holder = User::findOrFail($parcel->assigned_rider_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $input = $this->acknowledgement($issue, $holder);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/depart',
            ['expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])), 'barcode' => $parcel->tracking_number])
            ->assertConflict()->assertJsonPath('code', 'HANDOVER_HOLD');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $input)->assertOk();
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->receipt($issue, $grant);
        $this->assertNull($parcel->fresh()->assigned_rider_id);
        $this->assertSame('arrived_at_destination_hub', $parcel->fresh()->status);
        $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
        $this->assertSame(0, DB::table('cod_accounts')->count());
    }

    public function test_other_riders_cannot_discover_report_history_evidence_or_acknowledge_its_grant(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $body = $this->acknowledgement($issue, $holder);
        $stranger = $this->flowRider($parcel->originBayanHub, $this->createApprovedUser('courier'));
        $this->native($stranger);
        $this->getJson('/api/v1/rider/handover-issues')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/v1/rider/handover-issues/'.$issue->id)->assertNotFound();
        $this->getJson('/api/v1/rider/handover-issues/'.$issue->id.'/evidence')->assertNotFound();
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertNotFound();
        $this->assertSame($before, $this->businessState());
        $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel))->assertNotFound();
    }

    public function test_old_read_only_token_can_read_involved_history_but_cannot_write_recovery(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $body = $this->acknowledgement($issue, $holder);
        $holder->tokens()->latest('id')->first()->forceFill(['abilities' => ['rider:account', 'rider:operations:read', 'rider:operations:work']])->save();
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.capabilities.handover_issues', true)
            ->assertJsonPath('data.capabilities.handover_reporting', false)->assertJsonPath('data.capabilities.native_restricted_recovery', false);
        $this->getJson('/api/v1/rider/handover-issues/'.$issue->id)->assertOk()->assertJsonPath('data.recovery.actions.acknowledge', false);
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertForbidden();
        $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel))->assertForbidden();
    }

    public static function deniedAccounts(): array
    {
        return ['inactive' => ['status', 'inactive', 403], 'suspended' => ['status', 'suspended', 403],
            'closed' => ['closed_at', 'now', 403], 'approval lost' => ['kyc_status', 'rejected', 403],
            'credential changed' => ['password', 'AChangedPassword123!', 401], 'expired token' => ['token', 'expired', 401],
            'revoked token' => ['token', 'revoked', 401]];
    }

    #[DataProvider('deniedAccounts')]
    public function test_current_account_and_token_authority_are_required_even_with_an_owned_grant(string $field, string $value, int $status): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $body = $this->acknowledgement($issue, $holder);
        if ($field === 'token') {
            $token = $holder->tokens()->latest('id')->first();
            $value === 'revoked' ? $token->delete() : $token->forceFill(['expires_at' => now()->subMinute()])->save();
        } else {
            $holder->forceFill([$field => $value === 'now' ? now() : $value])->save();
        }
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertStatus($status)
            ->assertJsonStructure(['code', 'message', 'errors', 'request_id']);
        $this->assertSame($before, $this->businessState());
    }

    public static function invalidFields(): array
    {
        return ['wrong readiness role' => ['kind', 'missed_ready'], 'short holder' => ['reported_holder', 'A'],
            'long holder' => ['reported_holder', str_repeat('A', 101)], 'short place' => ['reported_location', 'Desk'],
            'long place' => ['reported_location', str_repeat('A', 256)], 'short notes' => ['notes', 'Too short'],
            'long notes' => ['notes', str_repeat('A', 1001)], 'hidden notes' => ['notes', "Actual\u{200B} possession at the hub"],
            'unsafe notes' => ['notes', '<script>bad event</script>'], 'holder array' => ['reported_holder', ['Known rider']],
            'notes boolean' => ['notes', true], 'long waybill' => ['barcode', str_repeat('A', 256)],
            'hidden waybill' => ['barcode', "BGO\t-123"], 'foreign buyer field' => ['buyer_id', '42'],
            'forged body key' => ['request_token', '00000000-0000-4000-8000-000000000000']];
    }

    #[DataProvider('invalidFields')]
    public function test_report_rejects_invalid_and_unexpected_fields_without_saving_evidence(string $field, mixed $value): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $this->native(User::findOrFail($parcel->courier_id));
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel, [$field => $value]))->assertUnprocessable()
            ->assertJsonValidationErrors($field)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame($before, $this->businessState());
        $this->assertSame([], Storage::disk('local')->allFiles('handover-issue-evidence'));
    }

    public static function malformedIds(): array
    {
        return [['0'], ['01'], ['-1'], ['1.0'], ['1e2'], ['9223372036854775808'], ['１２']];
    }

    #[DataProvider('malformedIds')]
    public function test_malformed_resource_identifiers_are_hidden_before_lookup(string $id): void
    {
        $rider = $this->createApprovedUser('courier');
        $this->native($rider);
        $this->getJson('/api/v1/rider/handover-issues/'.$id)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/rider/handover-issues/'.$id.'/evidence')->assertNotFound();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$id.'/acknowledge', [])->assertNotFound();
    }

    public function test_revocation_after_report_creation_rolls_back_the_command_report_and_private_upload(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        $this->native($rider);
        $token = $rider->tokens()->latest('id')->first();
        Event::listen('eloquent.created: '.HandoverIssue::class, fn () => $token->delete());
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel))->assertUnauthorized();
        $this->assertSame($before, $this->businessState());
        $this->assertSame([], Storage::disk('local')->allFiles('handover-issue-evidence'));
    }

    public function test_event_failure_rolls_back_the_private_upload_and_masks_debug_details(): void
    {
        config(['app.debug' => true]);
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $this->native(User::findOrFail($parcel->courier_id));
        Event::listen('eloquent.created: '.HandoverIssueEvent::class, fn () => throw new RuntimeException('Private document contents must never appear in a response.'));
        $before = $this->businessState();
        $response = $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel))->assertStatus(500)->assertJsonPath('code', 'REQUEST_FAILED');
        $this->assertStringNotContainsString('Private document contents', $response->getContent());
        $this->assertSame($before, $this->businessState());
        $this->assertSame([], Storage::disk('local')->allFiles('handover-issue-evidence'));
    }

    public function test_a_missing_handover_schema_is_unavailable_without_disabling_other_native_operations(): void
    {
        $rider = $this->createApprovedUser('courier');
        $this->native($rider);
        Schema::drop('handover_manifest_receipts');
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.handover_api_version', null)
            ->assertJsonPath('data.capabilities.handover_issues', false)->assertJsonPath('data.capabilities.handover_reporting', false);
        $this->getJson('/api/v1/rider/handover-issues')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $this->postJson('/api/v1/rider/handover-issues', [])->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
    }

    public function test_normalized_equivalent_report_retries_and_maximum_text_boundaries_preserve_one_result(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $this->native(User::findOrFail($parcel->courier_id));
        $body = $this->body($parcel, ['reported_holder' => str_repeat('A', 100), 'reported_location' => str_repeat('B', 255), 'notes' => str_repeat('C', 1000)]);
        $first = $this->postJson('/api/v1/rider/handover-issues', $body)->assertOk();
        $this->postJson('/api/v1/rider/handover-issues', [...$body, 'barcode' => ' '.strtolower($body['barcode']).' ', 'notes' => ' '.$body['notes'].' '])
            ->assertOk()->assertJsonPath('data.result', $first->json('data.result'))->assertJsonPath('data.replayed', true);
        $this->assertSame(1, HandoverIssue::count());
    }

    public function test_multipart_validation_without_an_accept_header_still_returns_private_json_errors(): void
    {
        $this->native($this->createApprovedUser('courier'));
        $this->post('/api/v1/rider/handover-issues', [])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_guest_web_session_cannot_replace_a_native_bearer_and_pending_users_get_no_handover_write_ability(): void
    {
        $this->actingAs($this->createApprovedUser('courier'))->getJson('/api/v1/rider/handover-issues')->assertUnauthorized();
        $pending = $this->createPendingUser('courier');
        $session = app(RiderAccountService::class)->session($pending, 'Pending rider');
        $this->assertNotContains(RiderAccountService::HANDOVER_ABILITY, $pending->tokens()->latest('id')->first()->abilities);
        $this->withToken($session['token'])->getJson('/api/v1/rider/handover-issues')->assertForbidden();
    }

    public static function uploadCases(): array
    {
        return ['JPG' => ['jpg', 'image', true], 'JPEG' => ['jpeg', 'image', true], 'PNG' => ['png', 'image', true],
            'WEBP' => ['webp', 'webp', true], 'PDF' => ['pdf', 'pdf', true], 'uppercase PNG' => ['PNG', 'image', true],
            'video' => ['mp4', 'video', false], 'renamed video' => ['pdf', 'video', false], 'renamed image' => ['pdf', 'image', false],
            'forged image' => ['png', 'text', false], 'forged PDF' => ['pdf', 'text', false], 'empty' => ['pdf', 'empty', false],
            'exact byte limit' => ['png', 'maximum', true], 'one byte over' => ['png', 'oversized', false]];
    }

    #[DataProvider('uploadCases')]
    public function test_uploads_verify_actual_contents_formats_and_inclusive_byte_limits(string $extension, string $content, bool $accepted): void
    {
        $original = match ($content) {
            'pdf' => UploadedFile::fake()->createWithContent('original.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF"),
            'webp' => UploadedFile::fake()->createWithContent('original.webp', base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAgA0JaQAA3AA/vuUAAA=')),
            'empty' => UploadedFile::fake()->createWithContent('empty.pdf', ''),
            'video' => UploadedFile::fake()->createWithContent('video.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom"),
            'text' => UploadedFile::fake()->createWithContent('text.txt', 'Plain text does not contain the claimed verification document.'),
            default => UploadedFile::fake()->image(in_array(strtolower($extension), ['jpg', 'jpeg'], true) ? 'original.jpg' : 'original.png'),
        };
        if (in_array($content, ['maximum', 'oversized'], true)) {
            $bytes = file_get_contents($original->getRealPath());
            file_put_contents($original->getRealPath(), $bytes.str_repeat(' ', 5242880 + ($content === 'oversized' ? 1 : 0) - strlen($bytes)));
        }
        $file = new UploadedFile($original->getRealPath(), 'evidence.'.$extension, null, null, true);
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $this->native(User::findOrFail($parcel->courier_id));
        $before = $this->businessState();
        $response = $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel, ['evidence' => $file]));
        if ($accepted) {
            $response->assertOk();
            $issue = HandoverIssue::sole();
            $this->assertSame($file->getSize(), Storage::disk('local')->size($issue->evidence_path));
            $this->get('/api/v1/rider/handover-issues/'.$issue->id.'/evidence')->assertOk();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('evidence');
            $this->assertSame($before, $this->businessState());
            $this->assertSame([], Storage::disk('local')->allFiles('handover-issue-evidence'));
        }
    }

    public static function changedSources(): array
    {
        return ['expired' => ['expired', 409], 'withdrawn' => ['withdrawn', 409], 'changed destination' => ['destination', 409],
            'reviewer restricted' => ['reviewer', 409], 'missing evidence' => ['missing', 404], 'altered evidence' => ['altered', 409],
            'wrong source token' => ['token', 409], 'stale issue version' => ['version', 409], 'wrong waybill' => ['barcode', 422]];
    }

    #[DataProvider('changedSources')]
    public function test_changed_or_expired_authorization_cannot_create_native_possession_or_a_partial_command(string $change, int $status): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $body = $this->acknowledgement($issue, $holder);
        if ($change === 'expired') {
            $this->travel(25)->hours();
            $this->native($holder);
        } elseif ($change === 'withdrawn') {
            $reviewer = User::findOrFail($grant->authorized_by_id);
            $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
            $this->postJson('/handover-issues/recovery/'.$grant->id.'/withdraw', ['expected_version' => $view['version'],
                'source_token' => $view['recovery']['source_token'], 'notes' => 'The reported source needs a new independent authorization.',
                'request_token' => (string) Str::uuid()])->assertOk();
            $this->native($holder);
        } elseif ($change === 'destination') {
            $parcel->order->update(['shipping_address' => 'A changed frozen destination address']);
        } elseif ($change === 'reviewer') {
            User::findOrFail($grant->authorized_by_id)->update(['status' => 'inactive']);
        } elseif ($change === 'missing') {
            Storage::disk('local')->delete($issue->evidence_path);
        } elseif ($change === 'altered') {
            Storage::disk('local')->put($issue->evidence_path, 'The retained private evidence was changed.');
        } elseif ($change === 'token') {
            $body['source_token'] = str_repeat('0', 64);
        } elseif ($change === 'version') {
            $body['expected_version']--;
        } else {
            $body['barcode'] = 'ANOTHER-WAYBILL';
        }
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertStatus($status);
        $this->assertSame($before, $this->businessState());
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public static function invalidAcknowledgements(): array
    {
        return ['zero version' => ['expected_version', 0], 'negative version' => ['expected_version', -1],
            'large version' => ['expected_version', 1000001], 'float version' => ['expected_version', 3.0],
            'boolean version' => ['expected_version', true], 'exponent version' => ['expected_version', '3e0'],
            'version with leading zero' => ['expected_version', '03'], 'array version' => ['expected_version', [3]],
            'missing source' => ['source_token', null], 'invalid source' => ['source_token', 'not-a-source'],
            'missing confirmation' => ['possession_verified', null], 'false confirmation' => ['possession_verified', false],
            'numeric confirmation' => ['possession_verified', 1], 'string confirmation' => ['possession_verified', 'true'],
            'short acknowledgement' => ['notes', 'Too short'], 'unsafe acknowledgement' => ['notes', '<b>Not a plain statement</b>'],
            'forged receiver' => ['hub_id', '99']];
    }

    #[DataProvider('invalidAcknowledgements')]
    public function test_acknowledgement_rejects_bad_types_versions_confirmations_and_injected_receiver_fields(string $field, mixed $value): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $body = $this->acknowledgement($issue, $holder);
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', [...$body, $field => $value], [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->businessState());
    }

    public function test_lost_response_acknowledgement_replays_original_result_after_real_receipt_and_retained_key_expiry(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $body = $this->acknowledgement($issue, $holder);
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key);
        $first = $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertOk();
        $this->receipt($issue, $grant);
        $this->travel(25)->hours();
        $this->native($holder, $key);
        $before = $this->businessState();
        $this->getJson('/api/v1/rider/commands/'.$key)->assertOk()->assertJsonPath('data.result', $first->json('data.result'));
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', [...$body, 'notes' => 'A different statement with the old request key must not be recorded.'])
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame($before, $this->businessState());
        $this->travel(7)->days();
        $this->native($holder, $key);
        $this->getJson('/api/v1/rider/commands/'.$key)->assertOk()->assertJsonPath('data.retry_expired', true);
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertConflict()->assertJsonPath('code', 'COMMAND_EXPIRED');
    }

    public function test_partial_acknowledgement_failure_rolls_back_checkpoint_event_and_command_without_releasing_hold(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $body = $this->acknowledgement($issue, $holder);
        Event::listen('eloquent.created: '.HandoverIssueEvent::class, function ($event) {
            if ($event->event_type === 'holder_acknowledged') {
                throw new RuntimeException('Injected receipt write failure.');
            }
        });
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertStatus(500);
        $this->assertSame($before, $this->businessState());
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public static function wrongRoles(): array
    {
        return [['buyer'], ['seller'], ['logistics'], ['admin']];
    }

    #[DataProvider('wrongRoles')]
    public function test_another_role_cannot_gain_rider_authority_from_a_token_ability(string $role): void
    {
        $actor = $this->createApprovedUser($role);
        $token = $actor->createToken('False rider ability', ['rider:account', 'rider:operations:read', RiderAccountService::HANDOVER_ABILITY], now()->addDay());
        $token->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $actor->getAuthPassword())])->save();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/rider/handover-issues')->assertForbidden();
        $this->assertSame(0, HandoverIssue::count());
    }

    public function test_pagination_and_filters_are_bounded_and_retained_reporter_history_is_independent_of_new_assignment(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        $this->native($rider);
        $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel))->assertOk();
        $issue = HandoverIssue::sole();
        foreach (['page=0', 'page=01', 'page=10001', 'per_page=0', 'per_page=51', 'per_page=1.0', 'status=unknown', 'company_id=99'] as $query) {
            $this->getJson('/api/v1/rider/handover-issues?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/rider/handover-issues?per_page=1')->assertOk()->assertJsonPath('data.pagination.per_page', 1)->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/rider/handover-issues?page=10000&per_page=50')->assertOk()->assertJsonPath('data.pagination.page', 10000)
            ->assertJsonPath('data.pagination.total', 1)->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/rider/handover-issues/'.$issue->id)->assertOk()->assertJsonPath('data.recovery', null);
    }

    public function test_missed_origin_intake_uses_native_observation_and_hub_mode_without_creating_an_old_scan(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $holder = User::findOrFail($parcel->courier_id);
        $this->native($holder);
        $this->postJson('/api/v1/rider/handover-issues', $this->body($parcel))->assertOk();
        $issue = HandoverIssue::sole();
        $reviewer = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/review', ['decision' => 'confirm', 'notes' => 'The missed origin intake was independently checked against the physical parcel.',
            'evidence_reviewed' => true, 'expected_version' => $view['version'], 'source_token' => $view['source_token'], 'request_token' => (string) Str::uuid()])->assertOk();
        $grant = $this->authorize($issue->fresh()->load('delivery.order'), $holder, 'hub_intake');
        $body = $this->acknowledgement($issue, $holder);
        $before = $parcel->fresh()->getRawOriginal();
        $checkpoints = $parcel->checkpoints()->count();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertOk();
        $this->assertSame($before, $parcel->fresh()->getRawOriginal());
        $this->assertSame($checkpoints, $parcel->checkpoints()->count());
        $this->receipt($issue, $grant);
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame(0, DB::table('cod_accounts')->count());
    }

    public function test_native_failed_attempt_and_supervised_return_keep_original_attempt_proof_and_retry_authority(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $holder = User::findOrFail($parcel->assigned_rider_id);
        $this->native($holder);
        $this->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/fail', ['expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])),
            'barcode' => $parcel->tracking_number, 'notes' => 'The buyer could not receive the parcel on this actual attempt.',
            'reason' => 'customer_unreachable', 'location_name' => 'At the saved delivery location', 'proof_image_file' => UploadedFile::fake()->image('attempt.png')])->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/rider/handover-issues', $this->body($parcel))->assertOk();
        $issue = HandoverIssue::sole();
        $reviewer = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/review', ['decision' => 'confirm', 'notes' => 'The known failed-attempt holder and actual required hub return were independently checked.',
            'evidence_reviewed' => true, 'expected_version' => $view['version'], 'source_token' => $view['source_token'], 'request_token' => (string) Str::uuid()])->assertOk();
        $grant = $this->authorize($issue->fresh()->load('delivery.order'), $holder, 'hub_failed_return');
        $body = $this->acknowledgement($issue, $holder);
        $attempt = $parcel->latestAttempt;
        $originalProof = $attempt->proof_path;
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertOk();
        $this->receipt($issue, $grant);
        $this->assertSame('recovered', $issue->fresh()->state);
        $this->assertSame($originalProof, $attempt->fresh()->proof_path);
        $this->assertTrue(Storage::disk('local')->exists($originalProof));
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame(1, DeliveryAttempt::where('delivery_id', $parcel->id)->count());
        $this->assertSame(0, DB::table('cod_accounts')->count());
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
    }

    public function test_a_second_acknowledgement_intent_cannot_duplicate_physical_possession_and_duty_off_keeps_existing_responsibility(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $holder = User::findOrFail($parcel->courier_id);
        $issue = $this->held($parcel);
        $grant = $this->authorize($issue, $holder);
        $holder->courierProfile->update(['is_available' => false]);
        $body = $this->acknowledgement($issue, $holder);
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $body)->assertOk();
        $next = $this->acknowledgement($issue, $holder);
        $before = $this->businessState();
        $this->postJson('/api/v1/rider/handover-recoveries/'.$grant->id.'/acknowledge', $next)->assertConflict();
        $this->assertSame($before, $this->businessState());
        $this->assertSame(1, $grant->events()->where('event_type', 'holder_acknowledged')->count());
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel));
    }

    public function test_unknown_numeric_body_fields_keep_the_typed_field_error_envelope(): void
    {
        $this->native($this->createApprovedUser('courier'));
        $response = $this->postJson('/api/v1/rider/handover-issues', ['unexpected'])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $raw = json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class, $raw->errors);
        $this->assertNotEmpty($raw->errors->{'0'});
    }
}
