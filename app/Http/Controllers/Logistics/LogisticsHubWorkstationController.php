<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogisticsHubWorkstationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $deliveries = Delivery::with(['order.items.product', 'order.buyer', 'courier', 'checkpoints'])
            ->latest()
            ->paginate(20);

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

        $totalDeliveries = Delivery::count();
        $inTransitCount = Delivery::whereIn('status', ['picked_up', 'in_transit', 'out_for_delivery'])->count();
        $unassignedCount = Delivery::whereNull('courier_id')->whereIn('status', ['unassigned', 'assigned'])->count();
        $deliveredCount = Delivery::where('status', 'delivered')->count();

        return Inertia::render('Admin/Logistics', [
            'deliveries' => $deliveries,
            'couriers' => $couriers,
            'filters' => [
                'search' => '',
                'status' => 'all',
            ],
            'stats' => [
                'total' => $totalDeliveries,
                'inTransit' => $inTransitCount,
                'unassigned' => $unassignedCount,
                'delivered' => $deliveredCount,
                'totalShippingRevenue' => $totalDeliveries * 60,
                'courierPayouts' => $deliveredCount * 48,
                'hubFee' => $deliveredCount * 12,
                'activeFleetCount' => $couriers->count(),
            ],
        ]);
    }

    public function scanIntake(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'barcode' => 'required|string',
            'location_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $barcode = trim($validated['barcode']);
        $delivery = Delivery::where('tracking_number', $barcode)
            ->when(is_numeric($barcode), fn ($query) => $query->orWhere('id', (int) $barcode))
            ->first();

        if (! $delivery) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Parcel not found with tracking code ' . $barcode], 404);
            }
            return back()->with('error', 'Parcel not found with tracking code ' . $barcode);
        }

        $checkpoint = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'hub_intake',
            'location_name' => $validated['location_name'] ?? 'Metro Manila Central Sorting Station',
            'barcode_scanned' => $delivery->tracking_number,
            'notes' => $validated['notes'] ?? 'Scanned at Central Logistics Hub intake',
            'scanned_by_id' => $request->user()?->id,
        ]);

        if (in_array($delivery->status, ['assigned', 'picked_up'])) {
            $delivery->update(['status' => 'in_transit']);
            if ($delivery->order && $delivery->order->status !== 'delivered') {
                $delivery->order->update(['status' => 'shipped']);
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Parcel #{$delivery->tracking_number} received at sorting hub.",
                'checkpoint' => $checkpoint,
                'delivery' => $delivery->fresh(),
            ]);
        }

        return back()->with('success', "Parcel #{$delivery->tracking_number} received at sorting hub.");
    }

    public function sortBarangay(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'delivery_id' => 'required|exists:deliveries,id',
            'barangay' => 'nullable|string',
            'bin' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $delivery = Delivery::findOrFail($validated['delivery_id']);
        $barangay = $validated['barangay'] ?? 'Barangay San Antonio';
        $bin = $validated['bin'] ?? 'BIN-A1';

        $checkpoint = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'barangay_sort',
            'location_name' => "Hub Sorting Bay ({$barangay} / {$bin})",
            'barcode_scanned' => $delivery->tracking_number,
            'notes' => $validated['notes'] ?? "Sorted for dispatch to {$barangay} ({$bin})",
            'scanned_by_id' => $request->user()?->id,
        ]);

        $delivery->update(['status' => 'out_for_delivery']);
        if ($delivery->order && $delivery->order->status !== 'delivered') {
            $delivery->order->update(['status' => 'shipped']);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Parcel #{$delivery->tracking_number} sorted for {$barangay}.",
                'checkpoint' => $checkpoint,
                'delivery' => $delivery->fresh(),
            ]);
        }

        return back()->with('success', "Parcel #{$delivery->tracking_number} sorted for {$barangay}.");
    }
}
