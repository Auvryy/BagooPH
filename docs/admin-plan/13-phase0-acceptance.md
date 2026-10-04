# B13: Cross-Role Phase 0 Acceptance and Failure Triage

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `test/admin-phase0-acceptance` |
| Phase | 0 acceptance gate |
| Minimum prerequisites | [B01](01-seller-category-approval.md) through [B12](12-truthful-overview.md) |
| Result | An evidence-based Phase 0 decision with no hidden test suppression or waived prerequisite. |

## Purpose

Audit shared governance as a buyer-seller-courier-logistics-admin transaction rather than a collection of screens. Diagnose pre-existing failures and distinguish fixture contradictions, genuine bugs, missing later workflows, and verification limits before claiming any phase complete.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)
- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [phpunit.xml](../../phpunit.xml)
- [tests/Feature/ChallengerM1Test.php](../../tests/Feature/ChallengerM1Test.php)
- [tests/Feature/ChallengerM1StressTest.php](../../tests/Feature/ChallengerM1StressTest.php)
- [tests/Feature/E2E/Support/InteractsWithRoles.php](../../tests/Feature/E2E/Support/InteractsWithRoles.php)
- [tests/Feature/E2E/Support/SimulatesOrderLifecycle.php](../../tests/Feature/E2E/Support/SimulatesOrderLifecycle.php)
- [tests/Feature/E2E/Support/AssertsCommissionLedgers.php](../../tests/Feature/E2E/Support/AssertsCommissionLedgers.php)
- [tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php](../../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php)
- [tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php](../../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php)
- [tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php](../../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php)
- [docs/CORE_FLOW_ROADMAP.md](../../docs/CORE_FLOW_ROADMAP.md)

## Implementation sequence

1. Read the latest roadmap baseline and capture a fresh isolated full-suite baseline before edits. Compare exact failing test identities, failure/error types, and assertion context, not only counts.
2. Build a Phase 0 requirements-to-entry-point/test matrix: canonical inputs, approval, fixed roles/adult identity, independent shop/resource scope, restrictions, active-work closure, private evidence, and honest unavailable modules.
3. Exercise every role and root/subdomain variant with active reviewed, pending, rejected, inactive, suspended, and unknown states. Include direct service/URL access, foreign IDs, stale sessions, and legacy compatibility.
4. Triage each failure using actual fixture setup, executable path, and normative rule. Inspect ChallengerM1StressTest's failing order setup without assuming the null order is merely a fixture problem.
5. Repair genuinely contradictory fixtures and scoped acceptance bugs while preserving mandatory Mother Hub, actor ownership, buyer completion, and pending financial gates. If a missing feature needs its own branch, name the owner/update the plan and keep the applicable gate incomplete.
6. Run affected checks then the full isolated suite, record exact new/resolved/persisting failures, design limits and required gate outcomes, and produce a concise release assessment in the roadmap.

## Decision and scope rules

- No skip/delete/blanket expected failure, weaker assertions, direct status shortcut, or restoration of forbidden admin/fixture overrides to obtain green tests.
- Phase 0 needs every applicable mandatory requirement to pass. Later-phase tests cannot be silently waived for their eventual phase; missing prerequisites remain recorded blockers.
- Whole-project completion requires the complete applicable transaction/adversarial/recovery/accounting suite. Publishing a scoped branch is a different claim.
- Simultaneous PostgreSQL locking remains unverified by SQLite; inspect ordering/constraints and report the limit instead of claiming concurrency proof.
- Do not change role/custody/financial contracts to fit old tests. Align fixtures with authority only after verifying the intended behavior.

## Data, legacy records, and recovery

- Tests use only SQLite :memory:. No development/production reset, destructive seeder, or business-record patch is allowed.
- Ignored old test artifacts may not exist on a fresh checkout. Capture a new baseline instead of inferring identical failures from matching totals.
- Preserve verified demo accounts and real history. Fixture repairs must create intended roles and legitimate evidence/eligibility, not mutate saved roles.

## Exclusions

- A catch-all implementation of all missing Phases 2-5 modules, broad UI redesign, and invented readiness ratings.
- Removing failures from the suite or downgrading required contracts to make the phase appear complete.
- Push/merge/release automation or claiming PostgreSQL race results from in-memory tests.

## Acceptance cases

| Case | Required result |
|---|---|
| Every role/state/portal variant | Protected actions apply the same current eligibility and narrow exceptions. |
| Canonical adversarial inputs | Invalid text/contact/IDs/age/categories reject without partial records. |
| Independent profile/resource scope | No parent/child approval or restriction bypass. |
| Current evidence and decision retry | Stale review conflicts; identical retry preserves immutable history. |
| Active-work restriction/closure | Custody, cash, snapshots, and accountable recovery remain intact. |
| Last-admin competing actions | Invariant is covered in tests/design; no unsupported concurrency claim. |
| Normal canonical transaction | Mandatory Mother Hub and buyer-only completion remain intact. |
| Existing failed/error identity | Root cause and owning gate recorded; no blanket fixture explanation. |
| Failure set changed | Exact new/resolved identities analyzed before assessment. |
| Missing later prerequisite | Relevant gate remains incomplete; no placeholder/fake success. |
| Truthful phase decision | Only fully evidenced scoped gates are marked passed; full readiness remains evidence-based. |

## Verification and review

Start with all branch-specific focused suites and the full isolated PHP suite, plus affected frontend builds. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after acceptance evidence and triage are recorded. If a required Phase 0 condition is still absent/failing, report the phase incomplete and its concrete next owner; do not automatically implement or begin B14.

```text
Implement only B13 from docs/admin-plan/13-phase0-acceptance.md on test/admin-phase0-acceptance.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
