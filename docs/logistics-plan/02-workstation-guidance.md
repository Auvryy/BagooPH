# Logistics workstation guidance

This bounded Phase 6 batch follows parcel recovery and improves the existing
logistics registry and pickup counter. It uses the current custody, manifest,
retry, return and pickup services under the operational contracts in
[Sorting Center and Logistics Flow](../SORTING_CENTER_LOGISTICS_FLOW.md) and
[Core Flow Validation](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md).

## Deliverables

1. Show the parcel's physical stage separately from its actual commercial order
   status. Identify the recorded custodian from retained checkpoint evidence;
   assignment or a planned route cannot establish custody.
2. Explain the next responsible actor, supported action and blocking condition
   for every registry/counter parcel. Respect handler assignments and company
   management authority, including missing evidence, unavailable riders,
   counter staging, locked/expired claims, retry review and parcel recovery.
3. Make grouped registry filters match their displayed counts. Keep the current
   owned-facility scope, pagination and search; route membership alone must not
   make another facility's parcel visible.
4. Replace fixed fulfilment/verification claims with clearly labelled recorded
   handover counts and actual pickup readiness. Distinguish current queues,
   search results and historical evidence; do not advertise an invented rate.
5. Preserve the logistics portal's existing appearance and reusable controls.
   Navigation to a scanner, manifest or review page does not perform a handover.

## Acceptance

- Real pickup, hub, Mother-Hub, destination, assigned-rider, counter and recovery
  records produce the corresponding guidance. Missing or inconsistent custody
  has an explicit blocker and no unsupported mutation control.
- Company administrators may manage assignment/review in their scope, but do
  not gain handler scans. Another company, unassigned handler, wrong facility,
  stale state, or unavailable rider cannot gain an action from the interface.
- Grouped filters return their actual members; counts use the same facility
  boundary, with empty queues represented accurately.
- Secure counter readiness includes retained staging, actual custody, expiry
  and verification locks. Merely arriving at the destination is not ready for
  buyer release. Reading a workstation cannot expire a claim or change custody.
- Focused PHP tests use SQLite `:memory:` and real flow writers for operational
  evidence. Server-rendered frontend checks verify visible guidance/controls;
  the TypeScript/Vite production build passes.
- The roadmap records final evidence, scoped before/after ratings and remaining
  release limitations. Local commits remain user-owned publication work.

## Boundary

No lifecycle, route, custody, claim-code, stock, COD or settlement rule changes;
no database reset or new schema. General redesign, new rates, restricted-resource
recovery, refunds and native mobile work remain separate selections. This batch
does not certify all of Phase 6 or production deployment.
