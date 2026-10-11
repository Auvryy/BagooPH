# V03: Seller and Platform Campaign Management

Reuse V02's rules and monetary records. Keep seller and admin screens native
to their own portals. This batch may combine their shared validation work,
but each writer authority needs separate acceptance checks.

## Seller: one approved shop

Extend the existing
[seller voucher controller](../../app/Http/Controllers/Seller/SellerVoucherController.php)
and [seller page](../../resources/js/Pages/Seller/Vouchers.tsx).
Sellers create fixed-peso or capped-percentage product offers for their own
approved shop. No extra shops, shop switching, role conversion or platform
campaign creation is introduced.

The form shows a readable buyer preview and the proposed seller expense/net
payout. Require a title, discount, minimum, start/end, usage limit, per-buyer
limit and finite spending ceiling. Normalize a generated or custom code
before validating uniqueness. Enforce percentage bounds, amount/cap bounds,
valid scheduling, affordable seller deductions and consistent terms on the
server.

Save drafts, schedule or publish within approved limits, pause/resume with
confirmation and end/archive a campaign. Normal seller promotions do not
require an admin to approve every ordinary edit. Published monetary terms
are immutable; changed terms create a new draft revision. Increasing a
budget still needs affordability validation and an audit entry.

Use truthful lifecycle states: draft, scheduled, active, paused, expired,
exhausted and archived. Derived expiry, quota and budget states cannot be
overridden by a misleading active flag. Ending a campaign blocks new use
without changing placed orders or original-result retries.

## Platform Admin: Bagoo-funded offers

Add proposed campaign pages/actions within the existing admin permission
system after inspecting its policies and route patterns. Do not claim those
routes already exist.

Platform Admin can draft, schedule, publish, pause and archive a platform-wide
product OR capped shipping campaign. Require a finite approved funding
budget and the same validated limits. Show exact committed, spent and
released amounts. Buyers see Bagoo as issuer; seller budgets and identities
are never selectable as the funding source for these campaigns.

Platform Admin may suspend an unsafe seller promotion with a recorded reason,
actor and time. Preserve evidence and notify the seller through the existing
appropriate channel. Admin does not masquerade as a seller or rewrite the
terms of redeemed vouchers. Logistics Company Admin, hub handlers, couriers
and buyers have no campaign publishing authority.

## Honest reporting

Server aggregates span the whole authorized campaign query, not the current
15-row page. Separate saved offers, successful redemptions and cancelled
allocations. Show actual promotional expense, remaining budget and settled
expense with plain labels. Do not label uses as claims or display a static
shipping campaign as a live statistic. No claimed revenue lift, attribution
or conversion rate without the required observations.

Paginate campaign lists and audit history. Keep notices and validation errors
close to the controls. Reuse existing dialogs and feedback patterns; do not
turn the seller portal into an admin console.

## Acceptance and stopping point

Test cross-shop reads/writes, pending or restricted sellers, every unauthorized
role, malformed percentages/codes/dates, expired/exhausted publication,
insufficient funding and duplicate requests. Verify publish/pause against
new checkout and replay against retained orders. Check immutable terms,
global aggregates and audit reasons. Run focused SQLite and frontend checks
plus the production build.

Stop with manageable campaigns and truthful reports. Buyer collection,
discovery and the new checkout picker belong to V04.
