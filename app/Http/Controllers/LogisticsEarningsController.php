<?php

namespace App\Http\Controllers;

use App\Services\Finance\LogisticsEarningsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LogisticsEarningsController extends Controller
{
    public function index(Request $request, LogisticsEarningsService $service)
    {
        $actor = $request->user();
        if ($actor->isCourier()) {
            return redirect()->route('courier.earnings');
        }

        return Inertia::render('Finance/DeliveryEarnings', [
            'summary' => $service->summary($actor),
            'entries' => $service->withReturns($service->scoped($actor))->with('order')->latest('earned_at')->paginate(20)->through(fn ($entry) => [
                'id' => $entry->id, 'order_number' => $entry->order->order_number, 'tracking_number' => $entry->snapshot['tracking_number'],
                'role' => $entry->role, 'pool' => $entry->pool, 'amount_cents' => $entry->amount_cents, 'net_amount_cents' => $service->net($entry),
                'status' => $entry->reversed_at ? 'Reversed' : ($entry->released_at ? 'Released' : 'Awaiting cash handover'),
                'virtual_only' => $entry->snapshot['virtual_only'], 'source_reference' => $entry->snapshot['source_reference'],
                'earned_at' => $entry->earned_at->toIso8601String(),
            ]),
        ]);
    }
}
