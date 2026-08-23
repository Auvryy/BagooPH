<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerProductController extends Controller
{
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
