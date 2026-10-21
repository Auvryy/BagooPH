<?php

namespace App\Http\Controllers;

use App\Models\AccountSessionAction;
use App\Services\AccountSessions\AccountSessionActions;
use App\Services\AccountSessions\AccountSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AccountSessionController extends Controller
{
    public function index(Request $request, AccountSessionService $sessions): Response
    {
        $data = $sessions->page($request);
        $host = $request->getHost();
        Inertia::encryptHistory($request->isSecure() || $host === 'localhost' || str_ends_with($host, '.localhost'));
        $response = $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($data)
            : Inertia::render('Account/Security', $data)->toResponse($request);

        return $response->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }

    public function sendCode(Request $request, AccountSessionActions $actions): JsonResponse
    {
        $result = $actions->sendCode($request);
        $status = $result['success'] ? 200 : ($result['status'] ?? 429);
        unset($result['status']);

        return response()->json($result, $status)->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, string $session, AccountSessionActions $actions): JsonResponse
    {
        return $this->result($actions->revoke($request, $session));
    }

    public function others(Request $request, AccountSessionActions $actions): JsonResponse
    {
        return $this->result($actions->revoke($request, null));
    }

    private function result(AccountSessionAction $action): JsonResponse
    {
        $count = $action->affected_count;

        return response()->json(['reference' => $action->id, 'affected_count' => $count,
            'message' => $count > 0 ? ($count === 1 ? 'The other session was signed out.' : $count.' other sessions were signed out.') : 'There are no active sessions left in that selection.'])
            ->header('Cache-Control', 'private, no-store');
    }
}
