# PF01 Browsing Acceptance and Deployment Checks

Use this guide after publishing and deploying a reviewed PF01 branch. Current
local test results, implementation scope and ratings belong in
[the roadmap](../CORE_FLOW_ROADMAP.md#october-16-2026-pf01-buyer-pagination-and-responsive-search).
These manual checks supplement automated evidence; they are not a statement
that Azure or a device has already passed.

## Configuration

`CATALOGUE_PREFETCH_ENABLED` controls the reviewed buyer catalogue preparation.
Its default is `true` in `config/catalogue.php`; `.env.example` includes the
setting. Set it to `false` and apply the normal deployment/config-cache refresh
to disable speculative data loading. The next ordinary response communicates
the setting to the client and clears its prepared-page state. Existing loaded
tabs need an ordinary request or reload to receive changed server configuration.

Normal product links, pagination, search submission and server validation remain
available with the flag disabled. The flag does not disable the existing Vite
asset-prefetch feature or browser caching of static files. The one-request
budget applies to speculative catalogue page data, not every asset/notification
request in the application.

Use the normal [deployment workflow](../WEB_AUTH_DEPLOYMENT.md). PF01 introduces
no schema migration or catalogue reseeding requirement. Do not reset a database
to check pagination; use enough existing eligible products or isolated fixtures.

## Manual checks

| Check | Expected result |
|---|---|
| Home pagination | With more than 18 eligible products, page 2 shows different products, the correct active number/range and a visible updated results region. Previous/Next and numbered links retain filters. Disabled controls cannot navigate. |
| Search pagination | With more than 24 matches, the next search page shows the next records. Category, sort, minimum zero, other price limits, rating and stock filters survive. |
| Origins and history | Local aliases and the configured HTTPS portal keep the current origin. Back/Forward restores the visible search/filter values. An excessive page number resolves to the last page and a matching client URL/indicator. |
| Header typing off the results page | Pause after at least two characters. Preparation does not navigate away. Enter/Search opens the exact latest query; a one-character explicit search remains allowed. |
| Live results | Typing on Search updates the results after a short pause without dropping focus. Older results cannot overwrite a newer query. Clearing removes only the search query and resets paging; other selected filters remain. |
| Input boundaries | Paste/composition work, the input stops at its 100-character limit and direct invalid submissions retain server validation. Network trouble is distinct from an invalid search value. |
| Intent and idle | Hover or keyboard focus prepares only the intended supported page after a brief delay. Passing quickly across cards does not start a request per card. Visible paging controls may prepare one next page after inactivity, when connection hints permit it. |
| Touch | Taps navigate normally and scrolling over a product is not prevented. There is no simulated desktop hover requirement on a phone. |
| Slow or disconnected client | Hidden/offline/Save-Data/very slow connections skip speculation. A failed actual catalogue navigation has a visible, dismissible Try again message for that destination. Typing does not keep sending automatic searches while offline. |
| Interrupted loading | A newer query or destination cancels obsolete waiting work. Delayed page-script loading must not cause an abandoned destination to open later. A failed preparation falls back to an ordinary request. |
| Account boundaries | Sign-out/in, another account, returning to a tab and supported cross-tab invalidation discard prepared state. A previous account's owned Bag quantities/offers are never reused for the new account. |
| Feedback and activity | Background requests do not consume success/errors, read messages/notifications, submit forms, record advertisement activity, or mutate orders/custody/cash. A real visit still displays its pending feedback. |
| Fresh purchasing | Bag changes invalidate relevant prepared state. Checkout still checks current price, stock, eligibility and offers; preparation reserves nothing. |
| Disable path | Turn the flag off through the normal reviewed deployment/config refresh. Ordinary browsing works and no new speculative catalogue data requests start after the client receives the setting. |

When reviewing requests, distinguish page-data preparation from the existing
static-asset prefetching. Record route/query/page, foreground versus speculative
request count, cache use, transferred data and timing. Never log credentials,
private evidence, bearer tokens or the contents of owned page responses.

Run authorized browser/device checks only when requested. Keep unperformed
checks visible and do not turn a local build, mocked transport test or SQLite
result into a claim about production latency, mobile Flutter or PostgreSQL
concurrency. The later [PF05 measurement batch](05-performance-and-release.md)
defines comparable samples and the remaining role-wide acceptance.
