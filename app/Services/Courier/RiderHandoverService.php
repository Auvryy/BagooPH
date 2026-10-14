<?php

namespace App\Services\Courier;

use App\Models\HandoverIssue;
use App\Models\HandoverRecoveryGrant;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Rules\RegistrationDocument;
use App\Services\Logistics\HandoverIssueService;
use App\Services\Logistics\HandoverRecoveryService;
use App\Services\Logistics\HubHandoverRecoveryService;
use App\Services\Logistics\WaybillScanInputService;
use App\Services\RiderAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Normalizer;

class RiderHandoverService
{
    public function __construct(private readonly HandoverIssueService $issues, private readonly RiderCommandService $commands) {}

    public function index(Request $request): array
    {
        $this->available();
        $input = RiderApiInput::body($request, [
            'status' => ['sometimes', Rule::in(['open', 'recovered', 'dismissed', 'all'])],
            'page' => ['sometimes', new AsciiPositiveInteger, 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', new AsciiPositiveInteger, 'integer', 'min:1', 'max:50'],
        ]) + ['status' => 'open', 'page' => 1, 'per_page' => 20];
        $query = $this->issues->readable($request->user());
        if ($input['status'] !== 'all') {
            $input['status'] === 'open' ? $query->whereNotNull('active_delivery_id') : $query->where('state', $input['status']);
        }
        $rows = $query->with('delivery.order')->latest('id')->paginate((int) $input['per_page'], ['*'], 'page', (int) $input['page']);

        return ['items' => $rows->getCollection()->map(fn ($issue) => $this->summary($issue))->all(),
            'pagination' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];
    }

    public function issue(User $actor, string $id): HandoverIssue
    {
        $this->available();

        return $this->issues->readable($actor)->with('delivery.order')->whereKey(RiderApiInput::id($id))->firstOrFail();
    }

    public function show(User $actor, string $id): array
    {
        $issue = $this->issue($actor, $id)->load('events');
        $pickup = app(HandoverRecoveryService::class)->detail($actor, $issue);
        $hub = app(HubHandoverRecoveryService::class)->detail($actor, $issue);
        $grant = $pickup['grant'] ?? $hub['grant'];
        $mine = $grant && (string) $grant['holder_id'] === (string) $actor->id;
        $canWrite = $actor->currentAccessToken()?->can(RiderAccountService::HANDOVER_ABILITY) ?? false;

        return [...$this->summary($issue),
            'reported' => ['holder' => $issue->reported_holder, 'location' => $issue->reported_location, 'notes' => $issue->notes],
            'recorded' => ['original' => $this->snapshot($issue->source_snapshot), 'current' => $this->snapshot($this->issues->snapshot($issue->delivery))],
            'evidence' => ['download_path' => '/api/v1/rider/handover-issues/'.$issue->id.'/evidence', 'mime_type' => $issue->evidence_mime],
            'recovery' => $grant ? ['id' => (string) $grant['id'], 'reference' => $grant['reference'], 'mode' => $grant['mode'],
                'holder_is_me' => (bool) $mine, 'receiving_hub' => ['id' => (string) $grant['hub']['id'], 'name' => $grant['hub']['name'], 'address' => $grant['hub']['address']],
                'expires_at' => RiderApiInput::time(HandoverRecoveryGrant::findOrFail($grant['id'])->expires_at),
                'acknowledged' => $grant['acknowledged'], 'closed' => $grant['closed'], 'blocker' => $grant['blocker'],
                'expected_version' => $grant['expected_version'], 'source_token' => $mine && $grant['source_token'] !== '' ? $grant['source_token'] : null,
                'manifest_reference' => $grant['manifest_reference'] ?? null, 'linked_recovery_reference' => $grant['linked_recovery_reference'] ?? null,
                'actions' => ['acknowledge' => $canWrite && $mine && $grant['can_acknowledge']]] : null,
            'history' => $issue->events->map(fn ($event) => ['id' => (string) $event->id, 'type' => $event->event_type,
                'actor_role' => $event->actor_role, 'notes' => $event->notes, 'recorded_at' => RiderApiInput::time($event->created_at)])->all()];
    }

    public function report(Request $request): array
    {
        $this->available();
        $this->normalize($request, ['reported_holder', 'reported_location', 'notes']);
        $input = RiderApiInput::body($request, [
            'barcode' => app(WaybillScanInputService::class)->barcodeRules(), 'kind' => ['required', Rule::in(['missed_scan'])],
            'reported_holder' => ['required', new ApplicationText('notes', 2, 100)],
            'reported_location' => ['required', new ApplicationText('notes', 5, 255)],
            'notes' => ['required', new ApplicationText('notes', 10, 1000)],
            'evidence' => ['bail', 'required', 'file', new RegistrationDocument],
        ]);
        $intent = [...array_diff_key($input, ['evidence' => true]),
            'file_hash' => hash_file('sha256', $input['evidence']->getRealPath()),
            'file_extension' => strtolower($input['evidence']->getClientOriginalExtension())];
        $path = null;
        try {
            return $this->commands->execute($request, 'handover.report', 'handover-report', $intent, function () use ($request, $input, &$path) {
                $issue = $this->issues->report($request->user(), [...$input, 'request_token' => $request->header('Idempotency-Key')]);
                $path = $issue->evidence_path;

                return $this->result($issue);
            });
        } finally {
            if ($path && ! HandoverIssue::where('evidence_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function acknowledge(Request $request, string $id): array
    {
        $this->available();
        $id = RiderApiInput::id($id);
        $this->normalize($request, ['notes']);
        $input = RiderApiInput::body($request, [
            'barcode' => app(WaybillScanInputService::class)->barcodeRules(),
            'notes' => ['required', new ApplicationText('notes', 10, 1000)],
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000'],
            'source_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'possession_verified' => ['required', 'accepted'],
        ]);
        $input['expected_version'] = (int) $input['expected_version'];
        if ($input['possession_verified'] !== true) {
            RiderApiInput::error('VALIDATION_FAILED', 'Confirm actual possession with a JSON boolean.', 422,
                ['possession_verified' => ['A true JSON boolean is required.']]);
        }

        return $this->commands->execute($request, 'handover.acknowledge', 'handover-recovery-'.$id, $input, function () use ($request, $id, $input) {
            $grant = HandoverRecoveryGrant::where('holder_id', $request->user()->id)->whereKey($id)->firstOrFail();
            $event = app(HandoverRecoveryService::class)->acknowledge($request->user(), $grant,
                [...$input, 'request_token' => $request->header('Idempotency-Key')]);

            return [...$this->result($grant->issue->fresh()), 'grant_id' => (string) $grant->id,
                'event_id' => (string) $event->id, 'event_type' => $event->event_type];
        });
    }

    private function summary(HandoverIssue $issue): array
    {
        return ['id' => (string) $issue->id, 'reference' => $issue->reference, 'state' => $issue->state, 'kind' => $issue->kind,
            'tracking_number' => $issue->delivery->tracking_number, 'created_at' => RiderApiInput::time($issue->created_at),
            'version' => $issue->version, 'held' => $issue->state === 'needs_recovery' && (string) $issue->active_delivery_id === (string) $issue->delivery_id];
    }

    private function result(HandoverIssue $issue): array
    {
        return ['issue_id' => (string) $issue->id, 'reference' => $issue->reference, 'state' => $issue->state,
            'version' => $issue->version, 'held' => $issue->state === 'needs_recovery' && (string) $issue->active_delivery_id === (string) $issue->delivery_id];
    }

    private function snapshot(array $snapshot): array
    {
        return ['commercial_status' => $snapshot['order_status'] ?? null, 'operational_stage' => $snapshot['delivery_status'] ?? null,
            'custody_kind' => $snapshot['custody']['kind'] ?? 'unknown', 'custody_label' => $snapshot['custody_label'] ?? 'Custody not established'];
    }

    private function normalize(Request $request, array $fields): void
    {
        $input = app(WaybillScanInputService::class)->normalize($request->except('evidence'));
        foreach ($fields as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim(Normalizer::normalize($input[$field], Normalizer::FORM_KC) ?: $input[$field], ' ');
            }
        }
        $request->merge($input);
    }

    private function available(): void
    {
        abort_unless(app(RiderAccountService::class)->handoverAvailable(), 503, 'Rider handover recovery is temporarily unavailable.');
    }
}
