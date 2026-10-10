<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
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

        return Inertia::render('Buyer/SavedProducts', ['entries' => $saved->paginate($request->user())]);
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
