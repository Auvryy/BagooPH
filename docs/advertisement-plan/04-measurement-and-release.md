# AD04: Modest Reporting and Combined Release Checks

Proposed follow-up after the managed display flow is stable. Basic reporting
helps the team decide whether an image earns its space; it is not ad billing,
conversion attribution or a promise of sales. Core authorization, expiry and
recovery checks remain release requirements in AD01/AD02.

## Useful measurements

Show impressions, clicks and click-through rate per advertisement revision,
placement and date window. Display zero or an unavailable state honestly; do
not seed fake performance. Show no rate when the impression denominator is zero.
Admin can compare platform placements; a seller sees only its own submissions
and their actual published results after AD03.

For this proposal, count an impression after the selected image has loaded and
at least half of it is visible for one second. This is a Bagoo measurement choice,
not a claim of certified or billable measurement. A hidden carousel slide, closed
tab, unrendered API record, preview or failed image does not count. Deduplicate
within a page instance and revision so rerenders do not inflate impressions.

Count a deliberate click on a currently published image once for a bounded
event token, including keyboard activation. Arrow/dot navigation is not a banner
click. Keep the real navigation working if event storage fails; do not hold
shopping behind an analytics request. Handle malformed, oversized, duplicate and
rate-limited events without exposing private ad revisions.

Use minimal first-party, short-lived identifiers to deduplicate events. Do not
store account email, legal identity, raw IP addresses or fingerprinting data in
advertisement event payloads. Define retention and aggregate old raw events;
keep publication/review history separately. Label click totals as clicks, not
unique people, attributed orders or advertising revenue.

## Reporting and operation

Use simple admin filters and an owned seller summary, not a new analytics portal
or external tracking service. Allow the platform team to pause an ineffective
or misleading image, replace it through a new revision and compare actual dates.
Do not automatically change catalogue ranking or spend money based on clicks.

The content owner maintains a short calendar: campaign purpose, artwork owner,
placement, exact period, linked products/offers and review status. Check upcoming
starts and expiries before publishing. End or replace a campaign when its purpose
is over, its supporting offer is gone or a target is no longer eligible.

## Combined gate and demonstration

Use [the acceptance matrix](ACCEPTANCE.md) for every selected batch. Demonstrate
one accurately described platform collection, one shop/product feature and one
real voucher promotion; then show a future start, expiry, manual pause and a
withdrawn target. If AD03 is selected, include a seller request, change feedback,
resubmission and reviewed publication. If measurement is selected, generate only
the real bounded preview/test events needed to establish behavior and keep them
out of production performance.

Run focused SQLite-memory backend checks and affected frontend checks/builds.
No browser automation or screenshots for routine validation; actual browser or
device interaction requires the user's explicit request under repository rules.
Record unverified appearance and PostgreSQL concurrency separately rather than
calling static rendering a full interactive or production acceptance result.

Prepare additive release/disable instructions, retained asset/history handling,
local commits and a combined PR description. Only the user publishes/deploys.
An ended advertisement never rewrites old orders, voucher redemptions, commissions
or seller payouts. A paid-ad product would require a new plan and separate money
controls before accepting any charge.
