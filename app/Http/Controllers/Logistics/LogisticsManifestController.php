<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsTransportTrip;
use App\Models\MotherBag;
use App\Models\User;
use App\Services\Finance\ShipmentLossAccountingService;
use App\Services\Logistics\CargoHandlingService;
use App\Services\Logistics\DispatchStagingService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Logistics\HandoverIssueService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsTripService;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Logistics\TransportArrivalService;
use App\Services\Returns\BuyerReturnBagService;
use App\Services\Returns\BuyerReturnRecoveryService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $preparationRoute = null;
        $sourceHint = $request->query('hub_id');
        $destinationHint = $request->query('destination_hub_id');
        if (is_string($sourceHint) && is_string($destinationHint)
            && preg_match('/\A[1-9][0-9]{0,18}\z/', $sourceHint) && preg_match('/\A[1-9][0-9]{0,18}\z/', $destinationHint)
            && ($sourceHintHub = $sourceHubs->firstWhere('id', (int) $sourceHint))) {
            $lane = app(DispatchStagingService::class)->corridors($sourceHintHub)
                ->firstWhere('destination_hub.id', (int) $destinationHint);
            if ($lane) {
                $preparationRoute = ['source_hub_id' => $sourceHintHub->id, 'destination_hub_id' => $lane['destination_hub']['id']];
            }
        }
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
        $trip = null;
        $tripCandidates = [];
        if ($selected) {
            $selected = $manifestQuery()->findOrFail($selected->id);
            $source = $selected->sourceHub;
            $destination = $selected->destinationHub;
            if (Schema::hasTable('logistics_trip_manifests')) {
                $tripId = DB::table('logistics_trip_manifests')->where('manifest_id', $selected->id)->orderByDesc('id')->value('trip_id');
                $shared = $tripId ? LogisticsTransportTrip::findOrFail($tripId) : null;
                if ($shared && $shared->status !== 'cancelled') {
                    $trip = $shared->only(['id', 'reference', 'status', 'version']) + [
                        'canManage' => $this->service->canManageAtHub($actor, $source),
                        'canDepart' => $actor->isLogistics() && $this->eligibility->canScan($actor, $source),
                        'canClose' => $this->service->canManageAtHub($actor, $destination),
                        'canReceive' => $actor->isLogistics() && $this->eligibility->canScan($actor, $destination),
                        'manifests' => $shared->manifests()->orderBy('logistics_manifests.id')->get()->map(fn ($member) => [
                            'id' => $member->id, 'reference' => $member->reference, 'direction' => $member->direction, 'status' => $member->status,
                            'outstanding' => $this->service->receiptCounts($member)['outstanding'],
                        ]),
                    ] + app(BuyerReturnBagService::class)->presentation($shared);
                } elseif ($selected->status === 'sealed' && $this->service->canManageAtHub($actor, $source)) {
                    $tripCandidates = $query->clone()->where($selected->only(['logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id']))
                        ->where('status', 'sealed')->whereIn('direction', ['outbound', 'return', 'buyer_return'])
                        ->whereNotIn('id', DB::table('logistics_trip_manifests')->whereNotNull('active_manifest_id')->select('manifest_id'))
                        ->orderBy('id')->limit(100)->get(['id', 'reference', 'direction']);
                }
            }
            $discrepancies = $this->service->openDiscrepancies($selected);
            $accountedLosses = app(ShipmentLossAccountingService::class)->members($selected);
            $readableRecoveryIds = app(ParcelRecoveryService::class)->observations($actor)
                ->whereIn('id', $discrepancies->pluck('id'))->pluck('id')->all();
            $detail = $this->summary($selected) + [
                'transportArrival' => app(TransportArrivalService::class)->presentation($actor, $selected),
                'motherBags' => MotherBag::where('manifest_id', $selected->id)->orderBy('created_at')
                    ->get(['id', 'reference', 'seal_number', 'status']),
                'cargoCapacity' => app(CargoHandlingService::class)->available() ? app(CargoHandlingService::class)->manifestCapacity($selected) : null,
                'reviewedHold' => app(HandoverHoldService::class)->manifestParcelIds($selected) !== [],
                'canManage' => $this->service->canManageAtHub($actor, $source),
                'canClose' => $this->service->canManageAtHub($actor, $destination),
                'canLoad' => $actor->isLogistics() && $this->eligibility->canScan($actor, $source),
                'canReceive' => $actor->isLogistics() && $this->eligibility->canScan($actor, $destination),
                'parcels' => $selected->direction === 'buyer_return' ? $selected->buyerReturnParcels()->with('parcel')->orderBy('id')->get()->map(fn ($member) => [
                    'id' => $member->id, 'tracking_number' => $member->barcode_snapshot, 'included' => $member->included,
                    'status' => $member->parcel->status, 'received_at' => $member->received_at?->toIso8601String(), 'receipt_condition' => $member->receipt_condition,
                    'confirmed_lost' => $accountedLosses->has($member->id),
                    'corrective_receipt' => null, 'handover_hold' => false, 'handover_issue_url' => null,
                ]) : $selected->parcels()->with('delivery.order')->orderBy('id')->get()->map(function ($parcel) use ($actor, $accountedLosses) {
                    $receipt = $this->service->correctiveForMember($parcel);
                    $hold = app(HandoverHoldService::class)->active($parcel->delivery);

                    return ['cargo_mode' => $parcel->cargo_mode, 'handling' => $parcel->handling_snapshot, 'id' => $parcel->id, 'tracking_number' => $parcel->tracking_number_snapshot, 'included' => $parcel->included,
                        'status' => $parcel->delivery->status, 'received_at' => $parcel->received_at?->toIso8601String(), 'receipt_condition' => $parcel->receipt_condition,
                        'confirmed_lost' => $accountedLosses->has($parcel->id),
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
            'sharedTrip' => $trip, 'tripCandidates' => $tripCandidates,
            'buyerReturns' => $sourceHubs->isNotEmpty() ? BuyerReturnParcel::whereIn('logistics_company_id', $companyIds)->whereIn('current_hub_id', $sourceHubIds)->where('status', 'at_hub')
                ->whereHas('returnCase', fn ($query) => $query->whereIn('status', ['at_destination_hub', 'in_transit']))->with('returnCase')->orderBy('id')->get()
                ->map(function ($parcel) {
                    $physical = app(BuyerReturnRecoveryService::class)->context($parcel);

                    return isset($physical['hub_ids'][$physical['position'] + 1]) ? [
                        'id' => $parcel->buyer_return_case_id, 'reference' => $parcel->returnCase->reference, 'barcode' => $parcel->barcode,
                        'source_hub_id' => $parcel->current_hub_id, 'destination_hub_id' => $physical['hub_ids'][$physical['position'] + 1],
                        'recovery_id' => $physical['recovery_id'], 'weight_grams' => $parcel->weight_grams] : null;
                })->filter()->values() : [],
            'hubs' => LogisticsHub::eligible()->whereIn('logistics_company_id', $companyIds)->orderBy('name')->get(['id', 'name', 'code', 'tier', 'logistics_company_id', 'parent_hub_id', 'latitude', 'longitude', 'address', 'city_municipality', 'province']),
            'sourceHubIds' => $sourceHubIds,
            'preparationRoute' => $preparationRoute,
            'vehicles' => $sourceHubs->isNotEmpty() ? LogisticsFleet::ready()->whereIn('vehicle_type', ['l300_van', 'wing_truck'])->whereIn('logistics_company_id', $companyIds)->whereIn('hub_id', $sourceHubIds)->whereNotNull('assigned_driver_id')
                ->whereHas('driver.courierProfile', fn ($profile) => $profile->operational()->where('is_available', true))
                ->whereNotIn('assigned_driver_id', LogisticsManifest::awaitingPhysicalReceipt()->select('driver_id'))
                ->with('driver')->orderBy('plate_number')->get()->reject(fn ($fleet) => Delivery::riderHasActiveWork($fleet->assigned_driver_id))->values()->map(fn ($fleet) => ['id' => $fleet->id, 'hub_id' => $fleet->hub_id,
                    'plate_number' => $fleet->plate_number, 'vehicle_type' => $fleet->vehicle_type, 'driver_name' => $fleet->driver->name, 'capacity_kg' => $fleet->capacity_kg]) : [],
            'basePath' => $request->is('hub/*') ? '/hub/manifests' : '/manifests',
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $manifest = $this->service->create($request->user(), $request->only(['source_hub_id', 'destination_hub_id', 'vehicle_id', 'creation_token', 'direction', 'parcel_recovery_case_id', 'buyer_return_case_id', 'buyer_return_recovery_id']),
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

    public function storeTrip(Request $request, LogisticsTripService $trips): JsonResponse|RedirectResponse
    {
        try {
            $trip = $trips->create($request->user(), $request->only(['manifest_ids', 'request_token']));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }

        return $request->expectsJson() ? response()->json(['trip' => $trip], $trip->wasRecentlyCreated ? 201 : 200)
            : back()->with('success', 'Shared trip prepared. Cargo remains at its recorded source hub.');
    }

    public function transportArrival(Request $request, LogisticsManifest $manifest, TransportArrivalService $arrivals): JsonResponse|RedirectResponse
    {
        try {
            $event = $arrivals->record($request->user(), $manifest, $request->except('_token'));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }

        return $request->expectsJson() ? response()->json(['event_reference' => $event->reference])
            : back()->with('success', 'Original vehicle arrival recorded. Parcel and sack receipts remain separate.');
    }

    public function tripCommand(Request $request, LogisticsTransportTrip $trip, string $action, LogisticsTripService $trips): JsonResponse|RedirectResponse
    {
        try {
            $trip = $trips->command($trip, $request->user(), $action, $request->only(['version', 'request_token']));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }

        return $request->expectsJson() ? response()->json(['trip' => $trip]) : back()->with('success', 'The shared trip update was saved.');
    }

    public function command(Request $request, LogisticsManifest $manifest, string $action): JsonResponse|RedirectResponse
    {
        try {
            $result = $this->service->command($manifest, $request->user(), $action, $request->only(['version', 'request_token', 'notes', 'barcode', 'parcel_id', 'condition', 'kind', 'source_event_id', 'cargo_mode', 'handling']));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }
        if ($request->expectsJson()) {
            return response()->json(['manifest' => $result['manifest']->state(), 'event_reference' => $result['event']->reference]);
        }

        return back()->with('success', 'The manifest action was recorded.');
    }

    public function packReturnSack(Request $request, LogisticsTransportTrip $trip, BuyerReturnBagService $bags): JsonResponse|RedirectResponse
    {
        try {
            $bag = $bags->pack($trip, $request->user(), $request->only(['version', 'request_token', 'barcode', 'physical_tag', 'physical_checked']));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }

        return $request->expectsJson() ? response()->json(['bag' => $bag->only(['id', 'reference', 'version', 'status', 'physical_tag', 'seal_number'])])
            : back()->with('success', 'The scanned return was packed in its actual sack.');
    }

    public function returnSackCommand(Request $request, LogisticsTransportTrip $trip, MotherBag $bag, string $bagAction, BuyerReturnBagService $bags): JsonResponse|RedirectResponse
    {
        try {
            $bag = $bags->command($trip, $bag, $request->user(), $bagAction, $request->only(['version', 'bag_version', 'request_token', 'barcode', 'return_barcode', 'seal_number', 'seal_intact', 'notes']));
        } catch (DomainException $error) {
            return $this->conflict($request, $error);
        }

        return $request->expectsJson() ? response()->json(['bag' => $bag->only(['id', 'reference', 'version', 'status', 'physical_tag', 'seal_number'])])
            : back()->with('success', 'The actual return-sack update was saved.');
    }

    private function summary(LogisticsManifest $manifest): array
    {
        return $manifest->state() + ['type' => $manifest->type, 'included_count' => $manifest->direction === 'buyer_return'
            ? $manifest->buyerReturnParcels()->where('included', true)->count() : $manifest->included_count,
            'buyerReturnUrl' => $manifest->buyer_return_case_id ? '/buyer-returns/'.$manifest->buyer_return_case_id : null,
            'receiptCounts' => $this->service->receiptCounts($manifest),
            'source' => $manifest->sourceHub->only(['id', 'name', 'code', 'tier', 'latitude', 'longitude', 'city_municipality', 'province']),
            'destination' => $manifest->destinationHub->only(['id', 'name', 'code', 'tier', 'latitude', 'longitude', 'city_municipality', 'province']),
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
