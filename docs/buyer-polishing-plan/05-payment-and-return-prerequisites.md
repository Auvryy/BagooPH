# Payment and buyer-return prerequisites

These **11 active cases** remain visible in the
[case ledger](CASE_COVERAGE.md). They are not ordinary control polish and are not
waived by the four smaller deliveries. Follow the [shared plan](README.md) and
the [original order follow-up scope](../buyer-readiness-plan/03-orders-and-communication.md).

The user retained COD and deferred complete refunds. Planning these dependencies
does not reverse that decision or authorize new external/paid services. Keep
their original failures/blocks visible until the feature and expected behavior
are explicitly selected, implemented and actually checked. Existing planning
ownership must be reused rather than duplicated.

## PAY: Online payment and prepaid cancellation

References: `BUY-PAY-002`, `BUY-PAY-003`, `BUY-PAY-004`, `BUY-PAY-005`,
`BUY-PAY-006`, `BUY-PAY-007`, and `BUY-CAN-003`.

Current checkout requires COD. The missing gateway, callbacks, retry and expiry
lifecycle cannot be established with a fake paid reference or a passing COD test.
Choose an explicitly supported provider or an isolated provider test boundary
before implementation; no provider or endpoint is implied by this document.

Required decisions include payment states, reservation/stock timing, timeout
policy, supported reconciliation events, buyer-safe retry, cancellation/refund
eligibility and repayment handling. Preserve immutable original totals, original
voucher allocations and independent seller settlement. Never trust client payment
status, amount or a browser redirect as proof of payment.

| Reference | Required closure evidence after the prerequisite is selected |
|---|---|
| `BUY-PAY-002` | Verified successful payment for the exact saved amount, one retained provider reference, one state change and no duplicate stock/use consumption. |
| `BUY-PAY-003` | A genuine failure leaves an honest unpaid state, usable supported retry and no false seller shipping authority or paid ledger. |
| `BUY-PAY-004` | Leaving/cancelling the gateway preserves one original unpaid order and does not manufacture a successful charge. |
| `BUY-PAY-005` | An authenticated server event reconciles a real outcome even without the browser; repeated/out-of-order events cannot double-charge or rewrite history. |
| `BUY-PAY-006` | The approved expiry process cancels only eligible unpaid online orders, restores the correct reservation/stock once and follows the explicit voucher-use policy. |
| `BUY-PAY-007` | Before, exactly at and after the chosen expiry boundary, payment/expiry races yield one consistent outcome and recorded recovery; no paid-and-cancelled double result. |
| `BUY-CAN-003` | A permitted prepaid cancellation retains the original payment, computes the exact lawful repayment, records a once-only refund result and preserves voucher/sibling allocation rules. |

Test signature/ownership/amount/currency mismatch, foreign references, replay,
late success, failure after a partial write and unavailable external responses.
Use an isolated database and a controlled provider boundary. Real provider or
simultaneous PostgreSQL tests require their own explicit scope and safe environment.
While deferred, keep unsupported methods rejected clearly and these cases Blocked.

## RET: Buyer returns and repayment

References: `BUY-RET-001`, `BUY-RET-003`, `BUY-RET-004`, and `BUY-RET-005`.
The separate pre-delivery negative control is assigned to BP03 as
`BUY-RET-002`; it does not close this missing positive feature.

`BuyerDisputeController` currently returns an unavailable page. The existing
failed-delivery reverse route concerns parcels that were not successfully
received by the buyer. Do not substitute it for a post-delivery item complaint,
seller decision, escalation or repayment.

Reuse the established buyer-return/admin-refund planning work. Before selecting
implementation, decide the actual eligibility/window, interaction with explicit
buyer completion, item quantities, meaningful reasons/descriptions, private
evidence allowlist/count/size, seller response and escalation authority/deadlines,
return transport/receipt, disputed cash and COD repayment destination. Retain the
10% commission and cash/settlement ledger rules; do not introduce automatic
completion while designing a return window.

| Reference | Required closure evidence after the prerequisite is selected |
|---|---|
| `BUY-RET-001` | One owned eligible item request with bounded evidence is stored, visible in truthful buyer/seller states and notified to the correct owner. Duplicate and foreign requests cannot create extra cases. |
| `BUY-RET-003` | Real approved eligibility boundaries, early/late requests and completed-order interactions are checked at the exact clock boundaries without inventing a seven-day automatic completion rule. |
| `BUY-RET-004` | Actual evidence content, count and byte limits plus meaningful description boundaries are enforced server-side, with private access, no orphan upload on failure and useful field feedback. |
| `BUY-RET-005` | An authorized seller decision retains its exact reason and history. The buyer sees its actual result and can use only a permitted escalation; conflicting or repeated decisions are safe. |

Repayment also needs exact item/discount allocation, agreed shipping treatment,
retained original payment and once-only outgoing money evidence. Neither opening
a complaint nor marking a parcel returned establishes that a refund was paid.
Route/custody, decision authority and financial gates must be tested independently.

Until selected, retain the positive missing-feature failure and the three
dependent blocks. Keep the unavailable page honest and avoid sample submission
forms, fabricated case histories or success messages. No automatic new deadline
or hidden implementation inside BP01-BP04 is created by this plan.
