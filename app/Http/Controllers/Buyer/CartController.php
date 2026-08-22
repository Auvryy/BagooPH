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
        ]);

        $product = Product::findOrFail($request->input('product_id'));
        $quantity = $request->input('quantity', 1);

        $cart = $this->getCart($request);
        $item = $cart->items()->where('product_id', $product->id)->first();

        if ($item) {
            $item->quantity += $quantity;
            $item->save();
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $product->price,
            ]);
        }

        return back()->with('success', "'{$product->name}' added to your Bagoo cart!");
    }

    public function update(Request $request, CartItem $cartItem): RedirectResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1|max:99',
        ]);

        $cartItem->update([
            'quantity' => $request->input('quantity'),
        ]);

        return back()->with('success', 'Cart updated.');
    }

    public function destroy(CartItem $cartItem): RedirectResponse
    {
        $cartItem->delete();
        return back()->with('success', 'Item removed from cart.');
    }
}
