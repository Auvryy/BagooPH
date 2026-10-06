# B12: Truthful Admin Overview and Operational Counts

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/moderation-audit-and-overview` (B10-B12 batch) |
| Phase | 0 |
| Minimum prerequisites | [B04](04-logistics-resource-eligibility.md), [B11](11-governance-audit-viewer.md) |
| Result | Overview/Logistics show recorded counts and availability without invented finance. |

## Purpose

Replace sample or guessed success data with real queues and well-labeled recorded values. Admins should see what can be reviewed now and which finance/exception modules are still unavailable.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [STYLE_GUIDE.md](../STYLE_GUIDE.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Http/Controllers/Admin/AdminDashboardController.php](../../app/Http/Controllers/Admin/AdminDashboardController.php)
- [app/Http/Controllers/Admin/LogisticsHubController.php](../../app/Http/Controllers/Admin/LogisticsHubController.php)
- [app/Models/Order.php](../../app/Models/Order.php)
- [app/Models/Delivery.php](../../app/Models/Delivery.php)
- [app/Models/User.php](../../app/Models/User.php)
- [app/Models/CommissionLedger.php](../../app/Models/CommissionLedger.php)
- [resources/js/Pages/Admin/Dashboard.tsx](../../resources/js/Pages/Admin/Dashboard.tsx)
- [resources/js/Pages/Admin/Logistics.tsx](../../resources/js/Pages/Admin/Logistics.tsx)
- [resources/js/types/index.d.ts](../../resources/js/types/index.d.ts)

## Implementation sequence

1. Inventory each displayed metric/action and its actual source query. Name the statistic precisely: order count, pending application, active assignment, on-duty courier, or recorded monetary amount.
2. Replace fixed-fee order-count multiplication and unconditional ONLINE status. On duty is not proof of online/live location; unknown presence is unavailable, not online.
3. Show pending application/shop review and governance queues from delivered records. Link unresolved affected work to actual records; later exception screens remain unavailable until B14.
4. Keep money in PHP/₱ using decimal-aware formatting. Order gross/payment flags cannot be labeled platform revenue, reconciled COD, rider payout, or settled seller proceeds.
5. Remove sample disputes, invented charts/trends, and false-success buttons within the admin surface. Disable unavailable actions with a clear state and use truthful empty results.
6. Verify backend response projections and frontend types, filters, labels, and build. Do not add a finance ledger simply to fill a dashboard.

## Decision and scope rules

- Every shown total must have a defined source, scope, status filter, and currency basis. Do not present guessed shipping splits as recorded earnings.
- Counts across roles include the actual five-role policy; logistics responsibility is not a new account role.
- Company-specific overview stays within own company; cross-company Platform Admin oversight still uses fresh privileged checks.
- Unknown data is unavailable and empty data is empty. Zero cash cannot be inferred from an absent cash model.
- Future reconciliation/settlement totals are gated by B16-B18 and their underlying phases; basic overview does not prove whole admin readiness.

## Data, legacy records, and recovery

- Read only existing recorded sources; preserve order/payment/ledger history. No synthetic financial events or seed-generated trends.
- Distinguish incomplete legacy ledger data from a real zero balance. Document the query's limits in the roadmap.
- Do not execute database resets or change rider duty merely to demonstrate an overview state.

## Exclusions

- COD/settlement event writers and financial reports (B16-B18), live GPS/presence tracking, analytics, and rates editors.
- Full disputes/refunds, fake payment/remittance success, and new external services.
- Broad redesign/restyling of buyer/seller/rider portals.

## Acceptance cases

| Case | Required result |
|---|---|
| Real queue counts | Match seeded isolated recorded states and authorized scope. |
| No records | Honest empty state; no examples or fabricated trends. |
| Unavailable finance source | Unavailable message; no count-times-fee revenue or invented payout. |
| Duty/presence labels | On-duty state is accurate; no unsupported ONLINE assertion. |
| Money labels/currency | PHP/₱ and defined monetary basis; gross cannot masquerade as commission. |
| Company isolation | No foreign network counts/details leak. |
| Inactive/stale admin | Overview/detail reads deny across portal variants. |
| Unavailable action | No mutation or false success endpoint. |
| Mixed lifecycle states | Counts use documented canonical criteria without changing order state. |
| Types/build | Actual payload and TS types agree; required production build passes. |

## Verification and review

Start with SharedPrivilegedAccessTest and LogisticsHubSuiteTest plus focused overview/query truthfulness tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when the admin surface shows only real scoped data and honest unavailable modules, and the frontend build passes. Finance remains a later source-driven feature.

```text
Implement B12 from docs/admin-plan/12-truthful-overview.md on admin/moderation-audit-and-overview after B11's focused gate passes.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
