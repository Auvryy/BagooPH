# Performance Plan Sources

Checked on October 16, 2026. These references support the proposed
[performance plan](README.md); they are not measured Bagoo performance results.

## Project evidence

| Source to inspect | Relevance |
|---|---|
| `resources/js/Components/ProductCard.tsx` | Shared product destination and current image loading behavior |
| `resources/js/Pages/Buyer/Home.tsx` | Marketplace feed, filters and paginator rendering |
| `resources/js/Pages/Buyer/Search.tsx` | Search grid, filters, related products and paginator rendering |
| `resources/js/Layouts/BuyerLayout.tsx` | Desktop/mobile search inputs, query state and submit behavior |
| `app/Http/Controllers/Buyer/BuyerHomeController.php` | Active home response and feed pagination |
| `app/Http/Controllers/Buyer/BuyerProductController.php` | Active search/detail response, owned Bag quantities and offers |
| `app/Services/Commerce/BuyerDiscoveryService.php` | Validated filters, literal wildcard escaping, deterministic ordering and page recovery |
| `app/Http/Middleware/HandleInertiaRequests.php` | Account/hub/shared props, feedback and private response headers |
| `app/Http/Controllers/ChatController.php` | Conversation read that also updates unread state; GET alone is not a safety classification |
| `app/Http/Controllers/NotificationController.php` | Notification preview, intentional acknowledgement and full-page boundaries |
| `tests/Feature/Buyer/BuyerDiscoveryAndSavedProductsTest.php` | Existing filter, search pagination, ownership and unavailable-product cases |
| `routes/web.php` | Resolve current route handlers and aliases before changing legacy page files |
| `package-lock.json` | Resolved Inertia adapter/core versions and existing frontend dependencies |

The installed adapter/core source was also inspected for hover triggering,
cache duration, cache tags and invalidation support. Dependency installation
directories are implementation evidence, not tracked project documentation or
an excuse to change versions during this work. Recheck actual versions when
implementation begins; use [TECH_STACK.md](../TECH_STACK.md) as supporting context.

## Official references

- [Inertia 2 prefetching](https://inertiajs.com/docs/v2/data-props/prefetching)
  documents intent-triggered page preparation, configurable retention and cache
  flushing. Bagoo's proposed delay, entry limit and role rules are project
  choices rather than framework defaults. Use v2 documentation for the current
  dependency; the site's unversioned URL now defaults to a newer major version.
- [Inertia 2 partial reloads](https://inertiajs.com/docs/v2/data-props/partial-reloads)
  describes same-component selective props and lazy server evaluation. Preserve
  coherent dependent props and distinguish a partial response from a full page.
- [MDN Network Information API](https://developer.mozilla.org/en-US/docs/Web/API/Network_Information_API)
  explains connection hints and their limited browser availability. Feature
  detection and an ordinary-navigation fallback remain necessary.

These sources explain mechanisms. Cache budgets, role choices, request
priorities, acceptance cases and measurement design are Bagoo-specific proposed
engineering decisions. No hardware-site identity, speed ranking or claimed
third-party production result is required to justify this plan.
