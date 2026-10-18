# Browsing and Portal Navigation Acceptance

Use this guide after publishing and deploying a reviewed PF01 or PF02 branch. Current
local test results, implementation scope and ratings belong in
[the roadmap](../CORE_FLOW_ROADMAP.md#october-16-2026-pf01-buyer-pagination-and-responsive-search).
These manual checks supplement automated evidence; they are not a statement
that Azure or a device has already passed.

## Configuration

`CATALOGUE_PREFETCH_ENABLED` controls the reviewed buyer catalogue preparation
and PF02 buyer/seller page-code preparation.
Its default is `true` in `config/catalogue.php`; `.env.example` includes the
setting. Set it to `false` and apply the normal deployment/config-cache refresh
to disable speculative data loading. The next ordinary response communicates
the setting to the client and clears its prepared-page state. Existing loaded
tabs need an ordinary request or reload to receive changed server configuration.

Normal product links, pagination, search submission and server validation remain
available with the flag disabled. The flag does not disable the existing Vite
asset-prefetch feature or browser caching of static files. The one-request
budget applies to speculative catalogue page data. PF02 shares that coordinator
with one reviewed page-code preparation at a time; an import can load dependent
static chunks. This is not a limit on every asset/notification request.

Use the normal [deployment workflow](../WEB_AUTH_DEPLOYMENT.md). PF01 and PF02 introduce
no schema migration or catalogue reseeding requirement. Do not reset a database
to check pagination; use enough existing eligible products or isolated fixtures.

## Marketplace checks

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

## Buyer and seller navigation

PF02 prepares static page code for reviewed owned destinations. It keeps owned
page responses out of the speculative data cache because those requests need
current authorization, mutable records and, for some pages, sensitive settings
or generated action references. The public catalogue keeps its PF01 policy.
The [PF02 roadmap entry](../CORE_FLOW_ROADMAP.md#october-18-2026-pf02-buyer-and-seller-navigation)
records the actual source audit, verification and scoped assessment.

| Check | Expected result |
|---|---|
| Buyer intent | From the buyer header/profile menu, deliberate hover or keyboard focus can prepare Profile or Saved products code. A quick pass cancels the delay; touch does not block scrolling. No owned page GET occurs until activation. |
| Seller intent | Dashboard links and the seller sidebar can prepare Products, Orders, Dashboard or the actual storefront-preview component. Root `/seller/...` and the existing seller-host paths work; root `/products` remains the buyer catalogue. |
| Private activation | Opening an owned destination sends a fresh authorized request even when its code is ready. The fresh request starts without waiting for a speculative import. It does not display a prepared private response or use an old approval/stock/order result. |
| Profile sections | Switching to Account, Addresses, Wallet, Vouchers or Orders requests that section's coherent props and current account presentation. A failed switch leaves the previous section selected with visible feedback. Repeated clicks while pending do not create duplicate requests. |
| Order filters | The selected status, result count, action flags and pagination agree. Address, account and voucher work is skipped when not requested. Selecting another stage resets its page; Refresh retains the selected stage and page. |
| Edits and saved products | After changing contact/address details, saving/removing a product, updating stock or fulfilling an order, a subsequent open shows saved values from the server. Other tabs still require current server validation; no private snapshot can supply permission. |
| Seller lists | Search, category, status, stock and page remain attached to the selected URL. Fresh list updates include current shop/category context. Moderation feedback shows the latest decision without exposing its private history. |
| Last-page recovery | Removing the final saved item or changing a list can shorten pagination. The server selects the remaining last page, and the client corrects only its page parameter while retaining filters and scroll. |
| Error recovery | A failed reviewed navigation has a visible Try again destination; failed section/filter reads retain feedback and input. Messages, finance and other unreviewed destinations keep their existing request/error behavior. |
| No unintended activity | Hover/focus never reads messages or notifications, issues pickup codes, fetches private evidence, records ad events or submits actions. Private data prefetch requests, including unmarked requests with `Purpose: prefetch`, are rejected before controller work. |
| Shared budget | Catalogue data and reviewed page-code preparation share one active candidate. Hidden/offline/Save-Data/very slow clients and foreground work skip new preparation. Existing session, context, mutation, return-to-tab and cross-tab invalidation still apply. |
| Disable and presentation | After an ordinary response receives the disabled setting, no new policy-driven preparation starts. Fresh links/forms still work. Buyer and seller layouts, typography, colors, navigation grouping and responsive rules retain their own design. |

Static imports may already be present in the browser's module cache; they do not
contain owned records. Cancelling a link's intent prevents a scheduled import,
but an import already started cannot be aborted. It retains the preparation slot
until it settles and cannot restore invalidated context metadata. Neither a
local build nor a controlled transport test measures real Azure click latency.
Run explicitly authorized browser/device checks separately. PF03-PF05 retain
their own implementation and measurement gates.
