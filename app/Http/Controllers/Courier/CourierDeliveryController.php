<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourierDeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // My assigned deliveries
        $myDeliveries = Delivery::where('courier_id', $user->id)
            ->with(['order.items.product'])
            ->latest()
            ->get();

        // Unassigned deliveries ready for pickup
        $availableJobs = Delivery::whereNull('courier_id')
            ->whereIn('status', ['unassigned', 'assigned'])
            ->with(['order.items.product'])
            ->latest()
            ->get();

        $completedCount = Delivery::where('courier_id', $user->id)
            ->where('status', 'delivered')
            ->count();

        $activeCount = Delivery::where('courier_id', $user->id)
            ->whereIn('status', ['assigned', 'picked_up', 'in_transit', 'out_for_delivery'])
            ->count();

        return Inertia::render('Courier/Deliveries', [
            'myDeliveries' => $myDeliveries,
            'availableJobs' => $availableJobs,
            'stats' => [
                'active' => $activeCount,
                'completed' => $completedCount,
                'available' => $availableJobs->count(),
            ],
        ]);
    }

    public function claim(Request $request, Delivery $delivery): RedirectResponse
    {
        if ($delivery->courier_id !== null) {
            return back()->with('error', 'This delivery has already been assigned to another courier.');
        }

        $delivery->update([
            'courier_id' => $request->user()->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        return back()->with('success', "Delivery task #{$delivery->tracking_number} accepted!");
    }

    public function updateStatus(Request $request, Delivery $delivery): RedirectResponse
    {
        if ($delivery->courier_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => 'required|in:picked_up,in_transit,out_for_delivery,delivered,failed',
            'courier_notes' => 'nullable|string|max:500',
        ]);

        $updates = [
            'status' => $validated['status'],
            'courier_notes' => $validated['courier_notes'] ?? $delivery->courier_notes,
        ];

        if ($validated['status'] === 'picked_up' && ! $delivery->picked_up_at) {
            $updates['picked_up_at'] = now();
            $delivery->order->update(['status' => 'shipped']);
        }

        if ($validated['status'] === 'delivered' && ! $delivery->delivered_at) {
            $updates['delivered_at'] = now();
            $delivery->order->update([
                'status' => 'delivered',
                'payment_status' => 'paid',
            ]);
        }

        $delivery->update($updates);

        return back()->with('success', "Delivery status updated to {$validated['status']}.");
    }
}
