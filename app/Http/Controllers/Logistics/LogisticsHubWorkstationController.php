<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Logistics\DeliveryRecoveryService;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Logistics\HubParcelWorkstationService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\LogisticsSortingInputService;
use App\Services\Logistics\OrderStateMachineService;
use App\Services\Logistics\ParcelRecoveryService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Logistics\RiderCompanyRequestService;
use App\Services\Logistics\WaybillScanInputService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LogisticsHubWorkstationController extends Controller
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    /**
     * A picked-up parcel is physically on its way to the origin Bayan Hub,
     * but it is not in hub custody until the hub operator records the intake
     * scan. Keep it visible to that origin facility so the handoff cannot
     * disappear between the pickup rider and the workstation.
     */
    protected function scopeDeliveriesAtHubs($query, $hubIds, ?int $companyId = null)
    {
        $hubIds = collect($hubIds)->filter()->values();

        if ($companyId) {
            $query->where('logistics_company_id', $companyId);
        }

        return $query->where(function ($scope) use ($hubIds) {
            $scope->whereIn('current_hub_id', $hubIds)
                ->orWhere(function ($pendingIntake) use ($hubIds) {
                    $pendingIntake
                        ->whereIn('origin_bayan_hub_id', $hubIds)
                        ->whereNull('current_hub_id')
                        ->where('status', OrderStateMachineService::STATUS_PICKED_UP);
                })->orWhere(function ($returning) use ($hubIds) {
                    $returning->whereIn('destination_bayan_hub_id', $hubIds)->whereNull('current_hub_id')->whereRaw("deliveries.status = 'delivery_failed'");
                });
        });
    }

    /**
     * Primary workstation index for hub operators and warehouse handlers.
     */
    public function index(Request $request): Response
    {
        return $this->dashboard($request);
    }

    /**
     * Active facility resolver helper.
     */
    protected function getActiveHub(Request $request, ?User $user): array
    {
        [$hub, $hubs] = $this->eligibility->hubContext($request, strict: true);
        if ($hub) {
            $request->session()->put('active_hub_id', $hub->id);
        }

        return [$hub, $hubs];
    }

    /**
     * Company command center with an optional owned-facility filter.
     */
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        [$activeHub, $accessibleHubs] = $this->getActiveHub($request, $user);
        $company = $accessibleHubs->first()?->company;
        $isCompanyAdministrator = $user && $this->eligibility->isCompanyAdministrator($user);

        $requestedHubId = $request->integer('hub_id');
        $scopeHub = $requestedHubId > 0
            ? $accessibleHubs->firstWhere('id', $requestedHubId)
            : null;

        if (! $isCompanyAdministrator) {
            $scopeHub = $activeHub;
        }

        $scopeHubIds = $scopeHub
            ? collect([$scopeHub->id])
            : $accessibleHubs->pluck('id');

        $terminalStatuses = ['delivered', 'customer_collected', 'completed', 'cancelled', 'returned'];
        $exceptionStatuses = ['failed', OrderStateMachineService::STATUS_DELIVERY_FAILED, OrderStateMachineService::STATUS_RETURN_TO_SENDER];
        $outboundCheckpointTypes = [
            OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
            OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
            OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
        ];

        $deliveryQuery = fn () => $this->scopeDeliveriesAtHubs(Delivery::query(), $scopeHubIds, $company?->id);
        $custodyQuery = fn () => Delivery::query()
            ->when($company?->id, fn ($query, $companyId) => $query->where('logistics_company_id', $companyId))
            ->whereIn('current_hub_id', $scopeHubIds);

        $parcelsInCustody = $custodyQuery()->whereNotIn('status', $terminalStatuses)->count();
        $readyForDispatch = $custodyQuery()->whereIn('status', [
            OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
            OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
        ])->count();
        $exceptions = $custodyQuery()->whereIn('status', $exceptionStatuses)->count();
        $dispatchedToday = DeliveryCheckpoint::query()
            ->whereIn('hub_id', $scopeHubIds)
            ->whereHas('delivery', fn ($query) => $query->where('logistics_company_id', $company?->id ?? 0))
            ->whereIn('checkpoint_type', $outboundCheckpointTypes)
            ->whereDate('created_at', today())
            ->count();

        $inboundCheckpointTypes = [
            OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
            OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
            OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
        ];
        $movementCheckpoints = DeliveryCheckpoint::query()
            ->whereIn('hub_id', $scopeHubIds)
            ->whereHas('delivery', fn ($query) => $query->where('logistics_company_id', $company?->id ?? 0))
            ->whereIn('checkpoint_type', [...$inboundCheckpointTypes, ...$outboundCheckpointTypes])
            ->where('created_at', '>=', today()->subDays(6)->startOfDay())
            ->get(['checkpoint_type', 'created_at'])
            ->groupBy(fn (DeliveryCheckpoint $checkpoint) => $checkpoint->created_at->toDateString());

        $movement = collect(range(6, 0))->map(function (int $daysAgo) use (
            $movementCheckpoints,
            $inboundCheckpointTypes,
            $outboundCheckpointTypes
        ) {
            $date = today()->subDays($daysAgo);
            $dayCheckpoints = $movementCheckpoints->get($date->toDateString(), collect());

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('D'),
                'inbound' => $dayCheckpoints->whereIn('checkpoint_type', $inboundCheckpointTypes)->count(),
                'outbound' => $dayCheckpoints->whereIn('checkpoint_type', $outboundCheckpointTypes)->count(),
            ];
        });

        $counterPickupCount = $custodyQuery()
            ->where('delivery_type', 'hub_self_pickup')
            ->whereIn('status', [
                OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
            ])
            ->count();

        $unexpected = app(ParcelRecoveryService::class)->observations($user)
            ->where(function ($query) use ($scopeHubIds) {
                $query->whereIn('hub_id', $scopeHubIds)
                    ->orWhereHas('recoveryCase', fn ($case) => $case
                        ->where(function ($work) use ($scopeHubIds) {
                            $work->where('state', 'held')->whereHas('delivery', fn ($parcel) => $parcel
                                ->where('status', ParcelRecoveryService::HELD)->whereIn('current_hub_id', $scopeHubIds));
                            $work->orWhere(function ($arriving) use ($scopeHubIds) {
                                $arriving->where('state', 'in_transit')->whereHas('manifests', fn ($manifest) => $manifest
                                    ->where('status', 'dispatched')->whereIn('destination_hub_id', $scopeHubIds));
                            });
                        }));
            })
            ->whereHas('manifest', fn ($query) => $query->where('logistics_company_id', $company?->id ?? 0));
        $resolvedUnexpected = LogisticsManifestEvent::where('event_type', 'resolve-discrepancy')
            ->whereIn('manifest_id', $unexpected->clone()->select('manifest_id'))->get(['payload'])->pluck('payload.source_event_id');
        $unexpectedCount = $unexpected->whereNotIn('id', $resolvedUnexpected)->count();

        $attentionQueue = [
            [
                'key' => 'failed_delivery',
                'label' => 'Failed deliveries',
                'count' => $exceptions,
                'href' => route('hub.deliveries', ['status' => 'delivery_failed']),
                'tone' => 'danger',
            ],
            [
                'key' => 'awaiting_rider',
                'label' => 'Waiting for rider assignment',
                'count' => $deliveryQuery()->where('status', OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN)->count(),
                'href' => route('hub.deliveries', ['status' => 'sorted_to_barangay_bin']),
                'tone' => 'warning',
            ],
            [
                'key' => 'counter_pickup',
                'label' => 'Awaiting counter collection',
                'count' => $counterPickupCount,
                'href' => route('hub.counter'),
                'tone' => 'info',
            ],
            [
                'key' => 'next_scan',
                'label' => 'Awaiting next hub scan',
                'count' => $deliveryQuery()->whereIn('status', [
                    OrderStateMachineService::STATUS_PICKED_UP,
                    OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
                    OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
                    OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                    OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                    OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
                ])->count(),
                'href' => route('hub.scan.station'),
                'tone' => 'neutral',
            ],
            [
                'key' => 'parcel_recovery', 'label' => 'Unexpected parcels needing recovery', 'count' => $unexpectedCount,
                'href' => route('hub.parcel-recovery.index'), 'tone' => 'warning',
            ],
        ];

        $parcelFlow = [
            'origin_hub' => $custodyQuery()->where('status', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB)->count(),
            'mother_hub_transit' => $custodyQuery()->whereIn('status', [
                OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
                OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
            ])->count(),
            'destination_hub' => $custodyQuery()
                ->where('delivery_type', '!=', 'hub_self_pickup')
                ->whereIn('status', [
                    OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                    OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                    OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
                ])->count(),
            'final_mile' => $custodyQuery()->where('status', OrderStateMachineService::STATUS_OUT_FOR_DELIVERY)->count(),
            'counter_pickup' => $counterPickupCount,
        ];

        $facilities = LogisticsHub::query()
            ->whereIn('id', $scopeHubIds)
            ->withCount([
                'currentDeliveries as parcels_count' => fn ($query) => $query->whereColumn('deliveries.logistics_company_id', 'logistics_hubs.logistics_company_id')->whereNotIn('status', $terminalStatuses),
                'outboundDeliveries as awaiting_origin_intake_count' => fn ($query) => $query
                    ->whereColumn('deliveries.logistics_company_id', 'logistics_hubs.logistics_company_id')
                    ->whereNull('current_hub_id')
                    ->where('status', OrderStateMachineService::STATUS_PICKED_UP),
                'currentDeliveries as exceptions_count' => fn ($query) => $query->whereColumn('deliveries.logistics_company_id', 'logistics_hubs.logistics_company_id')->whereIn('status', $exceptionStatuses),
                'fleet as active_fleet_count' => fn ($query) => $query->ready(),
            ])
            ->orderBy('tier')
            ->orderBy('name')
            ->get()
            ->map(fn (LogisticsHub $hub) => [
                'id' => $hub->id,
                'name' => $hub->name,
                'code' => $hub->code,
                'tier' => $hub->tier,
                'city_municipality' => $hub->city_municipality,
                'parcels' => $hub->parcels_count,
                'awaiting_origin_intake' => $hub->awaiting_origin_intake_count,
                'exceptions' => $hub->exceptions_count,
                'active_fleet' => $hub->active_fleet_count,
                'capacity' => $hub->capacity,
                'utilization_rate' => $hub->capacity > 0
                    ? round(($hub->parcels_count / $hub->capacity) * 100, 1)
                    : 0,
            ]);

        $recentActivity = DeliveryCheckpoint::query()
            ->with(['delivery', 'hub', 'scannedBy'])
            ->whereIn('hub_id', $scopeHubIds)
            ->whereHas('delivery', fn ($query) => $query->where('logistics_company_id', $company?->id ?? 0))
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (DeliveryCheckpoint $checkpoint) => [
                'id' => $checkpoint->id,
                'tracking_number' => $checkpoint->delivery?->tracking_number ?? $checkpoint->barcode_scanned,
                'event' => $checkpoint->checkpoint_type,
                'facility' => $checkpoint->hub?->name ?? $checkpoint->location_name,
                'operator' => $checkpoint->scannedBy?->name ?? 'System',
                'timestamp' => $checkpoint->created_at->format('M d, Y · H:i'),
                'relative_time' => $checkpoint->created_at->diffForHumans(),
            ]);

        return Inertia::render('Hub/Dashboard', [
            'scope' => [
                'mode' => $scopeHub ? 'hub' : 'company',
                'company_name' => $company?->name ?? 'Logistics Company',
                'company_code' => $company?->code,
                'selected_hub_id' => $scopeHub?->id,
                'hubs' => $accessibleHubs->map(fn (LogisticsHub $hub) => [
                    'id' => $hub->id,
                    'name' => $hub->name,
                    'code' => $hub->code,
                    'tier' => $hub->tier,
                ])->values(),
                'can_view_company' => $isCompanyAdministrator,
            ],
            'stats' => [
                'parcels_in_custody' => $parcelsInCustody,
                'ready_for_dispatch' => $readyForDispatch,
                'exceptions' => $exceptions,
                'dispatched_today' => $dispatchedToday,
            ],
            'attentionQueue' => $attentionQueue,
            'movement' => $movement,
            'parcelFlow' => $parcelFlow,
            'facilities' => $facilities,
            'recentActivity' => $recentActivity,
        ]);
    }

    /**
     * Hub & sortation network topology overview.
     */
    public function network(Request $request): Response
    {
        $user = $request->user();
        [$activeHub, $accessibleHubs] = $this->getActiveHub($request, $user);
        $company = $accessibleHubs->first()?->company;
        $isCompanyAdministrator = $user && $this->eligibility->isCompanyAdministrator($user);
        $terminalStatuses = ['delivered', 'customer_collected', 'completed', 'cancelled', 'returned'];

        $networkHubs = LogisticsHub::query()
            ->with('company')
            ->withCount([
                'handlers as handlers_count' => fn ($query) => $query->eligible(),
                'fleet as fleet_count' => fn ($query) => $query->ready(),
                'currentDeliveries as parcel_count' => fn ($query) => $query->whereColumn('deliveries.logistics_company_id', 'logistics_hubs.logistics_company_id')->whereNotIn('status', $terminalStatuses),
                'outboundDeliveries as awaiting_origin_intake_count' => fn ($query) => $query
                    ->whereColumn('deliveries.logistics_company_id', 'logistics_hubs.logistics_company_id')
                    ->whereNull('current_hub_id')
                    ->where('status', OrderStateMachineService::STATUS_PICKED_UP),
                'currentDeliveries as ready_pickup_count' => fn ($query) => $query
                    ->whereColumn('deliveries.logistics_company_id', 'logistics_hubs.logistics_company_id')
                    ->where('delivery_type', 'hub_self_pickup')
                    ->whereIn('status', [
                        OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                        OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                    ]),
            ])
            ->whereIn('id', $accessibleHubs->pluck('id'))
            ->orderBy('tier')
            ->orderBy('name')
            ->get()
            ->map(function ($h) {
                $parcelCount = $h->parcel_count;
                $utilization = $h->capacity > 0 ? round(($parcelCount / $h->capacity) * 100, 1) : 0;

                return [
                    'id' => $h->id,
                    'name' => $h->name,
                    'code' => $h->code,
                    'tier' => $h->tier,
                    'company_name' => $h->company?->name ?? 'Bagoo Express Dispatch Fleet',
                    'company_code' => $h->company?->code ?? 'BGX',
                    'province' => $h->province,
                    'city_municipality' => $h->city_municipality,
                    'barangay' => $h->barangay,
                    'address' => $h->address,
                    'latitude' => $h->latitude,
                    'longitude' => $h->longitude,
                    'capacity' => $h->capacity,
                    'coverage_barangays' => $h->coverage_barangays ?? [],
                    'allows_self_pickup' => (bool) $h->allows_self_pickup,
                    'is_active' => (bool) $h->is_active,
                    'handlers_count' => $h->handlers_count,
                    'fleet_count' => $h->fleet_count,
                    'parcel_count' => $parcelCount,
                    'awaiting_origin_intake_count' => $h->awaiting_origin_intake_count,
                    'ready_pickup_count' => $h->ready_pickup_count,
                    'utilization' => $utilization,
                ];
            });

        return Inertia::render('Hub/Network', [
            'riderIntake' => app(RiderCompanyRequestService::class)->companyWorkspace($user, $request->query()),
            'scope' => [
                'company_name' => $company?->name ?? 'Logistics Company',
                'company_code' => $company?->code,
                'active_hub_id' => $activeHub?->id,
                'can_switch_facility' => $isCompanyAdministrator,
                'can_scan' => $activeHub && $this->eligibility->canScan($user, $activeHub),
            ],
            'hubs' => $networkHubs,
            'placementVehicles' => $isCompanyAdministrator
                ? LogisticsFleet::ready()->where('logistics_company_id', $company?->id ?? 0)
                    ->orderBy('plate_number')->get(['id', 'hub_id', 'plate_number', 'assigned_driver_id'])
                    ->map(fn ($vehicle) => [...$vehicle->only(['id', 'hub_id', 'plate_number']), 'assigned' => $vehicle->assigned_driver_id !== null])
                : [],
        ]);
    }

    /**
     * Corporate Enterprise Modules & Future Roadmap Explorer.
     */
    public function roadmap(Request $request): Response
    {
        $user = $request->user();
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $selectedModule = $request->query('module', 'personnel');

        return Inertia::render('Hub/Roadmap', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'selectedModule' => $selectedModule,
        ]);
    }

    public function placeResource(Request $request, LogisticsPlacementService $placements): JsonResponse|RedirectResponse
    {
        abort_unless($this->eligibility->isCompanyAdministrator($request->user()), 403, 'Only a company administrator can manage personnel placement.');
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $validated = $request->validate([
            'kind' => 'required|in:handler,courier',
            'email' => 'required|string|email|max:254',
            'hub_id' => 'required|integer|exists:logistics_hubs,id',
            'vehicle_id' => 'exclude_unless:kind,courier|nullable|integer|exists:logistics_fleet,id',
            'barangay' => ['exclude_unless:kind,courier', 'nullable', 'string', new ApplicationText('notes', 1, 255)],
            'current_hub_id' => 'exclude_unless:kind,courier|nullable|integer|exists:logistics_hubs,id',
        ]);
        [$hub] = $this->eligibility->hubContext($request, strict: true);
        $account = User::where('role', $validated['kind'] === 'courier' ? 'courier' : 'logistics')
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($validated['email']))])->first();
        if (! $account) {
            if (! $request->wantsJson()) {
                throw ValidationException::withMessages(['email' => 'Choose an approved account eligible for this network.']);
            }

            return $this->operationError($request, 'Choose an approved account eligible for this network.');
        }
        try {
            if ($validated['kind'] === 'handler') {
                $placements->assignHandler($request->user(), $hub, $account);
            } else {
                $placements->placeCourier($request->user(), $hub, $account,
                    isset($validated['vehicle_id']) ? (int) $validated['vehicle_id'] : null,
                    $validated['barangay'] ?? null,
                    isset($validated['current_hub_id']) ? (int) $validated['current_hub_id'] : null);
            }
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409 || $request->wantsJson()) {
                throw $exception;
            }

            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        } catch (DomainException $exception) {
            if (! $request->wantsJson()) {
                throw ValidationException::withMessages(['email' => $exception->getMessage()]);
            }

            return $this->operationError($request, $exception->getMessage());
        }
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Personnel placement saved.']);
        }

        return back()->with('success', 'Personnel placement saved.');
    }

    /**
     * Multi-tier vehicle fleet management.
     */
    public function fleet(Request $request): Response
    {
        $user = $request->user();
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $selectedTier = $request->query('tier', 'all');
        $selectedStatus = $request->query('status', 'all');

        $fleetBase = LogisticsFleet::query()
            ->where('hub_id', $activeHub?->id ?? 0)
            ->where('logistics_company_id', $activeHub?->logistics_company_id ?? 0);
        $fleetQuery = (clone $fleetBase)->with(['hub', 'driver.courierProfile', 'company']);

        if ($selectedTier !== 'all') {
            $fleetQuery->where('vehicle_type', $selectedTier);
        }

        if ($selectedStatus !== 'all') {
            $fleetQuery->where('status', $selectedStatus);
        }

        $fleet = $fleetQuery->latest()->get()->map(function ($f) {
            $driver = $f->driver;
            if ($driver?->courierProfile?->logistics_company_id !== $f->logistics_company_id
                || $driver?->courierProfile?->assigned_hub_id !== $f->hub_id
                || $driver?->courierProfile?->vehicle_id !== $f->id) {
                $driver = null;
            }

            return [
                'id' => $f->id,
                'plate_number' => $f->plate_number,
                'vehicle_type' => $f->vehicle_type,
                'model' => $f->model,
                'capacity_kg' => (float) $f->capacity_kg,
                'status' => $f->status,
                'hub_name' => $f->hub?->name ?? 'Unassigned Hub',
                'hub_code' => $f->hub?->code ?? 'N/A',
                'driver_name' => $driver?->name ?? 'Unassigned Driver',
                'driver_phone' => $driver?->phone ?? 'N/A',
                'driver_email' => $driver?->email ?? 'N/A',
            ];
        });

        $fleetStats = [
            'total' => (clone $fleetBase)->count(),
            'active' => (clone $fleetBase)->ready()->count(),
            'motorcycles' => (clone $fleetBase)->where('vehicle_type', 'motorcycle')->count(),
            'vans' => (clone $fleetBase)->where('vehicle_type', 'l300_van')->count(),
            'trucks' => (clone $fleetBase)->where('vehicle_type', 'wing_truck')->count(),
        ];

        return Inertia::render('Hub/Fleet', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'fleet' => $fleet,
            'stats' => $fleetStats,
            'filters' => [
                'tier' => $selectedTier,
                'status' => $selectedStatus,
            ],
        ]);
    }

    /**
     * Filterable parcels and waybill registry.
     */
    public function deliveries(Request $request, HubParcelWorkstationService $workstation): Response
    {
        $filters = $request->validate(['search' => 'nullable|string|max:120', 'status' => 'nullable|string|max:80', 'delivery_type' => 'nullable|string|in:all,doorstep,hub_self_pickup', 'page' => ['nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000']]);
        $user = $request->user();
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $search = trim($filters['search'] ?? '');
        $statusFilter = $filters['status'] ?? 'all';
        $deliveryType = $filters['delivery_type'] ?? 'all';

        $basePath = $request->is('hub/*') ? '/hub' : '';
        $riders = null;
        $eligibleRiders = function () use ($activeHub, &$riders) {
            return $riders ??= $this->eligibility->riderCandidates($activeHub)->get()->map(fn ($profile) => [
                'id' => $profile->user_id,
                'name' => $profile->user?->name ?? 'Rider',
                'assigned_barangay' => $profile->assigned_barangay,
                'vehicle_type' => $profile->vehicle_type,
            ])->values();
        };

        $deliveries = function () use ($activeHub, $workstation, $user, $eligibleRiders, $basePath, $search, $statusFilter, $deliveryType) {
            $query = $workstation->registry($activeHub)->with([
                'order.buyer',
                'order.items.product',
                'originBayanHub',
                'originMotherHub',
                'destinationBayanHub',
                'destinationMotherHub',
                'assignedRider',
                'courier',
                'currentHub',
                'latestCustodyCheckpoint',
                'pickupClaim',
            ]);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('tracking_number', 'like', "%{$search}%")
                        ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$search}%"))
                        ->orWhereHas('order.buyer', fn ($bq) => $bq->where('name', 'like', "%{$search}%"));
                });
            }

            $workstation->filter($query, $statusFilter, $activeHub);

            if ($deliveryType !== 'all') {
                $query->where('delivery_type', $deliveryType);
            }

            return $query->latest()->paginate(15)->withPath($basePath.'/deliveries')->withQueryString()->through(function ($d) use ($activeHub, $workstation, $user, $eligibleRiders, $basePath) {
                $isAwaitingOriginIntake = $activeHub
                    && $d->status === OrderStateMachineService::STATUS_PICKED_UP
                    && $d->origin_bayan_hub_id === $activeHub->id
                    && $d->current_hub_id === null;

                return [
                    'id' => $d->id,
                    'tracking_number' => $d->tracking_number,
                    'order_number' => $d->order?->order_number ?? 'N/A',
                    'buyer_name' => $d->order?->buyer?->name ?? 'Customer',
                    'buyer_phone' => $d->order?->buyer?->phone ?? 'N/A',
                    'destination_barangay' => $d->order?->destination_barangay ?? 'N/A',
                    'shipping_city' => $d->order?->shipping_city ?? 'N/A',
                    'status' => $d->status,
                    'delivery_type' => $d->delivery_type,
                    'destination_bin' => $workstation->recordedBin($d),
                    'current_hub' => $d->currentHub?->name ?? ($isAwaitingOriginIntake ? 'Awaiting origin hub intake' : 'In Transit'),
                    'awaiting_origin_intake' => (bool) $isAwaitingOriginIntake,
                    'items' => $d->order?->items?->map(fn ($item) => [
                        'name' => $item->product?->name ?? 'Item',
                        'quantity' => $item->quantity,
                    ])->values() ?? [],
                    'destination_hub' => $d->destinationBayanHub?->name ?? 'Local Hub',
                    'rider_name' => $d->assignedRider?->name ?? 'Unassigned',
                    'total_amount' => (float) ($d->order?->total_amount ?? 0),
                    'payment_method' => $d->order?->payment_method ?? 'cod',
                    'item_count' => $d->order?->items?->count() ?? 1,
                    'created_at' => $d->created_at->format('M d, Y H:i'),
                    'guidance' => $workstation->guidance($d, $activeHub, $user, $eligibleRiders(), $basePath),
                ];
            });
        };

        return Inertia::render('Hub/Deliveries', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'deliveries' => $deliveries,
            'counts' => fn () => $workstation->counts($activeHub),
            'eligibleRiders' => $eligibleRiders,
            'basePath' => $basePath,
            'canScan' => $activeHub && $user->isLogistics() && $this->eligibility->canScan($user, $activeHub),
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
                'delivery_type' => $deliveryType,
            ],
        ]);
    }

    /**
     * Counter self-pickup workstation.
     */
    public function counter(Request $request, HubParcelWorkstationService $workstation): Response
    {
        $filters = $request->validate(['search' => 'nullable|string|max:120', 'page' => ['nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000']]);
        $user = $request->user();
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $search = trim($filters['search'] ?? '');
        $basePath = $request->is('hub/*') ? '/hub' : '';

        $counterParcels = $workstation->counterQueue($activeHub, $search)->latest('id')->paginate(15)
            ->appends(['search' => $search, 'hub_id' => $activeHub?->id])
            ->through(fn (Delivery $parcel) => $workstation->counterParcel($parcel, $activeHub, $user, $basePath));
        $pageParcels = $counterParcels->getCollection();

        $recentlyCollected = Delivery::with(['order.buyer'])
            ->where('delivery_type', 'hub_self_pickup')
            ->where('destination_bayan_hub_id', $activeHub?->id ?? 0)
            ->where('logistics_company_id', $activeHub?->logistics_company_id ?? 0)
            ->where('status', OrderStateMachineService::STATUS_CUSTOMER_COLLECTED)
            ->whereHas('pickupClaim', fn ($claim) => $claim->where('status', 'collected')->whereNotNull('consumed_at'))
            ->with('pickupClaim')
            ->withMax('pickupClaim', 'consumed_at')
            ->orderByDesc('pickup_claim_max_consumed_at')
            ->latest('id')
            ->limit(25)
            ->get()
            ->filter(fn (Delivery $parcel) => app(PickupClaimService::class)->hasCollectionEvidence($parcel))
            ->take(5)
            ->map(fn ($d) => [
                'id' => $d->id,
                'tracking_number' => $d->tracking_number,
                'buyer_name' => $d->order?->buyer?->name ?? 'Customer',
                'collected_at' => $d->pickupClaim->consumed_at->diffForHumans(),
            ])->values();

        return Inertia::render('Hub/CounterPickup', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'counterParcels' => $counterParcels,
            'requestToken' => (string) Str::uuid(),
            'canConfigureHours' => $activeHub && $this->eligibility->isCompanyAdministrator($request->user(), $activeHub->logistics_company_id),
            'recentlyCollected' => $recentlyCollected,
            'search' => $search,
            'basePath' => $basePath,
            'canScan' => $activeHub && $user->isLogistics() && $this->eligibility->canScan($user, $activeHub),
            'counterCounts' => [
                'shown' => $pageParcels->count(),
                'ready' => $pageParcels->where('guidance.ready_for_collection', true)->count(),
                'blocked' => $pageParcels->where('guidance.ready_for_collection', false)->count(),
            ],
        ]);
    }

    /**
     * Switch current operating facility session.
     */
    public function switchHub(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'hub_id' => 'required|integer|exists:logistics_hubs,id',
        ]);

        $user = $request->user();
        [, $accessibleHubs] = $this->getActiveHub($request, $user);

        abort_unless($user && $this->eligibility->isCompanyAdministrator($user), 403, 'Only a logistics company administrator can change the working facility.');

        $hub = $accessibleHubs->firstWhere('id', (int) $validated['hub_id']);
        abort_unless($hub, 403, 'You cannot access this facility.');

        session(['active_hub_id' => $hub->id]);

        $basePath = $request->is('hub/*') ? '/hub' : '';
        $previous = parse_url(url()->previous());
        $path = $previous['path'] ?? '';
        $pages = array_map(fn ($page) => $basePath.'/'.$page, ['dashboard', 'network', 'fleet', 'deliveries', 'scan', 'counter', 'manifests', 'roadmap']);
        $query = [];
        if (($previous['host'] ?? $request->getHost()) === $request->getHost() && in_array($path, $pages, true)) {
            parse_str($previous['query'] ?? '', $query);
        } else {
            $path = $basePath.'/dashboard';
        }
        // An old explicit hub filter must not immediately undo the new session selection.
        $query['hub_id'] = $hub->id;
        unset($query['page']);

        return redirect($path.'?'.http_build_query($query))->with('success', "Active facility switched to {$hub->name} ({$hub->code}).");
    }

    /**
     * Mobile-first PWA Scan Station workstation.
     */
    public function scanStation(Request $request, HubParcelWorkstationService $workstation): Response
    {
        $user = $request->user();
        abort_unless($user?->isLogistics(), 403, 'Platform administrators may audit logistics but cannot operate a scan station.');
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        abort_unless($activeHub, 403, 'No active facility is assigned to this account.');
        abort_unless($this->eligibility->canScan($user, $activeHub), 403, 'An active handler assignment is required for floor scans.');
        $basePath = $request->is('hub/*') ? '/hub' : '';

        // Recent scans at this hub
        $recentScans = DeliveryCheckpoint::with(['delivery.order.buyer', 'delivery.destinationBayanHub', 'scannedBy'])
            ->where('hub_id', $activeHub->id)
            ->whereHas('delivery', fn ($query) => $query->where('logistics_company_id', $activeHub->logistics_company_id))
            ->latest()
            ->limit(15)
            ->get()
            ->map(function ($cp) use ($workstation) {
                return [
                    'id' => $cp->id,
                    'tracking_number' => $cp->delivery?->tracking_number ?? $cp->barcode_scanned,
                    'checkpoint_type' => $cp->checkpoint_type,
                    'location_name' => $cp->location_name,
                    'notes' => $cp->notes,
                    'scanned_by' => $cp->scannedBy?->name ?? 'Floor Scanner',
                    'created_at' => $cp->created_at->toIso8601String(),
                    'status' => $cp->delivery?->status ?? 'in_transit',
                    'delivery_type' => $cp->delivery?->delivery_type ?? 'doorstep',
                    'destination_bin' => $workstation->recordedBin($cp->delivery),
                    'buyer_name' => $cp->delivery?->order?->buyer?->name ?? 'Customer',
                ];
            });

        // Pickup riders leave the parcel in PICKED_UP until the origin hub
        // operator confirms physical receipt. Show that handoff queue here so
        // the Los Baños workstation can immediately find the parcel to scan.
        $pendingOriginIntake = Delivery::with(['order.buyer', 'order.items.product', 'assignedRider'])
            ->where('logistics_company_id', $activeHub->logistics_company_id)
            ->where('origin_bayan_hub_id', $activeHub->id)
            ->whereNull('current_hub_id')
            ->where('status', OrderStateMachineService::STATUS_PICKED_UP)
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Delivery $delivery) => [
                'id' => $delivery->id,
                'tracking_number' => $delivery->tracking_number,
                'order_number' => $delivery->order?->order_number,
                'buyer_name' => $delivery->order?->buyer?->name ?? 'Customer',
                'rider_name' => $delivery->assignedRider?->name ?? 'Pickup rider',
                'item_names' => $delivery->order?->items?->map(fn ($item) => $item->product?->name ?? 'Item')->values() ?? [],
                'updated_at' => $delivery->updated_at->diffForHumans(),
            ])
            ->values();

        // Parcels staged or waiting at this hub for counter self-pickup
        $counterQueue = $workstation->counterQueue($activeHub);
        $counterTotal = (clone $counterQueue)->count();
        $counterPickups = $counterQueue->latest('id')->limit(20)->get()
            ->map(fn (Delivery $parcel) => $workstation->counterParcel($parcel, $activeHub, $user, $basePath));

        // Quick stats for active hub
        $stats = [
            'parcels_in_hub' => $activeHub ? Delivery::query()->where('logistics_company_id', $activeHub->logistics_company_id)->where('current_hub_id', $activeHub->id)->whereNotIn('status', ['delivered', 'customer_collected', 'cancelled'])->count() : 0,
            'ready_pickup' => $workstation->stagedCounterCount($activeHub),
            'dispatched_today' => $activeHub ? DeliveryCheckpoint::where('hub_id', $activeHub->id)
                ->whereHas('delivery', fn ($query) => $query->where('logistics_company_id', $activeHub->logistics_company_id))
                ->whereIn('checkpoint_type', [
                    OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
                    OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
                    OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
                ])
                ->whereDate('created_at', today())
                ->count() : 0,
        ];

        return Inertia::render('Hub/ScanStation', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'recentScans' => $recentScans,
            'pendingOriginIntake' => $pendingOriginIntake,
            'counterPickups' => $counterPickups,
            'counterTotal' => $counterTotal,
            'basePath' => $basePath,
            'stats' => $stats,
        ]);
    }

    /**
     * Floor handler barcode intake with dynamic routing prompts.
     */
    public function scanIntake(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->isLogistics(), 403, 'Only logistics operators may scan parcel custody.');

        $inputs = app(WaybillScanInputService::class);
        $request->merge($inputs->normalize($request->only(['barcode', 'notes', 'action', 'expected_status']), allowTrackingUrl: true));
        $validated = $request->validate([
            'barcode' => $inputs->barcodeRules(),
            'hub_id' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'exists:logistics_hubs,id'],
            'notes' => $inputs->notesRules(),
            'mode' => 'nullable|in:inspect,confirm',
            'action' => 'required_if:mode,confirm|nullable|string|max:100|regex:/\A[A-Z_]+\z/',
            'expected_status' => 'required_if:mode,confirm|nullable|string|max:100|regex:/\A[a-z_]+\z/',
            'attempt_reference' => ['nullable', 'string', 'max:64'],
            'route_reference' => ['nullable', 'string', 'max:64'],
            'claim_reference' => ['nullable', 'string', 'max:64'],
        ]);

        $barcode = trim($validated['barcode']);
        [$hub] = $this->getActiveHub($request, $request->user());
        abort_unless($hub && $this->eligibility->canScan($request->user(), $hub), 403, 'An active handler assignment is required for floor scans.');
        if (str_starts_with($barcode, 'BRT-')) {
            $return = BuyerReturnParcel::where('barcode', $barcode)->first();
            abort_unless($return && $return->logistics_company_id === $hub->logistics_company_id
                && in_array($hub->id, $return->hub_ids, true), 404, 'This return barcode is not expected in your facility scope.');
            abort_unless(($validated['mode'] ?? 'inspect') === 'inspect', 409,
                'Open the buyer return to record its required intake or actual manifest receipt. This scan only identifies it.');

            return $this->scanResponse(['success' => true, 'confirmed' => false,
                'message' => 'Buyer return identified. Open its case for the actual intake, manifest or seller-collection step.',
                'return_case' => ['reference' => $return->returnCase->reference, 'barcode' => $return->barcode,
                    'href' => '/buyer-returns/'.$return->buyer_return_case_id, 'status' => $return->returnCase->status]]);
        }
        $matches = Delivery::with([
            'order.items.product',
            'order.buyer',
            'order.shop',
            'originBayanHub',
            'originMotherHub',
            'destinationBayanHub',
            'destinationMotherHub',
            'assignedRider',
        ])
            ->useWritePdo()
            ->where('tracking_number', $barcode)
            ->orWhereHas('order', fn ($q) => $q->where('order_number', $barcode))
            ->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['barcode' => 'This code matches more than one parcel and needs review. Use its unique tracking barcode.']);
        }
        $delivery = $matches->first();

        if (! $delivery) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => "Parcel #{$barcode} not found in logistics registry.",
                ], 404);
            }

            return back()->with('error', "Parcel #{$barcode} not found in logistics registry.");
        }

        if (! $hub || $hub->logistics_company_id !== $delivery->logistics_company_id) {
            return $this->operationError($request, 'This parcel is outside the active facility company.', 403);
        }

        $routingEngine = app(LogisticsRoutingEngine::class);
        $prompt = $routingEngine->getDynamicScanPrompt($delivery, $hub);
        $alreadyReceived = $delivery->status === OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB
            && DeliveryCheckpoint::where('delivery_id', $delivery->id)->where('hub_id', $hub->id)
                ->where('checkpoint_type', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB)->exists();

        if ($prompt['action'] === 'INSPECT_WAYBILL' && (($validated['mode'] ?? 'inspect') === 'confirm'
            || ! app(HubParcelWorkstationService::class)->registry($hub)->whereKey($delivery->id)->exists())) {
            return $this->operationError(
                $request,
                'This parcel is not expected at the active facility for its current custody state.',
                409
            );
        }

        if (($validated['mode'] ?? 'inspect') !== 'confirm') {
            $requiresConfirmation = ! in_array($prompt['action'], ['AWAIT_BARANGAY_SORT', 'AWAIT_RETRY_REVIEW', 'AWAIT_SELLER_RECEIPT', 'AWAIT_RETURN_REVIEW', 'AWAIT_BUYER_PICKUP', 'INSPECT_WAYBILL'], true);

            return $this->scanResponse($this->scanPayload(
                delivery: $delivery,
                hub: $hub,
                prompt: $prompt,
                message: $requiresConfirmation
                    ? 'Waybill verified. Confirm the displayed custody action to continue.'
                    : 'Waybill verified. Use the destination sorting action to continue.',
                requiresConfirmation: $requiresConfirmation,
                alreadyReceived: $alreadyReceived
            ));
        }

        $requestedAction = strtoupper(trim($validated['action']));
        $expectedStatus = strtolower(trim($validated['expected_status']));
        $targetStatus = $this->scanTargetForAction($requestedAction);

        if (! $targetStatus) {
            return $this->operationError($request, 'The requested scan action is not a recognized custody handoff.', 422);
        }

        if (! in_array($requestedAction, ['RECEIVE_FAILED_DELIVERY', 'RELEASE_APPROVED_RETRY', 'STAGE_SELLER_RETURN', 'STAGE_FOR_PICKUP', 'START_EXPIRED_PICKUP_RETURN'], true)
            && $delivery->status === $expectedStatus && ($prompt['action'] !== $requestedAction || $prompt['next_status'] !== $targetStatus)) {
            return $this->operationError($request, 'The scan instruction no longer matches the parcel route. Scan the waybill again.', 409);
        }

        $stateMachine = app(OrderStateMachineService::class);

        try {
            $recovery = app(DeliveryRecoveryService::class);
            $updatedDelivery = match ($requestedAction) {
                'STAGE_FOR_PICKUP' => app(PickupClaimService::class)->stage($delivery, $request->user(), $hub, $barcode, $expectedStatus),
                'START_EXPIRED_PICKUP_RETURN' => app(PickupClaimService::class)->beginReturn($delivery, $request->user(), $hub, $barcode, $expectedStatus, $validated['claim_reference'] ?? ''),
                'STAGE_SELLER_RETURN' => app(DeliveryReturnService::class)->stage($delivery, $request->user(), $hub, $barcode, $expectedStatus, $validated['route_reference'] ?? ''),
                'RECEIVE_FAILED_DELIVERY' => $recovery->receive($delivery, $request->user(), $hub, $barcode, $expectedStatus, $validated['attempt_reference'] ?? ''),
                'RELEASE_APPROVED_RETRY' => $recovery->beginRetry($delivery, $request->user(), $hub, $barcode, $expectedStatus, $validated['attempt_reference'] ?? ''),
                default => $stateMachine->transition(
                    delivery: $delivery,
                    targetStatus: $targetStatus,
                    actor: $request->user(),
                    scanMetadata: [
                        'hub_id' => $hub?->id,
                        'barcode' => $barcode,
                        'location_name' => $hub ? "{$hub->name} ({$hub->code})" : 'Sorting Hub Terminal',
                        'facility_code' => $hub?->code,
                        'scan_action' => $requestedAction,
                        'expected_status' => $expectedStatus,
                        'notes' => $validated['notes'] ?? "Floor Scan: {$requestedAction}",
                    ]
                ),
            };
        } catch (DomainException $exception) {
            return $this->operationError($request, $exception->getMessage(), 409);
        }

        $updatedDelivery->load([
            'order.items.product',
            'order.buyer',
            'originBayanHub',
            'originMotherHub',
            'destinationBayanHub',
            'destinationMotherHub',
            'currentHub',
        ]);
        $nextPrompt = $routingEngine->getDynamicScanPrompt($updatedDelivery, $hub);
        $requiresNextConfirmation = ! in_array($nextPrompt['action'], [
            'AWAIT_BARANGAY_SORT',
            'AWAIT_RETRY_REVIEW',
            'AWAIT_BUYER_PICKUP',
            'AWAIT_SELLER_RECEIPT',
            'AWAIT_RETURN_REVIEW',
            'INSPECT_WAYBILL',
        ], true);

        $payload = $this->scanPayload(
            delivery: $updatedDelivery,
            hub: $hub,
            prompt: $nextPrompt,
            message: $requiresNextConfirmation
                ? "Custody action recorded for parcel #{$delivery->tracking_number}. The next facility action is ready."
                : "Custody action recorded for parcel #{$delivery->tracking_number}.",
            requiresConfirmation: $requiresNextConfirmation,
            confirmed: true,
            alreadyReceived: $alreadyReceived && $requestedAction === 'RECEIVE_FROM_PICKUP_RIDER'
        );

        if ($request->wantsJson()) {
            return $this->scanResponse($payload);
        }

        return back()->with('scan_result', $payload);
    }

    private function scanPayload(
        Delivery $delivery,
        LogisticsHub $hub,
        array $prompt,
        string $message,
        bool $requiresConfirmation,
        bool $confirmed = false,
        bool $alreadyReceived = false
    ): array {
        $delivery->loadMissing(['order.items.product', 'order.buyer', 'currentHub', 'courier', 'assignedRider', 'latestCustodyCheckpoint', 'latestOriginalCheckpoint', 'pickupClaim']);
        $base = request()->is('hub/*') ? '/hub' : '';
        $riders = $delivery->status === 'sorted_to_barangay_bin' && $delivery->delivery_type === 'doorstep'
            ? $this->eligibility->riderCandidates($hub)->get()->map(fn ($profile) => ['id' => $profile->user_id, 'assigned_barangay' => $profile->assigned_barangay]) : collect();
        $workstation = app(HubParcelWorkstationService::class);
        $guidance = $workstation->guidance($delivery, $hub, request()->user(), $riders, $base);
        $registryUrl = $workstation->registry($hub)->whereKey($delivery->id)->exists()
            ? $base.'/deliveries?hub_id='.$hub->id.'&search='.rawurlencode($delivery->tracking_number) : null;
        if (app(HandoverHoldService::class)->held($delivery)) {
            $prompt = ['action' => 'AWAIT_HANDOVER_RECOVERY', 'prompt' => 'Complete supervised handover recovery before an ordinary scan.', 'next_status' => $delivery->status, 'color' => 'amber'];
        }
        if (in_array($guidance['action'], ['counter', 'sort', 'assign'], true) && $guidance['href']) {
            $guidance['href'] .= '&search='.rawurlencode($delivery->tracking_number);
        }
        $custodyHub = $delivery->currentHub;
        $requiresManifest = in_array($prompt['action'], ['DISPATCH_TO_FEEDER', 'DISPATCH_LINE_HAUL', 'RECEIVE_AT_MOTHER_HUB', 'RECEIVE_AT_DESTINATION_HUB', 'DISPATCH_RETURN_MANIFEST', 'RECEIVE_RETURN_MANIFEST'], true);
        $canConfirm = $requiresConfirmation && ! $requiresManifest && $guidance['can_act'] && $guidance['action'] === 'scan';

        return [
            'success' => true,
            'message' => $alreadyReceived ? 'Already received at this hub. The original intake time is unchanged. '.$guidance['next_step'].'.'
                : (! $confirmed && ! $canConfirm ? 'Waybill verified. '.$guidance['next_step'].'.' : $message),
            'already_received' => $alreadyReceived,
            'confirmed' => $confirmed,
            'prompt' => [
                ...$prompt,
                'expected_status' => $delivery->status,
                'requires_confirmation' => $canConfirm,
                'manifest_url' => $requiresManifest ? $base.'/manifests?hub_id='.$hub->id : null,
            ],
            'delivery' => [
                'id' => $delivery->id,
                'tracking_number' => $delivery->tracking_number,
                'status' => $delivery->status,
                'delivery_type' => $delivery->delivery_type,
                'destination_bin' => app(HubParcelWorkstationService::class)->recordedBin($delivery),
                'guidance' => $guidance,
                'registry_url' => $registryUrl,
                'current_hub' => $custodyHub ? [
                    'id' => $custodyHub->id,
                    'name' => $custodyHub->name,
                    'code' => $custodyHub->code,
                    'tier' => $custodyHub->tier,
                ] : null,
                'buyer' => [
                    'name' => $delivery->order?->buyer?->name ?? 'Customer',
                    'phone' => $delivery->order?->buyer?->phone ?? 'N/A',
                    'barangay' => $delivery->order?->destination_barangay ?? 'N/A',
                    'city' => $delivery->order?->shipping_city ?? 'N/A',
                    'landmark' => $delivery->order?->landmark,
                ],
                'order' => [
                    'order_number' => $delivery->order?->order_number,
                    'total_amount' => (float) ($delivery->order?->total_amount ?? 0),
                    'payment_method' => $delivery->order?->payment_method ?? 'cod',
                    'items' => $delivery->order?->items?->map(fn ($item) => [
                        'name' => $item->product?->name ?? 'Item',
                        'quantity' => $item->quantity,
                        'price' => (float) $item->price,
                    ]) ?? [],
                ],
            ],
        ];
    }

    private function scanResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
    }

    private function scanTargetForAction(string $action): ?string
    {
        return match ($action) {
            'RECEIVE_FROM_PICKUP_RIDER' => OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
            'DISPATCH_TO_FEEDER' => OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
            'RECEIVE_AT_MOTHER_HUB' => OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
            'SORT_TO_LINE_HAUL' => OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
            'DISPATCH_LINE_HAUL' => OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
            'RECEIVE_AT_DESTINATION_HUB' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
            'STAGE_FOR_PICKUP' => OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
            'RECEIVE_FAILED_DELIVERY' => OrderStateMachineService::STATUS_DELIVERY_FAILED,
            'RELEASE_APPROVED_RETRY' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
            'STAGE_SELLER_RETURN' => OrderStateMachineService::STATUS_RETURN_TO_SENDER,
            'START_EXPIRED_PICKUP_RETURN' => OrderStateMachineService::STATUS_RETURN_TO_SENDER,
            default => null,
        };
    }

    /**
     * Bayan Hub customer counter self-pickup handover.
     */
    public function releasePickup(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->isLogistics(), 403, 'Only logistics operators may release counter parcels.');

        $inputs = app(WaybillScanInputService::class);
        $request->merge($inputs->normalize($request->only(['barcode', 'notes'])));
        $validated = $request->validate(['barcode' => $inputs->barcodeRules(),
            'hub_id' => ['required', new AsciiPositiveInteger, 'integer', 'exists:logistics_hubs,id']]);

        [$activeHub] = $this->getActiveHub($request, $request->user());
        abort_unless($activeHub && $this->eligibility->canScan($request->user(), $activeHub), 403, 'An active handler assignment is required for counter release.');
        $barcode = trim($validated['barcode']);
        $delivery = Delivery::with(['order.buyer', 'order.items.product'])
            ->where('tracking_number', $barcode)
            ->first();

        if (! $delivery) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => "Parcel #{$barcode} not found."], 404);
            }

            return back()->with('error', "Parcel #{$barcode} not found.");
        }

        [$hub] = $this->getActiveHub($request, $request->user());
        if (
            ! $hub
            || $hub->logistics_company_id !== $delivery->logistics_company_id
            || $hub->id !== $delivery->destination_bayan_hub_id
        ) {
            return $this->operationError($request, 'This parcel is outside the active counter facility.', 403);
        }

        try {
            $result = app(PickupClaimService::class)->release($delivery, $request->user(), $hub,
                $request->only(['barcode', 'claim_code', 'buyer_id', 'recipient_name', 'identity_confirmed', 'request_token', 'notes', 'cash_received', 'change_given', 'cash_confirmed']));
        } catch (DomainException $error) {
            return $request->expectsJson() ? response()->json(['success' => false, 'message' => $error->getMessage()], 409)
                : back()->withErrors(['counter' => $error->getMessage()]);
        }
        if (! $result['success']) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $result['message']], 422);
            }

            return back()->withErrors(['claim_code' => $result['message']]);
        }
        $payload = ['success' => true, 'message' => $result['message'], 'delivery' => $result['delivery']->only(['id', 'tracking_number', 'status'])];

        return $request->wantsJson() ? response()->json($payload) : back()->with('success', $result['message']);
    }

    public function pickupHours(Request $request): JsonResponse|RedirectResponse
    {
        $values = $request->validate(['hub_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1'],
            'operating_hours' => ['required', 'string', new ApplicationText('notes', 2, 255)]]);
        DB::transaction(function () use ($request, $values) {
            $hub = LogisticsHub::findOrFail($values['hub_id']);
            $this->eligibility->lockNetwork($hub->logistics_company_id, [$request->user()->id], [$hub->id]);
            abort_unless($this->eligibility->isCompanyAdministrator($request->user(), $hub->logistics_company_id), 403);
            $hub->refresh()->update(['operating_hours' => trim($values['operating_hours'])]);
        });

        return $request->expectsJson() ? response()->json(['success' => true]) : back()->with('success', 'Actual pickup hours saved.');
    }

    /**
     * Dispatch parcel into designated barangay delivery bin.
     */
    public function sortBarangay(Request $request, LogisticsSortingInputService $inputs): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->isLogistics(), 403, 'Only logistics operators may sort parcels.');

        $validated = $inputs->validate($request->all());

        $delivery = Delivery::with('order')->findOrFail($validated['delivery_id']);
        [$activeHub] = $this->getActiveHub($request, $request->user());

        if (
            ! $activeHub
            || $activeHub->logistics_company_id !== $delivery->logistics_company_id
            || $activeHub->id !== $delivery->destination_bayan_hub_id
        ) {
            return $this->operationError($request, 'This parcel is outside the active destination facility.', 403);
        }

        if ($delivery->delivery_type !== 'doorstep') {
            return $this->operationError($request, 'Self-pickup parcels must be staged at the counter, not assigned to a barangay bin.');
        }

        if ($delivery->status !== OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB) {
            return $this->operationError($request, 'Parcel must be scanned into its destination Bayan Hub before sorting.');
        }

        if (! $delivery->destination_bayan_hub_id || $delivery->current_hub_id !== $delivery->destination_bayan_hub_id) {
            return $this->operationError($request, 'Parcel is not physically recorded at its destination Bayan Hub.');
        }

        $stateMachine = app(OrderStateMachineService::class);

        ['barangay' => $barangay, 'bin' => $bin] = $inputs->destination($delivery, $validated);

        $locationName = "Hub Sorting Bay ({$barangay} / {$bin})";

        try {
            $stateMachine->transition(
                delivery: $delivery,
                targetStatus: OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                actor: $request->user(),
                scanMetadata: [
                    'hub_id' => $activeHub->id,
                    'destination_bin' => $bin,
                    'location_name' => $locationName,
                    'notes' => $validated['notes'] ?? "Sorted to bin {$bin} for {$barangay}",
                ]
            );
        } catch (DomainException $exception) {
            return $this->operationError($request, $exception->getMessage(), 409);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Parcel #{$delivery->tracking_number} sorted to {$bin}.",
                'delivery' => $delivery->fresh(),
            ]);
        }

        return back()->with('success', "Parcel #{$delivery->tracking_number} sorted to {$bin}.");
    }

    /**
     * Assign a sorted doorstep parcel to an eligible final-mile rider.
     */
    public function assignRider(Request $request, Delivery $delivery): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->isLogistics(), 403, 'Only logistics operators may assign final-mile riders.');

        $validated = $request->validate([
            'rider_id' => 'required|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ]);

        [$activeHub] = $this->getActiveHub($request, $request->user());
        if (
            ! $activeHub
            || $activeHub->logistics_company_id !== $delivery->logistics_company_id
            || $activeHub->id !== $delivery->destination_bayan_hub_id
        ) {
            return $this->operationError($request, 'This parcel is outside the active destination facility.', 403);
        }

        $rider = User::with('courierProfile')->findOrFail($validated['rider_id']);

        $result = DB::transaction(function () use ($delivery, $rider, $request, $validated) {
            $orderId = Delivery::whereKey($delivery->id)->value('order_id');
            $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $lockedDelivery = Delivery::whereKey($delivery->id)->where('order_id', $lockedOrder->id)->lockForUpdate()->firstOrFail();

            if ($lockedDelivery->delivery_type !== 'doorstep') {
                return ['error' => 'Self-pickup parcels cannot be assigned to a delivery rider.'];
            }

            if (! in_array($lockedDelivery->status, [OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN, OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER], true)) {
                return ['error' => 'Parcel must be sorted into its destination bin before rider assignment.'];
            }

            if ($lockedDelivery->assigned_rider_id && $lockedDelivery->assigned_rider_id !== $rider->id) {
                return ['error' => 'Parcel is already assigned to a final-mile rider.'];
            }

            try {
                $updated = app(OrderStateMachineService::class)->transition(
                    delivery: $lockedDelivery,
                    targetStatus: OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
                    actor: $request->user(),
                    scanMetadata: [
                        'hub_id' => $lockedDelivery->destination_bayan_hub_id,
                        'rider_id' => $rider->id,
                        'location_name' => $lockedDelivery->destinationBayanHub?->name ?? 'Destination Bayan Hub',
                        'notes' => $validated['notes'] ?? "Assigned to final-mile rider {$rider->name}",
                    ]
                );
            } catch (DomainException $exception) {
                return ['error' => $exception->getMessage()];
            }

            return ['delivery' => $updated];
        });

        if (isset($result['error'])) {
            return $this->operationError($request, $result['error']);
        }

        $message = "Parcel #{$delivery->tracking_number} assigned to {$rider->name}.";
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'delivery' => $result['delivery']->fresh(),
            ]);
        }

        return back()->with('success', $message);
    }

    private function operationError(Request $request, string $message, int $status = 422): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'error' => $message], $status);
        }

        return back()->with('error', $message);
    }
}
