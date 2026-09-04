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
            ->with(['category', 'images'])
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
            'featured_image' => 'nullable|string',
            'image_file' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'image_files' => 'nullable|array',
            'image_files.*' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'gallery_manifest' => 'nullable|string',
        ], [
            'compare_at_price.gt' => 'The slashed price must be higher than the regular selling price.',
            'image_files.*.max' => 'Each product image must not exceed 5MB.',
            'image_files.*.mimes' => 'Images must be in PNG, JPG, JPEG, WEBP, or GIF format.',
        ]);

        $defaultFallback = 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80';
        $slug = Str::slug($validated['name']) . '-' . rand(1000, 9999);

        unset($validated['image_file'], $validated['image_files'], $validated['gallery_manifest']);

        $product = Product::create([
            ...$validated,
            'shop_id' => $shop->id,
            'slug' => $slug,
            'featured_image' => $defaultFallback,
            'status' => 'active',
        ]);

        $this->syncProductImages($product, $request, $defaultFallback);

        return back()->with('success', 'Product created successfully.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getShop($request);
        if ($product->shop_id && $product->shop_id !== $shop->id && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized product modification.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'price' => 'required|numeric|min:0.01',
            'compare_at_price' => 'nullable|numeric|gt:price',
            'stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|max:50',
            'description' => 'required|string',
            'featured_image' => 'nullable|string',
            'image_file' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'image_files' => 'nullable|array',
            'image_files.*' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'gallery_manifest' => 'nullable|string',
            'status' => 'required|in:active,draft,archived',
        ], [
            'compare_at_price.gt' => 'The slashed price must be higher than the regular selling price.',
            'image_files.*.max' => 'Each product image must not exceed 5MB.',
            'image_files.*.mimes' => 'Images must be in PNG, JPG, JPEG, WEBP, or GIF format.',
        ]);

        unset($validated['image_file'], $validated['image_files'], $validated['gallery_manifest']);

        $product->update($validated);

        $this->syncProductImages($product, $request);

        return back()->with('success', 'Product updated successfully.');
    }

    /**
     * Resolve and sync gallery images for a product.
     * Supports both ordered multi-image gallery manifests and legacy single-image payloads.
     */
    private function syncProductImages(Product $product, Request $request, ?string $fallbackUrl = null): void
    {
        $orderedUrls = [];

        if ($request->filled('gallery_manifest')) {
            $manifest = json_decode($request->input('gallery_manifest'), true);
            if (is_array($manifest)) {
                $uploadedFiles = $request->file('image_files', []);

                foreach ($manifest as $item) {
                    $type = $item['type'] ?? 'url';

                    if ($type === 'file' && isset($item['file_index']) && isset($uploadedFiles[$item['file_index']])) {
                        $file = $uploadedFiles[$item['file_index']];
                        $path = $file->store('products', 'public');
                        $orderedUrls[] = '/storage/' . $path;
                    } elseif (($type === 'existing' || $type === 'url') && ! empty($item['url'])) {
                        $orderedUrls[] = $item['url'];
                    }
                }
            }
        }

        // Fallback to legacy single file or single url if manifest was empty
        if (empty($orderedUrls)) {
            if ($request->hasFile('image_file')) {
                $path = $request->file('image_file')->store('products', 'public');
                $orderedUrls[] = '/storage/' . $path;
            } elseif ($request->filled('featured_image')) {
                $orderedUrls[] = $request->input('featured_image');
            } elseif ($fallbackUrl) {
                $orderedUrls[] = $fallbackUrl;
            } elseif ($product->featured_image) {
                $orderedUrls[] = $product->featured_image;
            }
        }

        if (! empty($orderedUrls)) {
            $primaryUrl = $orderedUrls[0];
            $product->update(['featured_image' => $primaryUrl]);

            ProductImage::where('product_id', $product->id)->delete();

            foreach ($orderedUrls as $index => $url) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_url' => $url,
                    'is_primary' => ($index === 0),
                    'sort_order' => $index,
                ]);
            }
        }
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getShop($request);
        if ($product->shop_id && $product->shop_id !== $shop->id && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized product deletion.');
        }

        $product->delete();
        return back()->with('success', 'Product deleted.');
    }
}
