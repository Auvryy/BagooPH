<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Services\Logistics\CargoHandlingService;
use App\Services\Logistics\LogisticsAccountService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\MotherBagService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogisticsMotherBagController extends Controller
{
    public function index(
        Request $request,
        LogisticsAccountService $accounts,
        MotherBagService $service,
        LogisticsEligibilityService $eligibility,
        CargoHandlingService $handling,
    ): JsonResponse {
        $actor = $request->user();
        $assignedHubs = $accounts->hubs($actor);
        abort_if($assignedHubs->isEmpty(), 403, 'No active scanning assignment is available.');

        $requestedHubId = $request->integer('hub_id');
        $hub = $requestedHubId > 0
            ? $assignedHubs->firstWhere('id', $requestedHubId)
            : $assignedHubs->first();
        abort_unless($hub, 403, 'This facility is not assigned to your operator account.');

        $companyHubs = LogisticsHub::eligible()
            ->where('logistics_company_id', $hub->logistics_company_id)
            ->orderBy('tier')
            ->orderBy('name')
            ->get();

        $nextHubs = $companyHubs->filter(function (LogisticsHub $candidate) use ($hub) {
            if ($candidate->id === $hub->id) {
                return false;
            }
            if ($hub->isMotherHub()) {
                return $candidate->isMotherHub() || $candidate->parent_hub_id === $hub->id;
            }

            return $candidate->id === $hub->parent_hub_id;
        })->values()->map->only(['id', 'name', 'code', 'tier', 'parent_hub_id']);

        $bags = $service->readable($actor)
            ->where(fn ($q) => $q->where('source_hub_id', $hub->id)->orWhere('destination_hub_id', $hub->id))
            ->with(['source', 'destination'])
            ->withCount(['parcels' => fn ($q) => $q->whereNull('removed_at')])
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (MotherBag $bag) => $this->summary($bag, $actor, $eligibility, $handling))
            ->values();

        $manifests = LogisticsManifest::where('source_hub_id', $hub->id)
            ->where('status', 'draft')
            ->with(['vehicle', 'driver'])
            ->latest()
            ->get()
            ->map(fn (LogisticsManifest $manifest) => [
                'id' => $manifest->id,
                'reference' => $manifest->reference,
                'source_hub_id' => $manifest->source_hub_id,
                'destination_hub_id' => $manifest->destination_hub_id,
                'vehicle_plate' => $manifest->vehicle?->plate_number,
                'driver_name' => $manifest->driverName(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'hub' => [
                    'id' => $hub->id,
                    'name' => $hub->name,
                    'code' => $hub->code,
                    'tier' => $hub->tier,
                    'job_role' => $eligibility->jobRole($actor, $hub) ?? 'all',
                    'can_intake' => $eligibility->canIntake($actor, $hub),
                    'can_pack_dispatch' => $eligibility->canPackDispatch($actor, $hub),
                ],
                'bags' => $bags,
                'next_hubs' => $nextHubs,
                'manifests' => $manifests,
                'vehicles' => MotherBagService::dispatchVehicles($hub),
                'presets' => $handling->presets(),
            ],
        ]);
    }

    public function show(
        Request $request,
        MotherBag $bag,
        MotherBagService $service,
        LogisticsEligibilityService $eligibility,
        CargoHandlingService $handling,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($service->readable($actor)->whereKey($bag->id)->exists(), 403);

        return response()->json([
            'data' => $this->detail($bag, $actor, $eligibility, $handling),
        ]);
    }

    public function store(
        Request $request,
        LogisticsAccountService $accounts,
        MotherBagService $service,
        LogisticsEligibilityService $eligibility,
        CargoHandlingService $handling,
    ): JsonResponse {
        $actor = $request->user();
        $hubId = $request->integer('source_hub_id');
        abort_unless($hubId > 0 && $accounts->hubs($actor)->contains('id', $hubId), 403, 'This facility is not assigned to your operator account.');

        try {
            $bag = $service->create($actor, $request->all());
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Mother Bag created. Scan parcels to pack.',
            'data' => $this->detail($bag, $actor, $eligibility, $handling),
        ], 201);
    }

    public function command(
        Request $request,
        MotherBag $bag,
        string $action,
        MotherBagService $service,
        LogisticsEligibilityService $eligibility,
        CargoHandlingService $handling,
    ): JsonResponse {
        $actor = $request->user();
        try {
            $updated = $service->command($bag, $actor, $action, $request->all());
        } catch (DomainException $error) {
            $message = $error->getMessage();
            $needsMeasurements = str_contains($message, 'Measure this outer package first')
                || str_contains($message, 'incomplete package dimensions')
                || str_contains($message, 'weight and all three outer-package dimensions');

            return response()->json([
                'message' => $message,
                'needs_measurements' => $needsMeasurements,
            ], 422);
        }

        return response()->json([
            'message' => match ($action) {
                'add' => 'Parcel added to Mother Bag.',
                'remove' => 'Parcel removed from Mother Bag.',
                'seal' => 'Mother Bag sealed and ready for dispatch.',
                'reopen' => 'Mother Bag reopened for correction.',
                'load' => 'Mother Bag loaded onto transport manifest.',
                'unload' => 'Mother Bag unloaded from draft manifest.',
                'open' => 'Seal verified and Mother Bag opened for receiving.',
                default => 'Mother Bag updated.',
            },
            'data' => $this->detail($updated, $actor, $eligibility, $handling),
        ]);
    }

    /** Seal (if open), create the manifest, load and dispatch the bag in one call. */
    public function dispatch(
        Request $request,
        MotherBag $bag,
        MotherBagService $service,
        LogisticsEligibilityService $eligibility,
        CargoHandlingService $handling,
    ): JsonResponse {
        try {
            $result = $service->dispatchBag($bag, $request->user(), $request->all());
        } catch (DomainException|AuthorizationException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json([
            'message' => "Bag sealed and dispatched on {$result['manifest']->reference}.",
            'data' => $this->detail($result['bag'], $request->user(), $eligibility, $handling),
            'manifest' => ['id' => $result['manifest']->id, 'reference' => $result['manifest']->reference, 'status' => $result['manifest']->status],
        ]);
    }

    private function summary(
        MotherBag $bag,
        $actor,
        LogisticsEligibilityService $eligibility,
        CargoHandlingService $handling,
    ): array {
        $bag->loadMissing(['source', 'destination']);

        return $bag->only([
            'id',
            'reference',
            'status',
            'seal_number',
            'version',
            'source_hub_id',
            'destination_hub_id',
            'manifest_id',
            'capacity_preset',
        ]) + [
            'parcels_count' => $bag->parcels_count ?? $bag->parcels()->whereNull('removed_at')->count(),
            'physical_tag' => $bag->physical_tag,
            'packing_policy_version' => $bag->packing_policy_version,
            'capacity' => $handling->available() ? $handling->capacity($bag) : null,
            'source' => $bag->source?->only(['id', 'name', 'code', 'tier']),
            'destination' => $bag->destination?->only(['id', 'name', 'code', 'tier']),
            'can_pack' => $bag->source ? $eligibility->canPackDispatch($actor, $bag->source) : false,
            'can_open' => $bag->destination ? $eligibility->canIntake($actor, $bag->destination) : false,
        ];
    }

    private function detail(
        MotherBag $bag,
        $actor,
        LogisticsEligibilityService $eligibility,
        CargoHandlingService $handling,
    ): array {
        $bag = $bag->fresh(['source', 'destination']);

        return $this->summary($bag, $actor, $eligibility, $handling) + [
            'parcels' => $bag->parcels()
                ->with('delivery')
                ->latest('id')
                ->get()
                ->map(fn ($member) => [
                    'id' => $member->delivery_id,
                    'tracking_number' => $member->delivery?->tracking_number ?? $member->barcode_scanned,
                    'status' => $member->delivery?->status ?? $member->source_state,
                    'removed' => $member->removed_at !== null,
                    'received' => $member->received_at !== null,
                    'handling' => $member->handling_snapshot,
                ])->values(),
            'events' => DB::table('mother_bag_events')
                ->join('users', 'users.id', '=', 'mother_bag_events.actor_id')
                ->where('mother_bag_id', $bag->id)
                ->orderByDesc('mother_bag_events.id')
                ->limit(25)
                ->get(['event_type', 'barcode_scanned', 'users.name as actor', 'mother_bag_events.created_at'])
                ->values(),
        ];
    }
}
