<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourierDeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isOnline = session('courier_duty_status', true);

        // My active and recent deliveries
        $myDeliveries = Delivery::where('courier_id', $user->id)
            ->with(['order.items.product', 'order.buyer'])
            ->latest()
            ->get();

        // Unassigned deliveries available for broadcast (FCFS)
        $availableJobs = Delivery::whereNull('courier_id')
            ->whereIn('status', ['unassigned', 'assigned'])
            ->with(['order.items.product', 'order.buyer'])
            ->latest()
            ->get();

        $completedDeliveries = Delivery::where('courier_id', $user->id)
            ->where('status', 'delivered')
            ->get();

        $activeDeliveries = Delivery::where('courier_id', $user->id)
            ->whereIn('status', ['assigned', 'picked_up', 'in_transit', 'out_for_delivery'])
            ->get();

        // Total COD cash collected on-hand
        $codCollected = $completedDeliveries->where('order.payment_method', 'cod')->sum(function ($d) {
            return $d->order ? (float) $d->order->total_amount : 0;
        });

        // Rider delivery payouts earned (₱60 avg per parcel)
        $totalEarned = $completedDeliveries->count() * 60;

        return Inertia::render('Courier/Deliveries', [
            'myDeliveries' => $myDeliveries,
            'availableJobs' => $availableJobs,
            'isOnline' => $isOnline,
            'stats' => [
                'active' => $activeDeliveries->count(),
                'completed' => $completedDeliveries->count(),
                'available' => $availableJobs->count(),
                'todayEarnings' => $totalEarned,
                'codOnHand' => $codCollected,
            ],
        ]);
    }

    public function claim(Request $request, Delivery $delivery): RedirectResponse
    {
        if ($delivery->courier_id !== null) {
            return back()->with('error', 'This delivery has already been claimed by another rider.');
        }

        $delivery->update([
            'courier_id' => $request->user()->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        return back()->with('success', "Delivery task #{$delivery->tracking_number} claimed! Proceed to store for pickup.");
    }

    public function updateStatus(Request $request, Delivery $delivery): RedirectResponse
    {
        if ($delivery->courier_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => 'required|in:picked_up,in_transit,out_for_delivery,delivered,failed',
            'courier_notes' => 'nullable|string|max:500',
            'proof_image' => 'nullable|string',
        ]);

        $updates = [
            'status' => $validated['status'],
            'courier_notes' => $validated['courier_notes'] ?? $delivery->courier_notes,
        ];

        if (! empty($validated['proof_image'])) {
            $updates['proof_image'] = $validated['proof_image'];
        }

        if ($validated['status'] === 'picked_up' && ! $delivery->picked_up_at) {
            $updates['picked_up_at'] = now();
            if ($delivery->order) {
                $delivery->order->update(['status' => 'shipped']);
            }
        }

        if ($validated['status'] === 'delivered' && ! $delivery->delivered_at) {
            $updates['delivered_at'] = now();
            if (! isset($updates['proof_image']) || empty($updates['proof_image'])) {
                $updates['proof_image'] = 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500&auto=format&fit=crop&q=60';
            }
            if ($delivery->order) {
                $delivery->order->update([
                    'status' => 'delivered',
                    'payment_status' => 'paid',
                ]);
            }
        }

        $delivery->update($updates);

        return back()->with('success', "Delivery status updated to {$validated['status']}.");
    }

    public function earnings(Request $request): Response
    {
        $user = $request->user();

        $completed = Delivery::where('courier_id', $user->id)
            ->where('status', 'delivered')
            ->with(['order.items.product', 'order.buyer'])
            ->latest('delivered_at')
            ->get();

        $totalCompleted = $completed->count();
        $totalEarnings = $totalCompleted * 60; // ₱60 per delivered parcel
        $codCollected = $completed->sum(function ($d) {
            return ($d->order && $d->order->payment_method === 'cod') ? (float) $d->order->total_amount : 0;
        });

        $trips = $completed->map(function ($d) {
            return [
                'id' => $d->id,
                'tracking_number' => $d->tracking_number,
                'order_number' => $d->order ? $d->order->order_number : 'N/A',
                'store_name' => $d->pickup_store_name ?? 'Bagoo Merchant Hub',
                'delivery_address' => $d->delivery_address,
                'recipient_name' => $d->delivery_recipient_name,
                'delivered_at' => $d->delivered_at ? $d->delivered_at->format('M d, Y h:i A') : 'Completed',
                'payment_method' => $d->order ? strtoupper($d->order->payment_method) : 'COD',
                'cod_amount' => $d->order ? (float) $d->order->total_amount : 0,
                'payout' => 60.00,
            ];
        });

        return Inertia::render('Courier/Earnings', [
            'stats' => [
                'totalCompleted' => $totalCompleted,
                'totalEarnings' => $totalEarnings,
                'codCollected' => $codCollected,
                'remittanceStatus' => 'Good Standing',
                'payoutRate' => '₱60.00 / trip',
            ],
            'trips' => $trips,
        ]);
    }

    public function messages(Request $request): Response
    {
        $user = $request->user();

        $messages = Message::where('sender_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->with(['sender.shop', 'receiver.shop', 'product'])
            ->latest()
            ->get();

        $grouped = $messages->groupBy(function ($msg) use ($user) {
            return $msg->sender_id === $user->id ? $msg->receiver_id : $msg->sender_id;
        });

        $conversations = [];
        foreach ($grouped as $otherUserId => $msgs) {
            $otherUser = User::with('shop')->find($otherUserId);
            if ($otherUser) {
                $lastMsg = $msgs->first();
                $conversations[] = [
                    'user' => $otherUser,
                    'last_message' => $lastMsg->message,
                    'last_time' => $lastMsg->created_at->diffForHumans(),
                    'unread_count' => $msgs->where('receiver_id', $user->id)->where('is_read', false)->count(),
                    'messages' => $msgs->sortBy('created_at')->values(),
                ];
            }
        }

        return Inertia::render('Courier/Messages', [
            'conversations' => $conversations,
        ]);
    }

    public function profile(Request $request): Response
    {
        $user = $request->user();
        $isOnline = session('courier_duty_status', true);

        $completedCount = Delivery::where('courier_id', $user->id)->where('status', 'delivered')->count();

        return Inertia::render('Courier/Profile', [
            'user' => $user,
            'isOnline' => $isOnline,
            'fleetData' => [
                'vehicle_type' => 'Motorcycle (Express Dispatch)',
                'plate_number' => 'NCS-8892',
                'license_number' => 'N02-18-092831',
                'license_status' => 'Verified (Class A/A1/B)',
                'or_cr_status' => 'Valid & Registered',
                'zone' => 'Metro Manila & Rizal Corridor',
                'completed_deliveries' => $completedCount,
                'rating' => 4.95,
            ],
        ]);
    }

    public function toggleDuty(Request $request): RedirectResponse
    {
        $current = session('courier_duty_status', true);
        session(['courier_duty_status' => ! $current]);

        $statusText = ! $current ? 'ONLINE & READY FOR JOBS' : 'OFF-DUTY';

        return back()->with('success', "Courier duty status changed to: {$statusText}");
    }
}
