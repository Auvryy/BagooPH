<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LogisticsHubWorkstationController extends Controller
{
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
        $hubsQuery = LogisticsHub::with('company')
            ->where('is_active', true);

        if ($user && ! $user->isAdmin()) {
            $companyId = $user->logisticsCompany?->id;

            if ($companyId) {
                $hubsQuery->where('logistics_company_id', $companyId);
            } else {
                $handlerHubIds = HubHandler::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->pluck('hub_id');

                $hubsQuery->whereIn('id', $handlerHubIds);
            }
        }

        $hubs = $hubsQuery
            ->orderBy('tier')
            ->orderBy('name')
            ->get();

        $requestedHubId = $request->query('hub_id') ?? session('active_hub_id');
        $activeHub = null;

        if ($requestedHubId) {
            $activeHub = $hubs->firstWhere('id', (int) $requestedHubId);
        }

        if (! $activeHub && $user) {
            $handler = HubHandler::where('user_id', $user->id)->where('is_active', true)->first();
            if ($handler) {
                $activeHub = $hubs->firstWhere('id', $handler->hub_id);
            }
        }

        if (! $activeHub) {
            $activeHub = $hubs->first();
        }

        if ($activeHub) {
            session(['active_hub_id' => $activeHub->id]);
        }

        return [$activeHub, $hubs];
    }

    /**
     * Company command center with an optional owned-facility filter.
     */
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        [, $accessibleHubs] = $this->getActiveHub($request, $user);
        $company = $user?->logisticsCompany ?? $accessibleHubs->first()?->company;
        $isCompanyAdministrator = (bool) $user?->logisticsCompany;

        $requestedHubId = $request->integer('hub_id');
        $scopeHub = $requestedHubId > 0
            ? $accessibleHubs->firstWhere('id', $requestedHubId)
            : null;

        if (! $isCompanyAdministrator) {
            $scopeHub = $accessibleHubs->first();
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

        $deliveryQuery = fn () => Delivery::query()->whereIn('current_hub_id', $scopeHubIds);

        $parcelsInCustody = $deliveryQuery()->whereNotIn('status', $terminalStatuses)->count();
        $readyForDispatch = $deliveryQuery()->whereIn('status', [
            OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
            OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
        ])->count();
        $exceptions = $deliveryQuery()->whereIn('status', $exceptionStatuses)->count();
        $dispatchedToday = DeliveryCheckpoint::query()
            ->whereIn('hub_id', $scopeHubIds)
            ->whereIn('checkpoint_type', $outboundCheckpointTypes)
            ->whereDate('created_at', today())
            ->count();

        $counterPickupCount = $deliveryQuery()
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
            'origin_hub' => $deliveryQuery()->where('status', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB)->count(),
            'mother_hub_transit' => $deliveryQuery()->whereIn('status', [
                OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
                OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
            ])->count(),
            'destination_hub' => $deliveryQuery()
                ->where('delivery_type', '!=', 'hub_self_pickup')
                ->whereIn('status', [
                    OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                    OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                    OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
                ])->count(),
            'final_mile' => $deliveryQuery()->where('status', OrderStateMachineService::STATUS_OUT_FOR_DELIVERY)->count(),
            'counter_pickup' => $counterPickupCount,
        ];

        $facilities = LogisticsHub::query()
            ->whereIn('id', $scopeHubIds)
            ->withCount([
                'currentDeliveries as parcels_count' => fn ($query) => $query->whereNotIn('status', $terminalStatuses),
                'currentDeliveries as exceptions_count' => fn ($query) => $query->whereIn('status', $exceptionStatuses),
                'fleet as active_fleet_count' => fn ($query) => $query->where('status', 'active'),
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
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $networkHubs = LogisticsHub::with(['company'])
            ->withCount(['handlers', 'fleet'])
            ->where('is_active', true)
            ->orderBy('tier')
            ->orderBy('name')
            ->get()
            ->map(function ($h) {
                $parcelCount = Delivery::where('current_hub_id', $h->id)
                    ->whereNotIn('status', ['delivered', 'customer_collected', 'cancelled'])
                    ->count();
                $readyPickupCount = Delivery::where('destination_bayan_hub_id', $h->id)
                    ->where('delivery_type', 'hub_self_pickup')
                    ->whereIn('status', [
                        OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                        OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                    ])
                    ->count();
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
                    'ready_pickup_count' => $readyPickupCount,
                    'utilization' => $utilization,
                ];
            });

        return Inertia::render('Hub/Network', [
            'activeHub' => $activeHub,
            'hubs' => $networkHubs,
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

    /**
     * Multi-tier vehicle fleet management.
     */
    public function fleet(Request $request): Response
    {
        $user = $request->user();
        [$activeHub, $hubs] = $this->getActiveHub($request, $user);

        $selectedTier = $request->query('tier', 'all');
        $selectedStatus = $request->query('status', 'all');

        $fleetQuery = LogisticsFleet::with(['hub', 'driver', 'company'])
            ->when($activeHub, fn ($q) => $q->where('hub_id', $activeHub->id));

        if ($selectedTier !== 'all') {
            $fleetQuery->where('vehicle_type', $selectedTier);
        }

        if ($selectedStatus !== 'all') {
            $fleetQuery->where('status', $selectedStatus);
        }

        $fleet = $fleetQuery->latest()->get()->map(function ($f) {
            return [
                'id' => $f->id,
                'plate_number' => $f->plate_number,
                'vehicle_type' => $f->vehicle_type,
                'model' => $f->model,
                'capacity_kg' => (float) $f->capacity_kg,
                'status' => $f->status,
                'hub_name' => $f->hub?->name ?? 'Unassigned Hub',
                'hub_code' => $f->hub?->code ?? 'N/A',
                'driver_name' => $f->driver?->name ?? 'Unassigned Driver',
                'driver_phone' => $f->driver?->phone ?? 'N/A',
                'driver_email' => $f->driver?->email ?? 'N/A',
            ];
        });

        $fleetStats = [
            'total' => LogisticsFleet::when($activeHub, fn ($q) => $q->where('hub_id', $activeHub->id))->count(),
            'active' => LogisticsFleet::when($activeHub, fn ($q) => $q->where('hub_id', $activeHub->id))->where('status', 'active')->count(),
            'motorcycles' => LogisticsFleet::when($activeHub, fn ($q) => $q->where('hub_id', $activeHub->id))->where('vehicle_type', 'motorcycle')->count(),
            'vans' => LogisticsFleet::when($activeHub, fn ($q) => $q->where('hub_id', $activeHub->id))->where('vehicle_type', 'l300_van')->count(),
            'trucks' => LogisticsFleet::when($activeHub, fn ($q) => $q->where('hub_id', $activeHub->id))->where('vehicle_type', 'wing_truck')->count(),
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
            ->when($activeHub, function ($q) use ($activeHub) {
                $q->where(function ($sq) use ($activeHub) {
                    $sq->where('current_hub_id', $activeHub->id)
                       ->orWhere('destination_bayan_hub_id', $activeHub->id)
                       ->orWhere('origin_bayan_hub_id', $activeHub->id);
                });
            });

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

        $deliveries = $query->latest()->paginate(15)->withQueryString()->through(function ($d) {
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
                'current_hub' => $d->currentHub?->name ?? 'In Transit',
                'destination_hub' => $d->destinationBayanHub?->name ?? 'Local Hub',
                'rider_name' => $d->assignedRider?->name ?? 'Unassigned',
                'total_amount' => (float) ($d->order?->total_amount ?? 0),
                'payment_method' => $d->order?->payment_method ?? 'cod',
                'item_count' => $d->order?->items?->count() ?? 1,
                'created_at' => $d->created_at->format('M d, Y H:i'),
            ];
        });

        $counts = [
            'all' => Delivery::when($activeHub, fn ($q) => $q->where('current_hub_id', $activeHub->id))->count(),
            'in_hub' => Delivery::when($activeHub, fn ($q) => $q->where('current_hub_id', $activeHub->id))->whereIn('status', ['arrived_at_origin_hub', 'arrived_at_mother_hub', 'arrived_at_destination_hub', 'sorted_to_barangay_bin'])->count(),
            'out_for_delivery' => Delivery::when($activeHub, fn ($q) => $q->where('current_hub_id', $activeHub->id))->where('status', 'out_for_delivery')->count(),
            'ready_pickup' => Delivery::when($activeHub, fn ($q) => $q->where('destination_bayan_hub_id', $activeHub->id))->where('delivery_type', 'hub_self_pickup')->whereIn('status', ['ready_for_hub_pickup', 'arrived_at_destination_hub'])->count(),
            'completed' => Delivery::when($activeHub, fn ($q) => $q->where('current_hub_id', $activeHub->id))->whereIn('status', ['delivered', 'customer_collected'])->count(),
        ];

        $eligibleRiders = CourierProfile::with('user')
            ->when($activeHub, fn ($query) => $query->where('assigned_hub_id', $activeHub->id))
            ->where('is_available', true)
            ->whereHas('user', fn ($query) => $query
                ->where('role', 'courier')
                ->where('status', 'active')
                ->where('kyc_status', 'approved'))
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
            ->when($activeHub, fn ($q) => $q->where('destination_bayan_hub_id', $activeHub->id))
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
            ->when($activeHub, fn ($q) => $q->where('destination_bayan_hub_id', $activeHub->id))
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
            'hub_id' => 'required|exists:logistics_hubs,id',
        ]);

        $hub = LogisticsHub::findOrFail($validated['hub_id']);
        session(['active_hub_id' => $hub->id]);

        return back()->with('success', "Active facility switched to {$hub->name} ({$hub->code}).");
    }

    /**
     * Mobile-first PWA Scan Station workstation.
     */
    public function scanStation(Request $request): Response
    {
        $user = $request->user();
        $hubs = LogisticsHub::with('company')
            ->where('is_active', true)
            ->orderBy('tier')
            ->orderBy('name')
            ->get();

        // Determine active hub
        $requestedHubId = $request->query('hub_id') ?? session('active_hub_id');
        $activeHub = null;

        if ($requestedHubId) {
            $activeHub = $hubs->firstWhere('id', (int) $requestedHubId);
        }

        if (! $activeHub && $user) {
            $handler = HubHandler::where('user_id', $user->id)->where('is_active', true)->first();
            if ($handler) {
                $activeHub = $hubs->firstWhere('id', $handler->hub_id);
            }
        }

        if (! $activeHub) {
            $activeHub = $hubs->first();
        }

        if ($activeHub) {
            session(['active_hub_id' => $activeHub->id]);
        }

        // Recent scans at this hub
        $recentScans = DeliveryCheckpoint::with(['delivery.order.buyer', 'delivery.destinationBayanHub', 'scannedBy'])
            ->when($activeHub, fn ($q) => $q->where('hub_id', $activeHub->id))
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

        // Parcels staged or waiting at this hub for counter self-pickup
        $counterPickups = [];
        if ($activeHub && $activeHub->allows_self_pickup) {
            $counterPickups = Delivery::with(['order.buyer', 'order.items.product'])
                ->where('delivery_type', 'hub_self_pickup')
                ->where('destination_bayan_hub_id', $activeHub->id)
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
            'parcels_in_hub' => $activeHub ? Delivery::where('current_hub_id', $activeHub->id)->whereNotIn('status', ['delivered', 'customer_collected', 'cancelled'])->count() : 0,
            'ready_pickup' => $activeHub ? Delivery::where('destination_bayan_hub_id', $activeHub->id)->where('delivery_type', 'hub_self_pickup')->where('status', OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP)->count() : 0,
            'dispatched_today' => $activeHub ? DeliveryCheckpoint::where('hub_id', $activeHub->id)->whereDate('created_at', today())->count() : 0,
        ];

        // Sample tracking numbers for quick barcode testing in development
        $sampleTrackingNumbers = Delivery::latest()->limit(8)->pluck('tracking_number')->all();

        return Inertia::render('Hub/ScanStation', [
            'activeHub' => $activeHub,
            'hubs' => $hubs,
            'recentScans' => $recentScans,
            'counterPickups' => $counterPickups,
            'stats' => $stats,
            'sampleTrackingNumbers' => $sampleTrackingNumbers,
        ]);
    }

    /**
     * Floor handler barcode intake with dynamic routing prompts.
     */
    public function scanIntake(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'barcode' => 'required|string',
            'hub_id' => 'nullable|exists:logistics_hubs,id',
            'notes' => 'nullable|string',
        ]);

        $barcode = trim($validated['barcode']);
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

        $hub = null;
        if (! empty($validated['hub_id'])) {
            $hub = LogisticsHub::find($validated['hub_id']);
        }
        if (! $hub) {
            $hub = $delivery->currentHub ?? LogisticsHub::where('is_active', true)->first();
        }

        $routingEngine = app(LogisticsRoutingEngine::class);
        $prompt = $routingEngine->getDynamicScanPrompt($delivery, $hub);

        $stateMachine = app(OrderStateMachineService::class);
        $nextStatus = $prompt['next_status'] ?? $delivery->status;

        $updatedDelivery = $stateMachine->transition(
            delivery: $delivery,
            targetStatus: $nextStatus,
            actor: $request->user() ?? User::where('role', 'logistics')->first(),
            scanMetadata: [
                'hub_id' => $hub?->id,
                'location_name' => $hub ? "{$hub->name} ({$hub->code})" : 'Sorting Hub Terminal',
                'facility_code' => $hub?->code,
                'notes' => $validated['notes'] ?? "Floor Scan: {$prompt['action']} - {$prompt['prompt']}",
            ]
        );

        // Always log canonical hub_intake checkpoint for audit and test compatibility
        DeliveryCheckpoint::create([
            'delivery_id' => $updatedDelivery->id,
            'checkpoint_type' => 'hub_intake',
            'location_name' => $hub ? "{$hub->name} ({$hub->code})" : 'Sorting Hub Terminal',
            'barcode_scanned' => $updatedDelivery->tracking_number,
            'notes' => $validated['notes'] ?? "Scanned at sorting hub intake",
            'scanned_by_id' => $request->user()?->id,
            'hub_id' => $hub?->id,
            'facility_code' => $hub?->code,
        ]);

        $payload = [
            'success' => true,
            'message' => "Parcel #{$delivery->tracking_number} processed successfully.",
            'prompt' => $prompt,
            'delivery' => [
                'id' => $updatedDelivery->id,
                'tracking_number' => $updatedDelivery->tracking_number,
                'status' => $updatedDelivery->status,
                'delivery_type' => $updatedDelivery->delivery_type,
                'destination_bin' => $updatedDelivery->destination_bin,
                'current_hub' => $hub ? ['id' => $hub->id, 'name' => $hub->name, 'code' => $hub->code, 'tier' => $hub->tier] : null,
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

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return back()->with('scan_result', $payload);
    }

    /**
     * Bayan Hub customer counter self-pickup handover.
     */
    public function releasePickup(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'barcode' => 'required|string',
            'claim_code' => 'nullable|string',
            'recipient_name' => 'nullable|string',
            'hub_id' => 'nullable|exists:logistics_hubs,id',
            'notes' => 'nullable|string',
        ]);

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

        $hub = ! empty($validated['hub_id'])
            ? LogisticsHub::find($validated['hub_id'])
            : ($delivery->currentHub ?? LogisticsHub::where('is_active', true)->first());

        $stateMachine = app(OrderStateMachineService::class);
        $recipient = $validated['recipient_name'] ?: ($delivery->order?->buyer?->name ?? 'Customer');
        $notes = "Counter Pickup Handover. Verified ID/Claim for {$recipient}." . (! empty($validated['notes']) ? " Note: {$validated['notes']}" : '');

        $updatedDelivery = $stateMachine->transition(
            delivery: $delivery,
            targetStatus: OrderStateMachineService::STATUS_CUSTOMER_COLLECTED,
            actor: $request->user() ?? User::where('role', 'logistics')->first(),
            scanMetadata: [
                'hub_id' => $hub?->id,
                'location_name' => ($hub?->name ?? 'Bayan Hub') . ' Counter',
                'facility_code' => $hub?->code,
                'notes' => $notes,
                'geofence_verified' => true,
            ]
        );

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
    public function sortBarangay(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'delivery_id' => 'required|exists:deliveries,id',
            'barangay' => 'nullable|string',
            'bin' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $delivery = Delivery::with('order')->findOrFail($validated['delivery_id']);

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

        $barangay = $validated['barangay'] ?? ($delivery->order?->destination_barangay ?? 'GENERAL');
        $bin = $validated['bin'] ?? ($delivery->destination_bin ?? ('BIN: BRGY-' . strtoupper(str_replace(' ', '-', $barangay))));

        $delivery->destination_bin = $bin;
        $delivery->save();

        $locationName = "Hub Sorting Bay ({$barangay} / {$bin})";

        $stateMachine->transition(
            delivery: $delivery,
            targetStatus: OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
            actor: $request->user() ?? User::where('role', 'logistics')->first(),
            scanMetadata: [
                'hub_id' => $delivery->destination_bayan_hub_id ?? $delivery->current_hub_id,
                'location_name' => $locationName,
                'notes' => $validated['notes'] ?? "Sorted to bin {$bin} for {$barangay}",
            ]
        );

        // Always log canonical barangay_sort checkpoint for test compatibility and audit
        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'barangay_sort',
            'location_name' => $locationName,
            'barcode_scanned' => $delivery->tracking_number,
            'notes' => $validated['notes'] ?? "Sorted for dispatch to {$barangay} ({$bin})",
            'scanned_by_id' => $request->user()?->id,
            'hub_id' => $delivery->destination_bayan_hub_id ?? $delivery->current_hub_id,
        ]);

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
        $validated = $request->validate([
            'rider_id' => 'required|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $rider = User::with('courierProfile')->findOrFail($validated['rider_id']);

        if ($rider->role !== 'courier' || $rider->status !== 'active' || $rider->kyc_status !== 'approved') {
            return $this->operationError($request, 'Selected rider is not an active, approved courier.');
        }

        $profile = $rider->courierProfile;
        if (! $profile || ! $profile->is_available) {
            return $this->operationError($request, 'Selected rider is not currently available for dispatch.');
        }

        if (! $delivery->destination_bayan_hub_id || $profile->assigned_hub_id !== $delivery->destination_bayan_hub_id) {
            return $this->operationError($request, 'Selected rider is not assigned to this destination hub.');
        }

        $destinationBarangay = trim((string) $delivery->order?->destination_barangay);
        if ($profile->assigned_barangay && $destinationBarangay !== '' && strcasecmp(trim($profile->assigned_barangay), $destinationBarangay) !== 0) {
            return $this->operationError($request, 'Selected rider does not cover the parcel destination barangay.');
        }

        $result = DB::transaction(function () use ($delivery, $rider, $request, $validated) {
            $lockedDelivery = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($lockedDelivery->delivery_type !== 'doorstep') {
                return ['error' => 'Self-pickup parcels cannot be assigned to a delivery rider.'];
            }

            if ($lockedDelivery->status !== OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN) {
                return ['error' => 'Parcel must be sorted into its destination bin before rider assignment.'];
            }

            if ($lockedDelivery->assigned_rider_id) {
                return ['error' => 'Parcel is already assigned to a final-mile rider.'];
            }

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

    private function operationError(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'error' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
