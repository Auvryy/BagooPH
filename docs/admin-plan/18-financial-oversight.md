# B18: Read-Only Financial Evidence and Reconciled Totals

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/financial-oversight` |
| Phase | 5 |
| Minimum prerequisites | [B16](16-cod-reconciliation.md), [B17](17-seller-settlement.md) |
| Result | Displayed cash and proceeds totals can be traced to immutable source records. |

## Purpose

Provide practical financial oversight once real cash and settlement records exist. Reviewers should distinguish collection, held cash, remittance, reconciliation, pending proceeds, and settlement with references to original events.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)
- [STYLE_GUIDE.md](../STYLE_GUIDE.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/CommissionLedger.php](../../app/Models/CommissionLedger.php)
- [app/Models/Order.php](../../app/Models/Order.php)
- [app/Models/Delivery.php](../../app/Models/Delivery.php)
- [app/Http/Controllers/Admin/AdminDashboardController.php](../../app/Http/Controllers/Admin/AdminDashboardController.php)
- [app/Http/Controllers/Admin/LogisticsHubController.php](../../app/Http/Controllers/Admin/LogisticsHubController.php)
- [resources/js/Pages/Admin/Dashboard.tsx](../../resources/js/Pages/Admin/Dashboard.tsx)
- [resources/js/Pages/Admin/Logistics.tsx](../../resources/js/Pages/Admin/Logistics.tsx)

## Implementation sequence

1. Inventory actual B16/B17 source records and define each read projection with currency, event time, holder/recipient/company scope, lifecycle gate, and adjustment treatment.
2. Build traceable totals for collected/held/remitted/reconciled COD and pending/eligible/settled product proceeds. Every total links to its included records; use exact decimal/cents and avoid double counting transfer stages.
3. Show platform product commission, seller product share, shipping/handling, rider earnings and logistics revenue separately only where their real source exists. Unimplemented categories remain unavailable.
4. Add ordinary validated date/state/company/recipient filters and stable pagination. Platform Admin reviews globally; Company Admin sees own operational cash only; seller sees own proceeds where applicable.
5. Render source references, before/after correction context, and linked immutable adjustments without edit/delete or override controls. A failed source fetch cannot be shown as a genuine zero balance.
6. Replace B12 unavailable finance panels only after source acceptance passes. Verify aggregation, boundaries, privacy, types/build, and traceability from dashboard to event.

## Decision and scope rules

- Do not sum order paid gross as platform revenue or use delivery count multiplied by fixed fees as recorded earnings.
- Collection, remittance, reconciliation, and settlement are distinct stages; the same money must not be counted repeatedly as revenue.
- Historical order/recipient/time facts use recorded snapshots. Current status filters must not erase completed history after restrictions/closure.
- Read oversight creates no new cash decision, commission edit, buyer completion, or seller payout permission.
- Display PHP/₱ and clearly defined product/shipping bases. Unknown or unavailable financial source is not zero.

## Data, legacy records, and recovery

- Use real immutable events and authorized adjustments. Legacy ambiguous ledger rows remain labeled/unavailable until controlled reconciliation proves them.
- Preserve evidence and subject/actor references; anonymization must not break financial traceability.
- No synthetic financial seed history, database reset, or bulk ledger repair is included in a reporting branch.

## Exclusions

- New money writers/overrides, bank/payment providers, advanced analytics, exports, rates changes, and forecasting.
- Refunds, chargebacks, exchanges, post-delivery dispute processing, or direct historical ledger editing.
- Live fleet/location dashboards and redesign of unrelated portals.

## Acceptance cases

| Case | Required result |
|---|---|
| Each total traced | Included source events and adjustments reproduce the displayed decimal total. |
| Transfer-stage double count | Held/remitted/reconciled sums reflect distinct balances, not repeated revenue. |
| Product versus shipping | 10%/90% basis and shipping/rider/logistics values stay separate. |
| Pending/eligible/settled | Require actual completion/reconciliation/settlement records, not delivery/paid flags. |
| Date/state filters | Canonical timezone boundaries and stable pagination yield correct scoped records. |
| Company/seller isolation | Foreign cash/proceeds/evidence deny. |
| Restricted/closed historical actor | Retained source provenance remains auditable by current authorized reviewer. |
| Missing source or query failure | Unavailable/error, not invented zero or sample balance. |
| Read-only adjustments/history | No edit/delete or payout route becomes available from oversight. |
| Payload/currency/build | Typed projections use PHP/₱ and production build passes. |

## Verification and review

Start with B16/B17 financial suites, shared privileged-access tests, and new aggregation/scope/traceability cases. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when every displayed financial value is traceable and appropriately scoped. Whole-admin/project readiness still requires the roadmap's complete cross-role gates; do not automatically add optional reports.

```text
Implement only B18 from docs/admin-plan/18-financial-oversight.md on admin/financial-oversight.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
