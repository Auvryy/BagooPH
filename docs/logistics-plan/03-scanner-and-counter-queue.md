# Scanner guidance and counter queue

This selected Phase 6 batch follows [workstation guidance](02-workstation-guidance.md).
The [logistics custody contract](../SORTING_CENTER_LOGISTICS_FLOW.md) and
[validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md) retain authority.

## Deliverables

1. Keep actual shelf/bin names and show "Shelf not recorded" when absent. Reuse
   recorded custody, commercial state, next actor and blockers in scanner results
   and its bounded counter preview. A destination route alone cannot place a
   parcel in that facility's queue.
2. Replace the scanner's incomplete name/notes release form with navigation to
   the existing secure counter verification. Preserve selected facility and
   parcel search on root and hub-subdomain routes. Scanning and navigation do
   not collect COD, release a parcel or complete an order.
3. Paginate the counter at 15 parcels per page, with deterministic ordering and
   scoped search. Show the full matching total and explicitly label readiness
   and blockers as counts for the displayed page. Do not infer readiness for
   unseen parcels or recalculate it from status alone.
4. Bound query work to the displayed parcels and existing history/preview limits.
   Reuse original staging and custody checks; eager-load read evidence where
   useful without caching authority for a mutation. Preserve deadline feedback,
   fresh release checks and actual collection-time history ordering.
5. Keep the existing logistics portal style and shared guidance components.

## Acceptance

- Recorded bin labels survive; missing bins do not imply staging or an invented
  shelf. Arrived, staged, locked, expired and inconsistent parcels expose their
  actual guidance without read-side writes.
- Counter pagination preserves company/facility ownership, search and route
  context. Totals include all matches; page counts include only displayed rows.
  Empty and out-of-range pages remain truthful and navigable.
- Query/loaded-parcel work stays bounded as the matching queue grows. Latest
  original checkpoints and actual custody still control readiness, including
  non-custody notes, mirrored rows and inconsistent staging evidence.
- Company administrators do not gain handler scan/release authority. Wrong
  facility, stale form, expired claim and incomplete handover evidence remain
  rejected by the existing authoritative services.
- Focused PHP checks use isolated SQLite `:memory:`; frontend checks cover copy,
  guidance, page controls and current deadlines. The production build passes.
- The roadmap records evidence, scoped ratings, limits and local commits.
  Only the user publishes or deploys the branch.

## Boundary

No new schema, physical transition, route, claim-code, stock, cash or settlement
policy. General redesign, cross-role tracking consistency, new rates, disputes,
refunds, native mobile work and production load certification remain separate.
