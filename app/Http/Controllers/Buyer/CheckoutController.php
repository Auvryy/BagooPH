<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\LogisticsHub;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\BuyerAccessService;
use App\Services\Commerce\CommerceInputService;
use App\Services\KycSubmissionService;
use App\Services\Orders\CheckoutOrderService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\ShopEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
            $itemIds = app(CommerceInputService::class)->selection($rawIds);
            if ($cart->items->whereIn('id', $itemIds)->count() !== count($itemIds)) {
                return redirect()->route('buyer.cart')->with('error', 'One or more selected Shopping Bag items are unavailable.');
            }
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
            'checkoutToken' => app(CheckoutSubmissionService::class)->issue($user, $cart),
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

        if (! $cart) {
            return redirect()->route('buyer.cart')->with('error', 'Your shopping bag is empty.');
        }

        $selection = $request->validate(['item_ids' => ['required', 'array', 'list', 'min:1']]);

        try {
            $orders = app(CheckoutOrderService::class)->place(
                $user,
                $cart,
                $selection['item_ids'],
                $request->all()
            );

            $message = $orders->count() === 1
                ? "Order #{$orders->first()->order_number} successfully placed."
                : "{$orders->count()} shop orders successfully placed.";

            return redirect()->route('buyer.orders.index')->with('success', $message);
        } catch (ValidationException|AuthorizationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409 && ! $request->expectsJson()) {
                return redirect()->route('buyer.orders.index')->with('error', $e->getMessage());
            }
            throw $e;
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Checkout could not be saved.', ['buyer_id' => $user->id, 'exception' => get_class($e)]);

            return back()->with('error', 'We could not save your order. Please try again with the same checkout details.');
        }
    }
}
