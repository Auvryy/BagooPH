<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSale;
use App\Services\Commerce\ProductSaleService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProductSaleController extends Controller
{
    use HasSellerShop;

    public function index(Request $request, Product $product)
    {
        $shop = $this->getActiveShop($request);
        abort_unless($product->shop_id === $shop->id, 403);

        return Inertia::render('Seller/ProductSales', ['shop' => $shop, 'product' => $product,
            'sales' => $product->sales()->orderByDesc('starts_at')->orderByDesc('id')->paginate(20)->withPath($request->getPathInfo())]);
    }

    private function mutate(Request $request, Product $product, \Closure $work, bool $requireActive = true)
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $product, $work, $requireActive) {
            $shop = $this->getActiveShop($request);
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless($product->shop_id === $shop->id, 403);
            if ($requireActive && ! app(ShopEligibilityService::class)->productIsEligible($product)) {
                throw ValidationException::withMessages(['sale' => 'Publish an approved, active product before scheduling a sale.']);
            }
            $work($product);

            return back()->with('success', 'Sale settings saved. Existing orders keep their original prices.');
        });
    }

    public function store(Request $request, Product $product, ProductSaleService $service)
    {
        return $this->mutate($request, $product, fn ($locked) => $service->save($locked, $request->all()));
    }

    public function update(Request $request, Product $product, ProductSale $sale, ProductSaleService $service)
    {
        return $this->mutate($request, $product, fn ($locked) => $service->save($locked, $request->all(), $sale));
    }

    public function end(Request $request, Product $product, ProductSale $sale, ProductSaleService $service)
    {
        return $this->mutate($request, $product, fn ($locked) => $service->end($locked, $sale), requireActive: false);
    }
}
