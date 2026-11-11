# Question 1: automatic parcel route planning

Decision: accepted with the clarifications below. Implementation is locally verified on `feat/brainstorm-routing-feedback`; publication and merge remain pending.

## What the buyer and backend do

1. The buyer enters a destination and chooses an eligible logistics company. The existing checkout policy selects one company that must serve every selected shop; each shop receives its own route.
2. The server resolves the seller's origin child hub and the destination child hub from that company's eligible address/postal/barangay coverage. Barangay alone is insufficient, and ambiguous coverage is rejected.
3. The configured hierarchy determines the planned path:

| Situation | Planned facility path |
|---|---|
| Same child hub | Local hub; no Mother Hub transport |
| Different child hubs, same Mother Hub | Origin child → shared Mother Hub → destination child |
| Different Mother Hubs | Origin child → origin Mother Hub → destination Mother Hub → destination child |

4. Checkout shows a compact, expandable **Planned delivery route** for each shop. It labels the local bypass or Mother Hub route and ends at the buyer's address or hub collection. Changing address/company/collection hub hides the old route while the quote refreshes. Invalid coverage uses the existing blocking feedback.
5. Confirmation validates the route again and saves the ordered hub IDs and shipping snapshot. Ordinary route planning cannot overwrite a version-two parcel that already has its saved path, even before pickup. Network edits affect future orders; recorded parcels require controlled recovery.
6. A preview never creates an order, assigns a rider, records a checkpoint or claims an arrival. Actual custody continues through existing authorized scans and Mother Bag/manifest handovers.

## Shared rules kept stable

- Carrier eligibility, route previews and final route creation use the same resolver. Approval, company boundaries, eligible hubs, postal coverage, parent Mother Hubs and the current supported-road exclusions are checked consistently.
- Version-one orders keep their original Mother Hub and collection requirements. The local bypass applies to version two.
- Route feedback uses the existing per-shop `shipping_quote.route` and `same_hub_bypass` fields. No new credentials, external routing provider, public endpoint or migration is required.
- A routing preview returns hub identifiers/names/types; it does not return customer street addresses, phone numbers or staff credentials.
- Existing bag rules remain: sealed bag departure applies to its members, while receipt verifies the seal and individual parcels. The source sentence about scanning at every station does not require rescanning every child parcel while its bag remains sealed.
- The route is a company hub plan under the current version-two hierarchy. It does not establish a street-by-street road itinerary, live GPS location, traffic ETA or physical carrier service between arbitrary provinces. Those require actual network configuration and operational verification; a province exclusion list alone cannot prove physical connectivity.

## Evidence and remaining limits

- Isolated SQLite routing, checkout and Mother Bag suites: **26 tests, 403 assertions passed**. Coverage includes local/shared/different Mother Hub paths, preview versus saved route, no preview side effects, ambiguous/inactive coverage, changed Mother Hub before confirmation, multiple shops, original snapshots, legacy rules and sealed-bag custody.
- Frontend route component and full-checkout integration cover route order, local/collection labels, multiple shops, pending feedback and removal of stale/failed previews. Address disclosure checks also pass.
- TypeScript check and production Vite build passed. Build output was isolated from the local server's assets. PHP formatting and Git whitespace checks passed.
- The broader existing buyer-voucher frontend file has three failures also reproduced against unchanged main: old order-total text, old product/address layout and old unavailable-carrier copy expectations. They are separate from the new route assertions; this delivery does not claim that whole file is green.
- Eight broader local delivery/return tests reached a host limitation: GD is unavailable for their generated image fixtures. They are not counted as passed. This limits full delivery/return acceptance, while the route/checkout/Mother Bag checks above passed independently.
- The routing/checkout tests now use a minimal fixture and a fresh testing configuration instead of the full manual-demo seeder, which currently executes PostgreSQL-specific reset commands. Test data stays in SQLite memory with isolated storage.
- No visible browser, connected phone, development database reset or deployment was used for this delivery. Interactive visual review and operational/device checks remain separate.

Scoped engineering assessment: route planning consistency and checkout feedback **5/10 → 8/10**, based on the checks above. This is not a rating of nationwide logistics readiness.

## Local commits

- `0cba318`: preserve the dependency plan from the preceding documentation branch.
- `786437f`: shared backend route validation, previews, frozen paths and isolated regression fixtures.
- `340ad77`: checkout route feedback and frontend checks.

The decision/checklist/roadmap record is committed separately after these implementation commits. The PR description is a local draft; no PR or deployment is published by this delivery.

## Next work

The [build-order tracker](BAGOO_PH_BRAINSTORMING_CHECKLIST.md) retains separate decisions for every original idea. This question establishes the current route contract and feedback. Later work can reuse it for operator identity, individual scans, bags, dispatch, reverse transport and timers. It does not approve new financial penalties, new geography, offline scanning or revised tariffs.
