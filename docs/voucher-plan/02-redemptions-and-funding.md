# V02: Redemption and Funding Foundation

Before expanding promotions, each redeemed discount needs an owner, immutable
terms, an exact order allocation and an explicit payer. Review the proposed
[funding and combination rules](README.md) before this batch.

## Proposed records, not existing schema

Prefer extending the existing Voucher and checkout submission rather than
building a parallel checkout. Inspect migrations before choosing final names.
The following are proposed responsibilities:

| Responsibility | Required data |
|---|---|
| Campaign revision | Immutable issuer and shop scope, funding party, kind, amount/cap, minimum, period, quotas, budget and creator |
| Saved offer | Buyer plus campaign revision, unique and removable; no reservation |
| Redemption | Buyer, revision and checkout submission, actual discount and immutable terms; unique per revision/submission |
| Order allocation | Product/shipping reductions, issuer expense and original order/parcel association in integer cents |
| Funding entries | Budget commitment, settlement expense and append-only void/reversal references |

Immutable issuer type must not be inferred solely from nullable shop_id.
Deleting or archiving a shop cannot turn its campaign into a platform offer.
Archive published/used campaigns; deleting unused drafts is a separate policy.
Keep order totals and legacy voucher references while adding detailed
allocations for new checkouts. Never force several redemptions into one
singular voucher_id or guess new model names are already available.

## Quote and checkout contract

Add proposed platform selection and per-shop selection inputs, plus explicit
no-voucher choices. Validate all IDs, scope and stacking server-side. Sort
selection maps before including them in the submission fingerprint. Preserve
the same token's original result even when a campaign later expires, pauses
or exhausts; different canonical input must still be rejected.

Represent pesos as integer cents, reusing the existing money parser where
appropriate. Clamp reductions to their own eligible merchandise or shipping
basis. Use deterministic proportional allocation and remainder handling.
Shop minima exclude other shops; platform qualification excludes shipping.
Percentage terms and caps must match the documented calculation order.

Lock campaign/quota/budget and per-buyer redemption state consistently with
the existing checkout lock order. Unique constraints back up application
checks, including the first-use race. Reserve the actual quoted funding
amount, not the advertised maximum. Persist orders, stock changes,
redemptions, budget commitments and result links in one transaction.
Any failure rolls back all of them. Saving an offer consumes none of them.

Each applied campaign consumes one use per successful checkout, including a
platform voucher distributed across several orders. Per-buyer limits refer
to actual uses, not the number of saved cards or generated orders.

## Finance and cancellation

Extend settlement with separate issuer-funded promotional expense entries
and a visible net payout. Preserve the original 10%/90% gross split and the
existing immutable COD expectation. Couriers collect the saved buyer total,
not a newly calculated campaign amount. Restrict finance actions to their
existing authority; campaign publishers cannot reconcile cash or bypass
buyer receipt confirmation.

Retain current cancellation behavior: cancellation does not restore voucher
quota or silently produce a replacement. Mark cancelled order allocations
with an append-only reference; never redistribute them to sibling orders.
Release only their verified, unspent funding commitment, exactly once, inside
the authorized cancellation transaction. Preserve the rest of a multi-shop
checkout. Published history and usage remain auditable.

Delivery failure or physical return is not a refund or a campaign reversal.
Automatic quota restoration, cash refunds and post-custody reversals need
separate policy and are deferred. State this clearly in buyer terms.

## Migration and acceptance

Preserve historical totals and settlements. Keep legacy used_count as an
honest baseline; do not fabricate buyer redemptions, saves or funding records.
Archive/reissue uncertain legacy campaigns after review; never infer a
platform issuer just because a shop link is missing. No fresh-database reset
is required. Retain the documented verified demo accounts.

Pass isolated SQLite checks for quota/budget boundaries, per-buyer limits,
wrong ownership, stacking rejection, deterministic rounding, rollback at
each transaction stage, replay after expiry, altered-input replay and partial
cancellation. Verify the reconciliation example and unchanged gross split.
SQLite checks do not establish production PostgreSQL race behavior; any
future database-specific check needs a separately authorized isolated setup.

Stop before campaign publication and new buyer saving controls. Record the
migration boundary, actual checks and scoped assessment in the roadmap.
