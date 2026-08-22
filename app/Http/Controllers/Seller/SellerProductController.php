<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SellerProductController extends Controller
{
    private function getShop(Request $request): Shop
    {
        return Shop::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'name' => $request->user()->name . "'s Store",
                'slug' => Str::slug($request->user()->name . '-store-' . $request->user()->id),
                'status' => 'active',
            ]
        );
    }

    public function index(Request $request): Response
    {
        $shop = $this->getShop($request);
        $products = Product::where('shop_id', $shop->id)
            ->with('category')
            ->latest()
            ->paginate(10);

        $categories = Category::where('is_active', true)->get();

        return Inertia::render('Seller/Products', [
            'products' => $products,
            'categories' => $categories,
            'shop' => $shop,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $shop = $this->getShop($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'price' => 'required|numeric|min:0.01',
            'compare_at_price' => 'nullable|numeric|gt:price',
            'stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|max:50',
            'description' => 'required|string',
            'featured_image' => 'nullable|string|url',
        ]);

        $slug = Str::slug($validated['name']) . '-' . rand(1000, 9999);

        $product = Product::create([
            ...$validated,
            'shop_id' => $shop->id,
            'slug' => $slug,
            'featured_image' => $validated['featured_image'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80',
            'status' => 'active',
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => $product->featured_image,
            'is_primary' => true,
        ]);

        return back()->with('success', 'Product created successfully.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getShop($request);
        if ($product->shop_id !== $shop->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'price' => 'required|numeric|min:0.01',
            'compare_at_price' => 'nullable|numeric',
            'stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|max:50',
            'description' => 'required|string',
            'featured_image' => 'nullable|string|url',
            'status' => 'required|in:active,draft,archived',
        ]);

        $product->update($validated);

        return back()->with('success', 'Product updated successfully.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getShop($request);
        if ($product->shop_id !== $shop->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $product->delete();
        return back()->with('success', 'Product deleted.');
    }
}
