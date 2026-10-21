<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — BagooPH Logistics</title>
    <link rel="icon" type="image/svg+xml" href="/bagoo.svg">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #0f172a; background: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; }
        main { min-height: 100vh; min-height: 100svh; display: flex; align-items: center; justify-content: center; padding: 40px 16px; }
        section { width: 100%; max-width: 512px; padding: 32px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 1px 3px #0f172a0d; }
        .brand { display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 700; }
        .brand img { width: 40px; height: 40px; border-radius: 8px; }
        .notice { display: block; width: 28px; height: 28px; margin-top: 32px; color: #E00D42; }
        h1 { margin: 16px 0 0; font-size: 24px; line-height: 1.4; letter-spacing: -.02em; }
        .description { margin: 12px 0 0; color: #475569; font-size: 14px; line-height: 1.75; }
        nav { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
        nav a { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 16px; border: 1px solid #cbd5e1; border-radius: 8px; color: #1e293b; text-decoration: none; font-size: 14px; font-weight: 600; }
        nav a:hover { background: #f8fafc; }
        nav a:focus-visible { outline: 2px solid #E00D42; outline-offset: 2px; }
        nav .primary { color: #fff; background: #E00D42; border-color: #E00D42; }
        nav .primary:hover { background: #C20836; }
        .reference { margin: 24px 0 0; padding-top: 16px; border-top: 1px solid #e2e8f0; overflow-wrap: anywhere; color: #64748b; font-size: 12px; line-height: 1.7; }
        @media (max-width: 480px) { section { padding: 24px; } nav { flex-direction: column; } }
    </style>
</head>
<body>
<main aria-labelledby="logistics-error-title">
    <section>
        <div class="brand"><img src="/bagoo.svg" alt=""><span>BagooPH Logistics</span></div>
        <svg class="notice" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3h.01"/></svg>
        <h1 id="logistics-error-title">{{ $title }}</h1>
        <p class="description">{{ $description }}</p>
        <nav aria-label="Page recovery">
            @if($loginUrl)<a href="{{ $loginUrl }}">Sign in again</a>@endif
            @if($retryUrl)<a href="{{ $retryUrl }}">Try again</a>@endif
            <a class="primary" href="{{ $overviewUrl }}">Open Overview</a>
        </nav>
        @if($requestId)<p class="reference">Reference: {{ $requestId }}</p>@endif
    </section>
</main>
</body>
</html>
