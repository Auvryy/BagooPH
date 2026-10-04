# B16: COD Custody Records and Platform Reconciliation

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/cod-reconciliation` |
| Phase | 5 |
| Minimum prerequisites | [B13](13-phase0-acceptance.md), [B14](14-exception-oversight.md), [B15](15-governance-notifications.md); verified Phases 2-4 custody, recovery, counter, and notification gates |
| Result | Every COD movement is recorded; platform reconciliation is a distinct authorized decision. |

## Purpose

Build traceable cash movement from collection to platform reconciliation. Parcel delivery, a paid flag, or a commission row is not evidence that cash reached the hub or platform.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)
- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/Order.php](../../app/Models/Order.php)
- [app/Models/Delivery.php](../../app/Models/Delivery.php)
- [app/Models/CommissionLedger.php](../../app/Models/CommissionLedger.php)
- [app/Services/Orders/CheckoutOrderService.php](../../app/Services/Orders/CheckoutOrderService.php)
- [app/Services/Orders/OrderLifecycleService.php](../../app/Services/Orders/OrderLifecycleService.php)
- [app/Services/Logistics/OrderStateMachineService.php](../../app/Services/Logistics/OrderStateMachineService.php)
- [app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php](../../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php)
- [app/Http/Controllers/Admin/LogisticsHubController.php](../../app/Http/Controllers/Admin/LogisticsHubController.php)

## Implementation sequence

1. Inspect payment/order snapshots, cash actions, commission creation, and actual Phase 3 counter/return behavior. Define expected COD from server records with separate product/shipping accounting.
2. Design append-only cash events for rider or counter collection, rider-to-hub remittance, hub-to-platform remittance, platform reconciliation, and linked adjustments. Record money in decimal/integer cents with actor, holder/recipient scope, server time, references, reason and retry identity.
3. Authorize each operational writer at its real role/company/facility/assignment boundary. A recipient verifies the expected source and amount; the client cannot dictate due amount or falsely declare a handoff.
4. Lock order/cash obligations in a consistent order and enforce uniqueness/source-state constraints. Record each movement and affected balances atomically; shortage/overage is an explicit discrepancy, not overwritten history.
5. Add Platform Admin reconciliation of recorded remittance evidence, with current version, reason for discrepancies, and immutable decision. Keep seller commission pending until B17's independent eligibility is satisfied.
6. Integrate real cash event notices with B15, then test duplicate/partial remittance, recovery/RTS effects, append-only correction, privacy, and no early settlement.

## Decision and scope rules

- Physical parcel custody and cash custody are separate records. A parcel scan or DELIVERED does not reconcile cash.
- Platform reconciliation requires actual complete remittance/evidence under the contract. A guessed order-count fee or payment_status is insufficient.
- Company Admin handles only own operational remittance; Platform Admin performs platform reconciliation. Neither may edit/delete original money events.
- Adjustments reference original events and explain actor/reason/amount. Client floats, timestamps, commissions, and totals are never authority.
- Expected amount, received amount, discrepancy, held cash, remitted cash, and reconciled cash remain distinguishable. Failed transaction leaves no partial balance movement.

## Data, legacy records, and recovery

- Preserve existing payment/commission history but do not relabel old paid or settled flags as proven COD reconciliation.
- Identify ambiguous legacy cash obligations for controlled review with evidence. Do not manufacture cash collection or backdate a remittance.
- Inspect real constraints and references before migration. Preserve closed/restricted actor provenance and current cash holder; no destructive ledger reset.

## Exclusions

- Seller settlement (B17), read-only consolidated finance reports (B18), refunds/disputes, and payment gateway integration.
- Admin editing original ledger amounts, inventing shipping splits, completing orders, or routine custody scans.
- Live banking integrations, real external transfers during tests, or new paid services.

## Acceptance cases

| Case | Required result |
|---|---|
| Rider/counter valid collection | Exact server-expected COD recorded by the authorized assigned actor once. |
| Client amount/foreign assignment | Injection or wrong scope rejects without cash/balance changes. |
| Rider-to-hub and hub-to-platform | Correct source/recipient evidence and amounts; holder changes only with recorded movement. |
| Duplicate/competing remittance | One recorded movement; stale/different attempt conflicts. |
| Shortage/overage/partial movement | Explicit discrepancy and preserved obligations; no overwritten original amount. |
| Reconciliation prerequisites missing | Paid/delivered flag cannot grant reconciliation or settlement. |
| Authorized append adjustment | Original event retained; linked actor/reason/cents are traceable. |
| Financial/audit write fails | Entire movement rolls back without partial balance. |
| Restricted actor/recovery cash | No new work; narrowly authorized remittance preserves provenance and holder. |
| DELIVERED or buyer completion | Neither alone marks COD reconciled or seller settled. |
| Company isolation/immutable records | Foreign cash and update/delete requests deny; private references stay scoped. |

## Verification and review

Start with order lifecycle, custody, failure/counter, and commission assertion suites plus new COD event/reconciliation acceptance tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when actual cash movement and platform reconciliation pass their gate without early commission settlement. Seller release and consolidated financial views remain B17/B18.

```text
Implement only B16 from docs/admin-plan/16-cod-reconciliation.md on admin/cod-reconciliation.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
