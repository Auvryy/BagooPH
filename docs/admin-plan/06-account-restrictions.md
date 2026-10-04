# B06: Reasoned Account Restrictions and Admin Continuity

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/account-restrictions` |
| Phase | 0 |
| Minimum prerequisites | [B03](03-shop-approval-eligibility.md), [B04](04-logistics-resource-eligibility.md), [B05](05-buyer-access-alignment.md) |
| Result | Account restriction/reactivation is audited, safe during work, and cannot remove all eligible admins. |

## Purpose

Add the actual governance decision that removes or restores account activity eligibility. It must explain why, preserve obligations, and identify recovery responsibility without replacing custody, approval, or financial records.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)
- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/User.php](../../app/Models/User.php)
- [app/Models/Order.php](../../app/Models/Order.php)
- [app/Models/Delivery.php](../../app/Models/Delivery.php)
- [app/Models/CommissionLedger.php](../../app/Models/CommissionLedger.php)
- [app/Http/Controllers/Admin/AdminDashboardController.php](../../app/Http/Controllers/Admin/AdminDashboardController.php)
- [app/Services/KycDecisionService.php](../../app/Services/KycDecisionService.php)
- [app/Http/Middleware/EnsureApprovedAccount.php](../../app/Http/Middleware/EnsureApprovedAccount.php)
- [resources/js/Pages/Admin/Users.tsx](../../resources/js/Pages/Admin/Users.tsx)
- [routes/web.php](../../routes/web.php)

## Implementation sequence

1. Define per-role affected-work queries and positive source states for suspend/deactivate/reactivate. Distinguish active work, current custodian, held cash, approval, profile scope, and account activity.
2. Design append-only restriction decisions with subject, current version, action/reason, actor/time, before/after and retry identity. Inspect existing audit patterns but do not reuse a KYC submission decision as an unrelated suspension record.
3. Authenticate a freshly eligible Platform Admin; lock account, affected scope, continuity guard, and required recovery records in a consistent transaction order. Apply restriction and accountable affected-work record atomically.
4. Block new work according to the admin matrix while preserving existing evidence and the buyer's narrow owned-order exception. For carried parcels/cash, record the responsible recovery scope without automatic reassignment or stock restoration.
5. Require a separate reasoned reactivation with valid reviewed identity and eligible relevant scope. Do not approve rejected KYC, clear child restrictions, resume stale assignments, or change role/duty implicitly.
6. Prevent routine suspension/deactivation from removing the last eligible Platform Admin, including two competing admin restrictions. Add clear confirmation/context/error states in the existing account UI.

## Decision and scope rules

- Meaningful reason and exact source state are mandatory. An identical retry returns the original result; a competing action/reason/version conflicts.
- Restriction changes activity eligibility only. Account KYC, shop/company restrictions, placement, courier duty, custody, and settlement remain separate.
- An affected-work record identifies responsibility while actual recovery stays actor-owned. B14 adds later exception oversight; no routine scan override is introduced here.
- Evaluate last-admin eligibility using the same active/admin/adult policy as privileged access, with concurrency-aware serialization. Counting an inactive, invalid, or pending replacement is insufficient.
- No public admin registration or role conversion solves continuity. Controlled recovery is separate and must not become a hidden privileged bypass.

## Data, legacy records, and recovery

- Preserve historical status facts but do not fabricate reasoned decisions for unaudited old changes. Display legacy provenance clearly.
- Never delete orders, cash, review decisions, or assignment history to make suspension possible. A failure to persist required restriction/recovery audit rolls back the decision.
- Known invalid reviewed dates remain B08 review candidates. Preserve the explicit missing-date legacy compatibility until a controlled reviewed change is available.

## Exclusions

- Resource-specific restriction decisions (B07), reviewed identity correction (B08), closure (B09), and full delivery exception/retry UI (B14).
- New roles/permission editors, admin impersonation, destructive cancellation, arbitrary order-state edits, or cash adjustments.
- External notifications and broad redesign of account management.

## Acceptance cases

| Case | Required result |
|---|---|
| Reasoned suspension for each role | One restriction records actor/reason/current state and blocks new work in affected scope. |
| Missing/unknown reason or state | Validation/conflict; no account or audit mutation. |
| Identical retry | Original outcome/reviewer/time/reason; no duplicate recovery responsibility. |
| Competing stale decision | One transition wins; stale request conflicts without overwriting. |
| Active orders/parcel/cash | Evidence and current custodian remain; responsible recovery is recorded. |
| Buyer existing-order exception | Owned tracking/receipt only; new/foreign/premature work denies. |
| Separate reactivation | Valid approval/scope required; child restrictions and stale assignments do not clear. |
| Inactive admin/stale session | All privileged reads/writes deny on root and subdomain routes. |
| Last eligible admin | Self or peer restriction cannot remove final oversight; concurrent requests serialize safely by design. |
| Audit/recovery write failure | Full restriction rolls back, including related records. |
| Role injection | Saved role stays fixed; decision cannot grant another role. |

## Verification and review

Start with SharedPrivilegedAccessTest, SharedPortalAccessTest, AccountRoleImmutabilityTest, CourierOperationsHardeningTest, and new account-restriction/continuity acceptance cases. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when reasoned account decisions and their active-work/continuity safeguards pass the scoped gate. Resource restrictions and operational recovery remain separately bounded tasks.

```text
Implement only B06 from docs/admin-plan/06-account-restrictions.md on admin/account-restrictions.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
