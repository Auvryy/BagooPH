# Bagoo Voucher Experience Plan

Buyers should choose a real offer and see its effect before placing an order.
Typing a code remains an optional fallback. Sellers manage promotions for their
one approved shop; Platform Admin manages Bagoo-funded campaigns.

This is a proposal for review, not authorization to implement every batch.
The [documentation map](../README.md), [buyer flow](../BUYER_FLOWCHART.md),
[seller flow](../SELLER_FLOW.md), [validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md)
and [finance authority](../ADMIN_FLOW.md) remain authoritative. Current audit
findings and implementation evidence belong in [the roadmap](../CORE_FLOW_ROADMAP.md).
New financial policy must be reviewed before V02 implementation.

## Four bounded deliveries

| Batch | Deliverable | Suggested branch | Prerequisite |
|---|---|---|---|
| V01 | [Honest offers and accurate quotes](01-quotes-and-honest-offers.md) | fix/voucher-quote-consistency | Existing checkout, shop eligibility and signed submissions |
| V02 | [Redemptions, funding and stacking](02-redemptions-and-funding.md) | feat/voucher-redemption-foundation | V01 accepted; funding and cancellation policy reviewed |
| V03 | [Seller and platform campaigns](03-campaign-management.md) | feat/voucher-campaign-management | V02 accepted; writer permissions and accounting available |
| V04 | [Buyer saving and selection](04-buyer-selection.md) | feat/buyer-voucher-selection | V02 and V03 accepted; public campaign discovery available |

Start with V01. Keep V02 separate because it changes monetary records and
checkout atomicity. Seller and admin campaign work can share V03's validation
engine, with separate ownership checks, interfaces and logical commits. V03 and
V04 may share a later goal after the foundation passes, but retain their separate
acceptance gates. [The final acceptance gate](ACCEPTANCE.md) is a combined
verification and handoff, not another large feature branch.

See [rollout and retained records](ROLLOUT.md) for additive deployment, bounded
demo offers and stopping new operations without deleting monetary history.

Schedule selected work only, with actual availability and verification time
within the November 20, 2026 delivery target. A plan is not a past time log.
Do not begin all four batches or reorganize unrelated work automatically.

## Recommended first-release rules

| Decision | Proposed Bagoo rule |
|---|---|
| Shop offers | At most one product voucher per selected shop; fixed pesos or capped percentage |
| Platform offers | At most one Bagoo voucher per checkout: product discount OR shipping discount |
| Combination | One shop offer for each shop can combine with the single platform offer |
| Qualification | Minimum spend uses current eligible selected merchandise before vouchers, excluding shipping |
| Calculation | Apply shop product discounts first; platform product discount uses the remaining merchandise amount |
| Shipping | Calculate each parcel's actual fee first; discount only eligible remaining shipping |
| Limits | Required end time, global redemption limit, per-buyer limit and finite funded campaign budget |
| Saving | Saves are bookmarks, not reservations of quota or money |
| Code entry | Optional; never required when an eligible public offer can be selected |
| Cancellation | Preserve current non-restoration of voucher use; no automatic replacement or sibling reallocation |

Shop-funded shipping, first-order targeting, category targeting, loyalty
points, cashback, wallet balances, invitation campaigns, gamification, live
sales, refunds/exchanges and advertising attribution are deferred. Whole-shop
and platform-wide offers are enough for the first useful release. No new paid
service, mobile app change or payment method is needed.

These limits are Bagoo design choices, informed by
[official references](REFERENCES.md), not universal marketplace rules.

The separately selected [product-selection extension](PRODUCT_SELECTION.md)
adds shop vouchers for 1–100 owned products, including their variants. It keeps
whole-shop offers, existing voucher slots and immutable revisions. Only
qualifying selected merchandise counts toward the targeted minimum and
discount; original order and return amounts retain that scope. Code-only
discovery and other deferred promotion workflows remain separate selections.

## Funding must be explicit

Keep the documented commission split on original merchandise: 10% platform
commission and 90% gross seller share. Add a separate, auditable promotional
expense rather than silently changing that basis.

| Entry | Example |
|---|---|
| Original merchandise | PHP 1,000 |
| Shop-funded discount | PHP 100 |
| Bagoo-funded product discount | PHP 50 |
| Shipping before discounts | PHP 50 |
| Buyer COD | PHP 900 |
| Gross seller share / commission | PHP 900 / PHP 100 |
| Seller promotional expense / net seller payout | PHP 100 / PHP 800 |
| Platform promotional expense / net commission | PHP 50 / PHP 50 |
| Shipping allocation | PHP 50, accounted for separately |

The example reconciles: PHP 800 seller payout + PHP 50 net platform amount +
PHP 50 shipping = PHP 900 collected. A platform shipping discount instead
creates a platform expense equal to its actual shipping reduction; it must not
reduce the courier or hub entitlement or offset merchandise.

Seller-funded offers must not produce a negative seller payout. Platform
subsidies may exceed commission only within an explicitly funded campaign
budget. A budget is an approved spending ceiling, not an invented cash account.
Buyer-only receipt confirmation and reconciled COD remain settlement gates.
Original prices, commissions, routes and statuses remain intact.

Historical orders cannot be assigned a guessed funder or have paid settlements
rewritten. Existing campaigns with uncertain funding need an explicit legacy
review; new terms use a new campaign revision. V02 must establish this boundary
before V03 can publish newly funded offers.

## Design stays native to each portal

Buyer cards, picker sheets and empty states stay within BuyerLayout and the
existing profile workspace. Seller forms and campaign tables stay within
SellerLayout. Admin budgets, reasoned actions and audit rows stay within the
existing governance console. Share rules and small primitives, not entire
portal layouts.

Use Plus Jakarta Sans, PHP/₱, the #E00D42 accent, existing responsive patterns,
visible controls and accessible focus states. Offer cards show the issuer,
actual discount, minimum spend, cap and expiry. Use “Shipping discount” when a
cap leaves a fee payable; show “Free shipping” only when the quoted fee is zero.
Keep empty messages centered in their available panel space.

Preserve the purchase sidebar: Order issues appears immediately whenever
Purchases or Order issues is active, with the existing chevron and left guide
line. Explicit account-setting links still open account settings.

## Useful demonstration

Two approved shops, one approved buyer, one offer per shop and one platform
offer demonstrate selection, minimum spend, a capped discount, independent
parcel totals and exact COD. Show a second buyer hitting a real quota boundary,
an expired offer, a preserved retry, a seller ownership rejection and an admin
pause. Demonstrate recorded facts, not placeholder voucher cards or fabricated
campaign performance.
