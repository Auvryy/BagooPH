# Buyer returns and COD repayment

The user selected this complete feature and its prerequisites on October 19,
2026, alongside the [buyer polishing work](../buyer-polishing-plan/README.md).
The user explicitly chose a seven-day window from physical delivery, including
orders already completed by buyer receipt. This plan records the selected
contract and separate deliveries; implementation evidence belongs in
[the roadmap](../CORE_FLOW_ROADMAP.md).

The original purchase, forward delivery, buyer completion, COD collection and
seller payment remain retained evidence. A post-delivery return has its own case,
item quantities, decisions, reverse parcel and repayment journal. It must never
rewrite a delivered order as a failed delivery or manufacture a new purchase.
The existing failed-delivery return route is a separate source.

## Selected contract

- The original buyer may request eligible purchased units from physical delivery
  through exactly seven elapsed days later. Completion does not waive this window.
  A request made in time may continue after the window. Early/late requests reject.
- Use the actual delivered/counter-collected evidence and frozen original route.
  A legacy status label without its source evidence needs review; never invent
  a delivery time, paid amount or route. Current listing availability does not
  remove rights in an original purchase.
- Support damaged items, wrong items, missing parts and items not as described.
  Require meaningful plain text of 10–1,000 characters and one to five private
  JPG/PNG/WEBP photos, at most 5 MB each. Requests identify exact purchased item
  rows, original options and positive quantities rather than current catalogue
  prices. A unit cannot occupy conflicting cases or be repaid twice.
- The original seller responds within three elapsed days. Rejection retains the
  exact reason and offers buyer escalation for three days. An overdue unanswered
  request can be escalated. A current approved admin resolves an escalated case
  with a reason; no deadline automatically approves a refund.
- Approval authorizes return transport. The buyer presents the return parcel at
  the original destination Bayan Hub within seven days of approval. Authorised
  handlers scan intake and each actual transport handover through the retained
  Mother Hub network. The seller collects the staged parcel at the origin Bayan
  Hub and confirms its receipt/condition. Physical receipt and monetary repayment
  remain distinct.
- An approved, received return repays the original paid merchandise amount for
  those units after original product discounts. Original shipping and shipping
  discounts remain separate and are not refunded by this feature. Return handling
  uses the existing network without a new buyer charge or new tariff engine.
- Keep one seller account/one shop, the original gross 90%/10% split, original
  voucher allocations and original redeemed usage. Compute separate exact-cent
  return corrections and effective remaining proceeds. Campaign usage and the
  original funded expense do not automatically become available again.
- Record a real manual COD repayment with a fixed original buyer recipient,
  exact amount, unique reference and private proof. The buyer confirms receipt
  before the case is labelled repaid. Seller recovery, original payment and any
  cash discrepancy remain independently visible. Opening/approving a case or
  receiving a parcel never establishes that money was paid.

Live banking, online checkout payments, exchanges, maritime/air transport and
unrelated support disputes are separate scope. This selected return feature
supersedes the baseline's former blanket refund deferral only for this contract.

## Delivery sequence and estimates

These are future effort and availability assumptions, not logged work or a
promise that a browser/release check has happened. Each estimate includes checks,
review and recovery room; retain the November 20 project target.

| Delivery | Branch proposal | Scope | Initial estimate |
|---|---|---|---|
| R01 | `feat/buyer-return-review` | [Requests and review](01-requests-and-review.md): eligibility, exact purchased units, private evidence, seller/admin decisions, history, notifications and buyer/seller/admin pages. | 6 focused hours, including verification. |
| R02 | `feat/buyer-return-custody` | [Reverse custody](02-reverse-custody.md): real intake, original hub sequence, compatible manifests/fleet, scans, staging, seller receipt and truthful failure/retry states. | 8 focused hours, including cross-role checks. |
| R03 | `feat/buyer-return-repayment` | [Money and closure](03-cod-repayment.md): original amount allocation, seller holds/revised proceeds/recovery, verified repayment, financial views and integrated acceptance. | 8 focused hours, plus a 4-hour integration/review reserve. |

A proposed working allocation is October 19, 11:00–14:30 for scope/review;
October 20, 09:00–12:00 and 13:00–16:00 for R01; October 21, 09:00–12:00 and
13:00–16:00 plus October 22, 09:00–11:00 for R02; October 22, 13:00–16:00 and
October 23, 09:00–12:00 and 13:00–15:00 for R03; October 23, 15:00–17:00 and
October 24, 09:00–11:00 for integration, all Asia/Manila. Weekend availability is
an assumption. Actual progress may finish earlier; do not fabricate hours to
match this allocation.

Local stacked branches may be prepared within the authorized goal. Their final
tip includes prerequisites. Only the user publishes commits; no branch, merge,
production mutation or deployment is automatic. Browser and deployed reminder
observations retain the polishing plan's explicit gates.

## Acceptance coverage

| Reference or prerequisite | Required evidence |
|---|---|
| `BUY-RET-001` | One owned eligible request with exact item quantities, stored private evidence, real buyer/seller states, correct notification and safe duplicate/foreign rejection. |
| `BUY-RET-002` | Before physical delivery, no request action or direct bypass exists. Repeat the negative check after implementing the positive feature. |
| `BUY-RET-003` | Before/exactly at/after the seven-day boundary; completed orders within the window remain eligible under the user's explicit decision. Preserve the original differing completion assumption. |
| `BUY-RET-004` | Real 9/10/1,000/1,001-character and zero/one/five/six-photo boundaries; actual file bytes/types, private access, rollback cleanup and useful field errors. |
| `BUY-RET-005` | Authorized seller rejection retains its exact reason, buyer sees the actual state and permitted escalation, and stale/repeated/conflicting decisions cannot rewrite history. |
| Reverse transport | Actual scoped scans, every frozen hop, vehicle/driver/capacity authority, distinct source/receiving actors, current custody, seller receipt, replay and failure rollback. No direct Bayan-to-Bayan movement. |
| Original money | Deterministic original unit/discount/commission allocation, total quantity and cent conservation, original shipping and sibling allocations unchanged. |
| Before seller payment | Pending claims hold release; accepted returns reduce remaining proceeds through retained adjustments. An already issued authorization needs explicit refreshed consent after a return changes its basis. |
| After seller payment | Original paid evidence stays paid. Record the actual seller recovery/receipt and any remaining debt; do not invent funds or repay the buyer twice. |
| Repayment | Real eligible source, exact amount/recipient, private proof, authorization, buyer receipt, duplicate/reference uniqueness, failed-file/write rollback and immutable history. |
| Restrictions and history | Recheck current authority on writes, preserve owned history and existing cash responsibilities, block closure with outstanding return/cash obligations and keep incomplete custody honest. |

Test with isolated SQLite `:memory:` and existing cross-role flow helpers.
Frontend changes require the production build. React interaction checks strengthen
local evidence; they do not silently establish a browser/device procedure or a
production transfer. Final handoff must state actual commits, checks, publication
state and remaining observations.
