<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Commerce\CartLineService;
use App\Services\Commerce\CommerceInputService;
use App\Services\Commerce\InventoryService;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CartController extends Controller
{
    private function getCart(Request $request): Cart
    {
        if ($request->user()) {
            app(BuyerAccessService::class)->requireAccount($request->user());
        }
        $userId = $request->user()?->id;
        $sessionId = $request->session()->getId();

        if ($userId) {
            return app(CartLineService::class)->ownedCart($request->user(), $sessionId);
        }

        return Cart::firstOrCreate(
            ['session_id' => $sessionId],
            ['user_id' => null]
        );
    }

    public function index(Request $request): Response|JsonResponse
    {
        $cart = $this->getCart($request);
        $cart->load(['items.product.shop']);
        $eligibleIds = Product::availableForSale()->whereIn('id', $cart->items->pluck('product_id'))->pluck('id');
        foreach ($cart->items as $item) {
            $available = $eligibleIds->contains($item->product_id) && $item->product?->stock > 0;
            $reason = 'This listing is unavailable for new purchases. You can remove it from your Bag.';
            if ($available) {
                try {
                    $inventory = app(InventoryService::class);
                    $inventory->assertCheckoutAvailability(collect([$item]), collect([$item->product_id => $item->product]), $cart->user_id);
                    $item->setAttribute('unit_price', $inventory->unitPrice($item->product, $item->color, $item->size));
                } catch (RuntimeException $exception) {
                    $available = false;
                    $reason = $exception->getMessage();
                }
            }
            $item->setAttribute('available_for_purchase', $available);
            $item->setAttribute('unavailable_reason', $available ? null : $reason);
        }

        if ($request->expectsJson()) {
            $data = $request->validate(['item_ids' => ['required', 'array', 'list', 'min:1']]);
            $ids = app(CommerceInputService::class)->selection($data['item_ids']);
            $selected = $cart->items->whereIn('id', $ids);
            if ($selected->count() !== count($ids) || $selected->contains(fn ($item) => ! $item->available_for_purchase)) {
                return response()->json(['message' => 'One or more selected Shopping Bag items are unavailable.'], 422);
            }
            try {
                app(InventoryService::class)->assertCheckoutAvailability($selected, $selected->pluck('product')->keyBy('id'), $cart->user_id);
            } catch (RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return response()->json(['quote' => app(CheckoutQuoteService::class)->quote($selected),
                'availableVouchers' => app(CheckoutQuoteService::class)->offers($selected, buyer: $request->user())['offers']]);
        }

        // Default sort: Most recent product added/updated is the first row
        $items = $cart->items->sort(function ($a, $b) {
            $timeDiff = ($b->updated_at?->timestamp ?? 0) <=> ($a->updated_at?->timestamp ?? 0);
            if ($timeDiff !== 0) {
                return $timeDiff;
            }

            return $b->id <=> $a->id;
        })->values();

        return Inertia::render('Cart/Index', [
            'cart' => $cart,
            'items' => $items,
            'total' => $cart->total,
            'quote' => app(CheckoutQuoteService::class)->quote($items->where('available_for_purchase', true)->take(1)),
        ]);
    }

    public function store(Request $request, CartLineService $lines): RedirectResponse
    {
        if ($request->user()) {
            app(BuyerAccessService::class)->requireBagChanges($request->user());
        }
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'max:99'],
            'color' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:50',
            'buy_now' => ['nullable', 'boolean'],
        ]);
        $buyNow = (bool) ($validated['buy_now'] ?? false);
        if ($buyNow) {
            if (! $request->user()) {
                return redirect()->guest(route('login'));
            }
            app(BuyerAccessService::class)->requirePortal($request->user());
        }

        $cart = $this->getCart($request);
        $quantity = (int) ($validated['quantity'] ?? 1);
        $color = $this->normalizeOption($validated['color'] ?? null);
        $size = $this->normalizeOption($validated['size'] ?? null);

        [$product, $line] = DB::transaction(function () use ($request, $cart, $validated, $quantity, $color, $size, $lines) {
            Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            try {
                $product = app(ShopEligibilityService::class)->lockSaleProducts([$validated['product_id']], $request->user() ? [$request->user()->id] : [])->get($validated['product_id']);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['product_id' => $exception->getMessage()]);
            }
            if ($request->user()) {
                app(BuyerAccessService::class)->requireBagChanges($request->user());
            }
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => 'This product is no longer available.']);
            }

            $line = $lines->addLocked($cart, $product, $quantity, $color, $size);

            return [$product, $line];
        });

        if ($buyNow) {
            return redirect()->route('checkout.index', ['items' => $line->id, 'quantity' => $quantity]);
        }

        return back()->with('success', "'{$product->name}' added to your shopping bag!");
    }

    public function update(Request $request, CartItem $cartItem, InventoryService $inventory): RedirectResponse
    {
        if ($request->user()) {
            app(BuyerAccessService::class)->requireBagChanges($request->user());
        }
        $cart = $this->getCart($request);

        // Authorization / IDOR Protection
        if ($cartItem->cart_id !== $cart->id) {
            abort(403, 'Unauthorized cart modification.');
        }

        $request->validate([
            'quantity' => ['bail', 'required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:99'],
        ]);

        $quantity = (int) $request->input('quantity');

        DB::transaction(function () use ($request, $cart, $cartItem, $quantity, $inventory) {
            Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $lockedItem = CartItem::query()
                ->whereKey($cartItem->id)
                ->where('cart_id', $cart->id)
                ->lockForUpdate()
                ->firstOrFail();
            try {
                $product = app(ShopEligibilityService::class)->lockSaleProducts([$lockedItem->product_id], $request->user() ? [$request->user()->id] : [])->get($lockedItem->product_id);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
            }
            if ($request->user()) {
                app(BuyerAccessService::class)->requireBagChanges($request->user());
            }
            if (! $product) {
                throw ValidationException::withMessages(['quantity' => 'This product is no longer available.']);
            }
            $productItems = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get();

            try {
                $maximum = $inventory->maximumCartLineQuantity(
                    $product,
                    $productItems,
                    $lockedItem->color,
                    $lockedItem->size,
                    $lockedItem->id,
                    $cart->user_id
                );
                $unitPrice = $inventory->unitPrice($product, $lockedItem->color, $lockedItem->size);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
            }

            if ($quantity > $maximum) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$maximum} units are available for this Shopping Bag selection.",
                ]);
            }

            $lockedItem->update([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);
        });

        return back()->with('success', 'Shopping bag updated.');
    }

    public function destroy(Request $request, CartItem $cartItem): RedirectResponse
    {
        if ($request->user()) {
            app(BuyerAccessService::class)->requireBagChanges($request->user());
        }
        $cart = $this->getCart($request);

        // Authorization / IDOR Protection
        if ($cartItem->cart_id !== $cart->id) {
            abort(403, 'Unauthorized cart modification.');
        }

        DB::transaction(function () use ($request, $cart, $cartItem) {
            Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($request->user()) {
                app(BuyerAccessService::class)->requireBagChanges($request->user(), lock: true);
            }
            CartItem::whereKey($cartItem->id)->where('cart_id', $cart->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return back()->with('success', 'Item removed from shopping bag.');
    }

    private function normalizeOption(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
