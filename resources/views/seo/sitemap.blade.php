{!! '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($staticPages as $page)
    <url>
        <loc>{{ $page['url'] }}</loc>
        <lastmod>{{ $page['lastmod'] }}</lastmod>
        <changefreq>{{ $page['changefreq'] }}</changefreq>
        <priority>{{ $page['priority'] }}</priority>
    </url>
@endforeach

@foreach ($categories as $category)
    <url>
        <loc>{{ $baseUrl }}/buyer/catalog?category={{ $category->slug }}</loc>
        <lastmod>{{ ($category->updated_at ?? now())->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
@endforeach

@foreach ($shops as $shop)
    <url>
        <loc>{{ $baseUrl }}/shop/{{ $shop->slug }}</loc>
        <lastmod>{{ ($shop->updated_at ?? now())->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
@endforeach

@foreach ($products as $product)
    <url>
        <loc>{{ $baseUrl }}/buyer/product/{{ $product->slug }}</loc>
        <lastmod>{{ ($product->updated_at ?? now())->toAtomString() }}</lastmod>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
@endforeach
</urlset>
