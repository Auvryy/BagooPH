# B04: Logistics Resource Eligibility and Company Scope

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/logistics-resource-eligibility` |
| Phase | 0 |
| Minimum prerequisites | [B02](02-application-validation.md) |
| Result | Network reads, placement, routing, and new-work actions share positive parent/scope checks. |

## Purpose

Apply the same company, facility, handler, courier, and fleet eligibility at selectors and backend action boundaries. Keep Platform Admin account approval separate from company placement and facility responsibility.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)
- [MASTER_LOGISTICS_SPECIFICATION.md](../MASTER_LOGISTICS_SPECIFICATION.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/LogisticsCompany.php](../../app/Models/LogisticsCompany.php)
- [app/Models/LogisticsHub.php](../../app/Models/LogisticsHub.php)
- [app/Models/HubHandler.php](../../app/Models/HubHandler.php)
- [app/Models/LogisticsFleet.php](../../app/Models/LogisticsFleet.php)
- [app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php](../../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php)
- [app/Http/Controllers/Admin/LogisticsHubController.php](../../app/Http/Controllers/Admin/LogisticsHubController.php)
- [app/Services/Logistics/LogisticsRoutingEngine.php](../../app/Services/Logistics/LogisticsRoutingEngine.php)
- [app/Models/User.php](../../app/Models/User.php)
- [routes/web.php](../../routes/web.php)

## Implementation sequence

1. Inventory executable company/hub/handler/fleet relationships and status values, courier company/hub placement, and all selectors/actions. Write a positive eligibility matrix using actual fields, not assumed new vehicle or admin roles.
2. Centralize ownership and parent checks: approved active company, active supported facility, eligible account, active scoped assignment, appropriate fleet readiness, and current action authority.
3. Use these checks in hub selection/workstations, handler assignment, courier placement, vehicle assignment, and every new-routing/dispatch entry point. Re-read requested parent relationships rather than trusting session or client IDs.
4. Filter options consistently but also authorize direct writes and private reads. Company administrators operate their own network; Platform Admin oversight does not grant handler scans.
5. Keep courier duty separate: off duty excludes new work while allowed existing-assignment completion remains narrowly scoped. Suspended account/resource needs controlled recovery, not a duty toggle.
6. Test selector/action agreement, root/subdomain routes, cross-company requests, and eligibility changes between selection and commit. Preserve existing physical custody and route checkpoints.

## Decision and scope rules

- All relevant parents must be positively eligible. An active child or handler assignment cannot bypass a rejected/suspended account or company.
- Rider company acceptance is placement, never platform KYC approval. Facility assignment cannot create global logistics eligibility.
- Use actual LogisticsFleet ownership and assignment relationships. Do not infer company membership from an arbitrary hub/plate supplied by the client.
- Service coverage remains contiguous land routes with at least one Mother Hub. Eligibility checks must not create a shortcut that skips required custody.
- An assignment change is administrative scope only; it is not evidence that the parcel or cash changed hands.

## Data, legacy records, and recovery

- Preserve placement history, orders, route/checkpoint references, and existing manifests where present. Identify invalid foreign/orphaned relationships without deleting custody evidence.
- Report unapproved/ambiguous legacy company or facility records for explicit review; do not grant approval by setting all active flags.
- Use isolated logistics seed fixtures and preserve verified demo accounts. Do not reset the development network to make tests pass.

## Exclusions

- Manifest lifecycle/scanning (Phase 2), failed-attempt/RTS/self-pickup implementation (Phase 3), and COD ledger (Phase 5).
- Reasoned account/resource restriction decisions (B06/B07), exception-management screens (B14), or new company accreditation modules.
- Live GPS, AI dispatch, maritime routes, or fleet analytics/redesign.

## Acceptance cases

| Case | Required result |
|---|---|
| Owned eligible network | Company/facility/assignment checks allow only the actor's permitted operation. |
| Rejected/suspended parent | Active child flags cannot enable selection, dispatch, or direct action. |
| Foreign parent or resource | Company/hub/handler/fleet/rider mismatch rejects without partial assignment. |
| Inactive or unknown child state | No new work; source-state denial is consistent across UI and service. |
| Unapproved courier | Company placement cannot make the account KYC eligible. |
| Off-duty assigned courier | No new assignment; only documented existing-assignment actions remain. |
| Stale selector/session | Changed parent/account eligibility is re-read before commit. |
| Admin oversight request | Authorized read does not authorize routine custody scan. |
| Assignment failure/retry | No partial parent/placement changes or duplicate action; existing custody stays intact. |
| Root/subdomain and query agreement | Both portal variants and candidate queries enforce the same scope. |

## Verification and review

Start with LogisticsHubSuiteTest, LogisticsOrderCustodyFlowTest, LogisticsSeedBaselineTest, CourierPortalAccessTest, CourierOperationsHardeningTest, and shared role/privileged access tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when parent and ownership eligibility is consistently enforced at the traced logistics entry points. Record missing later custody prerequisites in the roadmap without implementing them here.

```text
Implement only B04 from docs/admin-plan/04-logistics-resource-eligibility.md on admin/logistics-resource-eligibility.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
