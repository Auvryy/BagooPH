<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderSimulationController extends Controller
{
    public function advance(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $order->load(['delivery', 'items.product.shop', 'buyer']);
        $delivery = $order->delivery;
        $orderStatus = $order->status;
        $deliveryStatus = $delivery?->status ?? 'none';

        if ($orderStatus === 'cancelled') {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Cannot advance a cancelled order.'], 400);
            }
            return back()->with('error', 'Cannot advance a cancelled order.');
        }

        if ($orderStatus === 'delivered' && $deliveryStatus === 'delivered') {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Order is already delivered and settled.',
                    'order' => $order->fresh(['delivery', 'items', 'commissionLedger']),
                ]);
            }
            return back()->with('info', 'Order is already delivered and settled.');
        }

        // Stage 1: pending -> processing / packaging
        if ($orderStatus === 'pending') {
            $order->update(['status' => 'processing']);

            if (! $delivery) {
                $shop = $order->items->first()?->product?->shop;
                $delivery = Delivery::create([
                    'order_id' => $order->id,
                    'tracking_number' => 'BGO-' . strtoupper(Str::random(10)),
                    'logistics_partner' => 'Bagoo Express Dispatch Fleet',
                    'status' => 'unassigned',
                    'pickup_store_name' => $shop?->name ?? 'Merchant Store',
                    'pickup_address' => ($shop?->address ?? 'Artisan District') . ', ' . ($shop?->city ?? 'Manila'),
                    'delivery_recipient_name' => $order->recipient_name ?? $order->buyer?->name ?? 'Recipient',
                    'delivery_address' => $order->shipping_address . ', ' . $order->shipping_city,
                    'delivery_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000',
                    'estimated_delivery_at' => now()->addDays(3),
                ]);
            }

            DeliveryCheckpoint::create([
                'delivery_id' => $delivery->id,
                'checkpoint_type' => 'seller_pack',
                'location_name' => $delivery->pickup_store_name ?? 'Merchant Store',
                'barcode_scanned' => $delivery->tracking_number,
                'notes' => 'Seller approved and packed order into shipping parcel',
                'scanned_by_id' => $request->user()?->id,
            ]);

            $msg = "Order #{$order->order_number} advanced to Packaging.";
        }
        // Stage 2: processing / packaging -> ready_for_pickup
        elseif ($orderStatus === 'processing' || $orderStatus === 'packaging') {
            $order->update(['status' => 'ready_for_pickup']);
            if ($delivery) {
                $delivery->update(['status' => 'unassigned']);
            }
            $msg = "Order #{$order->order_number} marked Ready for Pickup.";
        }
        // Stage 3: ready_for_pickup -> picked_up / shipped
        elseif ($orderStatus === 'ready_for_pickup' || ($delivery && $deliveryStatus === 'unassigned')) {
            $courier = $delivery?->courier ?? User::where('role', 'courier')->first();
            if (! $courier) {
                $courier = User::factory()->courier()->create([
                    'status' => 'active',
                    'kyc_status' => 'approved',
                ]);
            }

            $order->update(['status' => 'shipped']);
            if ($delivery) {
                $delivery->update([
                    'courier_id' => $courier->id,
                    'status' => 'picked_up',
                    'assigned_at' => $delivery->assigned_at ?? now()->subMinutes(30),
                    'picked_up_at' => now(),
                ]);

                DeliveryCheckpoint::create([
                    'delivery_id' => $delivery->id,
                    'checkpoint_type' => 'courier_pickup',
                    'location_name' => $delivery->pickup_store_name ?? 'Merchant Store',
                    'barcode_scanned' => $delivery->tracking_number,
                    'notes' => 'Courier verified barcode and picked up parcel from store',
                    'scanned_by_id' => $courier->id,
                ]);
            }
            $msg = "Order #{$order->order_number} picked up by courier.";
        }
        // Stage 4: picked_up -> in_transit
        elseif ($deliveryStatus === 'picked_up' || ($orderStatus === 'shipped' && $deliveryStatus === 'assigned')) {
            if ($delivery) {
                $delivery->update(['status' => 'in_transit']);

                DeliveryCheckpoint::create([
                    'delivery_id' => $delivery->id,
                    'checkpoint_type' => 'hub_intake',
                    'location_name' => 'Metro Manila Central Sorting Station',
                    'barcode_scanned' => $delivery->tracking_number,
                    'notes' => 'Central logistics hub intake scan complete',
                    'scanned_by_id' => $request->user()?->id,
                ]);
            }
            $msg = "Order #{$order->order_number} in transit at Central Hub.";
        }
        // Stage 5: in_transit -> out_for_delivery
        elseif ($deliveryStatus === 'in_transit') {
            if ($delivery) {
                $delivery->update(['status' => 'out_for_delivery']);

                DeliveryCheckpoint::create([
                    'delivery_id' => $delivery->id,
                    'checkpoint_type' => 'barangay_sort',
                    'location_name' => 'Destination Delivery Bay',
                    'barcode_scanned' => $delivery->tracking_number,
                    'notes' => 'Parcel sorted for last-mile doorstep delivery',
                    'scanned_by_id' => $request->user()?->id,
                ]);
            }
            $msg = "Order #{$order->order_number} is Out for Delivery.";
        }
        // Stage 6: out_for_delivery -> delivered
        else {
            $order->update([
                'status' => 'delivered',
                'payment_status' => 'paid',
            ]);

            if ($delivery) {
                $delivery->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'proof_image' => $delivery->proof_image ?? 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
                ]);

                DeliveryCheckpoint::create([
                    'delivery_id' => $delivery->id,
                    'checkpoint_type' => 'doorstep_handover',
                    'location_name' => $delivery->delivery_address,
                    'barcode_scanned' => $delivery->tracking_number,
                    'notes' => 'Recipient verified parcel and confirmed handover with proof photo',
                    'scanned_by_id' => $delivery->courier_id,
                    'proof_image' => $delivery->proof_image,
                ]);
            }

            // Trigger atomic Commission Ledger creation
            $gross = (float) $order->subtotal;
            $sellerAmount = round($gross * 0.90, 2);
            $platformCommission = round($gross * 0.10, 2);
            $sellerUser = $order->items->first()?->product?->shop?->user_id;

            CommissionLedger::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'seller_id' => $sellerUser,
                    'courier_id' => $delivery?->courier_id,
                    'gross_amount' => $gross,
                    'seller_amount' => $sellerAmount,
                    'platform_commission' => $platformCommission,
                    'delivery_fee' => 60.00,
                    'status' => 'settled',
                ]
            );

            $msg = "Order #{$order->order_number} marked Delivered and commission split settled.";
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'order' => $order->fresh(['delivery.checkpoints', 'items.product', 'commissionLedger']),
            ]);
        }

        return back()->with('success', $msg);
    }

    public function reset(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $order->load('delivery');

        $order->update([
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        if ($order->delivery) {
            $order->delivery->update([
                'status' => 'unassigned',
                'assigned_at' => null,
                'picked_up_at' => null,
                'delivered_at' => null,
                'proof_image' => null,
            ]);
            $order->delivery->checkpoints()->delete();
        }

        $order->commissionLedger()?->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Order #{$order->order_number} reset to pending.",
                'order' => $order->fresh(['delivery', 'items']),
            ]);
        }

        return back()->with('success', "Order #{$order->order_number} reset to pending.");
    }
}
