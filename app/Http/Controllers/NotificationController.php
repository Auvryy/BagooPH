<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Notifications\NotificationCenterService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class NotificationController extends Controller
{
    public function preview(Request $request, NotificationCenterService $service): JsonResponse
    {
        $user = $service->current($request->user());
        try {
            $notices = $user->notifications()->whereIn('type', NotificationCenterService::TYPES)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get()
                ->map(fn ($notice) => $service->present($notice, $user));

            return response()->json(['notices' => $notices, 'summary' => $service->summary($user)])
                ->header('Cache-Control', 'no-store, private');
        } catch (QueryException) {
            abort(503, 'Notifications are temporarily unavailable.');
        }
    }

    public function readDisplayed(Request $request, NotificationCenterService $service): JsonResponse
    {
        $user = $service->current($request->user());
        $data = $request->validate(['ids' => ['required', 'array', 'list', 'min:1', 'max:6'], 'ids.*' => ['required', 'uuid', 'distinct']]);
        try {
            $service->acknowledgeDisplayed($user, $data['ids']);

            return response()->json(['summary' => $service->summary($user)])->header('Cache-Control', 'no-store, private');
        } catch (QueryException) {
            abort(503, 'Notifications are temporarily unavailable.');
        }
    }

    public function index(Request $request, NotificationCenterService $service): Response|HttpResponse
    {
        $user = $service->current($request->user());
        $filter = $request->validate(['filter' => ['sometimes', 'in:all,unread']])['filter'] ?? 'all';
        $context = $this->portalContext($user);
        try {
            $notices = $user->notifications()->whereIn('type', NotificationCenterService::TYPES)
                ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(fn ($notice) => $service->present($notice, $user));

            if ($notices->currentPage() > $notices->lastPage()) {
                return redirect()->to(route('notifications.index', ['filter' => $filter, 'page' => $notices->lastPage()], absolute: false));
            }

            return Inertia::render('Notifications/Index', [...$context, 'notices' => $notices, 'available' => true, 'filter' => $filter]);
        } catch (QueryException) {
            return Inertia::render('Notifications/Index', [...$context, 'notices' => null, 'available' => false, 'filter' => $filter])
                ->toResponse($request)->setStatusCode(503);
        }
    }

    private function portalContext(User $user): array
    {
        $portal = $user->isBuyer() ? 'buyer' : ($user->canAccessPortal() ? ($user->isCourier() ? 'courier' : 'dashboard') : 'account');
        $profile = $portal === 'courier' ? $user->courierProfile?->loadMissing(['company', 'hub']) : null;
        $hub = $profile?->hub;
        if ($hub?->logistics_company_id !== $profile?->logistics_company_id) {
            $hub = null;
        }

        return [
            'portal' => $portal,
            'accountUrl' => $user->canAccessPortal() ? '/dashboard' : '/pending-approval',
            'isOnline' => (bool) $profile?->is_available,
            'scope' => $profile ? [
                'company' => $profile->company?->name,
                'hub' => $hub?->name,
                'hubCode' => $hub?->code,
                'isAssigned' => (bool) ($profile->logistics_company_id && $profile->assigned_hub_id),
                'isOperational' => app(LogisticsEligibilityService::class)->isOperational($profile),
            ] : null,
        ];
    }

    public function read(Request $request, string $notification, NotificationCenterService $service): JsonResponse|RedirectResponse
    {
        $request->validate(['open' => ['sometimes', 'boolean']]);
        $user = $service->current($request->user());
        try {
            $notice = $service->acknowledge($user, $notification);
        } catch (QueryException) {
            abort(503, 'Notifications are temporarily unavailable.');
        }

        if ($request->boolean('open')) {
            $presented = $service->present($notice, $user);

            return redirect($presented['href'] ?? route('notifications.index', absolute: false))
                ->with('success', trim(($presented['data']['title'] ?? 'Account update').'. '.($presented['data']['body'] ?? '')));
        }

        return $request->expectsJson() ? response()->json(['read_at' => $notice->read_at->toIso8601String()]) : back();
    }
}
