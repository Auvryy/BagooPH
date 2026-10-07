<?php

namespace App\Http\Controllers;

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
    public function index(Request $request, NotificationCenterService $service): Response|HttpResponse
    {
        $user = $service->current($request->user());
        $filter = $request->validate(['filter' => ['sometimes', 'in:all,unread']])['filter'] ?? 'all';
        try {
            $notices = $user->notifications()->whereIn('type', NotificationCenterService::TYPES)
                ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(fn ($notice) => $service->present($notice, $user));

            return Inertia::render('Notifications/Index', ['notices' => $notices, 'available' => true, 'filter' => $filter,
                'accountUrl' => $user->canAccessPortal() ? '/dashboard' : '/pending-approval']);
        } catch (QueryException) {
            return Inertia::render('Notifications/Index', ['notices' => null, 'available' => false, 'filter' => $filter,
                'accountUrl' => $user->canAccessPortal() ? '/dashboard' : '/pending-approval'])
                ->toResponse($request)->setStatusCode(503);
        }
    }

    public function read(Request $request, string $notification, NotificationCenterService $service): JsonResponse|RedirectResponse
    {
        $user = $service->current($request->user());
        try {
            $notice = $service->acknowledge($user, $notification);
        } catch (QueryException) {
            abort(503, 'Notifications are temporarily unavailable.');
        }

        return $request->expectsJson() ? response()->json(['read_at' => $notice->read_at->toIso8601String()]) : back();
    }
}
