# Active buyer polishing case ledger

This is the **October 19 planning baseline**, not a changing implementation-status
list. Record later execution and closure evidence in the
[roadmap](../CORE_FLOW_ROADMAP.md). Read the [shared plan](README.md) for scope,
retained rules and the 57 likely passes excluded from active work by the user.

All **35 unresolved original cases** appear once in the allocation below:
three confirmed gaps, 23 blocked and nine unconfirmed. The additional rating
finding is counted separately. These are project interpretations and closure
conditions; no private source example accounts, products or transactions are
reproduced.

The prior review used local equivalent checks. It recorded three strict failures,
23 blocks and 66 Not Run; 57 of those unexecuted scenarios had positive automated
coverage and are not new manual backlog items here. A forecast, exclusion or rule
decision must not be relabelled as a completed original manual Pass.

## BP01: Search and notification controls

Owner plan: [BP01](01-search-and-notifications.md).

| Reference | Review finding | Required check to close |
|---|---|---|
| `BUY-SRCH-008` | Fail: no public shop-location filter. | Category, verified average rating and a supported shop area narrow the same eligible catalogue; clearing one retains the others and navigation cannot restore stale results. |
| `BUY-CHAT-005` | Fail: no explicit bulk-all notification action. | Read one owned notice, acknowledge the remaining intended snapshot, verify the exact unread decrease, preserve foreign/newer notices and handle retries/storage failure honestly. |

Additional finding, outside the original 35-case count:

| Reference | Review finding | Required check to close |
|---|---|---|
| `EXTRA-01` | Fail: the visible fractional rating choice is rejected by integer-only filter validation. | Activate every advertised threshold against the real route and verify matching averages, URL/state and boundaries; keep individual review stars integer-only. |

## BP02: Retained rules and precise boundaries

Owner plan: [BP02](02-rules-and-boundaries.md). A rule-conflict case closes only
with a recorded retained expectation and its actual result; its original
expectation is preserved for comparison.

| Reference | Review finding | Required check to close |
|---|---|---|
| `BUY-SRCH-002` | Blocked: independent shop taxonomy is assumed. | Verify only the approved root and active descendants; document that a separate store category system has not been implemented. |
| `BUY-VOU-003` | Blocked: an extra simultaneous platform slot is assumed. | Verify existing per-shop/Bagoo slots, replacement, excess-slot rejection and preserved sibling choices under the retained rule. |
| `BUY-VOU-004` | Unconfirmed: exact minimum-spend cent sequence was not executed. | Check one cent below, exactly at and one cent above the selected shop minimum, including the shortfall and unchanged rejected purchase. |
| `BUY-VOU-008` | Unconfirmed: complete percentage-cap boundary sequence was not executed. | Check eligibility, proportional saving, exact cap and values above it using charged merchandise prices and exact cents. |
| `BUY-VOU-009` | Blocked: the assumed shipping tariff differs. | Align actual fees first; verify shipping savings stop at the real fee and leave merchandise unchanged. |
| `BUY-VOU-010` | Blocked: a full seller-funded discount conflicts with the seller-share cap. | Verify the seller-funded cap, nonnegative merchandise/settlement amounts and preserved commission/shipping accounting. |
| `BUY-VOU-012` | Blocked: cancellation is assumed to restore a voucher use. | Verify once-only funding release with retained redemption usage, original allocation and unaffected sibling orders. |
| `BUY-CHK-001` | Blocked: combined example assumes different slots and fees. | Derive new per-shop and combined totals from retained rules, then compare previews, saved orders, Bag removal and exact allocations. |
| `BUY-CHK-002` | Blocked: an unsupported regional shipping price is assumed. | Verify supported destination changes, the actual per-shop fee and clear rejection of unsupported maritime service. |
| `BUY-CHK-003` | Blocked: a weight-bracket tariff is assumed. | Record the current fee basis and verify quantity/weight changes do not silently advertise an unimplemented tariff. |
| `BUY-CHK-005` | Blocked: the expected note maximum differs. | Check the real seller-note boundary, meaningful text and full persistence to the seller, including rejection just above the maximum. |
| `BUY-TRK-001` | Blocked: the expected tab set includes unsupported payment/refund states. | Verify actual COD stages, issue subnavigation and owned counts; explicitly retain missing payment/return prerequisites. |
| `BUY-TRK-003` | Blocked: receipt is assumed to release seller money immediately. | Verify owned physical delivery and buyer receipt separately from COD reconciliation and the actual seller-settlement gates. |
| `BUY-TRK-005` | Blocked: elapsed time is assumed to complete orders. | Verify an unconfirmed delivered order remains uncompleted with time; only explicit owned buyer receipt advances it. |
| `BUY-RATE-004` | Blocked: the review text maximum differs. | Check real text, integer-star, image count/content/type and byte boundaries, with owned purchase validation and no files saved on rejection. |

## BP03: Remaining interaction checks

Owner plan: [BP03](03-interaction-checks.md). Each row needs its named actual
interaction observation. Retain Not Run when only a related service test exists.

