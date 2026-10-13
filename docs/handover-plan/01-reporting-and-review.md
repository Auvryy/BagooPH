# H01 — Shared reporting and independent review

## Scope

Create a working web workspace for seller, rider, logistics and admin accounts. A report references an existing exact waybill, describes the missed readiness step or scan, identifies reported possession/location and includes private supporting evidence. Preserve a server-recorded snapshot of the parcel and its latest original custody checkpoint alongside the reporter's observations.

Sellers report their own shop's parcels. Riders report assigned pickup/final-mile work. Eligible handlers report parcels whose current facility or recorded custody belongs to their assigned hub. An eligible company administrator may report their own company's parcels. Platform admins can inspect and review; administrative creation of observations is outside this batch.

An independently eligible company administrator or platform admin can confirm the concern or dismiss it with a reason. Confirmation means **Needs recovery**, not Picked up, Delivered or Resolved. A reporter cannot review their own report. Review history records current parcel facts as well as the original report snapshot.

## Required behaviour

- Bounded, plain-text inputs; exact waybill matching; one private JPG, PNG, WEBP or PDF document, non-empty and at most 5 MB, with actual content/type validation.
- One open report per parcel, with retained dismissed history. Show only scoped records with paginated lists and meaningful empty states.
- Retry-safe report and review requests. Reusing a request token with different content must fail; a duplicate must not create another document or history entry.
- Recheck account, company and parcel involvement on the server. Losing authority prevents new work; original reporters retain read access while their account remains eligible.
- Review requires the displayed report version and current parcel snapshot token. Movement after the review page was loaded requires a fresh review. Reported possession never replaces recorded custody.
- Original report facts and every event must resist model and direct database edits/deletion. Evidence must not be served through public storage, a guessed path or public tracking.
- Both successful and rejected report/review attempts leave orders, parcels, checkpoints, stock and cash unchanged.
- Link the workspace from all affected portals. Rider navigation keeps its four ordinary bottom tabs and adds a drawer/sidebar link instead of another tab.

## Acceptance gate

Check ownership and company/hub/rider scope; active approval and authority loss; self-review rejection; valid, invalid, boundary and forged uploads; unauthorized downloads; duplicate/conflicting retries; competing open reports; stale decisions; dismissed-to-new-report history; immutable facts/events; paginated and empty pages; and unchanged custody/commercial/cash records. Run the focused PHP checks and production frontend build.

## Stop here

No automatic hold, corrective scan, physical transfer, notification delivery promise, native endpoint, Flutter change or bulk historical repair. A confirmed concern remains open until the later authorized recovery delivery. Record implementation evidence and remaining limits in `CORE_FLOW_ROADMAP.md`.
