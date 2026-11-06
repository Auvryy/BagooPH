<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Rules\AsciiPositiveInteger;
use App\Services\Commerce\SavedProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SavedProductController extends Controller
{
    public function index(Request $request, SavedProductService $saved): Response
    {
        $request->validate(['page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'max:1000000']]);

        $cart = Cart::query()->where('user_id', $request->user()->id)->with('items:id,cart_id,product_id')->first();
        $cartItemIdsByProduct = $cart?->items->mapWithKeys(fn ($item) => [(int) $item->product_id => (int) $item->id])->all() ?? [];

        return Inertia::render('Buyer/SavedProducts', [
            'entries' => $saved->paginate($request->user()),
            'cartItemIdsByProduct' => $cartItemIdsByProduct,
        ]);
    }

    public function store(Request $request, Product $product, SavedProductService $saved): JsonResponse|RedirectResponse
    {
        $saved->save($request->user(), $product);

        return $request->expectsJson() ? response()->json(['product_id' => $product->id, 'saved' => true]) : back()->with('success', 'Product saved.');
    }

    public function destroy(Request $request, Product $product, SavedProductService $saved): JsonResponse|RedirectResponse
    {
        $saved->remove($request->user(), $product->id);

        return $request->expectsJson() ? response()->json(['product_id' => $product->id, 'saved' => false]) : back()->with('success', 'Product removed from saved products.');
    }
}
