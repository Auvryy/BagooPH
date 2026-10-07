<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\LogisticsSortingInputService;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
                });
        });
    }

    /**
     * Scope the registry to parcels physically at the facility or waiting for
     * that facility's first custody scan. Route legs alone do not grant
     * visibility to a hub that has not received the parcel.
     */
    protected function scopeDeliveryRegistryAtHub($query, ?LogisticsHub $hub)
    {
        if (! $hub) {
            return $query->whereKey(0);
        }

        return $this->scopeDeliveriesAtHubs($query, [$hub->id], $hub->logistics_company_id);
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
            'barangay' => 'exclude_unless:kind,courier|nullable|string|max:100',
            'current_hub_id' => 'exclude_unless:kind,courier|nullable|integer|exists:logistics_hubs,id',
        ]);
        [$hub] = $this->eligibility->hubContext($request, strict: true);
        $account = User::whereRaw('LOWER(email) = ?', [strtolower(trim($validated['email']))])->first();
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
    public function deliveries(Request $request): Response
    {
        $user = $request->user();
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $search = trim($request->query('search', ''));
        $statusFilter = $request->query('status', 'all');
        $deliveryType = $request->query('delivery_type', 'all');

        $query = Delivery::with([
            'order.buyer',
            'order.items.product',
            'originBayanHub',
            'originMotherHub',
            'destinationBayanHub',
            'destinationMotherHub',
            'assignedRider',
            'currentHub',
        ])
            ->where(fn ($q) => $this->scopeDeliveryRegistryAtHub($q, $activeHub));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$search}%"))
                    ->orWhereHas('order.buyer', fn ($bq) => $bq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        if ($deliveryType !== 'all') {
            $query->where('delivery_type', $deliveryType);
        }

        $deliveries = $query->latest()->paginate(15)->withQueryString()->through(function ($d) use ($activeHub) {
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
                'destination_bin' => $d->destination_bin ?? 'STAGE: UNASSIGNED',
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
            ];
        });

        $registryQuery = fn () => $this->scopeDeliveryRegistryAtHub(Delivery::query(), $activeHub);

        $counts = [
            'all' => $registryQuery()->count(),
            'in_hub' => $registryQuery()->whereIn('status', ['arrived_at_origin_hub', 'arrived_at_mother_hub', 'arrived_at_destination_hub', 'sorted_to_barangay_bin'])->count(),
            'out_for_delivery' => $registryQuery()->where('status', 'out_for_delivery')->count(),
            'ready_pickup' => Delivery::where('destination_bayan_hub_id', $activeHub?->id ?? 0)->where('logistics_company_id', $activeHub?->logistics_company_id ?? 0)->where('delivery_type', 'hub_self_pickup')->whereIn('status', ['ready_for_hub_pickup', 'arrived_at_destination_hub'])->count(),
            'completed' => $registryQuery()->whereIn('status', ['delivered', 'customer_collected'])->count(),
        ];

        $eligibleRiders = $this->eligibility->riderCandidates($activeHub)
            ->get()
            ->map(fn ($profile) => [
                'id' => $profile->user_id,
                'name' => $profile->user?->name ?? 'Rider',
                'assigned_barangay' => $profile->assigned_barangay,
                'vehicle_type' => $profile->vehicle_type,
            ])
            ->values();

        return Inertia::render('Hub/Deliveries', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'deliveries' => $deliveries,
            'counts' => $counts,
            'eligibleRiders' => $eligibleRiders,
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
    public function counter(Request $request): Response
    {
        $user = $request->user();
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $search = trim($request->query('search', ''));

        $query = Delivery::with(['order.buyer', 'order.items.product'])
            ->where('delivery_type', 'hub_self_pickup')
            ->where('destination_bayan_hub_id', $activeHub?->id ?? 0)
            ->where('logistics_company_id', $activeHub?->logistics_company_id ?? 0)
            ->whereIn('status', [
                OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$search}%"))
                    ->orWhereHas('order.buyer', fn ($bq) => $bq->where('name', 'like', "%{$search}%"));
            });
        }

        $counterParcels = $query->latest()->get()->map(function ($d) {
            return [
                'id' => $d->id,
                'tracking_number' => $d->tracking_number,
                'order_number' => $d->order?->order_number ?? 'N/A',
                'buyer_name' => $d->order?->buyer?->name ?? 'Customer',
                'buyer_phone' => $d->order?->buyer?->phone ?? 'N/A',
                'status' => $d->status,
                'destination_bin' => $d->destination_bin ?? 'SHELF-A1',
                'total_amount' => (float) ($d->order?->total_amount ?? 0),
                'payment_method' => $d->order?->payment_method ?? 'cod',
                'item_count' => $d->order?->items?->count() ?? 1,
                'items' => $d->order?->items?->map(fn ($item) => [
                    'name' => $item->product?->name ?? 'Item',
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                ]) ?? [],
                'arrived_at' => $d->updated_at->format('M d, H:i'),
            ];
        });

        $recentlyCollected = Delivery::with(['order.buyer'])
            ->where('delivery_type', 'hub_self_pickup')
            ->where('destination_bayan_hub_id', $activeHub?->id ?? 0)
            ->where('logistics_company_id', $activeHub?->logistics_company_id ?? 0)
            ->where('status', OrderStateMachineService::STATUS_CUSTOMER_COLLECTED)
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'tracking_number' => $d->tracking_number,
                'buyer_name' => $d->order?->buyer?->name ?? 'Customer',
                'collected_at' => $d->updated_at->diffForHumans(),
            ]);

        return Inertia::render('Hub/CounterPickup', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'counterParcels' => $counterParcels,
            'recentlyCollected' => $recentlyCollected,
            'search' => $search,
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

        return back()->with('success', "Active facility switched to {$hub->name} ({$hub->code}).");
    }

    /**
     * Mobile-first PWA Scan Station workstation.
     */
    public function scanStation(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->isLogistics(), 403, 'Platform administrators may audit logistics but cannot operate a scan station.');
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        abort_unless($activeHub, 403, 'No active facility is assigned to this account.');
        abort_unless($this->eligibility->canScan($user, $activeHub), 403, 'An active handler assignment is required for floor scans.');

        // Recent scans at this hub
        $recentScans = DeliveryCheckpoint::with(['delivery.order.buyer', 'delivery.destinationBayanHub', 'scannedBy'])
            ->where('hub_id', $activeHub->id)
            ->whereHas('delivery', fn ($query) => $query->where('logistics_company_id', $activeHub->logistics_company_id))
            ->latest()
            ->limit(15)
            ->get()
            ->map(function ($cp) {
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
                    'destination_bin' => $cp->delivery?->destination_bin ?? 'N/A',
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
        $counterPickups = [];
        if ($activeHub && $activeHub->allows_self_pickup) {
            $counterPickups = Delivery::with(['order.buyer', 'order.items.product'])
                ->where('delivery_type', 'hub_self_pickup')
                ->where('destination_bayan_hub_id', $activeHub->id)
                ->where('logistics_company_id', $activeHub->logistics_company_id)
                ->whereIn('status', [
                    OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                    OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                    OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                ])
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'tracking_number' => $d->tracking_number,
                    'order_number' => $d->order?->order_number,
                    'buyer_name' => $d->order?->buyer?->name ?? 'Customer',
                    'buyer_phone' => $d->order?->buyer?->phone ?? 'N/A',
                    'status' => $d->status,
                    'destination_bin' => $d->destination_bin ?? 'STAGE: SELF-PICKUP-SHELF',
                    'total_amount' => (float) ($d->order?->total_amount ?? 0),
                    'payment_method' => $d->order?->payment_method ?? 'cod',
                    'item_count' => $d->order?->items?->count() ?? 1,
                ]);
        }

        // Quick stats for active hub
        $stats = [
            'parcels_in_hub' => $activeHub ? Delivery::query()->where('logistics_company_id', $activeHub->logistics_company_id)->where('current_hub_id', $activeHub->id)->whereNotIn('status', ['delivered', 'customer_collected', 'cancelled'])->count() : 0,
            'ready_pickup' => $activeHub ? Delivery::where('destination_bayan_hub_id', $activeHub->id)->where('logistics_company_id', $activeHub->logistics_company_id)->where('delivery_type', 'hub_self_pickup')->where('status', OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP)->count() : 0,
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
            'stats' => $stats,
        ]);
    }

    /**
     * Floor handler barcode intake with dynamic routing prompts.
     */
    public function scanIntake(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->isLogistics(), 403, 'Only logistics operators may scan parcel custody.');

        $validated = $request->validate([
            'barcode' => 'required|string',
            'hub_id' => 'nullable|exists:logistics_hubs,id',
            'notes' => 'nullable|string',
            'mode' => 'nullable|in:inspect,confirm',
            'action' => 'required_if:mode,confirm|nullable|string',
            'expected_status' => 'required_if:mode,confirm|nullable|string',
        ]);

        $barcode = trim($validated['barcode']);
        [$hub] = $this->getActiveHub($request, $request->user());
        abort_unless($hub && $this->eligibility->canScan($request->user(), $hub), 403, 'An active handler assignment is required for floor scans.');
        $delivery = Delivery::with([
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
            ->first();

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

        if ($prompt['action'] === 'INSPECT_WAYBILL') {
            return $this->operationError(
                $request,
                'This parcel is not expected at the active facility for its current custody state.',
                409
            );
        }

        if (($validated['mode'] ?? 'inspect') !== 'confirm') {
            $requiresConfirmation = $prompt['action'] !== 'AWAIT_BARANGAY_SORT';

            return $this->scanResponse($this->scanPayload(
                delivery: $delivery,
                hub: $hub,
                prompt: $prompt,
                message: $requiresConfirmation
                    ? 'Waybill verified. Confirm the displayed custody action to continue.'
                    : 'Waybill verified. Use the destination sorting action to continue.',
                requiresConfirmation: $requiresConfirmation
            ));
        }

        $requestedAction = strtoupper(trim($validated['action']));
        $expectedStatus = strtolower(trim($validated['expected_status']));
        $targetStatus = $this->scanTargetForAction($requestedAction);

        if (! $targetStatus) {
            return $this->operationError($request, 'The requested scan action is not a recognized custody handoff.', 422);
        }

        if ($delivery->status === $expectedStatus && ($prompt['action'] !== $requestedAction || $prompt['next_status'] !== $targetStatus)) {
            return $this->operationError($request, 'The scan instruction no longer matches the parcel route. Scan the waybill again.', 409);
        }

        $stateMachine = app(OrderStateMachineService::class);

        try {
            $updatedDelivery = $stateMachine->transition(
                delivery: $delivery,
                targetStatus: $targetStatus,
                actor: $request->user(),
                scanMetadata: [
                    'hub_id' => $hub?->id,
                    'location_name' => $hub ? "{$hub->name} ({$hub->code})" : 'Sorting Hub Terminal',
                    'facility_code' => $hub?->code,
                    'scan_action' => $requestedAction,
                    'expected_status' => $expectedStatus,
                    'notes' => $validated['notes'] ?? "Floor Scan: {$requestedAction}",
                ]
            );
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
            confirmed: true
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
        bool $confirmed = false
    ): array {
        $delivery->loadMissing(['order.items.product', 'order.buyer', 'currentHub']);
        $custodyHub = $delivery->currentHub;

        return [
            'success' => true,
            'message' => $message,
            'confirmed' => $confirmed,
            'prompt' => [
                ...$prompt,
                'expected_status' => $delivery->status,
                'requires_confirmation' => $requiresConfirmation,
            ],
            'delivery' => [
                'id' => $delivery->id,
                'tracking_number' => $delivery->tracking_number,
                'status' => $delivery->status,
                'delivery_type' => $delivery->delivery_type,
                'destination_bin' => $delivery->destination_bin,
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
            default => null,
        };
    }

    /**
     * Bayan Hub customer counter self-pickup handover.
     */
    public function releasePickup(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->isLogistics(), 403, 'Only logistics operators may release counter parcels.');

        $validated = $request->validate([
            'barcode' => 'required|string',
            'claim_code' => 'nullable|string',
            'recipient_name' => 'nullable|string',
            'hub_id' => 'nullable|exists:logistics_hubs,id',
            'notes' => 'nullable|string',
        ]);

        [$activeHub] = $this->getActiveHub($request, $request->user());
        abort_unless($activeHub && $this->eligibility->canScan($request->user(), $activeHub), 403, 'An active handler assignment is required for counter release.');
        $barcode = trim($validated['barcode']);
        $delivery = Delivery::with(['order.buyer', 'order.items.product'])
            ->where('tracking_number', $barcode)
            ->orWhereHas('order', fn ($q) => $q->where('order_number', $barcode))
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

        $stateMachine = app(OrderStateMachineService::class);
        $recipient = ($validated['recipient_name'] ?? null) ?: ($delivery->order?->buyer?->name ?? 'Customer');
        $notes = "Counter Pickup Handover. Verified ID/Claim for {$recipient}.".(! empty($validated['notes']) ? " Note: {$validated['notes']}" : '');

        try {
            $updatedDelivery = $stateMachine->transition(
                delivery: $delivery,
                targetStatus: OrderStateMachineService::STATUS_CUSTOMER_COLLECTED,
                actor: $request->user(),
                scanMetadata: [
                    'hub_id' => $hub->id,
                    'location_name' => $hub->name.' Counter',
                    'facility_code' => $hub->code,
                    'notes' => $notes,
                    'geofence_verified' => true,
                ]
            );
        } catch (DomainException $exception) {
            return $this->operationError($request, $exception->getMessage(), 409);
        }

        $payload = [
            'success' => true,
            'message' => "Parcel #{$delivery->tracking_number} successfully collected by {$recipient}!",
            'delivery' => [
                'id' => $updatedDelivery->id,
                'tracking_number' => $updatedDelivery->tracking_number,
                'status' => $updatedDelivery->status,
            ],
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return back()->with('success', $payload['message']);
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
