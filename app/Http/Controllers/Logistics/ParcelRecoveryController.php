<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Rules\AsciiPositiveInteger;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Logistics\HandoverIssueService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\ParcelRecoveryService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ParcelRecoveryController extends Controller
{
    public function __construct(private readonly ParcelRecoveryService $service, private readonly LogisticsEligibilityService $eligibility) {}

    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function show(Request $request, LogisticsManifestEvent $observation): Response
    {
        abort_unless($this->service->observations($request->user())->whereKey($observation->id)->exists(), 404);

        return $this->page($request, $observation);
    }

    private function page(Request $request, ?LogisticsManifestEvent $selected = null): Response
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['open', 'resolved', 'all'])], 'page' => ['sometimes', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000']]);
        $status = $filters['status'] ?? 'open';
        $actor = $request->user();
        $base = $request->is('hub/*') ? '/hub' : '';
        $query = $this->service->observations($actor);
        $resolved = LogisticsManifestEvent::where('event_type', 'resolve-discrepancy')->whereIn('manifest_id', $query->clone()->select('manifest_id'))
            ->get(['payload'])->pluck('payload.source_event_id')->all();
        if ($status !== 'all') {
            $status === 'resolved' ? $query->whereIn('id', $resolved) : $query->whereNotIn('id', $resolved);
        }
        $rows = $query->with(['manifest.destinationHub', 'actor', 'recoveryCase'])->latest('id')->paginate(15)->withQueryString();
        $readableIds = app(LogisticsManifestService::class)->readable($actor)
            ->whereIn('id', [...$rows->getCollection()->pluck('manifest_id'), $selected?->manifest_id])->pluck('id')->all();
        $rows->through(fn ($event) => $this->summary($event, $base, in_array($event->id, $resolved, true), in_array($event->manifest_id, $readableIds, true)));
        $detail = null;
        $creationToken = null;
        $vehicles = [];
        if ($selected) {
            $selected->load(['manifest.destinationHub', 'actor', 'recoveryCase.events.actor', 'recoveryCase.manifests', 'recoveryCase.delivery.order']);
            $case = $selected->recoveryCase;
            $caseReadableIds = $case ? app(LogisticsManifestService::class)->readable($actor)
                ->whereIn('id', [$case->originalMember->manifest_id, ...$case->manifests->pluck('id')])->pluck('id')->all() : [];
            $canAuthorize = $actor->isLogistics() && $this->eligibility->isCompanyManager($actor, $selected->manifest->logistics_company_id);
            $proposal = null;
            $blocker = null;
            $next = null;
            if (! $case) {
                try {
                    $proposal = $this->service->proposal($actor, $selected);
                } catch (DomainException $error) {
                    $blocker = $error->getMessage();
                }
            } elseif ($case->state === 'held') {
                try {
                    $next = $this->service->nextHub($case, $case->delivery);
                } catch (DomainException $error) {
                    $blocker = $error->getMessage();
                }
            }
            if ($case?->state === 'authorized') {
                try {
                    $this->service->assertOriginal($case, $case->delivery);
                    $latest = $case->delivery->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();
                    if ($latest?->id !== $case->source_checkpoint_id || DeliveryCheckpoint::state($case->delivery) !== $case->source_state) {
                        $blocker = 'Original custody changed. The company must withdraw this unused authorization and review the recorded discrepancy.';
                    }
                } catch (DomainException $error) {
                    $blocker = $error->getMessage();
                }
            }
            $hubIds = $case?->hub_ids ?? ($proposal['facts']['hub_ids'] ?? []);
            $hubs = LogisticsHub::whereIn('id', $hubIds)->get()->keyBy('id');
            $canIntake = $case?->state === 'authorized' && ! $blocker && $actor->isLogistics()
                && $this->eligibility->canScan($actor, LogisticsHub::findOrFail($case->observed_hub_id));
            $transfer = $case?->manifests->firstWhere('recovery_leg_index', $case->leg_index);
            $sourceHub = $case ? $hubs->get($case->hub_ids[$case->leg_index] ?? null) : null;
            $canPrepare = $sourceHub && app(LogisticsManifestService::class)->canManageAtHub($actor, $sourceHub);
            if ($next && $canPrepare && ! $transfer) {
                $creationToken = $request->session()->get('manifest_creation_token');
                if (! is_string($creationToken)) {
                    $creationToken = (string) Str::uuid();
                    $request->session()->put('manifest_creation_token', $creationToken);
                }
                $sourceHub = $hubs->get($case->hub_ids[$case->leg_index]);
                $type = $sourceHub->isMotherHub() && $hubs->get($next)->isMotherHub() ? 'wing_truck' : 'l300_van';
                $vehicles = LogisticsFleet::ready()->where('logistics_company_id', $case->logistics_company_id)->where('hub_id', $sourceHub->id)
                    ->where('vehicle_type', $type)->whereNotNull('assigned_driver_id')
                    ->whereHas('driver.courierProfile', fn ($profile) => $profile->where('is_available', true))
                    ->whereNotIn('assigned_driver_id', LogisticsManifest::where('status', 'dispatched')->select('driver_id'))
                    ->with('driver')->orderBy('id')->get()
                    ->reject(fn ($vehicle) => Delivery::riderHasActiveWork($vehicle->assigned_driver_id))->values()
                    ->map(fn ($vehicle) => ['id' => $vehicle->id, 'plate' => $vehicle->plate_number, 'driver' => $vehicle->driver?->name]);
            }
            $parcel = $case?->delivery;
            $knownParcel = $parcel ?? (isset($proposal['facts']['delivery_id']) ? Delivery::find($proposal['facts']['delivery_id']) : null);
            $handoverHold = $knownParcel ? app(HandoverHoldService::class)->active($knownParcel) : null;
            $custody = $parcel ? DeliveryCheckpoint::lastCustody($parcel) : ($proposal['facts']['custody_snapshot'] ?? null);
            $detail = $this->summary($selected, $base, in_array($selected->id, $resolved, true), in_array($selected->manifest_id, $readableIds, true)) + [
                'notes' => $selected->reason, 'blocker' => $blocker,
                'handoverHold' => $handoverHold !== null,
                'handoverIssueUrl' => $handoverHold && app(HandoverIssueService::class)->readable($actor)->whereKey($handoverHold->id)->exists()
                    ? '/handover-issues/'.$handoverHold->id : null,
                'canAuthorize' => $canAuthorize && ! $case && $proposal !== null, 'canIntake' => (bool) $canIntake,
                'canWithdraw' => $canAuthorize && $case?->state === 'authorized',
                'sourceToken' => $canAuthorize ? ($proposal['source_token'] ?? null) : null,
                'custody' => $this->custodyLabel($custody), 'expectedHub' => $hubs->get($case?->expected_hub_id ?? ($proposal['facts']['expected_hub_id'] ?? null))?->name,
                'route' => collect($hubIds)->map(fn ($id) => ['id' => $id, 'name' => $hubs->get($id)?->name, 'code' => $hubs->get($id)?->code, 'tier' => $hubs->get($id)?->tier]),
                'case' => $case ? ['id' => $case->id, 'reference' => $case->reference, 'state' => $case->state, 'version' => $case->version,
                    'legIndex' => $case->leg_index, 'reason' => $case->reason, 'currentHubId' => $parcel->current_hub_id,
                    'nextHubId' => $next, 'canPrepareTransfer' => $creationToken !== null,
                    'transferUrl' => $transfer && in_array($transfer->id, $caseReadableIds, true) ? $base.'/manifests/'.$transfer->id : null,
                    'originalManifestUrl' => in_array($case->originalMember->manifest_id, $caseReadableIds, true) ? $base.'/manifests/'.$case->originalMember->manifest_id : null,
                    'events' => $case->events->map(fn ($event) => ['reference' => $event->reference, 'type' => $event->event_type,
                        'actor' => $event->actor->name, 'hub' => LogisticsHub::find($event->hub_id)?->name, 'notes' => $event->reason,
                        'recordedAt' => $event->created_at->toIso8601String()]),
                    'transfers' => $case->manifests->map(fn ($manifest) => ['reference' => $manifest->reference, 'status' => $manifest->status,
                        'url' => in_array($manifest->id, $caseReadableIds, true) ? $base.'/manifests/'.$manifest->id : null]),
                ] : null,
            ];
        }

        return Inertia::render('Hub/ParcelRecovery', ['observations' => $rows, 'selectedObservation' => $detail, 'filters' => ['status' => $status],
            'basePath' => $base.'/parcel-recovery', 'manifestPath' => $base.'/manifests', 'requestToken' => (string) Str::uuid(),
            'creationToken' => $creationToken, 'vehicles' => $vehicles]);
    }

    private function summary(LogisticsManifestEvent $event, string $base, bool $resolved, bool $canOpenManifest): array
    {
        return ['id' => $event->id, 'reference' => $event->reference, 'kind' => $event->payload['kind'], 'barcode' => $event->barcode_scanned,
            'observedHub' => $event->manifest->destinationHub->name, 'reporter' => $event->actor->name,
            'reportedAt' => $event->created_at->toIso8601String(), 'state' => $resolved ? 'resolved' : ($event->recoveryCase?->state ?? 'needs_review'),
            'url' => $base.'/parcel-recovery/'.$event->id, 'manifestUrl' => $canOpenManifest ? $base.'/manifests/'.$event->manifest_id : null];
    }

    private function custodyLabel(?array $custody): string
    {
        if (($custody['kind'] ?? null) === 'hub') {
            return 'At '.(LogisticsHub::find($custody['hub_id'] ?? null)?->name ?? 'the recorded hub');
        }
        if (($custody['kind'] ?? null) === 'manifest') {
            return 'In transport on '.($custody['manifest_reference'] ?? 'the original manifest');
        }

        return 'No supported parcel custody linked yet';
    }

    public function authorize(Request $request, LogisticsManifestEvent $observation): JsonResponse|RedirectResponse
    {
        try {
            $case = $this->service->authorize($request->user(), $observation, $request->only(['source_token', 'request_token', 'notes']));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }

        return $this->success($request, $case->id, 'Recovery authorized. The assigned receiving handler must scan the actual parcel.');
    }

    public function command(Request $request, LogisticsManifestEvent $observation, string $action): JsonResponse|RedirectResponse
    {
        abort_unless($this->service->observations($request->user())->whereKey($observation->id)->exists(), 404);
        $case = $observation->recoveryCase()->firstOrFail();
        try {
            $input = $request->only(['version', 'request_token', 'notes', 'barcode']);
            $case = $action === 'intake' ? $this->service->intake($request->user(), $case, $input)
                : $this->service->withdraw($request->user(), $case, $input);
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }

        return $this->success($request, $case->id, $action === 'intake' ? 'The actual parcel receipt was recorded. Follow the recovery case for its next step.' : 'Unused authorization withdrawn. Original parcel custody is unchanged.');
    }

    private function success(Request $request, int $id, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['case_id' => $id]) : back()->with('success', $message);
    }

    private function conflict(Request $request, DomainException $error): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], 409) : back()->withErrors(['recovery' => $error->getMessage()]);
    }
}
