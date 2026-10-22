<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\User;
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
use Inertia\Inertia;
use Inertia\Response;

class LogisticsManifestController extends Controller
{
    public function __construct(private readonly LogisticsManifestService $service, private readonly LogisticsEligibilityService $eligibility) {}

    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function show(Request $request, LogisticsManifest $manifest): Response
    {
        abort_unless($this->service->readable($request->user())->whereKey($manifest->id)->exists(), 403);

        return $this->page($request, $manifest);
    }

    private function page(Request $request, ?LogisticsManifest $selected = null): Response
    {
        $actor = User::findOrFail($request->user()->id);
        $query = $this->service->readable($actor);
        $sourceHubs = $actor->isLogistics() ? $this->eligibility->accessibleHubs($actor)->get()->filter(fn (LogisticsHub $hub) => $this->service->canManageAtHub($actor, $hub))->values() : collect();
        $companyIds = $sourceHubs->pluck('logistics_company_id')->unique();
        $sourceHubIds = $sourceHubs->pluck('id');
        $token = null;
        if ($sourceHubs->isNotEmpty()) {
            $token = $request->session()->get('manifest_creation_token');
            if (! is_string($token)) {
                $token = (string) Str::uuid();
                $request->session()->put('manifest_creation_token', $token);
            }
        }
        $manifestQuery = fn () => $query->clone()->with(['sourceHub', 'destinationHub', 'vehicle', 'driver'])->withCount(['parcels as included_count' => fn ($query) => $query->where('included', true)]);
        $list = $manifestQuery()->latest('id')->paginate(20)->through(fn (LogisticsManifest $manifest) => $this->summary($manifest));
        $detail = null;
        if ($selected) {
            $selected = $manifestQuery()->findOrFail($selected->id);
            $source = $selected->sourceHub;
            $destination = $selected->destinationHub;
            $discrepancies = $this->service->openDiscrepancies($selected);
            $readableRecoveryIds = app(ParcelRecoveryService::class)->observations($actor)
                ->whereIn('id', $discrepancies->pluck('id'))->pluck('id')->all();
            $detail = $this->summary($selected) + [
                'reviewedHold' => app(HandoverHoldService::class)->manifestParcelIds($selected) !== [],
                'canManage' => $this->service->canManageAtHub($actor, $source),
                'canClose' => $this->service->canManageAtHub($actor, $destination),
                'canLoad' => $actor->isLogistics() && $this->eligibility->canScan($actor, $source),
                'canReceive' => $actor->isLogistics() && $this->eligibility->canScan($actor, $destination),
                'parcels' => $selected->direction === 'buyer_return' ? $selected->buyerReturnParcels()->with('parcel')->orderBy('id')->get()->map(fn ($member) => [
                    'id' => $member->id, 'tracking_number' => $member->barcode_snapshot, 'included' => $member->included,
                    'status' => $member->parcel->status, 'received_at' => $member->received_at?->toIso8601String(), 'receipt_condition' => $member->receipt_condition,
                    'corrective_receipt' => null, 'handover_hold' => false, 'handover_issue_url' => null,
                ]) : $selected->parcels()->with('delivery.order')->orderBy('id')->get()->map(function ($parcel) use ($actor) {
                    $receipt = $this->service->correctiveForMember($parcel);
                    $hold = app(HandoverHoldService::class)->active($parcel->delivery);

                    return ['id' => $parcel->id, 'tracking_number' => $parcel->tracking_number_snapshot, 'included' => $parcel->included,
                        'status' => $parcel->delivery->status, 'received_at' => $parcel->received_at?->toIso8601String(), 'receipt_condition' => $parcel->receipt_condition,
                        'corrective_receipt' => $receipt ? ['reference' => $receipt->reference, 'received_at' => $receipt->created_at->toIso8601String()] : null,
                        'handover_hold' => $hold !== null, 'handover_issue_url' => $hold && app(HandoverIssueService::class)->readable($actor)->whereKey($hold->id)->exists()
                            ? '/handover-issues/'.$hold->id : null];
                }),
                'correctiveReceipts' => $selected->correctiveReceipts()->with('receivedBy')->orderBy('id')->get()->filter(fn ($receipt) => $receipt->supports())
                    ->map(fn ($receipt) => ['reference' => $receipt->reference, 'tracking_number' => $receipt->tracking_number_snapshot,
                        'original_member_id' => $receipt->manifest_parcel_id, 'received_at' => $receipt->created_at->toIso8601String(), 'received_by' => $receipt->receivedBy->name,
                        'original_expected_count' => $receipt->original_expected_count, 'original_received_count' => $receipt->original_received_count])->values(),
                'discrepancies' => $discrepancies->map(fn ($event) => [
                    'id' => $event->id, 'reference' => $event->reference, 'kind' => $event->payload['kind'],
                    'parcel_id' => $event->manifest_parcel_id ?? $event->payload['return_member_id'] ?? null, 'barcode' => $event->barcode_scanned, 'reason' => $event->reason,
                    'recoveryUrl' => in_array($event->id, $readableRecoveryIds, true)
                        ? ($request->is('hub/*') ? '/hub' : '').'/parcel-recovery/'.$event->id : null,
                ]),
                'events' => $selected->events()->with('actor')->orderBy('id')->get()->map(fn ($event) => [
                    'id' => $event->id, 'reference' => $event->reference, 'type' => $event->event_type,
                    'actor' => $event->actor->name, 'actor_role' => $event->actor_role, 'barcode' => $event->barcode_scanned,
                    'reason' => $event->reason, 'created_at' => $event->created_at->toIso8601String(),
                ]),
            ];
        }

        return Inertia::render('Hub/Manifests', [
            'manifests' => $list, 'selectedManifest' => $detail, 'canCreate' => $sourceHubs->isNotEmpty(), 'creationToken' => $token,
            'commandToken' => (string) Str::uuid(),
            'buyerReturns' => $sourceHubs->isNotEmpty() ? BuyerReturnParcel::whereIn('logistics_company_id', $companyIds)->whereIn('current_hub_id', $sourceHubIds)->where('status', 'at_hub')
                ->whereHas('returnCase', fn ($query) => $query->whereIn('status', ['at_destination_hub', 'in_transit']))->with('returnCase')->orderBy('id')->get()
                ->filter(fn ($parcel) => isset($parcel->hub_ids[$parcel->route_position + 1]))
                ->map(fn ($parcel) => ['id' => $parcel->buyer_return_case_id, 'reference' => $parcel->returnCase->reference, 'barcode' => $parcel->barcode,
                    'source_hub_id' => $parcel->current_hub_id, 'destination_hub_id' => $parcel->hub_ids[$parcel->route_position + 1], 'weight_grams' => $parcel->weight_grams])->values() : [],
            'hubs' => LogisticsHub::eligible()->whereIn('logistics_company_id', $companyIds)->orderBy('name')->get(['id', 'name', 'code', 'tier', 'logistics_company_id']),
            'sourceHubIds' => $sourceHubIds,
            'vehicles' => $sourceHubs->isNotEmpty() ? LogisticsFleet::ready()->whereIn('vehicle_type', ['l300_van', 'wing_truck'])->whereIn('logistics_company_id', $companyIds)->whereIn('hub_id', $sourceHubIds)->whereNotNull('assigned_driver_id')
                ->whereHas('driver.courierProfile', fn ($profile) => $profile->operational()->where('is_available', true))
                ->whereNotIn('assigned_driver_id', LogisticsManifest::where('status', 'dispatched')->select('driver_id'))
                ->with('driver')->orderBy('plate_number')->get()->reject(fn ($fleet) => Delivery::riderHasActiveWork($fleet->assigned_driver_id))->values()->map(fn ($fleet) => ['id' => $fleet->id, 'hub_id' => $fleet->hub_id,
                    'plate_number' => $fleet->plate_number, 'vehicle_type' => $fleet->vehicle_type, 'driver_name' => $fleet->driver->name]) : [],
            'basePath' => $request->is('hub/*') ? '/hub/manifests' : '/manifests',
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $manifest = $this->service->create($request->user(), $request->only(['source_hub_id', 'destination_hub_id', 'vehicle_id', 'creation_token', 'direction', 'parcel_recovery_case_id', 'buyer_return_case_id']),
                $request->session()->get('manifest_creation_token'));
            $request->session()->forget('manifest_creation_token');
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }
        if ($request->expectsJson()) {
            return response()->json(['manifest' => $manifest->state()], $manifest->wasRecentlyCreated ? 201 : 200);
        }

        return redirect(($request->is('hub/*') ? '/hub/manifests/' : '/manifests/').$manifest->id)->with('success', 'Manifest draft prepared. Scan its actual outbound parcels before sealing.');
    }

    public function command(Request $request, LogisticsManifest $manifest, string $action): JsonResponse|RedirectResponse
    {
        try {
            $result = $this->service->command($manifest, $request->user(), $action, $request->only(['version', 'request_token', 'notes', 'barcode', 'parcel_id', 'condition', 'kind', 'source_event_id']));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }
        if ($request->expectsJson()) {
            return response()->json(['manifest' => $result['manifest']->state(), 'event_reference' => $result['event']->reference]);
        }

        return back()->with('success', 'The manifest action was recorded.');
    }

    private function summary(LogisticsManifest $manifest): array
    {
        return $manifest->state() + ['type' => $manifest->type, 'included_count' => $manifest->direction === 'buyer_return'
            ? $manifest->buyerReturnParcels()->where('included', true)->count() : $manifest->included_count,
            'buyerReturnUrl' => $manifest->buyer_return_case_id ? '/buyer-returns/'.$manifest->buyer_return_case_id : null,
            'receiptCounts' => $this->service->receiptCounts($manifest),
            'source' => $manifest->sourceHub->only(['id', 'name', 'code', 'tier']),
            'destination' => $manifest->destinationHub->only(['id', 'name', 'code', 'tier']),
            'vehicle_plate' => $manifest->vehicle->plate_number, 'driver_name' => $manifest->driver->name,
            'recoveryUrl' => $manifest->parcel_recovery_case_id
                ? (request()->is('hub/*') ? '/hub' : '').'/parcel-recovery/'.$manifest->recoveryCase->observation_event_id : null];
    }

    private function conflict(Request $request, DomainException $error): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $error->getMessage()], 409)
            : back()->withErrors(['manifest' => $error->getMessage()]);
    }
}
