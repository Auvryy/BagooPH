# Browsing and Portal Navigation Acceptance

Use this guide after publishing and deploying the reviewed PF01-PF05 deliveries. Current
local test results, implementation scope and ratings belong in
[the roadmap](../CORE_FLOW_ROADMAP.md#october-16-2026-pf01-buyer-pagination-and-responsive-search).
These manual checks supplement automated evidence; they are not a statement
that Azure or a device has already passed.

## Configuration

`CATALOGUE_PREFETCH_ENABLED` controls the reviewed buyer catalogue preparation
and reviewed buyer, seller, rider, logistics and admin page-code preparation.
Its default is `true` in `config/catalogue.php`; `.env.example` includes the
setting. Set it to `false` and apply the normal deployment/config-cache refresh
to disable speculative data loading. The next ordinary response communicates
the setting to the client and clears its prepared-page state. Existing loaded
tabs need an ordinary request or reload to receive changed server configuration.

Normal product links, pagination, search submission and server validation remain
available with the flag disabled. PF05 removes the blanket load-triggered Vite
asset waterfall. Normal initial module preloads and browser caching of static
files remain available. The one-candidate budget is shared by speculative
catalogue page data and reviewed page-code preparation; an import can load
dependent static chunks. This is not a limit on every asset/notification request.

Use the normal [deployment workflow](../WEB_AUTH_DEPLOYMENT.md). PF01-PF05 introduce
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

When reviewing requests, distinguish page-data preparation, reviewed component
imports and normal initial module loading. Record route/query/page, foreground versus speculative
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

## Rider web and logistics navigation

PF03 prepares reviewed static page code and retains fresh page-data requests.
Read [the implementation audit](../CORE_FLOW_ROADMAP.md#october-18-2026-pf03-rider-and-logistics-navigation)
for source decisions and local verification. This does not change the Flutter
project or establish a production speed measurement.

| Check | Expected result |
|---|---|
| Rider intent | Dashboard, Trips and Profile links prepare their component after deliberate hover/focus. Touch stays immediate. Preparation issues no destination queue, completed-trip, profile or message GET; Messages and COD are outside the preparation map. |
| Logistics intent | Reviewed Overview, Network, Fleet, Parcels, Scan Station, Counter and manifest navigation can prepare component code. No facility selection, manifest token, scan lookup, counter verification or other page data is requested in advance. Company administrators and handlers keep their existing separate authority. |
| Fresh opening | Activating a reviewed link immediately requests the exact current path/query with fresh authorization. A pending code import does not delay the request or provide an old duty, assignment, stock, permission or queue snapshot. Root and existing portal-host paths keep the current origin. |
| Facility selection | From a supported workspace with an explicit old `hub_id`, select another owned hub. The redirect keeps compatible filters, replaces that hub ID and resets paging. The facility label, records, counts and rider candidates agree. Repeated switching is blocked while the request is pending. A handler cannot switch to another facility. |
| Parcel registry | Search, status, delivery type, hub and page retain their exact identity. A failed filter read keeps the input and gives useful feedback. Sorting and assignment prevent duplicate submissions and report server validation. Disabled pagination does not navigate. New hub context clears the old local rider selection. |
| Refresh priority | An intentional navigation, manual Refresh or submitted action cancels the old automatic queue refresh. An already delivered obsolete poll result is rejected before applying props. Scans refresh the current auth/facility and queue props together. The existing 15-second rest policy remains; new preparation adds no polling loop. |
| Context change | Changed account, company, assigned hub, barangay, vehicle, duty or returned pickup/final-mile phase clears preparation metadata. A late import cannot restore the old generation. Returning to a tab and supported cross-tab invalidation still discard prepared context. |
| Stale operational action | A parcel moved, claimed, scanned or restricted by another actor requires the existing current server decision and recovery path. Preparation never submits a claim, toggles duty, records receipt, dispatches a manifest, issues a pickup secret or changes cash custody. |
| Network and recovery | Hidden/offline/Save-Data/very slow clients skip speculative preparation. Hidden/offline pages cancel current automatic refreshes; functional queue refresh retains its existing cadence when available. A failed reviewed navigation offers a visible Try again destination. Disable the existing configuration flag to stop preparation after the next ordinary response. |
| Presentation | Preserve the rider's blush canvas, 8px corners, 1280px navigation breakpoint, drawer/sidebar transitions, independent message scrolling and bottom-navigation clearance. Preserve the logistics sidebar grouping, tables and controls. Rider retry controls keep visible focus and usable touch areas. |

Private records are not retained in a speculative response cache. The existing
10-second preparation metadata and shared one-candidate budget cover page code;
an import can load dependent static chunks and cannot be aborted once started.
Code readiness does not eliminate fresh server work, image loading or rendering.
Do not infer Flutter/device behavior, simultaneous PostgreSQL acceptance or an
Azure latency reduction from the local SQLite and controlled frontend checks.
PF04 and PF05 add the selected admin and measured local acceptance below.

## Platform Admin navigation

PF04 prepares only `Admin/Dashboard`, `Admin/Users`, `Admin/Products` and the
`Governance/History` list. Exact root/subdomain aliases and current account
context remain part of the reviewed map. No private admin page response is
prefetched or retained as decision authority.

| Check | Expected result |
|---|---|
| Intent and opening | Deliberate hover/focus may import reviewed page code. Touch remains immediate. Activating a link makes a fresh authorized request; a pending import never delays the start of that request. |
| Directories | User search and role selection reset paging, retain useful input, prevent duplicate submissions and expose pending/error/empty results. Page numbers, rows and filters agree, including tied timestamps, an emptied last page and Back/Forward. Product paging retains current listing/compliance state. |
| Smaller fresh reads | Same-component updates include current identity, the enabled setting, feedback, directory rows and their filter/base-path props together. A context-only read skips directory work. The list contains only displayed fields; inspection links still load their own current details. |
| Review authority | KYC, shops, corrections, restrictions, closures, product moderation, private evidence, advertisements and finance remain outside the preparation map. Another reviewer's change produces current state or the existing conflict/refresh result when a decision is attempted. |
| No background effects | Intent creates no approval, restriction, document inspection, advertisement view/click, message acknowledgement, cash event or consumed flash/error feedback. Marked private data-prefetch requests receive a rejection before directory/controller work. |
| Access and recovery | Guests, another role, a revoked/expired session and changed account retain current denials. A failed reviewed navigation offers Try again; input remains usable after validation. Account/build/access changes invalidate metadata, and late imports cannot restore it. |
| Budget and presentation | Hidden/offline/Save-Data/very slow clients skip preparation. The existing shared flag disables it after the next ordinary response. Preserve the admin sidebar, header, table layout and Plus Jakarta Sans; search and pagination retain visible focus, active and disabled controls. |

## Combined release checks

[Local measurements](MEASUREMENTS.md) cover all selected portals, ten paired
samples per representative cold/prepared/in-flight/expired scenario, adverse
preparation, search scheduling, actual endpoint query/payload costs and static
asset budgets. They do not establish browser rendering or deployed latency.
The following remain explicit manual/deployment acceptance steps; they were not
performed by routine automation in this delivery.

1. Publish/review the combined branch, deploy using the normal workflow and
   verify the intended build. No database reset or reseeding is required.
2. In each portal, compare a cold opening with a deliberate prepared opening
   of its reviewed destination. Record route/query/page, source page, device,
   network conditions, build, click-to-meaningful-render time, median/p90 over
   at least ten repetitions, request count and transferred bytes. Separate
   page data, JavaScript, images and rendering; do not reuse controlled timings
   as observed device results.
3. Check mouse, keyboard, touch/scroll, rapid navigation, search composition,
   clearing, filtering, page boundaries and Back/Forward. Confirm current
   records and feedback before measuring speed.
4. Repeat under slow/offline recovery, Save-Data, a hidden/returned tab,
   incomplete and expired preparation. Actual navigation must remain usable;
   a speculative failure must not become an empty successful page or a retry
   loop. The removed global waterfall must not return.
5. Check sign-out/in and another account/tab, changed company/hub/placement,
   approval/access revocation, a relevant mutation and delayed old responses.
   Reopen review and operational pages after another authorized actor changes
   the record; decisions must still use current state and safeguards.
6. Disable `CATALOGUE_PREFETCH_ENABLED`, refresh normal deployment/config cache
   and make an ordinary request in each existing tab. New policy preparation
   must stop; direct links, forms, focused updates and fresh decisions must
   continue. Re-enable only after reviewing the observed request/latency cost.

Stop or disable preparation if records/permissions are wrong, queues overwrite
newer actions, background activity mutates a workflow, requests become unbounded
or measured cold/server behavior materially regresses. Keep actual failures and
unperformed checks visible. Flutter, simultaneous PostgreSQL acceptance and
Azure/device verification retain their separate boundaries.
