<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
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
        $company = $actor->isLogistics() ? LogisticsCompany::eligible()->where('user_id', $actor->id)->first() : null;
        $token = null;
        if ($company) {
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
            $detail = $this->summary($selected) + [
                'canManage' => $actor->isLogistics() && $company?->id === $selected->logistics_company_id,
                'canLoad' => $actor->isLogistics() && $this->eligibility->canScan($actor, $source),
                'canReceive' => $actor->isLogistics() && $this->eligibility->canScan($actor, $destination),
                'parcels' => $selected->parcels()->with('delivery.order')->orderBy('id')->get()->map(fn ($parcel) => [
                    'id' => $parcel->id, 'tracking_number' => $parcel->tracking_number_snapshot, 'included' => $parcel->included,
                    'status' => $parcel->delivery->status, 'received_at' => $parcel->received_at?->toIso8601String(),
                    'receipt_condition' => $parcel->receipt_condition,
                ]),
                'discrepancies' => $this->service->openDiscrepancies($selected)->map(fn ($event) => [
                    'id' => $event->id, 'reference' => $event->reference, 'kind' => $event->payload['kind'],
                    'parcel_id' => $event->manifest_parcel_id, 'barcode' => $event->barcode_scanned, 'reason' => $event->reason,
                ]),
                'events' => $selected->events()->with('actor')->orderBy('id')->get()->map(fn ($event) => [
                    'id' => $event->id, 'reference' => $event->reference, 'type' => $event->event_type,
                    'actor' => $event->actor->name, 'actor_role' => $event->actor_role, 'barcode' => $event->barcode_scanned,
                    'reason' => $event->reason, 'created_at' => $event->created_at->toIso8601String(),
                ]),
            ];
        }

        return Inertia::render('Hub/Manifests', [
            'manifests' => $list, 'selectedManifest' => $detail, 'canCreate' => (bool) $company, 'creationToken' => $token,
            'commandToken' => (string) Str::uuid(),
            'hubs' => $company ? LogisticsHub::eligible()->where('logistics_company_id', $company->id)->orderBy('name')->get(['id', 'name', 'code', 'tier']) : [],
            'vehicles' => $company ? LogisticsFleet::ready()->whereIn('vehicle_type', ['l300_van', 'wing_truck'])->where('logistics_company_id', $company->id)->whereNotNull('assigned_driver_id')
                ->with('driver')->orderBy('plate_number')->get()->map(fn ($fleet) => ['id' => $fleet->id, 'hub_id' => $fleet->hub_id,
                    'plate_number' => $fleet->plate_number, 'vehicle_type' => $fleet->vehicle_type, 'driver_name' => $fleet->driver->name]) : [],
            'basePath' => $request->is('hub/*') ? '/hub/manifests' : '/manifests',
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $manifest = $this->service->create($request->user(), $request->only(['source_hub_id', 'destination_hub_id', 'vehicle_id', 'creation_token']),
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
        return $manifest->state() + ['type' => $manifest->type, 'included_count' => $manifest->included_count,
            'source' => $manifest->sourceHub->only(['id', 'name', 'code', 'tier']),
            'destination' => $manifest->destinationHub->only(['id', 'name', 'code', 'tier']),
            'vehicle_plate' => $manifest->vehicle->plate_number, 'driver_name' => $manifest->driver->name];
    }

    private function conflict(Request $request, DomainException $error): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $error->getMessage()], 409)
            : back()->withErrors(['manifest' => $error->getMessage()]);
    }
}
