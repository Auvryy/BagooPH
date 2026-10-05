<?php

namespace App\Http\Controllers\Governance;

use App\Http\Controllers\Controller;
use App\Services\ResourceRestrictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResourceRestrictionController extends Controller
{
    public function index(Request $request, ResourceRestrictionService $restrictions): Response
    {
        $types = $restrictions->types($request->user());
        $filters = $request->validate(['type' => ['sometimes', Rule::in($types)], 'search' => 'nullable|string|max:100']);
        $type = $filters['type'] ?? $types[0];
        $query = $restrictions->query($request->user(), $type);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($q) use ($type, $search) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                if ($type === 'handler') {
                    $q->whereHas('user', fn ($user) => $user->where('name', 'like', '%'.$escaped.'%'));
                } else {
                    $q->where($type === 'fleet' ? 'plate_number' : 'name', 'like', '%'.$escaped.'%');
                }
            });
        }
        $resources = $query->orderBy('id')->paginate(15)->withQueryString()
            ->through(fn ($resource) => $restrictions->summary($type, $resource));

        return Inertia::render('Governance/Resources', ['resources' => $resources, 'types' => $types,
            'filters' => ['type' => $type, 'search' => $search], 'baseUrl' => $request->getPathInfo()]);
    }

    public function show(Request $request, string $type, int $resource, ResourceRestrictionService $restrictions): Response
    {
        return Inertia::render('Governance/ResourceRestriction', [
            'subject' => $restrictions->presentation($request->user(), $type, $resource),
            'endpoint' => $request->getPathInfo(), 'backUrl' => dirname(dirname($request->getPathInfo())).'?type='.$type,
        ]);
    }

    public function store(Request $request, string $type, int $resource, ResourceRestrictionService $restrictions): JsonResponse|RedirectResponse
    {
        $decision = $restrictions->decide($request->user(), $type, $resource, $request->all());

        return $request->wantsJson()
            ? response()->json(['decision_id' => $decision->id, 'message' => 'Resource activity decision recorded.'])
            : back()->with('success', 'Resource activity decision recorded. Parent approval and existing work remain separate.');
    }
}
