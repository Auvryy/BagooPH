<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $cart = Cart::where('user_id', $user->id)->with(['items.product.shop'])->first();

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        $subtotal = $cart->total;
        $shippingFee = $subtotal > 100 ? 0.00 : 10.00;
        $total = $subtotal + $shippingFee;

        return Inertia::render('Checkout/Index', [
            'cart' => $cart,
            'items' => $cart->items,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'total' => $total,
            'user' => $user,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $cart = Cart::where('user_id', $user->id)->with(['items.product.shop'])->first();

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        $validated = $request->validate([
            'recipient_name' => 'required|string|max:255',
            'recipient_phone' => 'required|string|max:50',
            'shipping_address' => 'required|string|max:500',
            'shipping_city' => 'required|string|max:100',
            'shipping_postal_code' => 'nullable|string|max:20',
            'payment_method' => 'required|string|in:card,cod,bank_transfer,e_wallet',
            'notes' => 'nullable|string|max:500',
        ]);

        $subtotal = $cart->total;
        $shippingFee = $subtotal > 100 ? 0.00 : 10.00;
        $totalAmount = $subtotal + $shippingFee;

        $order = DB::transaction(function () use ($user, $cart, $validated, $subtotal, $shippingFee, $totalAmount) {
            $order = Order::create([
                'order_number' => 'BGO-' . strtoupper(Str::random(8)),
                'buyer_id' => $user->id,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'total_amount' => $totalAmount,
                'payment_method' => $validated['payment_method'],
                'payment_status' => $validated['payment_method'] === 'cod' ? 'pending' : 'paid',
                'status' => 'processing',
                'recipient_name' => $validated['recipient_name'],
                'recipient_phone' => $validated['recipient_phone'],
                'shipping_address' => $validated['shipping_address'],
                'shipping_city' => $validated['shipping_city'],
                'shipping_postal_code' => $validated['shipping_postal_code'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $firstShop = null;
            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'shop_id' => $item->product->shop_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->quantity * $item->unit_price,
                ]);

                // Reduce stock and increment sales
                $item->product->decrement('stock', $item->quantity);
                $item->product->increment('sales_count', $item->quantity);

                if (! $firstShop && $item->product->shop) {
                    $firstShop = $item->product->shop;
                }
            }

            // Create delivery/shipment record ready for courier dispatch
            Delivery::create([
                'order_id' => $order->id,
                'courier_id' => null,
                'tracking_number' => 'TRK-BGO-' . rand(10000000, 99999999),
                'logistics_partner' => 'Bagoo Express Dispatch',
                'status' => 'unassigned',
                'pickup_store_name' => $firstShop ? $firstShop->name : 'BagooPH Central Hub',
                'pickup_address' => $firstShop ? ($firstShop->address . ', ' . $firstShop->city) : '100 Bagoo Hub Blvd',
                'pickup_phone' => $firstShop ? $firstShop->phone : '+1 (555) 000-0000',
                'delivery_address' => $order->shipping_address . ', ' . $order->shipping_city . ' ' . ($order->shipping_postal_code ?? ''),
                'delivery_recipient_name' => $order->recipient_name,
                'delivery_phone' => $order->recipient_phone,
                'estimated_delivery_at' => now()->addDays(2),
            ]);

            // Clear the cart
            $cart->items()->delete();

            return $order;
        });

        return redirect()->route('orders.show', $order->id)->with('success', "Order #{$order->order_number} placed successfully!");
    }
}
