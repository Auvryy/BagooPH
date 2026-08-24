<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerProductController extends Controller
{
    /**
     * Dedicated Product Search & Filter Catalog Page
     */
    public function search(Request $request): Response
    {
        $query = Product::with(['shop', 'category'])
            ->where('status', 'active');

        // Search Query (Name, Description, SKU, Category, and Shop Name)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
                  ->orWhereHas('category', function ($catQ) use ($search) {
                      $catQ->where('name', 'ilike', "%{$search}%");
                  })
                  ->orWhereHas('shop', function ($shopQ) use ($search) {
                      $shopQ->where('name', 'ilike', "%{$search}%");
                  });
            });
        }

        // Category Filter
        if ($request->filled('category') && $request->input('category') !== 'all') {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->input('category'));
            });
        }

        // Price Filters
        if ($request->filled('min_price') && is_numeric($request->input('min_price'))) {
            $query->where('price', '>=', (float)$request->input('min_price'));
        }
        if ($request->filled('max_price') && is_numeric($request->input('max_price'))) {
            $query->where('price', '<=', (float)$request->input('max_price'));
        }

        // In Stock Filter
        if ($request->boolean('in_stock') || $request->input('in_stock') === '1') {
            $query->where('stock', '>', 0);
        }

        // Rating Filter (e.g. 4 stars and above)
        if ($request->filled('rating') && is_numeric($request->input('rating'))) {
            $query->where('rating', '>=', (float)$request->input('rating'));
        }

        // Sorting
        $sort = $request->input('sort', 'relevance');
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'top_sales':
                $query->orderBy('sales_count', 'desc');
                break;
            case 'top_rated':
                $query->orderBy('rating', 'desc');
                break;
            case 'newest':
                $query->latest();
                break;
            default:
                $query->orderBy('sales_count', 'desc')->orderBy('rating', 'desc');
                break;
        }

        $products = $query->paginate(24)->withQueryString();

        $categories = Category::where('is_active', true)
            ->withCount('products')
            ->orderBy('id')
            ->get();

        // Related / Recommended Products
        $matchedIds = $products->pluck('id')->toArray();
        $relatedQuery = Product::with(['shop', 'category'])
            ->where('status', 'active')
            ->whereNotIn('id', $matchedIds);

        if ($request->filled('category') && $request->input('category') !== 'all') {
            $relatedQuery->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->input('category'));
            });
        }

        $relatedProducts = $relatedQuery->orderBy('sales_count', 'desc')
            ->take(6)
            ->get();

        return Inertia::render('Buyer/Search', [
            'products' => $products,
            'categories' => $categories,
            'relatedProducts' => $relatedProducts,
            'filters' => [
                'search' => $request->input('search', ''),
                'category' => $request->input('category', 'all'),
                'sort' => $sort,
                'min_price' => $request->input('min_price', ''),
                'max_price' => $request->input('max_price', ''),
                'in_stock' => $request->boolean('in_stock'),
                'rating' => $request->input('rating', ''),
            ],
        ]);
    }
    public function show(string $slug): Response
    {
        $product = Product::with(['shop.user', 'category', 'images', 'reviews.buyer'])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        $relatedProducts = Product::with(['shop', 'category'])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where('status', 'active')
            ->take(6)
            ->get();

        // Sample product variations (Colorways & Sizes)
        $variations = [
            'colors' => [
                ['id' => 'c1', 'name' => 'Stealth Black', 'hex' => '#111111', 'in_stock' => true],
                ['id' => 'c2', 'name' => 'Crimson Red', 'hex' => '#E00D42', 'in_stock' => true],
                ['id' => 'c3', 'name' => 'Matte Grey', 'hex' => '#6B7280', 'in_stock' => true],
                ['id' => 'c4', 'name' => 'Chalk White', 'hex' => '#F3F4F6', 'in_stock' => $product->stock > 10],
            ],
            'sizes' => [
                ['id' => 's1', 'name' => 'Standard', 'extra_price' => 0, 'stock' => max(5, floor($product->stock * 0.6))],
                ['id' => 's2', 'name' => 'Pro / Extended', 'extra_price' => 350, 'stock' => max(2, floor($product->stock * 0.4))],
            ],
        ];

        // Store performance metrics
        $shopStats = [
            'rating' => $product->shop?->rating ?? 4.9,
            'products_count' => $product->shop ? Product::where('shop_id', $product->shop_id)->count() : 12,
            'response_rate' => '99%',
            'response_time' => 'within hours',
            'joined' => '1 year ago',
            'is_mall' => true,
        ];

        return Inertia::render('Buyer/ProductDetail', [
            'product' => $product,
            'variations' => $variations,
            'relatedProducts' => $relatedProducts,
            'shopStats' => $shopStats,
        ]);
    }
}
