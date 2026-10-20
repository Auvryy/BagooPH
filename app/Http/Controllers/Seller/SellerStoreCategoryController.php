<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\StoreCategory;
use App\Rules\AsciiPositiveInteger;
use App\Services\Commerce\StoreCategoryService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerStoreCategoryController extends Controller
{
    use HasSellerShop;

    public function index(Request $request, StoreCategoryService $categories): Response
    {
        $shop = $this->getActiveShop($request);

        return Inertia::render('Seller/StoreCategories', ['shop' => $shop, 'categories' => $categories->rows($shop)]);
    }

    public function store(Request $request, StoreCategoryService $categories): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $categories) {
            $categories->save($this->getActiveShop($request), $request->all());

            return back()->with('success', 'Store category created.');
        });
    }

    public function update(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $storeCategory, $categories) {
            $categories->save($this->getActiveShop($request), $request->all(), $storeCategory);

            return back()->with('success', 'Store category updated.');
        });
    }

    public function reorder(Request $request, StoreCategoryService $categories): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $categories) {
            $data = $request->validate(['category_ids' => ['required', 'array', 'list', 'min:1', 'max:1000'],
                'category_ids.*' => ['bail', new AsciiPositiveInteger, 'integer', 'distinct']]);
            $categories->reorder($this->getActiveShop($request), array_map('intval', $data['category_ids']));

            return back()->with('success', 'Category order saved.');
        });
    }

    public function preview(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): JsonResponse
    {
        return response()->json($categories->affected($this->getActiveShop($request), $storeCategory));
    }

    public function destroy(Request $request, StoreCategory $storeCategory, StoreCategoryService $categories): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $storeCategory, $categories) {
            $data = $request->validate(['version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
            $categories->remove($this->getActiveShop($request), $storeCategory, $data['version']);

            return back()->with('success', 'Category deleted. Its products and other category memberships are kept.');
        });
    }
}
