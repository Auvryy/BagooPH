<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    private function getCart(Request $request): Cart
    {
        $userId = $request->user()?->id;
        $sessionId = $request->session()->getId();

        if ($userId) {
            return Cart::firstOrCreate(
                ['user_id' => $userId],
                ['session_id' => $sessionId]
            );
        }

        return Cart::firstOrCreate(
            ['session_id' => $sessionId],
            ['user_id' => null]
        );
    }

    public function index(Request $request): Response
    {
        $cart = $this->getCart($request);
        $cart->load(['items.product.shop']);

        return Inertia::render('Cart/Index', [
            'cart' => $cart,
            'items' => $cart->items,
            'total' => $cart->total,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'nullable|integer|min:1|max:99',
            'color' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:50',
        ]);

        $product = Product::where('status', 'active')->findOrFail($request->input('product_id'));
        $quantity = (int) $request->input('quantity', 1);

        if ($product->stock < $quantity) {
            return back()->with('error', "Sorry, only {$product->stock} units are currently available.");
        }

        $color = $request->input('color');
        $size = $request->input('size');

        $cart = $this->getCart($request);
        $item = $cart->items()
            ->where('product_id', $product->id)
            ->where('color', $color)
            ->where('size', $size)
            ->first();

        if ($item) {
            $newQuantity = $item->quantity + $quantity;
            if ($product->stock < $newQuantity) {
                return back()->with('error', "Cannot add more. Stock limit of {$product->stock} reached.");
            }
            $item->quantity = $newQuantity;
            $item->unit_price = $product->price; // Always sync with real database price
            $item->save();
        } else {
            $skuSnapshot = $product->sku . ($color ? "-{$color}" : '') . ($size ? "-{$size}" : '');
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'color' => $color,
                'size' => $size,
                'sku_snapshot' => $skuSnapshot,
            ]);
        }

        return back()->with('success', "'{$product->name}' added to your shopping bag!");
    }

    public function update(Request $request, CartItem $cartItem): RedirectResponse
    {
        $cart = $this->getCart($request);
        
        // Authorization / IDOR Protection
        if ($cartItem->cart_id !== $cart->id) {
            abort(403, 'Unauthorized cart modification.');
        }

        $request->validate([
            'quantity' => 'required|integer|min:1|max:99',
        ]);

        $quantity = (int) $request->input('quantity');
        if ($cartItem->product && $cartItem->product->stock < $quantity) {
            return back()->with('error', "Stock limit reached. Only {$cartItem->product->stock} available.");
        }

        $cartItem->update([
            'quantity' => $quantity,
        ]);

        return back()->with('success', 'Shopping bag updated.');
    }

    public function destroy(Request $request, CartItem $cartItem): RedirectResponse
    {
        $cart = $this->getCart($request);

        // Authorization / IDOR Protection
        if ($cartItem->cart_id !== $cart->id) {
            abort(403, 'Unauthorized cart modification.');
        }

        $cartItem->delete();
        return back()->with('success', 'Item removed from shopping bag.');
    }
}
