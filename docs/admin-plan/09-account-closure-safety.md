# B09: Account Closure, Active Work, and Evidence Retention

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/identity-and-closure-safety` |
| Phase | 0 |
| Minimum prerequisites | [B06](06-account-restrictions.md), [B07](07-resource-restrictions.md), [B08](08-reviewed-identity-corrections.md) |
| Result | Closure cannot erase active obligations, last-admin access, or referenced evidence. |

B08 and B09 share one selected delivery branch. B08 must pass its focused gate before B09 starts; keep separate acceptance evidence and commits. Stop after the complete batch for user review and merge before B10.

## Purpose

Replace narrow deletion assumptions with a role-wide closure decision. Determine whether an account may be deleted, requires retained/anonymized identity, or must remain blocked because work, cash, settlement, or protected history depends on it.

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
- [app/Models/KycDecision.php](../../app/Models/KycDecision.php)
- [app/Http/Controllers/ProfileController.php](../../app/Http/Controllers/ProfileController.php)
- [app/Http/Controllers/Buyer/BuyerProfileController.php](../../app/Http/Controllers/Buyer/BuyerProfileController.php)
- [app/Models/Shop.php](../../app/Models/Shop.php)
- [app/Models/LogisticsCompany.php](../../app/Models/LogisticsCompany.php)

## Implementation sequence

1. Inspect deletion callers, soft/hard deletion behavior, foreign keys/cascades, reviewer references, and role-specific owned/assigned work. Build an explicit active-order/custody/cash/settlement/history matrix.
2. Centralize closure eligibility using freshly locked current work and account scope. Protect seller obligations, assigned riders, handlers/company resources, buyer orders, and the last eligible Platform Admin.
3. Define permitted closure outcomes using actual dependencies: unreferenced inactive accounts may use existing safe deletion; referenced identities need an explicit retained/anonymized policy. Do not bypass existing immutable KYC protections.
4. Record a reasoned closure event where required before any approved irreversible/anonymizing operation. Preserve subject/reviewer provenance and retry identity without relying on a deleted live user row.
5. Apply only documented eligible personal-field anonymization, if included, after retention review. Keep transactional snapshots, custody, proofs, decisions, and money references intact.
6. Return useful blockers/next responsible action in existing profile/admin flows. Tests must expose unsafe cascades and races between closure and new work.

## Decision and scope rules

- No closure while active orders, parcel custody, COD, settlement, or required responsibilities remain unresolved.
- Absent future COD/settlement persistence is not evidence that a referenced account has no cash obligation. Be conservative and report the missing verification.
- Closing an account cannot mutate its role, complete/cancel orders, clear cash, restore stock, or transfer custody.
- Reviewer/subject decision references remain valid; a history-preserving closure is distinct from destroying immutable KYC records.
- Final eligible admin protection must serialize against restrictions/corrections/new work. A count outside the transaction is insufficient.

## Data, legacy records, and recovery

- Inspect real migrations and cascade behavior before selecting an outcome. Preserve all verified seed identities and demo ownership.
- Do not claim a legal retention period or erase evidence on the basis of an invented policy. Specify actual retained/anonymized fields for review.
- Retain historical amounts and snapshots. Failed closure or anonymization rolls back all scoped changes; do not remove files needed by earlier decisions.

## Exclusions

- Database resets, blanket user deletion, automatic disposal of private decision evidence, or cascading financial/custody deletion.
- New legal/compliance regimes, unrestricted export, full privacy portal, or undocumented retention schedules.
- Reassignment/recovery, completing old transactions, and financial settlement implementation to force a closure through.

## Acceptance cases

| Case | Required result |
|---|---|
| Unreferenced eligible account | Only the documented safe closure outcome occurs; no unrelated records change. |
| Active buyer/seller order | Closure blocks with intact order/stock/snapshots. |
| Assigned rider/handler custody | Closure blocks; current custodian/proof remains. |
| Cash or pending proceeds | Closure blocks until actual records prove obligations resolved. |
| Missing financial source for referenced work | No false zero-obligation claim; explicit blocker. |
| KYC subject/reviewer history | Protected identity/evidence references remain valid and auditable. |
| Last eligible admin | Self or peer closure cannot remove final oversight. |
| Stale closure/new work race | Re-read and locking prevent deletion after new responsibility appears. |
| Foreign/unauthorized closure | No target, file, or related data mutation. |
| Repeated/failed closure | No duplicate destructive action; partial anonymization rolls back. |
| Retained/anonymized outcome | Only policy-approved personal fields change; historical evidence/amounts persist. |

## Verification and review

Start with CourierAccountClosureTest, AccountRoleImmutabilityTest, KycDecisionGovernanceTest, profile deletion tests identified during inspection, and new role-wide closure cases. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when the role-wide matrix and safe outcomes are verified against actual foreign references. Missing cash/history prerequisites remain explicit blockers rather than permission for destructive deletion.

```text
Implement only B09 from docs/admin-plan/09-account-closure-safety.md on admin/identity-and-closure-safety.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
