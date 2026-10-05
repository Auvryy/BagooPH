<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\LogisticsHub;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\BuyerAccessService;
use App\Services\KycSubmissionService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Orders\CheckoutOrderService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requirePortal($request->user());
        $cart = Cart::where('user_id', $user->id)->with(['items.product.shop'])->first();

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('buyer.cart')->with('error', 'Your shopping bag is empty.');
        }

        // Determine which items to checkout:
        if ($request->filled('items')) {
            $rawIds = is_array($request->input('items'))
                ? $request->input('items')
                : explode(',', (string) $request->input('items'));
            $itemIds = array_filter(array_map('intval', $rawIds));
            $checkoutItems = $cart->items->whereIn('id', $itemIds)->values();
        } else {
            // Default to the most recent item added when arriving at checkout without explicit items parameter
            $mostRecentItem = $cart->items->sort(function ($a, $b) {
                $timeDiff = ($b->updated_at?->timestamp ?? 0) <=> ($a->updated_at?->timestamp ?? 0);
                if ($timeDiff !== 0) {
                    return $timeDiff;
                }

                return $b->id <=> $a->id;
            })->first();
            $checkoutItems = $mostRecentItem ? collect([$mostRecentItem]) : $cart->items;
        }

        if ($checkoutItems->isEmpty()) {
            return redirect()->route('buyer.cart')->with('error', 'Please select at least one item from your shopping bag to checkout.');
        }

        // Calculate subtotal directly from latest product database prices
        $subtotal = 0;
        foreach ($checkoutItems as $item) {
            $currentProduct = Product::find($item->product_id);
            if (! $currentProduct || ! app(ShopEligibilityService::class)->productIsEligible($currentProduct)) {
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

        // Approved/verified is the reviewed authorization state. Do not infer
        // a pending review from a missing path in the shared user payload.
        $kycStatus = $user->isKycApproved() ? 'approved' : ($user->kyc_status ?? 'none');

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

        $pickupHubs = LogisticsHub::eligible()->where('tier', 'local_bayan_hub')
            ->where('allows_self_pickup', true)
            ->get(['id', 'name', 'code', 'city_municipality', 'address', 'province']);

        return Inertia::render('Checkout/Index', [
            'cart' => $cart,
            'items' => $checkoutItems,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'total' => $total,
            'user' => $user,
            'availableVouchers' => $availableVouchers,
            'kycStatus' => $kycStatus,
            'kycFeedback' => $user->kyc_feedback,
            'addresses' => $addresses,
            'defaultAddressId' => $defaultAddress?->id,
            'pickupHubs' => $pickupHubs,
        ]);
    }

    public function uploadKycDocument(Request $request): RedirectResponse
    {
        $user = app(BuyerAccessService::class)->current($request->user());
        app(BuyerAccessService::class)->requireApplication($user);
        $validated = $request->validate([
            'id_document' => 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ]);

        app(KycSubmissionService::class)->submit($user, $validated, buyerUpload: true);

        return back()->with('success', 'Valid ID uploaded successfully! Your verification is now under review.');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requirePortal($request->user());

        $cart = Cart::where('user_id', $user->id)->with(['items.product.shop'])->first();

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('buyer.cart')->with('error', 'Your shopping bag is empty.');
        }

        $validated = $request->validate([
            'recipient_name' => 'required|string|max:255',
            'recipient_phone' => 'required|string|max:50',
            'shipping_address' => 'required|string|max:500',
            'shipping_city' => 'required|string|max:100',
            'shipping_province' => 'required|string|max:100',
            'shipping_postal_code' => 'required|regex:/^[0-9]{4}$/',
            'shipping_latitude' => 'nullable|numeric|between:-90,90',
            'shipping_longitude' => 'nullable|numeric|between:-180,180',
            'landmark' => 'nullable|string|max:255',
            'delivery_type' => 'nullable|string|in:doorstep,hub_self_pickup',
            'pickup_hub_id' => 'nullable|exists:logistics_hubs,id',
            'destination_barangay' => 'required|string|max:100',
            'payment_method' => 'nullable|string|in:cod,card,bank_transfer,e_wallet',
            'notes' => 'nullable|string|max:500',
            'voucher_code' => 'nullable|string|max:50',
            'save_address' => 'nullable|boolean',
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|integer|distinct',
        ]);

        // Contiguous Land Delimitation: strictly reject non-contiguous island addresses
        $routingEngine = app(LogisticsRoutingEngine::class);
        $province = $validated['shipping_province'] ?? $validated['shipping_city'];
        if (! $routingEngine->isContiguousRoadServiceable($province, $validated['shipping_city'])) {
            return back()->with('error', 'Delivery address is outside contiguous road freight boundaries. Maritime shipping is excluded.');
        }

        try {
            $orders = app(CheckoutOrderService::class)->place(
                $user,
                $cart,
                $validated['item_ids'],
                $validated
            );

            if ($request->boolean('save_address')) {
                $hasExisting = $user->addresses()->exists();
                $user->addresses()->create([
                    'recipient_name' => $validated['recipient_name'],
                    'phone' => $validated['recipient_phone'],
                    'city' => $validated['shipping_city'],
                    'province' => $province,
                    'barangay' => $validated['destination_barangay'],
                    'street' => $validated['shipping_address'],
                    'postal_code' => $validated['shipping_postal_code'],
                    'latitude' => $validated['shipping_latitude'] ?? null,
                    'longitude' => $validated['shipping_longitude'] ?? null,
                    'landmark' => $validated['landmark'] ?? null,
                    'type' => 'Home',
                    'is_default' => ! $hasExisting,
                ]);
            }

            $message = $orders->count() === 1
                ? "Order #{$orders->first()->order_number} successfully placed."
                : "{$orders->count()} shop orders successfully placed.";

            return redirect()->route('buyer.orders.index')->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
