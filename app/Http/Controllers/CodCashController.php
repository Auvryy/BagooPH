<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\CodAccount;
use App\Models\Delivery;
use App\Services\Finance\CodCashService;
use App\Services\Finance\CodCashViewService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CodCashController extends Controller
{
    private function base(Request $request): string
    {
        $uri = $request->route()->uri();
        if (preg_match('~\A(?:(admin|hub|courier)/)?cod(?:/|$)~', $uri, $match)) {
            return '/'.(empty($match[1]) ? '' : $match[1].'/').'cod';
        }

        return '/cash-handover';
    }

    public function index(Request $request, CodCashViewService $views)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $data = $views->page($request->user(), $filters) + ['base' => $this->base($request), 'requestToken' => (string) Str::uuid()];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store') : Inertia::render('Finance/Cod', $data);
    }

    public function show(Request $request, CodAccount $account, CodCashService $cash, CodCashViewService $views)
    {
        $actor = $cash->current($request->user());
        $account = $cash->account($actor, $account->id);
        $data = ['account' => $views->present($account, $actor), 'mode' => $views->mode($actor), 'can_reconcile' => $actor->isAdmin(),
            'isOnline' => (bool) $actor->courierProfile?->is_available,
            'base' => $this->base($request), 'requestToken' => (string) Str::uuid()];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store') : Inertia::render('Finance/CodDetail', $data);
    }

    public function command(Request $request, CodAccount $account, string $action, CodCashService $cash)
    {
        return $this->result($request, fn () => $cash->command($request->user(), $account, $action, $request->all()));
    }

    public function legacy(Request $request, Delivery $delivery, CodCashService $cash)
    {
        return $this->result($request, fn () => $cash->reviewLegacy($request->user(), $delivery, $request->all()));
    }

    private function result(Request $request, callable $command)
    {
        try {
            $event = $command();
        } catch (DomainException $error) {
            return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], 409) : back()->withErrors(['cash' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['reference' => $event->reference, 'account_id' => $event->cod_account_id])
            ->header('Cache-Control', 'private, no-store') : back()->with('success', 'The cash record was saved. Its original history is retained.');
    }

    public function login()
    {
        return Inertia::render('Finance/CashLogin');
    }

    public function signIn(LoginRequest $request, CodCashService $cash)
    {
        $request->authenticate();
        $actor = $request->user();
        if ((! $actor->isCourier() && ! $actor->isLogistics()) || $actor->closed_at !== null || $actor->email_verified_at === null
            || ! $cash->scoped($actor)->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'This account has no recorded cash responsibility. Contact the logistics manager.']);
        }
        $request->session()->regenerate();
        Inertia::clearHistory();

        return Inertia::location(redirect()->route('cash.index'));
    }
}
