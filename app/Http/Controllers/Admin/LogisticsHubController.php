<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogisticsHubController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search');
        $statusFilter = $request->input('status', 'all');

        $query = Delivery::with(['order.items.product', 'order.buyer', 'courier'])->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'ilike', "%{$search}%")
                    ->orWhere('delivery_recipient_name', 'ilike', "%{$search}%")
                    ->orWhere('pickup_store_name', 'ilike', "%{$search}%")
                    ->orWhereHas('order', function ($oq) use ($search) {
                        $oq->where('order_number', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $deliveries = $query->paginate(15)->withQueryString();

        // Platform-wide logistics stats
        $totalDeliveries = Delivery::count();
        $inTransitCount = Delivery::whereIn('status', ['picked_up', 'in_transit', 'out_for_delivery'])->count();
        $unassignedCount = Delivery::whereNull('courier_id')->whereIn('status', ['unassigned', 'assigned'])->count();
        $deliveredCount = Delivery::where('status', 'delivered')->count();

        // Courier Fleet Statistics
        $couriers = User::where('role', 'courier')->get()->map(function ($c) {
            $assignedCount = Delivery::where('courier_id', $c->id)->whereIn('status', ['assigned', 'picked_up', 'in_transit', 'out_for_delivery'])->count();
            $completedCount = Delivery::where('courier_id', $c->id)->where('status', 'delivered')->count();
            return [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone ?? 'N/A',
                'active_jobs' => $assignedCount,
                'completed_jobs' => $completedCount,
                'status' => 'ONLINE',
            ];
        });

        // Shipping revenue split stats
        $totalShippingRevenue = $totalDeliveries * 60;
        $courierPayouts = $deliveredCount * 48; // 80% split
        $hubMaintenanceFee = $deliveredCount * 12; // 20% split

        return Inertia::render('Admin/Logistics', [
            'deliveries' => $deliveries,
            'couriers' => $couriers,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
            ],
            'stats' => [
                'total' => $totalDeliveries,
                'inTransit' => $inTransitCount,
                'unassigned' => $unassignedCount,
                'delivered' => $deliveredCount,
                'totalShippingRevenue' => $totalShippingRevenue,
                'courierPayouts' => $courierPayouts,
                'hubFee' => $hubMaintenanceFee,
                'activeFleetCount' => $couriers->count(),
            ],
        ]);
    }

    public function override(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'delivery_id' => 'required|exists:deliveries,id',
            'courier_id' => 'required|exists:users,id',
            'status' => 'required|in:assigned,picked_up,in_transit,out_for_delivery,delivered',
        ]);

        $delivery = Delivery::findOrFail($validated['delivery_id']);
        $delivery->update([
            'courier_id' => $validated['courier_id'],
            'status' => $validated['status'],
            'assigned_at' => $delivery->assigned_at ?? now(),
        ]);

        return back()->with('success', "Delivery #{$delivery->tracking_number} manually reassigned by supervisor.");
    }
}
