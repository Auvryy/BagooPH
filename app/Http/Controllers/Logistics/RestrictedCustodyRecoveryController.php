<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\CustodyRecoveryGrant;
use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\RestrictionAffectedWork;
use App\Models\User;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RestrictedCustodyRecoveryController extends Controller
{
    public function login()
    {
        return Inertia::render('CustodyRecovery/Login');
    }

    public function signIn(LoginRequest $request, RestrictedCustodyRecoveryService $service)
    {
        $request->authenticate();
        if (! $service->ownGrants($request->user())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'This account has no current owned recovery authorization. Contact the logistics manager.']);
        }
        $request->session()->regenerate();
        Inertia::clearHistory();

        return Inertia::location(redirect()->route('custody-recovery.own'));
    }

    public function own(Request $request, RestrictedCustodyRecoveryService $service)
    {
        abort_unless($request->user()->isCourier(), 403);

        return Inertia::render('CustodyRecovery/Own', ['grants' => $service->ownGrants($request->user()), 'requestToken' => (string) Str::uuid()]);
    }

    public function receiving(Request $request, RestrictedCustodyRecoveryService $service)
    {
        return Inertia::render('CustodyRecovery/Own', ['grants' => $service->receiving($request->user()), 'requestToken' => (string) Str::uuid(), 'receiving' => true]);
    }

    public function review(Request $request, RestrictionAffectedWork $work, RestrictedCustodyRecoveryService $service)
    {
        try {
            $proposal = $service->proposal($request->user(), $work);
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        }

        $parcel = Delivery::findOrFail($work->delivery_id);
        $holder = User::findOrFail($proposal['facts']['courier_id']);

        return Inertia::render('CustodyRecovery/Review', ['workId' => $work->id, 'proposal' => $proposal, 'requestToken' => (string) Str::uuid(),
            'parcel' => ['tracking_number' => $parcel->tracking_number, 'order_number' => $parcel->order->order_number],
            'holderName' => $holder->name, 'receivingHub' => LogisticsHub::findOrFail($proposal['facts']['hub_id'])->only(['name', 'address']),
            'latestGrant' => CustodyRecoveryGrant::where('restriction_affected_work_id', $work->id)->latest('id')->first()?->only(['reference', 'expires_at'])]);
    }

    public function grant(Request $request, RestrictionAffectedWork $work, RestrictedCustodyRecoveryService $service)
    {
        return $this->result($request, fn () => $service->grant($request->user(), $work, $request->all()));
    }

    public function acknowledge(Request $request, CustodyRecoveryGrant $grant, RestrictedCustodyRecoveryService $service)
    {
        return $this->result($request, fn () => $service->acknowledge($request->user(), $grant, $request->all()));
    }

    public function receive(Request $request, CustodyRecoveryGrant $grant, RestrictedCustodyRecoveryService $service)
    {
        return $this->result($request, fn () => $service->receive($request->user(), $grant, $request->all()));
    }

    private function result(Request $request, callable $command)
    {
        try {
            $result = $command();
        } catch (DomainException $error) {
            return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], 409) : back()->withErrors(['recovery' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['reference' => $result->reference]) : back()->with('success', 'Owned recovery evidence recorded.');
    }
}
