<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\LogisticsHub;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Commerce\BuyerAddressService;
use App\Services\Commerce\CommerceInputService;
use App\Services\Governance\EnforcementService;
use App\Services\KycSubmissionService;
use App\Services\Logistics\LogisticsCheckoutService;
use App\Services\Orders\CheckoutOrderService;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\Wallet\WalletLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
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

        $quotes = app(CheckoutQuoteService::class);
        $selection = $request->validate(['quantity' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'between:1,99']]);
        $quantities = [];
        if (isset($selection['quantity'])) {
            if ($checkoutItems->count() !== 1) {
                throw ValidationException::withMessages(['quantity' => 'Choose one product for Buy Now.']);
            }
            $quantities = [$checkoutItems->first()->id => (int) $selection['quantity']];
        }
        try {
            $checkoutItems = $quotes->selectedItems($user, $checkoutItems->pluck('id')->all(), $quantities);
            $quote = $quotes->quote($checkoutItems);
        } catch (CheckoutException $exception) {
            return redirect()->route('buyer.cart')->with('error', $exception->getMessage());
        }
        $available = $quotes->offers($checkoutItems, buyer: $user);

        // Approved/verified is the reviewed authorization state. Do not infer
        // a pending review from a missing path in the shared user payload.
        $kycStatus = $user->isKycApproved() ? 'approved' : ($user->kyc_status ?? 'none');

        $addresses = $user->addresses()->orderByDesc('is_default')->oldest()->get();
        $addresses->each(fn ($address) => $address->setAttribute('location_token', app(BuyerAddressService::class)->locationToken($address)));
        $defaultAddress = $user->defaultAddress();
        $initialAddress = $defaultAddress ?? $addresses->firstWhere('is_default') ?? $addresses->first();
        $initialLogisticsChoices = app(LogisticsCheckoutService::class)->choices($checkoutItems, [
            'delivery_type' => 'doorstep',
            'shipping_province' => $initialAddress?->province,
            'shipping_city' => $initialAddress?->city,
            'shipping_postal_code' => $initialAddress?->postal_code,
            'destination_barangay' => $initialAddress?->barangay,
        ]);

        $pickupHubs = LogisticsHub::readyForSelfPickup()
            ->get(['id', 'name', 'code', 'city_municipality', 'address', 'province', 'logistics_company_id']);

        return Inertia::render('Checkout/Index', [
            'cart' => $cart,
            'items' => $checkoutItems,
            'itemQuantities' => $quantities,
            'subtotal' => $quote['subtotal'],
            'shippingFee' => $quote['shipping_fee'],
            'total' => $quote['total'],
            'quote' => $quote,
            'user' => $user,
            'availableVouchers' => $available['offers'],
            'kycStatus' => $kycStatus,
            'kycFeedback' => $user->kyc_feedback,
            'addresses' => $addresses,
            'defaultAddressId' => $defaultAddress?->id,
            'addressBookLimit' => BuyerAddressService::LIMIT,
            'doorstepPinRequired' => (bool) config('checkout.require_doorstep_pin'),
            'pickupHubs' => $pickupHubs,
            'logisticsCompanies' => $initialLogisticsChoices,
            'checkoutToken' => app(CheckoutSubmissionService::class)->issue($user, $cart, $checkoutItems),
            'walletBalanceCents' => app(WalletLedger::class)->balance($user),
            'codRestriction' => app(EnforcementService::class)->activeCodRestrictionForBuyer($user)?->only([
                'id', 'reference', 'reason_code', 'reason', 'expires_at',
            ]),
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

    public function store(Request $request): RedirectResponse|JsonResponse
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

            $targetUrl = $orders->first()->payment_method === 'wallet'
                ? ($orders->count() === 1
                    ? route('wallet.payments.show', $orders->first()->walletPayment)
                    : route('buyer.profile', ['tab' => 'orders', 'order_status' => 'to_pay']))
                : route('buyer.orders.index');

            if ($request->expectsJson()) {
                session()->flash('success', $message);

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'redirect' => $targetUrl,
                ]);
            }

            if ($orders->first()->payment_method === 'wallet') {
                return $orders->count() === 1
                    ? redirect()->to($targetUrl)->with('success', 'Order placed. Review and confirm its wallet payment within one hour.')
                    : redirect()->to($targetUrl)->with('success', 'Shop orders placed. Complete each wallet payment within one hour.');
            }

            return redirect()->to($targetUrl)->with('success', $message);
        } catch (ValidationException|AuthorizationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409 && ! $request->expectsJson()) {
                return redirect()->route('buyer.orders.index')->with('error', $e->getMessage());
            }
            throw $e;
        } catch (CheckoutException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Checkout could not be saved.', ['buyer_id' => $user->id, 'exception' => get_class($e)]);

            return back()->with('error', 'We could not save your order. Please try again with the same checkout details.');
        }
    }
}
