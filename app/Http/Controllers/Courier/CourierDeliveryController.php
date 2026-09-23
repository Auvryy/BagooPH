<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Message;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CourierDeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $profile = $user->courierProfile;
        $isOnline = session('courier_duty_status', true);

        // My active and recent deliveries
        $myDeliveries = Delivery::where(function ($query) use ($user) {
            $query->where('courier_id', $user->id)
                ->orWhere('assigned_rider_id', $user->id);
        })
            ->with(['order.items.product', 'order.buyer'])
            ->latest()
            ->get();

        // Unassigned deliveries available for broadcast (FCFS)
        $availableJobs = Delivery::whereNull('courier_id')
            ->where('status', 'unassigned')
            ->when($profile, fn ($query) => $query
                ->where('logistics_company_id', $profile->logistics_company_id)
                ->where('origin_bayan_hub_id', $profile->assigned_hub_id))
            ->when(! $profile, fn ($query) => $query->whereRaw('1 = 0'))
            ->whereHas('order', fn ($query) => $query->where('status', OrderStateMachineService::STATUS_READY_FOR_PICKUP))
            ->with(['order.items.product', 'order.buyer'])
            ->latest()
            ->get();

        $completedDeliveries = Delivery::where('assigned_rider_id', $user->id)
            ->where('status', 'delivered')
            ->get();

        $activeDeliveries = Delivery::where(function ($query) use ($user) {
            $query->where('courier_id', $user->id)
                ->orWhere('assigned_rider_id', $user->id);
        })
            ->whereIn('status', ['assigned', 'assigned_pickup', 'picked_up', 'assigned_to_rider', 'out_for_delivery'])
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
        $rider = $request->user()->load('courierProfile');
        if ($rider->status !== 'active' || $rider->kyc_status !== 'approved' || ! $rider->courierProfile?->is_available) {
            return back()->with('error', 'Only active, approved, and available riders may claim pickup jobs.');
        }

        $claimed = DB::transaction(function () use ($delivery, $rider) {
            $lockedDelivery = Delivery::with('order')->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($lockedDelivery->courier_id !== null || $lockedDelivery->status !== 'unassigned') {
                return false;
            }

            if ($lockedDelivery->order?->status !== OrderStateMachineService::STATUS_READY_FOR_PICKUP) {
                return false;
            }

            $profile = $rider->courierProfile;
            if (
                ! $profile
                || $profile->logistics_company_id !== $lockedDelivery->logistics_company_id
                || $profile->assigned_hub_id !== $lockedDelivery->origin_bayan_hub_id
            ) {
                return false;
            }

            $lockedDelivery->update([
                'courier_id' => $rider->id,
                'status' => 'assigned_pickup',
                'assigned_at' => now(),
            ]);

            DeliveryCheckpoint::record(
                delivery: $lockedDelivery,
                type: 'assigned_pickup',
                location: $lockedDelivery->pickup_store_name ?? 'Merchant Store',
                notes: "Pickup job claimed by {$rider->name}",
                actor: $rider
            );

            return true;
        });

        if (! $claimed) {
            return back()->with('error', 'This pickup job is unavailable or has already been claimed.');
        }

        return back()->with('success', "Delivery task #{$delivery->tracking_number} claimed! Proceed to store for pickup.");
    }

    public function updateStatus(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:picked_up,in_transit,out_for_delivery,delivered,failed',
            'courier_notes' => 'nullable|string|max:500',
            'proof_image_file' => 'nullable|image|max:5120',
        ]);
        $rider = $request->user();
        $delivery->load('order.items.product.shop');
        $requestedStatus = $validated['status'];

        if ($requestedStatus === 'in_transit') {
            return back()->with('error', 'Hub arrival must be recorded by an authorized hub waybill scan.');
        }

        if ($requestedStatus === 'picked_up') {
            if ($delivery->courier_id !== $rider->id || ! in_array($delivery->status, ['assigned', 'assigned_pickup'], true)) {
                return back()->with('error', 'Only the assigned pickup rider may collect this ready parcel.');
            }
            $targetStatus = OrderStateMachineService::STATUS_PICKED_UP;
        } elseif ($requestedStatus === 'out_for_delivery') {
            if ($delivery->assigned_rider_id !== $rider->id || $delivery->status !== OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER) {
                return back()->with('error', 'Only the assigned final-mile rider may dispatch this parcel from the hub.');
            }
            $targetStatus = OrderStateMachineService::STATUS_OUT_FOR_DELIVERY;
        } elseif (in_array($requestedStatus, ['delivered', 'failed'], true)) {
            if ($delivery->assigned_rider_id !== $rider->id || $delivery->status !== OrderStateMachineService::STATUS_OUT_FOR_DELIVERY) {
                return back()->with('error', 'Only the assigned final-mile rider may submit the delivery outcome.');
            }
            $targetStatus = $requestedStatus === 'delivered'
                ? OrderStateMachineService::STATUS_DELIVERED
                : OrderStateMachineService::STATUS_DELIVERY_FAILED;

            if (
                $targetStatus === OrderStateMachineService::STATUS_DELIVERED
                && ! $request->hasFile('proof_image_file')
            ) {
                return back()->with('error', 'Proof of delivery is required before completing the handover.');
            }
            if ($targetStatus === OrderStateMachineService::STATUS_DELIVERY_FAILED && empty($validated['courier_notes'])) {
                return back()->with('error', 'A delivery failure reason is required.');
            }
        } else {
            return back()->with('error', 'Unsupported delivery transition.');
        }

        $proofImage = null;
        if ($request->hasFile('proof_image_file')) {
            $proofImage = '/storage/'.$request->file('proof_image_file')->store('delivery-proofs', 'public');
        }

        try {
            $updatedDelivery = app(OrderStateMachineService::class)->transition(
                delivery: $delivery,
                targetStatus: $targetStatus,
                actor: $rider,
                scanMetadata: [
                    'rider_id' => $rider->id,
                    'location_name' => $targetStatus === OrderStateMachineService::STATUS_PICKED_UP
                        ? ($delivery->pickup_store_name ?? 'Merchant Store')
                        : ($delivery->delivery_address ?? 'Buyer Destination'),
                    'notes' => $validated['courier_notes'] ?? null,
                    'reason' => $targetStatus === OrderStateMachineService::STATUS_DELIVERY_FAILED
                        ? $validated['courier_notes']
                        : null,
                    'proof_image' => $proofImage,
                ]
            );
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $updatedDelivery->update([
            'courier_notes' => $validated['courier_notes'] ?? $updatedDelivery->courier_notes,
        ]);

        if ($targetStatus === OrderStateMachineService::STATUS_PICKED_UP) {
            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $delivery->id, 'checkpoint_type' => 'courier_pickup'],
                [
                    'location_name' => $delivery->pickup_store_name ?? 'Merchant Store',
                    'barcode_scanned' => $delivery->tracking_number,
                    'notes' => $validated['courier_notes'] ?? 'Pickup rider scanned and collected the seller parcel',
                    'scanned_by_id' => $rider->id,
                ]
            );
        }

        return back()->with('success', "Delivery status updated to {$targetStatus}.");
    }

    public function earnings(Request $request): Response
    {
        $user = $request->user();

        $completed = Delivery::where('assigned_rider_id', $user->id)
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
