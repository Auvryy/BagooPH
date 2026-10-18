# BP01: Search and notification controls

Proposed branch: `fix/buyer-search-and-notification-polish`.
Active references: `BUY-SRCH-008`, `BUY-CHAT-005`, and `EXTRA-01`.
Read the [shared plan](README.md) and [case ledger](CASE_COVERAGE.md).

## Outcome

Every advertised filter sends a supported request, combined filters operate
together, and a buyer can explicitly acknowledge all intended notifications.
Keep the surrounding buyer controls, spacing, typeface, navigation and feedback.

## Location filtering

The inspected `BuyerDiscoveryService` accepts category, price, stock and rating
filters but no location selection. `catalogueNavigation.ts` similarly drops
unrecognized keys. `Shop` and `ShopReviewService` currently retain reviewed shop
address and city; a separate shop province/region is not an established field.

Before implementation, trace the actual approved pickup-location data through
`Shop`, `ShopReviewService`, `ShopEligibilityService` and
`PhilippineAddressCatalog`. Use that evidence to support province/area filtering
and any narrower city selection. Do not substitute the buyer's destination, an
unrelated seller residential address or logistics service coverage for a shop's
reviewed pickup location.

Canonical location choices must come from the existing local hierarchy. Expand
a province/area to its canonical cities only when the stored shop location can
be resolved unambiguously. Repeated city names and unmatched legacy text must
not be guessed into an area. If structured reviewed location is required, include
that bounded data/correction prerequisite in this delivery with an additive
migration and an explicit legacy outcome. Preserve existing approval and catalogue
rules; do not fabricate a location or wipe/reseed records. A city-only shortcut
does not close the requested broader-area filter check.

Then implement and verify:

- Validated, bounded location input, a clear All locations choice, useful counts
  and an honest empty state. Reject malformed or unsupported values with field
  feedback; never interpolate location text into query syntax.
- Category, average rating and location combine with AND semantics. Clearing
  one filter retains the others and restarts pagination appropriately.
- Selection survives pagination, sorting, Back/Forward and deliberate reload.
  Typo suggestions, related results, current sale-price filters and eligibility
  use the same intended catalogue scope.
- Include the location in supported URL construction, live-search requests,
  request cancellation, prefetch identity and invalidation. A prepared result
  from another location cannot replace the visible selection.
- Keep public payloads bounded and free of private pickup address/evidence.
  Filter by the allowed public area, without exposing exact private location.

Acceptance must include eligible listings in two areas, mixed category/rating
results, no results, unknown/ambiguous location, rapid filter changes and an
out-of-range page. A missing or silently ignored area is still a failure.

## Average-rating control

`Buyer/Search.tsx` sends a fractional threshold from its visible 4.5+ control.
`BuyerDiscoveryService` currently requires a positive integer, and the discovery
test's invalid-input table encodes that rejection. The interface and expectation
must be tested together.

Support the displayed average thresholds, normalize their query representation
and filter real verified purchase averages inclusively. Cover below, exactly at
and above the threshold, unrated listings, combined filters and stable paging.
Reject arrays, non-finite text, exponent forms and unsupported thresholds clearly.
Correct the stale test that labels the advertised threshold malformed.

Individual review stars remain integers from 1 to 5. Do not loosen their rule,
or globally change a shared positive-integer validator to repair this filter.
Test activating every displayed filter choice against the real route, including
its persisted URL and returned selection.

## Explicit bulk notification acknowledgement

The current notification controller supports reading one owned notice and
acknowledging a bounded displayed preview. It has no bulk-all control/action.
Keep those existing meanings and add an explicit owner action through the
existing notification service and authenticated routing pattern.

- On the full notification page, test the unread sequence from several notices,
  through one opened notice, to the remaining notices being acknowledged by the
  explicit action. Include unread records beyond the first list/preview page.
- Define the action's snapshot boundary so a notice arriving after confirmation
  is not silently acknowledged. Derive ownership and supported types server-side;
  foreign identifiers, changed account context and forged counts cannot expand it.
- Preserve existing read timestamps. Repeating the action, an empty unread set
  and duplicate clicks are safe. Refresh the badge and current owned list without
  a full browser reload or a manufactured count.
- Show pending, success and actionable error feedback. A storage/network failure
  cannot claim success or clear unread records only in the client.
- Preserve preview-only acknowledgement of actually displayed rows. Do not turn
  opening the bell into a global bulk-read action. If shared page/service code
  affects other portals, verify their ownership and shell without restyling them.

## Checks and stopping point

Extend the relevant `BuyerDiscoveryAndSavedProductsTest`,
`BuyerStorefrontAcceptanceTest`, `CataloguePrefetchTest`,
`NotificationCenterTest`, `BuyerNotificationPreviewTest` and catalogue/notification
frontend checks where behavior changes. Add assertions for actual control requests,
location context changes, owned bulk updates and failures, not only source-string
presence. Run the production build after UI changes.

Keep location/rating work and notification work in separate logical commits with
clear bodies. Record actual evidence in the roadmap and case ledger. Do not start
a payment gateway, full returns, shipping-tariff replacement or unrelated portal
redesign inside BP01.
