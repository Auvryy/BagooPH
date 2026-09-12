<?php

namespace App\Http\Controllers;

use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicTrackingController extends Controller
{
    /**
     * Display public tracking details for a delivery parcel.
     */
    public function show(Request $request, ?string $tracking_number = null): Response|JsonResponse
    {
        $trackingCode = trim((string) ($tracking_number ?: $request->query('number', $request->query('tracking_number', $request->query('q', '')))));

        if (empty($trackingCode)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A valid tracking number is required.',
                ], 400);
            }

            return Inertia::render('Public/Tracking', [
                'parcel' => null,
                'searchedNumber' => '',
                'notFound' => false,
                'availableActions' => [],
            ]);
        }

        $delivery = Delivery::with([
            'order.items.product',
            'order.buyer',
            'checkpoints' => fn ($query) => $query->orderBy('created_at', 'asc'),
            'courier',
        ])
        ->where('tracking_number', $trackingCode)
        ->orWhereHas('order', fn ($q) => $q->where('order_number', $trackingCode))
        ->first();

        if (! $delivery) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => "No parcel found matching tracking number {$trackingCode}.",
                    'searched_number' => $trackingCode,
                ], 404);
            }

            return Inertia::render('Public/Tracking', [
                'parcel' => null,
                'searchedNumber' => $trackingCode,
                'notFound' => true,
                'availableActions' => [],
            ]);
        }

        $user = $request->user();
        $isCourier = $user && $user->isCourier();
        $isHub = $user && ($user->isLogistics() || $user->isAdmin());
        $isBuyer = $user && $delivery->order && $delivery->order->buyer_id === $user->id;
        $isSeller = $user && $delivery->order && $delivery->order->items->contains(function ($item) use ($user) {
            return $item->product && $item->product->shop && $item->product->shop->user_id === $user->id;
        });

        $availableActions = $this->determineAvailableActions($delivery, $user);

        // Mask recipient data for guest/public privacy protection
        $recipientName = $delivery->delivery_recipient_name ?: ($delivery->order?->recipient_name ?: 'Customer');
        $recipientPhone = $delivery->delivery_phone ?: ($delivery->order?->recipient_phone ?: '');
        $deliveryAddress = $delivery->delivery_address ?: ($delivery->order?->shipping_address ?: '');

        $maskedRecipientName = ($isCourier || $isHub || $isBuyer || $isSeller)
            ? $recipientName
            : $this->maskName($recipientName);

        $maskedRecipientPhone = ($isCourier || $isHub || $isBuyer || $isSeller)
            ? $recipientPhone
            : $this->maskPhone($recipientPhone);

        $maskedAddress = ($isCourier || $isHub || $isBuyer || $isSeller)
            ? $deliveryAddress
            : $this->maskAddress($deliveryAddress);

        $parcelData = [
            'id' => $delivery->id,
            'tracking_number' => $delivery->tracking_number,
            'status' => $delivery->status,
            'order_number' => $delivery->order?->order_number,
            'order_status' => $delivery->order?->status,
            'payment_method' => $delivery->order?->payment_method ? strtoupper($delivery->order->payment_method) : 'COD',
            'payment_status' => $delivery->order?->payment_status ?: 'unpaid',
            'total_amount' => $delivery->order ? (float) $delivery->order->total_amount : 0.0,
            'pickup_store_name' => $delivery->pickup_store_name ?: 'Bagoo Merchant Hub',
            'pickup_address' => ($isCourier || $isHub || $isSeller) ? $delivery->pickup_address : 'Artisan Fulfillment Hub, Metro Manila',
            'delivery_recipient_name' => $maskedRecipientName,
            'delivery_phone' => $maskedRecipientPhone,
            'delivery_address' => $maskedAddress,
            'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString() ?: now()->addDays(2)->toISOString(),
            'assigned_at' => $delivery->assigned_at?->toISOString(),
            'picked_up_at' => $delivery->picked_up_at?->toISOString(),
            'delivered_at' => $delivery->delivered_at?->toISOString(),
            'courier_name' => $delivery->courier?->name ?: 'Bagoo Express Fleet',
            'courier_vehicle' => 'Motorcycle Courier',
            'checkpoints' => $delivery->checkpoints->map(fn ($cp) => [
                'id' => $cp->id,
                'checkpoint_type' => $cp->checkpoint_type,
                'location_name' => $cp->location_name,
                'notes' => $cp->notes,
                'created_at' => $cp->created_at->toISOString(),
            ]),
            'items' => $delivery->order ? $delivery->order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product?->name ?: 'Product Item',
                'featured_image' => $item->product?->featured_image ?: '',
                'quantity' => $item->quantity,
                'color' => $item->color,
                'size' => $item->size,
                'unit_price' => ($isBuyer || $isSeller || $isHub) ? (float) $item->unit_price : null,
            ]) : [],
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'parcel' => $parcelData,
                'available_actions' => $availableActions,
            ]);
        }

        return Inertia::render('Public/Tracking', [
            'parcel' => $parcelData,
            'searchedNumber' => $trackingCode,
            'notFound' => false,
            'availableActions' => $availableActions,
        ]);
    }

    /**
     * Dedicated JSON API endpoint for Flutter mobile apps.
     */
    public function apiTrack(Request $request, string $tracking_number): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');
        return $this->show($request, $tracking_number);
    }

    /**
     * Execute role-based operational transitions directly from the tracking page.
     */
    public function executeAction(Request $request, string $tracking_number): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Please sign in to perform staff operations.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:courier_claim,courier_pickup,out_for_delivery,delivered,hub_intake,hub_sorted,buyer_confirm',
            'notes' => 'nullable|string|max:500',
            'location_name' => 'nullable|string|max:255',
            'proof_image' => 'nullable|string',
        ]);

        $delivery = Delivery::where('tracking_number', $tracking_number)->firstOrFail();
        $action = $validated['action'];

        switch ($action) {
            case 'courier_claim':
                if (! $user->isCourier() && ! $user->isAdmin()) {
                    abort(403, 'Only couriers can claim parcel pickups.');
                }
                $delivery->update([
                    'courier_id' => $user->id,
                    'status' => 'assigned',
                    'assigned_at' => now(),
                ]);
                DeliveryCheckpoint::record($delivery, 'courier_assigned', $delivery->pickup_store_name, 'Courier claimed pickup task', $user);
                break;

            case 'courier_pickup':
                if (! $user->isCourier() && ! $user->isAdmin()) {
                    abort(403, 'Only couriers can log parcel pickup.');
                }
                if (! $delivery->courier_id) {
                    $delivery->courier_id = $user->id;
                }
                $delivery->update([
                    'status' => 'picked_up',
                    'picked_up_at' => now(),
                ]);
                if ($delivery->order && $delivery->order->status !== 'delivered') {
                    $delivery->order->update(['status' => 'shipped']);
                }
                DeliveryCheckpoint::record(
                    $delivery,
                    'courier_pickup',
                    $validated['location_name'] ?? ($delivery->pickup_store_name ?? 'Merchant Store'),
                    $validated['notes'] ?? 'Courier scanned QR code and picked up parcel from store',
                    $user
                );
                break;

            case 'hub_intake':
                if (! $user->isLogistics() && ! $user->isAdmin()) {
                    abort(403, 'Only logistics hub operators can perform intake scans.');
                }
                $delivery->update(['status' => 'in_transit']);
                if ($delivery->order && ! in_array($delivery->order->status, ['shipped', 'delivered', 'completed'])) {
                    $delivery->order->update(['status' => 'shipped']);
                }
                DeliveryCheckpoint::record(
                    $delivery,
                    'hub_intake',
                    $validated['location_name'] ?? 'Metro Manila Central Sorting Station',
                    $validated['notes'] ?? 'Hub operator scanned QR code for warehouse intake',
                    $user
                );
                break;

            case 'hub_sorted':
                if (! $user->isLogistics() && ! $user->isAdmin()) {
                    abort(403, 'Only logistics hub operators can sort parcels.');
                }
                $delivery->update(['status' => 'in_transit']);
                DeliveryCheckpoint::record(
                    $delivery,
                    'hub_sorted',
                    $validated['location_name'] ?? 'Sorting Bay Alpha',
                    $validated['notes'] ?? 'Parcel scanned and sorted for local destination dispatch',
                    $user
                );
                break;

            case 'out_for_delivery':
                if (! $user->isCourier() && ! $user->isAdmin()) {
                    abort(403, 'Only couriers can initiate doorstep delivery.');
                }
                $delivery->update(['status' => 'out_for_delivery']);
                DeliveryCheckpoint::record(
                    $delivery,
                    'out_for_delivery',
                    $validated['location_name'] ?? 'Local Destination Station',
                    $validated['notes'] ?? 'Rider scanned QR code and departed for doorstep delivery',
                    $user
                );
                break;

            case 'delivered':
                if (! $user->isCourier() && ! $user->isAdmin()) {
                    abort(403, 'Only couriers can confirm delivery handover.');
                }
                $delivery->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'proof_image' => $validated['proof_image'] ?? $delivery->proof_image ?? 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500&auto=format&fit=crop&q=60',
                    'courier_notes' => $validated['notes'] ?? $delivery->courier_notes,
                ]);
                if ($delivery->order) {
                    $delivery->order->update([
                        'status' => 'delivered',
                        'payment_status' => 'paid',
                    ]);

                    $gross = (float) $delivery->order->subtotal;
                    $sellerAmount = round($gross * 0.90, 2);
                    $platformCommission = round($gross * 0.10, 2);
                    $sellerUser = $delivery->order->items->first()?->product?->shop?->user_id;

                    CommissionLedger::firstOrCreate(
                        ['order_id' => $delivery->order->id],
                        [
                            'seller_id' => $sellerUser,
                            'courier_id' => $delivery->courier_id ?? $user->id,
                            'gross_amount' => $gross,
                            'seller_amount' => $sellerAmount,
                            'platform_commission' => $platformCommission,
                            'delivery_fee' => 60.00,
                            'status' => 'settled',
                            'settled_at' => now(),
                        ]
                    );
                }
                DeliveryCheckpoint::record(
                    $delivery,
                    'doorstep_handover',
                    $delivery->delivery_address ?: 'Destination Address',
                    $validated['notes'] ?? 'Doorstep handover verified via QR code scan and recipient confirmation',
                    $user,
                    $validated['proof_image'] ?? null
                );
                break;

            case 'buyer_confirm':
                if (! $delivery->order || $delivery->order->buyer_id !== $user->id) {
                    abort(403, 'Only the buyer can confirm order receipt.');
                }
                $delivery->order->update(['status' => 'completed']);
                DeliveryCheckpoint::record(
                    $delivery,
                    'buyer_confirmed',
                    $delivery->delivery_address ?: 'Customer Address',
                    'Customer confirmed receipt of parcel',
                    $user
                );
                break;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Parcel #{$delivery->tracking_number} action {$action} successfully recorded.",
                'new_status' => $delivery->fresh()->status,
            ]);
        }

        return back()->with('success', "Parcel #{$delivery->tracking_number} updated successfully.");
    }

    /**
     * Determine staff/buyer operational actions available to the current user.
     */
    protected function determineAvailableActions(Delivery $delivery, ?\App\Models\User $user): array
    {
        if (! $user) {
            return [];
        }

        $actions = [];
        $status = strtolower((string) $delivery->status);

        if ($user->isCourier() || $user->isAdmin()) {
            if (in_array($status, ['unassigned', 'assigned', 'ready_for_pickup', 'pending', 'placed'])) {
                $actions[] = [
                    'action' => 'courier_pickup',
                    'label' => 'Scan & Confirm Store Pickup',
                    'description' => 'Log pickup from merchant store and advance to transit',
                    'variant' => 'primary',
                ];
            }

            if (in_array($status, ['picked_up', 'in_transit'])) {
                $actions[] = [
                    'action' => 'out_for_delivery',
                    'label' => 'Start Doorstep Delivery',
                    'description' => 'Depart station with parcel for last-mile delivery',
                    'variant' => 'info',
                ];
            }

            if ($status === 'out_for_delivery') {
                $actions[] = [
                    'action' => 'delivered',
                    'label' => 'Confirm Handover & Mark Delivered',
                    'description' => 'Confirm parcel handover and collect payment if COD',
                    'variant' => 'success',
                ];
            }
        }

        if ($user->isLogistics() || $user->isAdmin()) {
            if (in_array($status, ['picked_up', 'assigned', 'in_transit'])) {
                $actions[] = [
                    'action' => 'hub_intake',
                    'label' => 'Log Sorting Hub Intake',
                    'description' => 'Record parcel arrival at central logistics facility',
                    'variant' => 'warning',
                ];
                $actions[] = [
                    'action' => 'hub_sorted',
                    'label' => 'Sort to Destination Bay',
                    'description' => 'Triage parcel to regional delivery dispatch lane',
                    'variant' => 'secondary',
                ];
            }
        }

        if ($delivery->order && $delivery->order->buyer_id === $user->id) {
            if ($status === 'delivered' && $delivery->order->status !== 'completed') {
                $actions[] = [
                    'action' => 'buyer_confirm',
                    'label' => 'Confirm Order Received',
                    'description' => 'Verify you have received your parcel in good condition',
                    'variant' => 'success',
                ];
            }
        }

        return $actions;
    }

    /**
     * Privacy masking for customer full names.
     */
    protected function maskName(string $name): string
    {
        $parts = explode(' ', trim($name));
        $masked = [];
        foreach ($parts as $part) {
            if (strlen($part) <= 2) {
                $masked[] = $part;
            } else {
                $masked[] = substr($part, 0, 1) . str_repeat('*', max(1, strlen($part) - 2)) . substr($part, -1);
            }
        }
        return implode(' ', $masked);
    }

    /**
     * Privacy masking for phone numbers (+63 9•• ••• 4567).
     */
    protected function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) >= 10) {
            return substr($clean, 0, 4) . ' ••• ••• ' . substr($clean, -4);
        }
        return '+63 9•• ••• ••••';
    }

    /**
     * Privacy masking for street addresses (reveals City and Province only).
     */
    protected function maskAddress(string $address): string
    {
        $parts = array_filter(array_map('trim', explode(',', $address)));
        if (count($parts) >= 2) {
            $lastTwo = array_slice($parts, -2);
            return 'Protected Location, ' . implode(', ', $lastTwo);
        }
        return 'Protected Delivery Address, Philippines';
    }
}
