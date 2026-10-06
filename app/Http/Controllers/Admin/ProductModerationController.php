<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductModerationController extends Controller
{
    public function show(Request $request, Product $product, ProductModerationService $moderation): Response
    {
        return Inertia::render('Admin/ProductModeration', [
            'subject' => $moderation->presentation($request->user(), $product),
            'endpoint' => $request->getPathInfo(),
            'backUrl' => $request->is('admin/*') ? '/admin/products' : '/products',
        ]);
    }

    public function store(Request $request, Product $product, ProductModerationService $moderation): RedirectResponse|JsonResponse
    {
        $decision = $moderation->decide($request->user(), $product, $request->all());
        if ($request->wantsJson()) {
            return response()->json(['decision_id' => $decision->id, 'message' => 'Product compliance decision recorded.']);
        }

        return back()->with('success', 'Product compliance decision recorded.');
    }
}
