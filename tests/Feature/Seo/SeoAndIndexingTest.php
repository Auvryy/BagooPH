<?php

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoAndIndexingTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_txt_allows_public_crawling_and_restricts_private_routes(): void
    {
        $robotsPath = public_path('robots.txt');
        $this->assertFileExists($robotsPath);

        $content = file_get_contents($robotsPath);
        $this->assertStringContainsString('Sitemap: https://bagooph.shop/sitemap.xml', $content);
        $this->assertStringContainsString('Allow: /buyer/catalog', $content);
        $this->assertStringContainsString('Allow: /buyer/product/', $content);
        $this->assertStringContainsString('Disallow: /admin', $content);
        $this->assertStringContainsString('Disallow: /hub', $content);
        $this->assertStringContainsString('Disallow: /courier', $content);
        $this->assertStringContainsString('Disallow: /buyer/checkout', $content);
        $this->assertStringContainsString('Disallow: /buyer/profile', $content);
    }

    public function test_sitemap_xml_returns_valid_xml_with_core_pages_and_active_products(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Seo Test Merchant',
            'slug' => 'seo-test-merchant',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'Seo Category',
            'slug' => 'seo-category',
            'is_active' => true,
        ]);

        $activeProduct = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Active SEO Product',
            'slug' => 'active-seo-product',
            'price' => 299.00,
            'stock' => 15,
            'status' => 'active',
        ]);

        $inactiveProduct = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Inactive Hidden Product',
            'slug' => 'inactive-hidden-product',
            'price' => 199.00,
            'stock' => 0,
            'status' => 'inactive',
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));

        // Core static pages
        $response->assertSee('/buyer/catalog');
        $response->assertSee('/seller');
        $response->assertSee('/overview');
        $response->assertSee('/track');

        // Dynamic entities
        $response->assertSee('buyer/product/' . $activeProduct->slug);
        $response->assertSee('buyer/catalog?category=' . $category->slug);
        $response->assertSee('shop/' . $shop->slug);

        // Inactive product should NOT be in sitemap
        $response->assertDontSee('buyer/product/' . $inactiveProduct->slug);
    }

    public function test_app_blade_renders_default_seo_tags_and_structured_data(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('<meta property="og:title"', false);
        $response->assertSee('<meta name="twitter:card"', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('https://schema.org', false);
        $response->assertSee('Organization', false);
    }

    public function test_google_site_verification_meta_tag_renders_when_configured(): void
    {
        config(['services.google.site_verification' => 'test-google-code-xyz-9876']);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<meta name="google-site-verification" content="test-google-code-xyz-9876">', false);
    }
}
