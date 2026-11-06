<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\StoreTag;
use App\Services\Commerce\StoreTagService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerStoreTagController extends Controller
{
    use HasSellerShop;

    public function index(Request $request, StoreTagService $tags): Response
    {
        $shop = $this->getActiveShop($request);

        return Inertia::render('Seller/StoreTags', ['shop' => $shop, 'tags' => $tags->rows($shop)]);
    }

    public function store(Request $request, StoreTagService $tags): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $tags) {
            $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:50'], 'product_name' => ['required', 'string', 'min:5', 'max:120']]);
            $tags->create($this->getActiveShop($request), $data['name'], $data['product_name']);

            return back()->with('success', 'Product tag added after relevance review.');
        });
    }

    public function update(Request $request, StoreTag $storeTag, StoreTagService $tags): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $storeTag, $tags) {
            $data = $request->validate([
                'name' => ['required', 'string', 'min:1', 'max:50'],
                'product_name' => ['nullable', 'string', 'min:5', 'max:120'],
            ]);
            $tags->rename($this->getActiveShop($request), $storeTag, $data['name'], $data['product_name'] ?? null);

            return back()->with('success', 'Product tag updated after relevance review.');
        });
    }

    public function destroy(Request $request, StoreTag $storeTag, StoreTagService $tags): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $storeTag, $tags) {
            $tags->delete($this->getActiveShop($request), $storeTag);

            return back()->with('success', 'Tag removed from your shop and product listings.');
        });
    }
}