| Reference | Review finding | Required check to close |
|---|---|---|
| `BUY-SRCH-005` | Unconfirmed: the browser/console hostile-string sequence was not executed. | Check literal search output, no script execution/query expansion, no private listing or unintended write, and useful feedback without a server error. |
| `BUY-PDP-002` | Unconfirmed: actual option clicks and image changes were not observed. | Move between distinct pairs and back; visible unit price, available quantity and real image must follow the selected combination. |
| `BUY-PDP-003` | Unconfirmed: the precise sold-out button interaction was not executed. | A zero-stock pair cannot be selected/submitted while a different available pair remains usable. |
| `BUY-CART-008` | Unconfirmed: independent-session persistence was not observed. | Sign out/in and open another session for the same buyer; retain owned lines/quantities and exclude a different buyer's Bag. |
| `BUY-CART-009` | Unconfirmed: full removal/reload/total sequence was not executed. | Remove selected and unselected rows; verify persistence, remaining totals/selections, foreign-ID denial and honest failed-request recovery. |
| `BUY-RET-002` | Unconfirmed: the exact pre-delivery negative UI check was not executed. | No actionable post-delivery request or direct bypass appears before delivery; this result cannot establish the positive return feature. |

## BP04: Reminder observation

Owner plan: [BP04](04-reminder-and-release.md).

| Reference | Review finding | Required check to close |
|---|---|---|
| `BUY-CHAT-004` | Unconfirmed: a deployed scheduled reminder was not observed. | An eligible owned subscription produces one real linked notice before the sale; retained delivery state proves arrival, with no replayed or late stale reminder. |

## PAY: Payment prerequisite

Owner plan: [PAY](05-payment-and-return-prerequisites.md#pay-online-payment-and-prepaid-cancellation).
These seven remain Blocked while COD-only scope is retained. They are not silently
included in a smaller UI repair or counted as accepted online-payment behavior.

| Reference | Review finding | Required check after prerequisite selection |
|---|---|---|
| `BUY-PAY-002` | Blocked: no online successful-payment lifecycle. | Verify the actual exact charge/reference and once-only paid transition independently of the browser return. |
| `BUY-PAY-003` | Blocked: no gateway-failure/retry lifecycle. | Verify genuine failure, truthful unpaid state, supported retry and no unauthorized shipment or false paid ledger. |
| `BUY-PAY-004` | Blocked: no gateway-cancel return lifecycle. | Preserve one original unpaid order and avoid a duplicate payment/order when the buyer leaves the gateway. |
| `BUY-PAY-005` | Blocked: no closed-browser payment reconciliation. | Verify authenticated, amount-bound server events and safe duplicate/out-of-order handling without browser participation. |
| `BUY-PAY-006` | Blocked: no selected unpaid-online expiry process. | Verify only eligible unpaid online orders expire and restore the correct stock/reservation once under the agreed voucher-use policy. |
| `BUY-PAY-007` | Blocked: no supported online payment/expiry boundary. | Verify before/at/after boundary and competing outcomes without a paid-and-cancelled or duplicated cash result. |
| `BUY-CAN-003` | Blocked: no complete prepaid cancellation/refund. | Verify permitted cancellation, exact retained original payment/repayment and once-only inventory/funding effects with the approved reuse rule. |

## RET: Buyer-return prerequisite

Owner plan: [RET](05-payment-and-return-prerequisites.md#ret-buyer-returns-and-repayment).
The negative pre-delivery check above remains separate.

| Reference | Review finding | Required check after prerequisite selection |
|---|---|---|
| `BUY-RET-001` | Fail: positive buyer return submission is unavailable. | Store one eligible owned item/evidence request, present truthful buyer/seller states, notify the right owner and prevent duplicate/foreign submissions. |
| `BUY-RET-003` | Blocked: request window depends on the missing flow. | Check actual approved eligibility and exact time boundaries, including explicit completion interactions without automatic completion. |
| `BUY-RET-004` | Blocked: return evidence/description checks depend on submission. | Verify meaningful text and private evidence content/count/size boundaries, ownership and orphan-file cleanup on rejection/failure. |
| `BUY-RET-005` | Blocked: no seller decision/escalation request workflow. | Retain authorized decisions and reasons, show the true buyer state, permit only authorized escalation and reject conflicting/replayed decisions. |

## Coverage audit and evidence boundaries

The delivery allocation is **2 BP01 + 15 BP02 + 6 BP03 + 1 BP04 + 7 PAY + 4 RET
= 35 original unresolved cases**, plus `EXTRA-01`. No active case is assigned to
two owners or omitted. The 57 excluded likely passes retain their existing
automated baseline rather than another manual checklist.

The assessment's fresh local baseline passed **803 backend tests / 14,007
assertions** and **447 frontend/helper checks** at `7c1bb59`. Those checks used
SQLite in memory and React/helper execution. They did not establish a complete
manual or deployed acceptance run. The additional controls, precise money
sequences and missing prerequisites must be judged by their own closure evidence.

No application behavior was changed by creating this plan. Future selected work
updates the roadmap with actual outcomes and changes only the relevant case's
status after its full agreed gate is satisfied.
