# Order Tracking Consistency

## Selected outcome

Buyers and sellers understand the same recorded order and parcel journey as
riders and hub staff. Preserve each portal's existing design and the distinction
between commercial order state, physical parcel stage, assignment and custody.
This is the selected remaining Phase 6 tracking batch; it does not authorize a
general redesign, native Flutter changes, refunds or new operational writers.

## Read contract

- Use shared server-side labels for canonical order states and documented legacy
  aliases. Preparing is not Ready for pickup. Delivered remains separate from
  the buyer's receipt confirmation and Completed.
- Show original, retained checkpoints in deterministic timestamp/ID order.
  Mirrored checkpoints cannot duplicate or replace original history. Include
  Bayan-Hub, Mother-Hub, retry, reverse-route, counter and parcel-recovery events.
- Describe a recorded holder only from the latest original custody checkpoint;
  planned route facilities and assigned riders do not establish handover.
  Missing or inconsistent records require explicit review rather than a sample
  location, guessed progress or a supported-action claim.
- Buyer and seller detail views show commercial state, physical stage, recorded
  holder, next responsible actor, next step and readable Philippine-time history.
  Preparation, failure, retry, cancellation, return, secure counter collection
  and completion need distinct explanations.
- An approved retry date must come from the retained recovery event. An earlier
  attempt's approval cannot describe a newer failed attempt. A future retry is
  scheduled, not already dispatched.
- Counter information distinguishes staging, expiry, verification lock,
  collection and return. Do not offer the one-time code for an expired, locked,
  already issued, collected or otherwise unavailable claim.
- Public tracking retains its existing masked response and read-only routes.
  Share status/event vocabulary without exposing full addresses, phones, private
  proofs, claim codes, identity data, internal references or operator details.
- Rider web and hub views retain role-owned operational guidance and server
  authorization. Verify their commercial state and physical journey against the
  same order; do not replace handler/rider permissions with display metadata.

## Refresh and actions

- Provide a clear Refresh tracking action and the time of the observed records.
  Refresh should update displayed history, actions and order counts where
  applicable. Do not advertise a continuously live feed without one.
- Seller detail/counter dialogs must use the current page's refreshed order,
  never a stale copied object. Close a dialog when its order leaves the page.
- Existing buyer receipt, seller fulfillment/cancellation/return, rider outcome
  and hub services remain the only mutation authorities. Reject stale, foreign,
  restricted and unsupported actions; display errors without a fake success.
- Tracking reads do not update order, stock, custody, claims or financial state.
  Owned lists retain bounded pagination, stage filters and scoped totals.

## Acceptance

1. Cover every canonical stage, known legacy aliases, missing delivery/custody,
   inconsistent states and unsupported stages without invented milestones.
2. Verify seller preparation versus readiness, pickup assignment versus custody,
   the full Mother-Hub route, delivery assignment versus departure, doorstep
   handover versus counter collection and buyer-only completion.
3. Cover failure before/after hub return, scheduled/released retry, multiple
   attempts, reverse transit, seller receipt, cancellation and parcel recovery.
4. Verify original chronological history, repeated/mirrored checkpoint handling,
   safe public fields and owned buyer/seller privacy, including restricted and
   mixed-shop history. No tracking read may mutate operational records.
5. Check expired/locked/issued/collected claims, stale forms and unauthorized
   action rejection using the actual existing writers.
6. Run isolated SQLite acceptance and relevant buyer/seller/public/rider/hub,
   cross-role, recovery and financial regression tests; frontend render/refresh
   checks and the TypeScript/Vite production build. No browser UI or production
   verification is implied by those local checks.

Record implementation evidence, scoped ratings and remaining gaps only in
[CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md). Hand off local logical commits for
the user's publication; no database reset or schema change is planned.
