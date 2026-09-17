<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Generate dynamic XML sitemap for search engine indexing.
     */
    public function index(Request $request): Response
    {
        $baseUrl = rtrim(url('/'), '/');
        if (empty($baseUrl) || $baseUrl === 'http://:' || $baseUrl === 'http://') {
            $baseUrl = rtrim(config('app.url', 'https://bagooph.shop'), '/');
        }

        $now = now()->toAtomString();

        $staticPages = [
            [
                'url' => "{$baseUrl}/",
                'lastmod' => $now,
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'url' => "{$baseUrl}/buyer/catalog",
                'lastmod' => $now,
                'changefreq' => 'daily',
                'priority' => '0.9',
            ],
            [
                'url' => "{$baseUrl}/seller",
                'lastmod' => $now,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ],
            [
                'url' => "{$baseUrl}/overview",
                'lastmod' => $now,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ],
            [
                'url' => "{$baseUrl}/about",
                'lastmod' => $now,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ],
            [
                'url' => "{$baseUrl}/track",
                'lastmod' => $now,
                'changefreq' => 'daily',
                'priority' => '0.7',
            ],
        ];

        // Active published products
        $products = Product::where('status', 'active')
            ->whereNotNull('slug')
            ->select(['id', 'slug', 'updated_at'])
            ->latest('updated_at')
            ->take(5000)
            ->get();

        // Active categories
        $categories = Category::where('is_active', true)
            ->whereNotNull('slug')
            ->select(['id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        // Active registered shops
        $shops = Shop::where('status', 'active')
            ->whereNotNull('slug')
            ->select(['id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        $xml = view('seo.sitemap', [
            'staticPages' => $staticPages,
            'categories' => $categories,
            'shops' => $shops,
            'products' => $products,
            'baseUrl' => $baseUrl,
        ])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
