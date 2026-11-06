<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'BagooPH') }}</title>

        <!-- Primary SEO & Search Engine Directives -->
        <meta name="description" content="BagooPH is the premier Philippine e-commerce and logistics marketplace. Shop verified merchants with nationwide Cash on Delivery (COD) and real-time parcel tracking.">
        <meta name="keywords" content="BagooPH, e-commerce Philippines, online shopping PH, cash on delivery, COD, parcel tracking, logistics hub, merchant marketplace, buy online Philippines">
        <meta name="author" content="BagooPH">
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
        <link rel="canonical" href="{{ url()->current() }}">

        <!-- Google Site Verification (configured via GOOGLE_SITE_VERIFICATION in .env) -->
        @if(config('services.google.site_verification'))
        <meta name="google-site-verification" content="{{ config('services.google.site_verification') }}">
        @endif

        @php
            $previewProduct = data_get($page ?? [], 'props.product');
            $previewProductName = data_get($previewProduct, 'name');
            $previewProductDescription = trim(strip_tags((string) data_get($previewProduct, 'description', '')));
            $previewTitle = $previewProductName ? $previewProductName.' — BagooPH' : 'BagooPH — Philippine E-Commerce & On-Demand Logistics';
            $previewDescription = $previewProduct && $previewProductDescription !== ''
                ? \Illuminate\Support\Str::limit($previewProductDescription, 240)
                : 'Shop verified merchants with nationwide Cash on Delivery (COD) and real-time order tracking on BagooPH.';
            $previewImage = data_get($previewProduct, 'featured_image') ?: asset('bagoo.svg');
            $previewImageUrl = \Illuminate\Support\Str::startsWith($previewImage, ['http://', 'https://']) ? $previewImage : asset($previewImage);
        @endphp

        <!-- Open Graph / Facebook / Messenger / Viber Previews -->
        <meta property="og:type" content="{{ $previewProduct ? 'product' : 'website' }}">
        <meta property="og:site_name" content="BagooPH">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:title" content="{{ $previewTitle }}">
        <meta property="og:description" content="{{ $previewDescription }}">
        <meta property="og:image" content="{{ $previewImageUrl }}">
        <meta property="og:image:alt" content="{{ $previewProductName ? $previewProductName.' product image' : 'BagooPH marketplace' }}">
        <meta property="og:locale" content="en_PH">

        <!-- Twitter Card -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $previewTitle }}">
        <meta name="twitter:description" content="{{ $previewDescription }}">
        <meta name="twitter:image" content="{{ $previewImageUrl }}">

        <!-- JSON-LD Schema.org Structured Data for Google Rich Snippets -->
        <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'BagooPH',
            'url' => url('/'),
            'logo' => asset('bagoo.svg'),
            'description' => 'Philippine E-Commerce & On-Demand Logistics Marketplace with Cash on Delivery (COD).',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => url('/buyer/catalog') . '?search={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
        </script>

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="/bagoo.svg">

        <!-- Font: Plus Jakarta Sans across every BagooPH interface -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
