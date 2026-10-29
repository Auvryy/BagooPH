<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\MotherBagService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MotherBagController extends Controller
{
    public function index(Request $request, MotherBagService $service, LogisticsEligibilityService $eligibility)
    {
        $actor = $request->user();
        abort_unless($actor->isLogistics(), 403);
        $stations = $eligibility->accessibleHubs($actor)->get();
        // A receiving facility is a route choice, not permission to work at that facility.
        $hubs = LogisticsHub::eligible()->whereIn('logistics_company_id', $stations->pluck('logistics_company_id'))->get();
        $query = $service->readable($actor)->with(['source', 'destination'])->withCount(['parcels' => fn ($q) => $q->whereNull('removed_at')]);
        $selected = $request->filled('bag') ? (clone $query)->findOrFail($request->query('bag')) : null;
        $summary = fn (MotherBag $bag) => $bag->only(['id', 'reference', 'status', 'seal_number', 'version', 'source_hub_id', 'destination_hub_id', 'manifest_id', 'parcels_count'])
            + ['source' => $bag->source->only(['id', 'name', 'code', 'tier', 'latitude', 'longitude']), 'destination' => $bag->destination->only(['id', 'name', 'code', 'tier', 'latitude', 'longitude'])];
        $detail = $selected ? $summary($selected) + [
            'canPack' => $eligibility->canScan($actor, $selected->source),
            'canOpen' => $eligibility->canScan($actor, $selected->destination),
            'parcels' => $selected->parcels()->with('delivery')->get()->map(fn ($member) => [
                'id' => $member->delivery_id, 'tracking_number' => $member->delivery->tracking_number,
                'status' => $member->delivery->status, 'removed' => $member->removed_at !== null, 'received' => $member->received_at !== null,
            ]),
            'events' => DB::table('mother_bag_events')->join('users', 'users.id', '=', 'mother_bag_events.actor_id')
                ->where('mother_bag_id', $selected->id)->orderByDesc('mother_bag_events.id')
                ->get(['event_type', 'barcode_scanned', 'users.name as actor', 'mother_bag_events.created_at']),
        ] : null;

        return Inertia::render('Hub/MotherBags', [
            'bags' => (clone $query)->latest()->paginate(20)->withQueryString()->through($summary), 'selectedBag' => $detail,
            'hubs' => $hubs->map(fn ($hub) => $hub->only(['id', 'name', 'code', 'tier', 'logistics_company_id', 'parent_hub_id', 'latitude', 'longitude', 'address', 'city_municipality', 'province']) + ['canScan' => $eligibility->canScan($actor, $hub)]),
            'manifests' => LogisticsManifest::whereIn('source_hub_id', $stations->pluck('id'))->where('status', 'draft')
                ->with(['vehicle', 'driver'])->get()->map(fn ($manifest) => $manifest->only(['id', 'reference', 'source_hub_id', 'destination_hub_id'])
                    + ['vehicle_plate' => $manifest->vehicle?->plate_number, 'driver_name' => $manifest->driver?->name]),
            'creationToken' => (string) Str::uuid(), 'basePath' => $request->is('hub/*') ? '/hub/mother-bags' : '/mother-bags',
        ]);
    }

    public function store(Request $request, MotherBagService $service)
    {
        try {
            $bag = $service->create($request->user(), $request->all());
        } catch (DomainException $error) {
            return back()->withErrors(['bag' => $error->getMessage()]);
        }

        return redirect(($request->is('hub/*') ? '/hub/mother-bags' : '/mother-bags').'?bag='.$bag->id)->with('success', 'Mother Bag created. Scan its parcels before sealing.');
    }

    public function command(Request $request, MotherBag $bag, string $action, MotherBagService $service)
    {
        try {
            $service->command($bag, $request->user(), $action, $request->all());
        } catch (DomainException $error) {
            return back()->withErrors(['bag' => $error->getMessage()]);
        }

        return back()->with('success', match ($action) {
            'add' => 'Parcel scanned into the bag.', 'remove' => 'Parcel removed with its reason recorded.',
            'seal' => 'Bag sealed. Its parcel list is locked.', 'reopen' => 'Bag reopened. Use a new seal when ready.',
            'load' => 'Master tag scanned. Every bag member is now on the manifest.',
            'unload' => 'The complete sealed bag was removed from the draft manifest.',
            'open' => 'Seal verified and bag opened. Scan each parcel at receiving.',
        });
    }
}
