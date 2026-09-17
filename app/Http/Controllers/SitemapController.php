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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($staticPages as $page) {
            $loc = htmlspecialchars($page['url'], ENT_XML1, 'UTF-8');
            $xml .= "    <url>\n";
            $xml .= "        <loc>{$loc}</loc>\n";
            $xml .= "        <lastmod>{$page['lastmod']}</lastmod>\n";
            $xml .= "        <changefreq>{$page['changefreq']}</changefreq>\n";
            $xml .= "        <priority>{$page['priority']}</priority>\n";
            $xml .= "    </url>\n";
        }

        foreach ($categories as $category) {
            $loc = htmlspecialchars("{$baseUrl}/buyer/catalog?category={$category->slug}", ENT_XML1, 'UTF-8');
            $lastmod = ($category->updated_at ?? now())->toAtomString();
            $xml .= "    <url>\n";
            $xml .= "        <loc>{$loc}</loc>\n";
            $xml .= "        <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "        <changefreq>weekly</changefreq>\n";
            $xml .= "        <priority>0.8</priority>\n";
            $xml .= "    </url>\n";
        }

        foreach ($shops as $shop) {
            $loc = htmlspecialchars("{$baseUrl}/shop/{$shop->slug}", ENT_XML1, 'UTF-8');
            $lastmod = ($shop->updated_at ?? now())->toAtomString();
            $xml .= "    <url>\n";
            $xml .= "        <loc>{$loc}</loc>\n";
            $xml .= "        <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "        <changefreq>weekly</changefreq>\n";
            $xml .= "        <priority>0.8</priority>\n";
            $xml .= "    </url>\n";
        }

        foreach ($products as $product) {
            $loc = htmlspecialchars("{$baseUrl}/buyer/product/{$product->slug}", ENT_XML1, 'UTF-8');
            $lastmod = ($product->updated_at ?? now())->toAtomString();
            $xml .= "    <url>\n";
            $xml .= "        <loc>{$loc}</loc>\n";
            $xml .= "        <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "        <changefreq>daily</changefreq>\n";
            $xml .= "        <priority>0.9</priority>\n";
            $xml .= "    </url>\n";
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
