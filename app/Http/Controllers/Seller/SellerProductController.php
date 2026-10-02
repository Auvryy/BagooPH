<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Commerce\SellerSalesMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SellerProductController extends Controller
{
    use HasSellerShop;

    public function __construct(private readonly SellerSalesMetricsService $salesMetrics) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
        ]);
        $shop = $this->getActiveShop($request);
        $search = trim((string) ($validated['search'] ?? ''));
        $products = $this->salesMetrics
            ->withProductLifecycleTotals(Product::query()->where('shop_id', $shop->id))
            ->when($search !== '', function ($query) use ($search) {
                $pattern = "%{$search}%";
                $query->where(function ($productQuery) use ($pattern) {
                    $productQuery
                        ->whereLike('name', $pattern, caseSensitive: false)
                        ->orWhereLike('sku', $pattern, caseSensitive: false);
                });
            })
            ->with(['category', 'images'])
            ->latest('updated_at')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        // Enforce root category enclosure on product listing filter / category choices
        $categoriesQuery = Category::where('is_active', true);
        if ($shop->root_category_id) {
            $categoriesQuery->where(function ($q) use ($shop) {
                $q->where('id', $shop->root_category_id)
                    ->orWhere('parent_id', $shop->root_category_id);
            });
        }
        $categories = $categoriesQuery->get();

        return Inertia::render('Seller/Products', [
            'products' => $products,
            'categories' => $categories,
            'shop' => $shop,
            'availableShops' => $this->getAvailableShops($request),
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $shop = $this->getActiveShop($request);

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
            'variants' => 'nullable',
        ], [
            'compare_at_price.gt' => 'The slashed price must be higher than the regular selling price.',
            'image_files.*.max' => 'Each product image must not exceed 5MB.',
            'image_files.*.mimes' => 'Images must be in PNG, JPG, JPEG, WEBP, or GIF format.',
        ]);

        // Enforce single root category enclosure
        if ($shop->root_category_id) {
            $allowedCategoryIds = Category::where('id', $shop->root_category_id)
                ->orWhere('parent_id', $shop->root_category_id)
                ->pluck('id')
                ->all();

            $chosenCategoryId = $validated['category_id'] ?? $shop->root_category_id;
            if (! in_array((int) $chosenCategoryId, $allowedCategoryIds)) {
                return back()->withErrors([
                    'category_id' => "This shop profile is restricted to the '{$shop->rootCategory?->name}' category enclosure. Switch store profiles to list items under other categories.",
                ]);
            }
            $validated['category_id'] = $chosenCategoryId;
        }

        $defaultFallback = '/storage/products/placeholder.webp';
        $slug = Product::generateUniqueSlug($validated['name']);

        // Auto-generate SKU if left blank by the seller
        $cleanPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $validated['name']), 0, 4) ?: 'PROD');
        $validated['sku'] = ! empty($validated['sku'])
            ? trim($validated['sku'])
            : 'BGO-'.$cleanPrefix.'-'.strtoupper(Str::random(5));

        $validated['variants'] = $this->sanitizeVariants($request, (int) $validated['stock']);

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
        $shop = $this->getActiveShop($request);
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
            'variants' => 'nullable',
            'status' => 'required|in:active,draft,archived',
        ], [
            'compare_at_price.gt' => 'The slashed price must be higher than the regular selling price.',
            'image_files.*.max' => 'Each product image must not exceed 5MB.',
            'image_files.*.mimes' => 'Images must be in PNG, JPG, JPEG, WEBP, or GIF format.',
        ]);

        // Enforce single root category enclosure
        if ($shop->root_category_id) {
            $allowedCategoryIds = Category::where('id', $shop->root_category_id)
                ->orWhere('parent_id', $shop->root_category_id)
                ->pluck('id')
                ->all();

            $chosenCategoryId = $validated['category_id'] ?? $shop->root_category_id;
            if (! in_array((int) $chosenCategoryId, $allowedCategoryIds)) {
                return back()->withErrors([
                    'category_id' => "This shop profile is restricted to the '{$shop->rootCategory?->name}' category enclosure. Switch store profiles to list items under other categories.",
                ]);
            }
            $validated['category_id'] = $chosenCategoryId;
        }

        // Auto-generate SKU if left blank or reset
        $cleanPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $validated['name']), 0, 4) ?: 'PROD');
        $validated['sku'] = ! empty($validated['sku'])
            ? trim($validated['sku'])
            : ($product->sku ?: 'BGO-'.$cleanPrefix.'-'.strtoupper(Str::random(5)));

        if ($request->has('variants')) {
            $validated['variants'] = $this->sanitizeVariants($request, (int) $validated['stock']);
        }

        unset($validated['image_file'], $validated['image_files'], $validated['gallery_manifest']);

        $product->update($validated);

        $this->syncProductImages($product, $request);

        return back()->with('success', 'Product updated successfully.');
    }

    /**
     * Sanitize and normalize dynamic product variations payload.
     */
    private function sanitizeVariants(Request $request, int $fallbackStock): ?array
    {
        if (! $request->has('variants')) {
            return null;
        }

        $variantsRaw = $request->input('variants');
        if (is_string($variantsRaw)) {
            $variantsRaw = json_decode($variantsRaw, true);
        }

        if (! is_array($variantsRaw)) {
            return null;
        }

        $colors = [];
        if (! empty($variantsRaw['colors']) && is_array($variantsRaw['colors'])) {
            foreach ($variantsRaw['colors'] as $idx => $color) {
                if (is_array($color) && ! empty($color['name'])) {
                    $imgUrl = ! empty($color['image_url']) ? trim($color['image_url']) : null;
                    if ($imgUrl && str_starts_with($imgUrl, 'blob:')) {
                        $imgUrl = null;
                    }
                    $colors[] = [
                        'id' => (string) ($color['id'] ?? ('c_'.($idx + 1))),
                        'name' => trim($color['name']),
                        'hex' => ! empty($color['hex']) ? trim($color['hex']) : '#111111',
                        'image_url' => $imgUrl,
                        'gallery_index' => isset($color['gallery_index']) && is_numeric($color['gallery_index']) ? (int) $color['gallery_index'] : null,
                        'in_stock' => isset($color['in_stock']) ? (bool) $color['in_stock'] : true,
                    ];
                }
            }
        }

        $sizes = [];
        if (! empty($variantsRaw['sizes']) && is_array($variantsRaw['sizes'])) {
            foreach ($variantsRaw['sizes'] as $idx => $size) {
                if (is_array($size) && ! empty($size['name'])) {
                    $sizes[] = [
                        'id' => (string) ($size['id'] ?? ('s_'.($idx + 1))),
                        'name' => trim($size['name']),
                        'extra_price' => isset($size['extra_price']) ? max(0, (float) $size['extra_price']) : 0,
                        'stock' => isset($size['stock']) ? max(0, (int) $size['stock']) : $fallbackStock,
                    ];
                }
            }
        }

        if (empty($colors) && empty($sizes)) {
            return null;
        }

        $option1Name = ! empty($variantsRaw['option1_name']) ? trim((string) $variantsRaw['option1_name']) : (! empty($colors) ? 'Color / Edition' : null);
        $option2Name = ! empty($variantsRaw['option2_name']) ? trim((string) $variantsRaw['option2_name']) : (! empty($sizes) ? 'Specification / Size' : null);

        return [
            'option1_name' => $option1Name,
            'option2_name' => $option2Name,
            'colors' => $colors,
            'sizes' => $sizes,
        ];
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
                        $orderedUrls[] = '/storage/'.$path;
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
                $orderedUrls[] = '/storage/'.$path;
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
        } else {
            $orderedUrls = ProductImage::where('product_id', $product->id)
                ->orderBy('sort_order')
                ->pluck('image_url')
                ->toArray();
        }

        // Map variant colors to final persisted gallery URLs
        if (! empty($product->variants) && is_array($product->variants)) {
            $variants = $product->variants;
            $updated = false;

            if (! empty($variants['colors']) && is_array($variants['colors'])) {
                foreach ($variants['colors'] as &$c) {
                    if (isset($c['gallery_index']) && is_numeric($c['gallery_index'])) {
                        $galleryIdx = (int) $c['gallery_index'];
                        if (isset($orderedUrls[$galleryIdx])) {
                            $c['image_url'] = $orderedUrls[$galleryIdx];
                            $updated = true;
                        }
                    } elseif (! empty($c['image_url']) && str_starts_with($c['image_url'], 'blob:')) {
                        $c['image_url'] = null;
                        $updated = true;
                    }
                }
                unset($c);
            }

            if ($updated) {
                $product->update(['variants' => $variants]);
            }
        }
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getActiveShop($request);
        if ($product->shop_id && $product->shop_id !== $shop->id && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized product deletion.');
        }

        $product->delete();

        return back()->with('success', 'Product deleted.');
    }
}
