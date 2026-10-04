# B14: Accountable Delivery and Restricted-Work Exception Oversight

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/exception-oversight` |
| Phase | 3, after role-owned Phases 2 and 3 prerequisites |
| Minimum prerequisites | [B13](13-phase0-acceptance.md); verified durable Phase 2 custody/manifests and Phase 3 attempts, retry/RTS, and secure self-pickup paths |
| Result | Admins can oversee real exceptions without replacing the physical custodian. |

## Purpose

Provide a small exception queue linking restrictions, failed deliveries, return paths, secure counter work, and accountable recovery. Admin oversight assigns responsibility and reviews evidence; actor-owned scans still prove parcel and cash handoff.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)
- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)
- [SELLER_FLOW.md](../SELLER_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/Order.php](../../app/Models/Order.php)
- [app/Models/Delivery.php](../../app/Models/Delivery.php)
- [app/Services/Logistics/OrderStateMachineService.php](../../app/Services/Logistics/OrderStateMachineService.php)
- [app/Services/Orders/OrderLifecycleService.php](../../app/Services/Orders/OrderLifecycleService.php)
- [app/Services/Logistics/PublicTrackingService.php](../../app/Services/Logistics/PublicTrackingService.php)
- [app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php](../../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php)
- [app/Http/Controllers/Admin/LogisticsHubController.php](../../app/Http/Controllers/Admin/LogisticsHubController.php)
- [resources/js/Pages/Admin/Logistics.tsx](../../resources/js/Pages/Admin/Logistics.tsx)

## Implementation sequence

1. Verify prerequisite record writers and acceptance paths before UI work. If attempts/manifests/secure claims are missing, report the role-owned blocker; this branch cannot substitute an admin status form.
2. Define exception references to actual order, parcel, current custodian/assignment, reason, evidence, cash responsibility, and owning company/facility. Reuse B06/B07 affected-work records without inventing custody events.
3. Give Platform Admin cross-company oversight and Company Admin own-company handling. Validate current source state and responsible recovery assignment; record governance responsibility changes with reason/history.
4. Display recorded attempt/proof/hub-return/retry/RTS evidence. Attempts one/two retry only after required hub return; third failure enters documented reverse routing; seller receipt alone establishes RETURNED.
5. Display secure self-pickup readiness/expiry status without exposing plaintext claim codes. Actual release remains expected-hub identity/code/COD verification by the authorized counter actor.
6. Resolve an exception only after actual supporting actor-owned evidence proves the obligation handled. Re-read/lock affected state, keep idempotency and history, and link notices later through B15.

## Decision and scope rules

- Administrative responsibility assignment does not transfer physical custody or held cash. Preserve original custodian until a valid handoff scan.
- Read oversight grants no routine seller/rider/handler operation or buyer receipt confirmation.
- Restricted actors receive only separately authorized recovery actions; an exception does not open their general portal/new work.
- Counter policy retains hashed one-time claims, correct hub/state/identity/COD, seven-day expiry and documented reminder milestones.
- An exception cannot silently cancel orders, restore stock, mark money reconciled, or settle seller proceeds.

## Data, legacy records, and recovery

- Use actual durable attempt/manifest/custody records. Do not reconstruct successful physical events from an order status alone.
- Keep historical proofs, responsible actors, reasons, and prior exception assignments. No deleting failures after recovery.
- Backfill legacy exception references only from real records with explicit provenance; ambiguous custody remains a review blocker.

## Exclusions

- Building missing rider scans/manifests/retry/RTS/secure counter foundations inside this admin branch.
- Direct admin custody/state overrides, plaintext pickup claims, refunds/disputes, and new delivery networks.
- COD ledger/settlement (B16/B17), external notifications, and live tracking.

## Acceptance cases

| Case | Required result |
|---|---|
| Recorded restriction exception | Links affected work/current custodian and real responsible scope. |
| Foreign Company Admin | Cannot read/reassign/resolve another company's exception. |
| Suspended actor recovery | Only documented owned recovery action, no new work. |
| Assignment without handoff | Custody/cash holder remains unchanged. |
| Attempt one/two before return | No retry assignment until required hub custody evidence. |
| Third failure/reverse route | Documented reverse custody; RETURNED only after seller receipt. |
| Counter claim/expiry | No code exposure or admin release bypass; invalid/expired claim cannot resolve as delivered. |
| Stale resolution | Fresh source/evidence conflict; no invented completion. |
| Retry/audit failure | One original governance outcome; transaction rollback preserves evidence. |
| Missing prerequisite | Branch blocks visibly instead of creating fake events or success. |

## Verification and review

Start with LogisticsOrderCustodyFlowTest, CourierOperationsHardeningTest, failure-checkpoint and real-world exception suites, plus new scoped exception-oversight cases after prerequisites exist. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after oversight of actual exception evidence is verified. Any missing role-owned custody/attempt/counter prerequisite prevents completion; do not fill it with an admin override.

```text
Implement only B14 from docs/admin-plan/14-exception-oversight.md on admin/exception-oversight.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
