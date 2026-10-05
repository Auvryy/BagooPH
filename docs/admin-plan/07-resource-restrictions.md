# B07: Independent Shop and Logistics Resource Restrictions

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/governance-restrictions` (B06+B07 batch) |
| Phase | 0 |
| Minimum prerequisites | Merged [B03](03-shop-approval-eligibility.md) and [B04](04-logistics-resource-eligibility.md); [B06](06-account-restrictions.md) focused gate verified on the shared branch |
| Result | A resource restriction has its own reason/history and cannot bypass or silently clear its parent. |

## Purpose

Govern shops, companies, hubs, handler assignments, and fleet independently of account activity. Restrict the affected scope, retain operational evidence, and require eligible parents plus a separate decision before reinstatement.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)
- [MASTER_LOGISTICS_SPECIFICATION.md](../MASTER_LOGISTICS_SPECIFICATION.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/Shop.php](../../app/Models/Shop.php)
- [app/Models/LogisticsCompany.php](../../app/Models/LogisticsCompany.php)
- [app/Models/LogisticsHub.php](../../app/Models/LogisticsHub.php)
- [app/Models/HubHandler.php](../../app/Models/HubHandler.php)
- [app/Models/LogisticsFleet.php](../../app/Models/LogisticsFleet.php)
- [app/Http/Controllers/Admin/LogisticsHubController.php](../../app/Http/Controllers/Admin/LogisticsHubController.php)
- [app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php](../../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php)
- [app/Services/Logistics/LogisticsRoutingEngine.php](../../app/Services/Logistics/LogisticsRoutingEngine.php)

## Implementation sequence

1. Map actual resource status/is_active values and parent relationships; define each permitted restriction/reactivation source state and authorized owner.
2. Reuse B06's decision principles with explicit resource subject/version and parent scope. Platform Admin owns shop/company governance; eligible Company Admin may act only on documented resources of its own company.
3. Lock target, relevant parent eligibility, and affected active work consistently. Record reason, actor/time, before/after, retry identity, and recovery responsibility atomically.
4. Apply the resource restriction to new listings/orders/routing/dispatch/assignment as applicable. Preserve current manifest/checkpoint/cash facts and do not treat replacement as a physical handoff.
5. Require eligible account/company/shop parents and current review for reinstatement. Keep child restrictions explicit; reactivating a company or hub cannot automatically reinstate handlers, fleet, or stale assignments.
6. Show parent and local restriction separately, with affected-work context. Test every resource type rather than relying on a shared UI badge.

## Decision and scope rules

- Resource review, activity, and account KYC remain separate. An active resource cannot bypass a restricted owner or company.
- Company Admin cannot lift platform company/account suspension, access foreign resources, approve platform KYC, or settle seller money.
- Disable new work without silently cancelling orders, changing stock, deleting manifests, or transferring cash/custody.
- Identical decisions are idempotent; stale parent/target state conflicts. Recovery and later operational exceptions use documented actor-owned actions.
- Unknown resource state or missing/mismatched parent denies new eligibility; no default active fallback.

## Data, legacy records, and recovery

- Preserve all referenced resources and history. Report legacy unreasoned restrictions separately rather than inventing audit.
- Do not cascade reactivation or deletion. Inventory orphaned/foreign relationships for controlled repair without rewriting prior custody.
- Stage only justified schema changes, with migration and rollback behavior reviewed against existing references and immutable audit.

## Exclusions

- Account decisions (B06), reviewed identity/category repair (B08), and full recovery/manifest/RTS implementation (later phases).
- Bulk resource deletion, parent-to-child activation shortcuts, new role/permission editors, or platform KYC delegated to company staff.
- Fleet optimization, live presence, pricing/rates, and finance settlement.

## Acceptance cases

| Case | Required result |
|---|---|
| Restrict each resource type | Scoped new work stops; reason/history and responsible recovery persist. |
| Active child with restricted parent | New work remains denied regardless of local active flag. |
| Parent reinstated | Restricted children remain restricted until their own valid decisions. |
| Company Admin own scope | Documented own-resource decisions pass; foreign/company-level platform overrides deny. |
| Active parcel/manifest/cash | History and custodian remain; no automatic reassignment or money transfer. |
| Missing reason/unknown source | No partial mutation or false success. |
| Stale target/parent | Conflict after fresh re-read; no parent eligibility bypass. |
| Identical/competing retry | One immutable decision; competing state/reason cannot overwrite. |
| Audit/recovery failure | Resource and affected decision roll back together. |
| Root/subdomain/service calls | Selectors and backend actions agree with independent resource scope. |

## Verification and review

Start with LogisticsHubSuiteTest, LogisticsOrderCustodyFlowTest, CourierOperationsHardeningTest, buyer/seller shop eligibility cases, and new resource-decision tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after each resource's reasoned decision and effective parent eligibility are verified. Do not use this branch to implement custody recovery or reactivate all dependents.

```text
Implement B07 from docs/admin-plan/07-resource-restrictions.md on admin/governance-restrictions after B06 verification.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
