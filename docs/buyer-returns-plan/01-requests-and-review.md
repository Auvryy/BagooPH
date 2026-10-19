# R01: Owned requests, evidence and review

Follow the [shared contract](README.md). This delivery replaces the backed buyer,
seller and admin return areas; it cannot claim custody or repayment before R02/R03.

## Eligibility and selected units

Use the original buyer, one original shop order, exact item IDs/options and actual
physical handover time. The interval is inclusive through delivery plus seven
elapsed days; future handover times, missing evidence and earlier statuses reject.
The user explicitly includes Completed orders in this interval. A completed state
never substitutes for physical evidence. Existing timely cases keep their rights
after the request window closes.

Freeze original item quantities, unit prices/subtotals, delivery source, seller,
shop and route identity. Derive available unit positions server-side under an
order lock. Aggregate claims across cases so quantities cannot exceed the purchase
or conflict. Different originally charged sale segments remain distinct item rows.
The submitted amount, buyer/seller identity, timestamps and statuses are not trusted.

Withdrawn, unused requests may be retried within the window. A decided claim is
resolved through its permitted escalation rather than duplicate claims for the
same units. Keep retained case and decision history.

## Form and private evidence

- Require an allowlisted reason, meaningful 10–1,000-character description, one to
  five actual JPG/PNG/WEBP photos of at most 5 MB each and positive original-unit
  quantities. Reject arrays, hidden/control text, empty/renamed/video files,
  excessive count/bytes and foreign identifiers server-side.
- Show the real limits beside controls, retain safe input and selected files on
  recoverable failure, and show per-field/pending/result feedback. Keep buyer,
  seller and admin portal styles/navigation; use ordinary Inertia actions.
- Store files privately with their original content hashes. A protected download
  rechecks case ownership/review authority and actual stored content. Generic
  serialization, notifications and public product reviews expose no private path.
- Store files only for a validated authorized request. On rollback remove only
  newly stored unreferenced files; never delete an earlier successful request's
  evidence when a retry fails.

## Decisions and clocks

The seller responds within three elapsed days. Approve return transport or reject
with a meaningful exact stored reason. Rejection does not move a parcel, mark an
order unpaid, release funds or erase the buyer request. The buyer may escalate a
rejection for three days and may escalate an unanswered request once its seller
response period expires. Permit an explicit buyer withdrawal before intake.

Admin review records an approval/rejection and reason against the current case
version. It may not fabricate a hub receipt or a paid repayment. A seller cannot
resolve their own escalated admin review. Approved cases receive a seven-day
physical drop-off deadline; intake before/at that deadline preserves the case.
Expiry applies only to unused return authority and appends a retained event.
There is no automatic refund or order completion.

Use a server-issued creation token and versioned command tokens. Identical retries
retain the original result, changed reuse and stale competing commands conflict,
and notifications commit with their source event. Test failed audit/notice/file
writes as well as successful requests.

## Screens and access

Buyer purchases show a return action only for an eligible original order, actual
open-case state and a Return/Refund issue destination without changing canonical
order status. Request/detail/history pages show exact selected units, reasons,
evidence, deadlines and the next permitted action. Seller and admin queues use
real owned/scoped records and readable empty/error states.

A later listing restriction does not erase an original purchase or its case.
New review decisions need current authority. Restricted owners retain the narrow
history and cash/receipt responsibility documented by R03 without obtaining new
commerce or administrative access. Account closure must respect outstanding cases.

Verify valid requests, 7-day and decision/appeal clock boundaries, completed-order
interaction, foreign/mixed-shop/stale cases, reserved unit quantities, file privacy,
exact feedback, rollback, duplicates and source-linked notifications. Record the
original manual expectations separately from the explicit completed-order policy.
