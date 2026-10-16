# PF01: Product Pagination and Responsive Search

## Outcome and first acceptance gate

Fix the reported buyer pagination behavior first: page 2 must show the next
products and a matching active-page indicator. A product list page represents
more products, not additional images of the same product. Cover both the
marketplace feed and product search before caching either navigation path.

The screenshot establishes a user-reported failure, not its cause. Trace the
exact active route and generated pagination URL, request page/query, response
product IDs and rendered props. Check local path aliases and configured portal
origins, redirects, query loss, stale client state and scroll position. Do not
declare a seed problem, missing data, browser cache problem or completed repair
without evidence.

Read the [shared policy](POLICY.md), [buyer contract](../BUYER_FLOWCHART.md),
[form review](../FORM_VALIDATION_REVIEW.md) and existing
[BS04 discovery requirements](../buyer-seller-plan/04-buyer-discovery-and-saved-products.md).
This is a new reported regression/performance delivery, not a claim that the
earlier discovery batch never existed.

## 1. Make pagination observable and correct

- Trace `BuyerHomeController`, `BuyerProductController`,
  `BuyerDiscoveryService`, `Buyer/Home` and `Buyer/Search`; identify the live
  handlers before changing older `Marketplace` components.
- Preserve search, category, sorting, price bounds including zero, rating and
  stock filters in every next/previous/numbered URL. Use deterministic ordering
  with the existing ID tie-breaker. Reset to page 1 on a changed filter/query.
- Use current-origin navigation for paginator URLs without dropping path,
  filters or page. Do not change the production origin or weaken middleware
  to compensate for a client URL problem.
- Render disabled controls as actually disabled, without a fake `#` navigation.
  Mark the current page accessibly and show a useful result range/count.
- After an explicit page selection, focus or announce the new result region
  and bring that grid into view. Preserve the general layout and Back/Forward
  behavior. Do not interpret an unchanged viewport as proof of unchanged data.
- Preserve the documented out-of-range fallback to the last valid page and
  synchronize its URL/active indicator as appropriate. Zero results have an
  honest empty state, not a clickable second page.

## 2. Prepare products and search without flooding requests

Establish the shared request/cache policy in this branch. Add selective product
detail preparation to the shared product card after the safety review. Apply
it to cards in search as well as the home feed; do not preload every product's
gallery, related products recursively or every route linked by the response.

For the desktop and mobile header search:

- Keep a controlled input with the current 100-character limit and server
  trimming/validation. One-character searches remain valid on explicit submit;
  start automatic preparation only after at least two trimmed characters.
  Do not remove apostrophes, accents or literal wildcard characters.
- After roughly 300ms without another edit, prepare the exact current results
  route and filters, with page reset to 1. Typing on another page must not
  navigate away or steal focus. Enter/Search bypasses the remaining debounce
  and opens the latest descriptor immediately.
- Reuse matching in-flight/cached work on submit; do not send a prefetch and
  an unrelated duplicate visit for the same query. Clear superseded triggers
  and ignore old responses after typing, clearing, navigation or unmounting.
- On the actual `Buyer/Search` page, a settled input may update the matching
  grid in place with coherent partial props. Coalesce typing history rather
  than creating a Back entry per character. Explicit navigation/submission
  retains useful history and URL sharing.
- Synchronize the header and filter input with fresh page props and history.
  Preserve the current category/sort/price selection when searching within
  filtered results. Clearing search removes its query and resets pagination;
  it must not silently discard unrelated filters or jump to the homepage.
- Handle composition input, paste, rapid changes, clear, Enter and keyboard
  navigation. Never request an intermediate composition value. Keep loading,
  empty, invalid and network-failure feedback distinct and concise.

After the current grid settles, allow one eligible next page to prepare during
idle time under the shared budget. Hover/focus on numbered pages can prepare
the selected destination. Moving the pointer over product cards must not launch
unbounded parallel requests. Lack of a cache hit always permits normal browsing.

## 3. Preserve freshness and scope

Retain catalogue/shop approval and restriction checks on all reads. Keep current
server validation for Bag mutations and checkout, including stock, prices and
voucher eligibility. Invalidate relevant prepared product/results data after
local Bag or catalogue-related changes; never show another account's cached
Bag quantities/offers. Recheck shared props, session feedback, advertisement
activity and redirects before enabling a destination.

Do not create a new autocomplete API, external search index, new recommendation
feature, schema migration or product reseeder merely to implement this batch.
Any later suggestion dropdown requires a concrete separate scope decision.

## Verification and stopping point

Use isolated SQLite fixtures large enough for at least two home pages and two
search pages. Run the relevant existing buyer discovery tests plus meaningful
new cases for the reported path. Existing search pagination coverage is useful
but does not itself prove that the screenshot's active feed path works.

| Acceptance group | Required examples |
|---|---|
| Pagination | Page 1/2 have the correct distinct product IDs, count and active indicator; next/previous/numbered links work on home and search; filters survive; ties are stable; boundary/empty/out-of-range pages behave honestly |
| URL and state | Current-origin paths and queries are retained; relevant aliases resolve the same intended page; Back/Forward and query changes restore inputs; explicit paging reveals the updated grid |
| Typing | Debounce, Enter flush, paste, composition, 1/2/100/101-character boundaries, clear, invalid shapes, literal wildcard handling and late-response rejection |
| Preparation | Same-descriptor reuse, different-query/page separation, TTL expiry, one speculative request, bounded idle page preparation, disabled/hidden/offline/Save-Data cases and normal-request fallback |
| Identity and activity | Guest/buyer transition, sign-out/in as another account, no old response reuse, no premature acknowledgement/activity/write, preserved flash/validation feedback and unchanged eligibility |
| Feedback | Loading does not imply no results; errors can retry; keyboard/touch remain usable; unrelated page layouts and input focus remain stable |

Verify client behavior using the existing frontend test tooling, server behavior
with relevant PHP tests, and run `npm run build` for the implementation. Do not
use browser automation or screenshots unless the user explicitly requests them.
If a visual/deployed behavior remains unverified, record it honestly and provide
a short manual check. Record request/payload observations without inventing an
Azure speed result. Stop after PF01; PF02-PF05 are not started automatically.
