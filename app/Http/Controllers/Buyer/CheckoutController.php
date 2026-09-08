<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Voucher;
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
            return redirect()->route('buyer.cart')->with('error', 'Your shopping bag is empty.');
        }

        // Calculate subtotal directly from latest product database prices
        $subtotal = 0;
        foreach ($cart->items as $item) {
            $currentProduct = Product::find($item->product_id);
            if (! $currentProduct || $currentProduct->status !== 'active') {
                return redirect()->route('buyer.cart')->with('error', 'One or more items in your bag are currently unavailable.');
            }
            $subtotal += $currentProduct->price * $item->quantity;
        }

        $shippingFee = $subtotal > 1500 ? 0.00 : 50.00;
        $total = $subtotal + $shippingFee;

        // Fetch available platform and merchant vouchers
        $availableVouchers = Voucher::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->get();

        $kycStatus = $user->kyc_status ?? 'none';

        // Fetch saved addresses, migrating user profile address if user has no saved addresses
        if ($user->addresses()->count() === 0 && $user->address && $user->city) {
            $user->addresses()->create([
                'recipient_name' => $user->name,
                'phone' => $user->phone ?? '',
                'province' => 'Metro Manila',
                'city' => $user->city,
                'barangay' => null,
                'street' => $user->address,
                'postal_code' => $user->postal_code,
                'type' => 'Home',
                'is_default' => true,
            ]);
        }

        $addresses = $user->addresses()->orderByDesc('is_default')->oldest()->get();
        $defaultAddress = $user->defaultAddress();

        return Inertia::render('Checkout/Index', [
            'cart' => $cart,
            'items' => $cart->items,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'total' => $total,
            'user' => $user,
            'availableVouchers' => $availableVouchers,
            'kycStatus' => $kycStatus,
            'kycFeedback' => $user->kyc_feedback,
            'addresses' => $addresses,
            'defaultAddressId' => $defaultAddress?->id,
        ]);
    }

    public function uploadKycDocument(Request $request): RedirectResponse
    {
        $request->validate([
            'id_document' => 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ]);

        $user = $request->user();
        $idPath = '/storage/' . $request->file('id_document')->store('kyc_documents', 'public');

        $user->update([
            'id_document_path' => $idPath,
            'kyc_status' => 'pending_approval',
            'kyc_submitted_at' => now(),
        ]);

        return back()->with('success', 'Valid ID uploaded successfully! Your verification is now under review.');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Enforce KYC verification gate before allowing order submission
        if (! $user->isAdmin()) {
            if ($user->kyc_status === 'pending_approval') {
                return redirect()->route('buyer.checkout')->with('error', 'Your ID verification is currently pending review. Please wait for approval before completing your purchase.');
            }

            if ($user->kyc_status === 'rejected') {
                return redirect()->route('buyer.checkout')->with('error', 'Your submitted ID was rejected. Please re-upload a valid ID to proceed.');
            }

            if ($user->kyc_status !== 'approved') {
                return redirect()->route('buyer.checkout')->with('error', 'Identity verification is required before placing an order. Please upload a valid ID to proceed.');
            }
        }

        $cart = Cart::where('user_id', $user->id)->with(['items.product.shop'])->first();

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('buyer.cart')->with('error', 'Your shopping bag is empty.');
        }

        $validated = $request->validate([
            'recipient_name' => 'required|string|max:255',
            'recipient_phone' => 'required|string|max:50',
            'shipping_address' => 'required|string|max:500',
            'shipping_city' => 'required|string|max:100',
            'shipping_postal_code' => 'nullable|string|max:20',
            'payment_method' => 'required|string|in:card,cod,bank_transfer,e_wallet',
            'notes' => 'nullable|string|max:500',
            'voucher_code' => 'nullable|string|max:50',
            'save_address' => 'nullable|boolean',
        ]);

        if ($request->boolean('save_address') && ! empty($validated['shipping_address'])) {
            $hasExisting = $user->addresses()->exists();
            $user->addresses()->create([
                'recipient_name' => $validated['recipient_name'],
                'phone' => $validated['recipient_phone'],
                'city' => $validated['shipping_city'],
                'street' => $validated['shipping_address'],
                'postal_code' => $validated['shipping_postal_code'] ?? null,
                'type' => 'Home',
                'is_default' => ! $hasExisting,
            ]);
        }

        try {
            $order = DB::transaction(function () use ($user, $cart, $validated) {
                // Recompute exact total from database to prevent price manipulation
                $subtotal = 0;
                foreach ($cart->items as $item) {
                    $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();
                    
                    if ($product->status !== 'active') {
                        throw new \Exception("'{$product->name}' is no longer active.");
                    }

                    if ($product->stock < $item->quantity) {
                        throw new \Exception("'{$product->name}' does not have enough stock (Only {$product->stock} available).");
                    }

                    $subtotal += $product->price * $item->quantity;
                }

                $shippingFee = $subtotal > 1500 ? 0.00 : 50.00;
                $voucherDiscount = 0.0;
                $appliedVoucher = null;

                if (! empty($validated['voucher_code'])) {
                    $code = strtoupper(trim($validated['voucher_code']));
                    $appliedVoucher = Voucher::where('code', $code)->where('is_active', true)->first();

                    if ($appliedVoucher && $appliedVoucher->isValidForAmount($subtotal)) {
                        $voucherDiscount = $appliedVoucher->calculateDiscount($subtotal, $shippingFee);
                        $appliedVoucher->increment('used_count');
                    }
                }

                $totalAmount = max(0, ($subtotal + $shippingFee) - $voucherDiscount);

                $order = Order::create([
                    'order_number' => 'BGO-' . strtoupper(Str::random(8)),
                    'buyer_id' => $user->id,
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shippingFee,
                    'total_amount' => $totalAmount,
                    'payment_method' => $validated['payment_method'],
                    'payment_status' => $validated['payment_method'] === 'cod' ? 'pending' : 'paid',
                    'status' => 'pending',
                    'recipient_name' => $validated['recipient_name'],
                    'recipient_phone' => $validated['recipient_phone'],
                    'shipping_address' => $validated['shipping_address'],
                    'shipping_city' => $validated['shipping_city'],
                    'shipping_postal_code' => $validated['shipping_postal_code'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                $firstShop = null;
                foreach ($cart->items as $item) {
                    $product = Product::findOrFail($item->product_id);
                    
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'shop_id' => $product->shop_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $product->price,
                        'subtotal' => $item->quantity * $product->price,
                        'color' => $item->color,
                        'size' => $item->size,
                        'sku_snapshot' => $item->sku_snapshot,
                    ]);

                    // Atomically reduce stock & increment sales counter
                    $product->decrement('stock', $item->quantity);
                    $product->increment('sales_count', $item->quantity);

                    if (! $firstShop && $product->shop) {
                        $firstShop = $product->shop;
                    }
                }

                // Create Delivery record for courier pool
                Delivery::create([
                    'order_id' => $order->id,
                    'tracking_number' => 'BGO-' . strtoupper(Str::random(10)),
                    'logistics_partner' => 'Bagoo Express Dispatch Fleet',
                    'status' => 'unassigned',
                    'pickup_store_name' => $firstShop?->name ?? 'Bagoo Prime Store',
                    'pickup_address' => ($firstShop?->address ?? 'Artisan District') . ', ' . ($firstShop?->city ?? 'Metro Manila'),
                    'delivery_recipient_name' => $validated['recipient_name'],
                    'delivery_address' => $validated['shipping_address'] . ', ' . $validated['shipping_city'],
                    'delivery_phone' => $validated['recipient_phone'],
                    'estimated_delivery_at' => now()->addDays(3),
                ]);

                // Clear cart items safely
                $cart->items()->delete();

                return $order;
            });

            return redirect()->route('buyer.orders.index')->with('success', "Order #{$order->order_number} successfully placed!");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
