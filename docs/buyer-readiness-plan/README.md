# Buyer readiness in three deliveries

This plan groups the remaining buyer review areas into three sequential coding
branches. It uses project interpretations rather than reproducing external case
text, example accounts, products, prices or input data. B1/account and B2/address
work remain separate; preserve the merged address-book behavior.

Read the [buyer contract](../BUYER_FLOWCHART.md),
[validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md),
[form review](../FORM_VALIDATION_REVIEW.md) and
[documentation authority](../README.md) before implementation. Current findings
and execution evidence belong in [the roadmap](../CORE_FLOW_ROADMAP.md).

## Three branches

| Order | Areas | Cases to assess | Delivery branch | Plan |
|---|---|---:|---|---|
| 1 | B3 browsing/search, B4 product details, B5 saved products/following, B6 Shopping Bag | 34 | `fix/buyer-discovery-and-bag-acceptance` | [Shopping and Bag](01-shopping-and-bag.md) |
| 2 | B7 vouchers, B8 checkout, B9 payment | 30 | `fix/buyer-checkout-and-voucher-acceptance` | [Checkout and vouchers](02-checkout-and-vouchers.md) |
| 3 | B10 tracking, B11 cancellation, B12 returns/refunds, B13 reviews, B14 messages/notifications | 28 | `fix/buyer-orders-and-communication-acceptance` | [Orders and communication](03-orders-and-communication.md) |

See the dated [delivery results and agreed expectations](04-delivery-results.md)
for the completed core repairs, verification and remaining feature gaps. The
roadmap remains the authority for later implementation changes. The additional
[advanced shopping delivery](05-advanced-shopping.md) completes the five remaining
feature areas on `feat/advanced-shopping-controls`, stacked after these branches.

All 92 scenarios have a home. These are allocations for assessment and repair,
not 92 passing results. Module labels here identify buyer areas; they do not
refer to the older admin B01-B18 branch sequence.

This grouping follows the purchase journey and shares related setup. First make
the selected products and Bag reliable; then validate money and order creation;
then use those orders to verify tracking, communication and permitted follow-up.
Reuse the completed discovery, order, review and voucher foundations rather than
rebuilding them. Keep logical commits inside each delivery.

## Resolve scope before claiming full coverage

The review assumptions are configurable examples rather than Bagoo's authority.
Record an agreed expected result or an explicit unresolved gap for every
disagreement. Never silently change a project invariant, label a missing feature
as passed, or mark an unresolved case inapplicable without review.

The agreed review decision is to retain Bagoo's existing rules: 10% commission,
COD checkout, buyer-confirmed completion and deferred full post-delivery refunds.
Record these as agreed differences rather than failed implementations of the
alternate assumptions. This decision does not exempt ordinary missing features
or validation defects from assessment and repair.

| Topic | Existing Bagoo rule or observed boundary | Required disposition |
|---|---|---|
| Payment | COD is the implemented checkout method; there is no card/wallet gateway delivery in this plan. | Verify COD and reject unsupported payment attempts clearly. Online payment, gateway callbacks and payment expiry require separate scope. |
| Completion | Only the buyer confirms a delivered order as completed. | Preserve this rule. Do not implement timed completion to satisfy an alternate assumption. |
| Commission and settlement | Commission is 10%; cash reconciliation and seller settlement have independent gates. | Preserve the financial contract; buyer receipt alone cannot fabricate available seller funds. |
| Shipping | The current quote uses the existing per-shop shipping policy; actual routing requires the supported land/hub network. | Agree any weight-based tariff separately. An arbitrary location or nationwide reference does not establish a route. |
| Voucher slots | Checkout currently allows one offer per shop and one Bagoo offer; a Bagoo offer can discount shipping. | Decide whether an additional simultaneous platform shipping slot is required before changing funding/quote rules. |
| Returns/refunds | Buyer post-delivery disputes are currently unavailable; failed-delivery return-to-sender is a different implemented flow. | Define and authorize the full return/refund lifecycle before building it. Honest unavailable behavior is not a passing full return flow. |
| Promotions and new features | Flash-sale inventory, reminders, vacation mode and cumulative purchase caps need explicit delivery scope; shop following currently has a local UI toggle. | Assess each requirement and estimate the missing behavior. Do not use advertisement schedules or placeholder counters as proof of a sale or follow. |
| Review policy | Purchase ownership and review creation exist; edit windows and some text/privacy limits differ. | Resolve the exact rule, then keep UI, server and existing history consistent. |

## Proposed preparation window

Checking starts **October 19, 2026 at 07:00 Asia/Manila**. The following is a
capacity proposal, not an unattended schedule or a promise that all missing
features fit overnight. It covers core repairs and verification; new payment,
refund or flash-sale systems need separate estimates.

| Date | Window, Asia/Manila | Intended work |
|---|---|---|
| October 18-19 | 22:00 to 00:00 | Batch 1, including its focused checks and handoff |
| October 19 | 00:15 to 02:45 | Batch 2, including financial and checkout regression |
| October 19 | 03:00 to 05:30 | Batch 3 core repairs and the buyer tracking presentation |
| October 19 | 05:30 to 06:30 | Combined buyer walkthrough, user publication/deployment and recovery checks |
| October 19 | 06:30 to 07:00 | Final buffer and known-gap review |

If earlier work overruns, preserve the end-to-end purchase, privacy and money
checks first. Reduce optional additions explicitly and report the remaining
coverage; do not remove failure tests or claim completion to match the clock.

## Execution and completion

- Planning alone does not begin application changes or authorize all new scope.
  Select implementation explicitly, then work in order. A selected later batch
  starts from the accepted predecessor. Check the current branch and preserve
  unrelated work before switching.
- Record each case as implemented and verified, a confirmed defect, a new feature,
  a policy disagreement, or awaiting execution. Retain failure and recovery cases.
- Keep buyer additions consistent with the surrounding buyer portal: Plus Jakarta
  Sans, Bagoo crimson, existing surfaces, controls, spacing and responsive layouts.
  Use the local frontend design skill for UI changes; preserve other portal styles.
- Run relevant SQLite-memory tests, appropriate frontend checks and one final
  production build for each changed frontend batch. Broaden checks when shared
  rules change. Routine verification does not use browser automation.
- Every delivery needs logical commits with explanatory bodies, a combined PR
  description, honest verification limits and the roadmap evidence. Only the user
  publishes under the current repository rule. No automatic push, merge, Azure
  deployment, database reset or external payment integration is enabled here.
- The final handoff includes the actual pages/accounts needed for a manual
  purchase and the unresolved scope items. A local automated result does not prove
  deployed browser, mail, optical scanning or real payment behavior.
