<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Rules\AsciiPositiveInteger;
use App\Services\Commerce\ProductGalleryInput;
use App\Services\Commerce\SellerInventoryService;
use App\Services\Commerce\SellerListingActions;
use App\Services\Commerce\SellerListingInput;
use App\Services\Commerce\SellerListingSubmission;
use App\Services\Commerce\SellerSalesMetricsService;
use App\Services\Commerce\StoreCategoryService;
use App\Services\Commerce\VariantMatrixService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SellerProductController extends Controller
{
    use HasSellerShop;

    public function __construct(
        private readonly SellerSalesMetricsService $salesMetrics,
        private readonly SellerInventoryService $inventory,
    ) {}

    public function index(Request $request): Response
    {
        $shop = $this->getActiveShop($request);
        $filters = $this->inventory->selection($request, $shop);
        $loadProducts = function () use ($shop, $filters) {
            $query = $this->salesMetrics
                ->withProductLifecycleTotals(Product::query()->where('shop_id', $shop->id))
                ->withCount('orderItems')
                ->with(['category', 'images', 'sales', 'storeCategories', 'moderationDecisions' => fn ($decisions) => $decisions->latest('id')->limit(1)]);
            $products = $this->inventory->paginate($this->inventory->filter($query, $filters), $filters);

            $products->through(function (Product $product) {
                $decision = $product->moderationDecisions->first();
                $product->setAttribute('compliance_feedback', $decision ? [
                    'action' => $decision->action, 'reason' => $decision->reason,
                    'decided_at' => $decision->decided_at->toISOString(),
                    'next_action' => $product->compliance_restricted
                        ? 'Correct the listing details and ask Platform Admin to review reinstatement. Editing or publishing alone cannot lift the restriction.'
                        : 'Normal seller listing and shop eligibility still apply.',
                ] : null);

                return $product;
            });
            $products->getCollection()->each->unsetRelation('moderationDecisions');

            return $products;
        };

        return Inertia::render('Seller/Products', [
            'products' => $loadProducts,
            'categories' => fn () => Category::whereIn('id', app(ShopEligibilityService::class)->categoryIds($shop))->get(),
            'shop' => $shop,
            'filters' => $filters,
            'storeCategories' => fn () => app(StoreCategoryService::class)->rows($shop),
            'copiedProduct' => fn () => Product::where('shop_id', $shop->id)->where('status', 'draft')
                ->with(['images', 'storeCategories'])->find($request->session()->get('listing_copy_id')),
            'bulkResults' => fn () => $request->session()->get('listing_bulk_results', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request) {
            $shop = $this->getActiveShop($request);
            $product = app(SellerListingSubmission::class)->run($request, $shop, 'create', fn () => $this->createListing($request));

            return back()->with('success', $product->status === 'draft' ? 'Product draft saved.' : 'Product published successfully.');
        });
    }

    private function createListing(Request $request): Product
    {
        $shop = $this->getActiveShop($request);

        $validated = app(SellerListingInput::class)->validate($request, $shop);

        $storeCategoryIds = app(StoreCategoryService::class)->membershipIds($shop, $request->input('store_category_ids', []));

        $defaultFallback = '/storage/products/placeholder.webp';
        $slug = Product::generateUniqueSlug($validated['name']);

        // Auto-generate SKU if left blank by the seller
        $cleanPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $validated['name']), 0, 4) ?: 'PROD');
        $validated['sku'] = isset($validated['sku']) && trim($validated['sku']) !== ''
            ? trim($validated['sku'])
            : 'BGO-'.$cleanPrefix.'-'.strtoupper(Str::random(5));

        $validated['variants'] = $this->sanitizeVariants($request, (int) $validated['stock']);
        if (isset($validated['variants']['combinations'])) {
            $validated['stock'] = array_sum(array_column($validated['variants']['combinations'], 'stock'));
        }

        unset($validated['image_file'], $validated['image_files'], $validated['gallery_manifest']);

        $product = Product::create([
            ...$validated,
            'shop_id' => $shop->id,
            'slug' => $slug,
            'featured_image' => $defaultFallback,
            'status' => $validated['status'] ?? 'active',
        ]);

        $product->storeCategories()->sync($storeCategoryIds);
        $this->syncProductImages($product, $request, $defaultFallback);

        return $product;
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, fn () => $this->updateInShop($request, $product));
    }

    private function updateInShop(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getActiveShop($request);
        $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
        if ($product->shop_id !== $shop->id) {
            abort(403, 'Unauthorized product modification.');
        }

        $validated = app(SellerListingInput::class)->validate($request, $shop, $product);

        // Auto-generate SKU if left blank or reset
        $cleanPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $validated['name']), 0, 4) ?: 'PROD');
        $validated['sku'] = isset($validated['sku']) && trim($validated['sku']) !== ''
            ? trim($validated['sku'])
            : ($product->sku !== null && $product->sku !== '' ? $product->sku : 'BGO-'.$cleanPrefix.'-'.strtoupper(Str::random(5)));

        if ($request->has('variants')) {
            $validated['variants'] = $this->sanitizeVariants($request, (int) $validated['stock']);
            app(VariantMatrixService::class)->assertRestorable($product, $validated['variants']);
        }
        $effectiveVariants = array_key_exists('variants', $validated) ? $validated['variants'] : $product->variants;
        if (isset($effectiveVariants['combinations'])) {
            $validated['stock'] = array_sum(array_column($effectiveVariants['combinations'], 'stock'));
        }

        unset($validated['image_file'], $validated['image_files'], $validated['gallery_manifest']);

        $storeCategoryIds = app(StoreCategoryService::class)->membershipIds($shop, $request->input('store_category_ids', $product->storeCategories()->pluck('store_categories.id')->all()));
        $product->update($validated);
        $product->storeCategories()->sync($storeCategoryIds);

        $this->syncProductImages($product, $request);

        return back()->with('success', 'Product updated successfully.');
    }

    public function updateStock(Request $request, Product $product): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, fn () => $this->updateStockInShop($request, $product));
    }

    private function updateStockInShop(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getActiveShop($request);
        $validated = $request->validate([
            'mode' => 'required|in:set,add',
            'quantity' => 'required|integer|min:0|max:99999',
        ]);

        if ($validated['mode'] === 'add' && (int) $validated['quantity'] < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Enter at least 1 unit to add to stock.',
            ]);
        }

        $newStock = DB::transaction(function () use ($product, $shop, $validated): int {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($lockedProduct->shop_id !== $shop->id) {
                abort(403, 'Unauthorized stock modification.');
            }

            if ($lockedProduct->category_id === null) {
                throw ValidationException::withMessages(['category_id' => 'This product needs an approved category before stock can be changed.']);
            }
            app(ShopEligibilityService::class)->categoryFor($shop, $lockedProduct->category_id);
            if (isset($lockedProduct->variants['combinations'])) {
                throw ValidationException::withMessages(['quantity' => 'Edit the product and update each option combination. Combined stock is calculated from those rows.']);
            }
            $newStock = $validated['mode'] === 'add'
                ? $lockedProduct->stock + (int) $validated['quantity']
                : (int) $validated['quantity'];

            if ($newStock > 99999) {
                throw ValidationException::withMessages([
                    'quantity' => 'Available stock cannot exceed 99,999 units.',
                ]);
            }

            $lockedProduct->update(['stock' => $newStock]);

            return $newStock;
        });

        return back()->with('success', "Available stock updated to {$newStock} units.");
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
        if ($variantsRaw === null || $variantsRaw === '') {
            return null;
        }
        if (is_string($variantsRaw)) {
            $variantsRaw = json_decode($variantsRaw, true);
        }

        if (! is_array($variantsRaw)) {
            throw ValidationException::withMessages(['variants' => 'Enter valid product options before saving.']);
        }
        if (array_diff(array_keys($variantsRaw), ['colors', 'sizes', 'option1_name', 'option2_name', 'combinations', 'pricing_mode']) !== []) {
            throw ValidationException::withMessages(['variants' => 'Use no more than two option groups. Remove unsupported option fields before saving.']);
        }

        foreach (['colors', 'sizes'] as $group) {
            if (isset($variantsRaw[$group]) && is_array($variantsRaw[$group])) {
                foreach ($variantsRaw[$group] as &$option) {
                    if (is_array($option) && isset($option['name']) && is_string($option['name'])) {
                        $option['name'] = trim($option['name']);
                    }
                }
                unset($option);
            }
        }
        Validator::make($variantsRaw, [
            'option3_name' => ['prohibited'],
            'pricing_mode' => ['sometimes', Rule::in(['surcharge', 'independent'])],
            'colors' => ['sometimes', 'array', 'list', 'max:20'],
            'colors.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'sizes' => ['sometimes', 'array', 'list', 'max:20'],
            'sizes.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'sizes.*.stock' => ['sometimes', 'nullable', 'regex:/\A[0-9]+\z/', 'integer', 'between:0,99999'],
            'sizes.*.extra_price' => ['sometimes', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0,99999999.99'],
            'option1_name' => ['nullable', 'string', 'max:100'],
            'option2_name' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $colors = [];
        if (! empty($variantsRaw['colors']) && is_array($variantsRaw['colors'])) {
            foreach ($variantsRaw['colors'] as $idx => $color) {
                if (is_array($color) && isset($color['name']) && $color['name'] !== '') {
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
                if (is_array($size) && isset($size['name']) && $size['name'] !== '') {
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
            if (isset($variantsRaw['combinations'])) {
                throw ValidationException::withMessages(['variants' => 'Add an option group before configuring combination stock.']);
            }

            return null;
        }

        $option1Name = ! empty($variantsRaw['option1_name']) ? trim((string) $variantsRaw['option1_name']) : (! empty($colors) ? 'Color / Edition' : null);
        $option2Name = ! empty($variantsRaw['option2_name']) ? trim((string) $variantsRaw['option2_name']) : (! empty($sizes) ? 'Specification / Size' : null);

        $matrix = app(VariantMatrixService::class)->validate($variantsRaw, $colors, $sizes);

        return [
            ...($matrix !== null ? ['combinations' => $matrix] : []),
            ...($matrix !== null && ($variantsRaw['pricing_mode'] ?? null) === 'independent' ? ['pricing_mode' => 'independent'] : []),
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
                        $path = app(ProductGalleryInput::class)->store($file);
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
                $path = app(ProductGalleryInput::class)->store($request->file('image_file'));
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
        return app(ShopEligibilityService::class)->mutate($request, fn () => $this->destroyInShop($request, $product));
    }

    private function destroyInShop(Request $request, Product $product): RedirectResponse
    {
        $shop = $this->getActiveShop($request);
        $actions = app(SellerListingActions::class);
        $result = $actions->remove($actions->owned($shop, $product->id));

        return back()->with('success', $result === 'archived' ? 'Product archived to preserve its references and history.' : 'Product deleted.');
    }

    public function duplicate(Request $request, Product $product): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $product) {
            $shop = $this->getActiveShop($request);
            $actions = app(SellerListingActions::class);
            $owned = $actions->owned($shop, $product->id);
            $copy = app(SellerListingSubmission::class)->run($request, $shop, 'duplicate:'.$owned->id,
                fn () => $actions->duplicate($shop, $owned));

            return back()->with('success', 'Product copied as a private draft.')->with('listing_copy_id', $copy->id);
        });
    }

    public function bulk(Request $request): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request) {
            $shop = $this->getActiveShop($request);
            $data = $request->validate([
                'action' => ['required', Rule::in(['deactivate', 'delete'])],
                'product_ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
                'product_ids.*' => ['bail', new AsciiPositiveInteger, 'integer', 'distinct'],
            ]);
            $results = app(SellerListingActions::class)->bulk($shop, $data['product_ids'], $data['action']);

            return back()->with('success', 'Product selection processed. Review each result below.')->with('listing_bulk_results', $results);
        });
    }
}
