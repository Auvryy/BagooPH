<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\HandoverIssue;
use App\Rules\AsciiPositiveInteger;
use App\Services\Logistics\HandoverIssueService;
use App\Services\Logistics\HandoverRecoveryService;
use App\Services\Logistics\HubHandoverRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HandoverIssueController extends Controller
{
    public function __construct(private readonly HandoverIssueService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['open', 'recovered', 'dismissed', 'all'])],
            'page' => ['sometimes', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000']]);
        $status = $filters['status'] ?? 'open';
        $actor = $this->service->actor($request->user());
        $query = $this->service->readable($actor);
        if ($status !== 'all') {
            $status === 'open' ? $query->whereNotNull('active_delivery_id') : $query->where('state', $status);
        }
        $issues = $query->with(['delivery.order', 'reporter'])->latest('id')->paginate(15)->withQueryString()
            ->through(fn ($issue) => $this->summary($issue));

        return Inertia::render('Handover/Index', ['issues' => $issues, 'status' => $status, 'canReport' => ! $actor->isAdmin(),
            'isOnline' => (bool) $actor->courierProfile?->is_available]);
    }

    public function show(Request $request, HandoverIssue $issue): Response
    {
        abort_unless($this->service->readable($request->user())->whereKey($issue->id)->exists(), 404);
        $issue->load(['delivery.order', 'reporter', 'events.actor']);
        $current = $this->service->snapshot($issue->delivery);

        return Inertia::render('Handover/Show', ['issue' => [...$this->summary($issue), 'version' => $issue->version,
            'reported_holder' => $issue->reported_holder, 'reported_location' => $issue->reported_location, 'notes' => $issue->notes,
            'original' => $issue->source_snapshot, 'current' => $current, 'source_token' => $this->service->sourceToken($current),
            'can_review' => $issue->state === 'pending_review' && $this->service->canReview($request->user(), $issue),
            'evidence_url' => '/handover-issues/'.$issue->id.'/evidence',
            'recovery' => app(HandoverRecoveryService::class)->detail($request->user(), $issue),
            'hub_recovery' => app(HubHandoverRecoveryService::class)->detail($request->user(), $issue),
            'events' => $issue->events->map(fn ($event) => ['id' => $event->id, 'type' => $event->event_type,
                'actor_name' => $event->actor?->name, 'actor_role' => $event->actor_role, 'notes' => $event->notes,
                'created_at' => $event->created_at->toIso8601String(), 'parcel_snapshot' => $event->parcel_snapshot]),
        ], 'isOnline' => (bool) $request->user()->courierProfile?->is_available]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $issue = $this->service->report($request->user(), $request->all());

        return $request->expectsJson() ? response()->json(['id' => $issue->id, 'reference' => $issue->reference, 'state' => $issue->state])
            : redirect('/handover-issues/'.$issue->id)->with('success', 'Your handover report is saved for independent review.');
    }

    public function review(Request $request, HandoverIssue $issue): JsonResponse|RedirectResponse
    {
        $result = $this->service->review($request->user(), $issue, $request->all());

        return $request->expectsJson() ? response()->json(['id' => $result->id, 'reference' => $result->reference, 'state' => $result->state])
            : redirect('/handover-issues/'.$issue->id)->with('success', 'Your review is saved. Recorded parcel custody is unchanged.');
    }

    public function evidence(Request $request, HandoverIssue $issue): BinaryFileResponse
    {
        $path = $this->service->evidencePath($request->user(), $issue);

        return response()->download($path, $issue->reference.'.'.$issue->evidence_extension,
            ['Content-Type' => $issue->evidence_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function summary(HandoverIssue $issue): array
    {
        return ['id' => $issue->id, 'reference' => $issue->reference, 'state' => $issue->state, 'kind' => $issue->kind,
            'tracking_number' => $issue->delivery->tracking_number, 'order_number' => $issue->delivery->order->order_number,
            'reporter_name' => $issue->reporter?->name, 'created_at' => $issue->created_at->toIso8601String()];
    }
}
