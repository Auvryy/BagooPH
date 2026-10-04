# B11: Read-Only Account Context and Governance Audit Search

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/governance-audit-viewer` |
| Phase | 0 |
| Minimum prerequisites | [B03](03-shop-approval-eligibility.md), [B06](06-account-restrictions.md), [B07](07-resource-restrictions.md), [B08](08-reviewed-identity-corrections.md), [B09](09-account-closure-safety.md), [B10](10-product-moderation.md) |
| Result | Authorized reviewers can trace recorded governance decisions without editing history. |

## Purpose

Give admins a usable account inspector and ordinary searchable decision history. Explain separate identity, activity, resource, placement, and duty states with links to actual supporting records instead of one misleading active badge.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [VERIFICATION_DOCUMENT_SECURITY.md](../VERIFICATION_DOCUMENT_SECURITY.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/User.php](../../app/Models/User.php)
- [app/Models/KycDecision.php](../../app/Models/KycDecision.php)
- [app/Services/KycDecisionService.php](../../app/Services/KycDecisionService.php)
- [app/Services/VerificationDocumentService.php](../../app/Services/VerificationDocumentService.php)
- [app/Http/Controllers/Admin/AdminKycController.php](../../app/Http/Controllers/Admin/AdminKycController.php)
- [app/Http/Controllers/Admin/AdminDashboardController.php](../../app/Http/Controllers/Admin/AdminDashboardController.php)
- [resources/js/Pages/Admin/Users.tsx](../../resources/js/Pages/Admin/Users.tsx)
- [resources/js/Pages/Admin/KycQueue.tsx](../../resources/js/Pages/Admin/KycQueue.tsx)
- [routes/web.php](../../routes/web.php)

## Implementation sequence

1. Inventory decision sources delivered by dependencies and their retention/privacy rules. Define a read projection with explicit record type and subject; do not collapse unrelated events into fabricated KYC decisions.
2. Add a scoped account-context response showing fixed role, KYC, activity, reviewed identity, owned shop/company states, placements/duty, and affected-work references separately.
3. Provide server-validated subject/actor/type/date filters, deterministic pagination/order, and links to recorded before/after values, reason, server time, and prior decision.
4. Authorize current privileged eligibility on every list/detail request. Company-scoped views expose only the documented own-company subset; Platform Admin may review across companies without performing their actions.
5. Keep private evidence behind fresh authorized document links and hash checks. History may reference retained evidence but must expose no raw paths, passwords, tokens, or plaintext counter claim codes.
6. Build a compact read-only inspector/history using existing admin components. Explain legacy/unavailable evidence honestly and test retained/anonymized subjects/reviewers.

## Decision and scope rules

- History is immutable. Search/detail views provide no record edit/delete or implicit reverse-decision control.
- Each entry states its actual source/provenance. Legacy status without a decision is shown as legacy, not a historical approval with guessed actor/time.
- Current account/resource state is separate from a historical before/after snapshot.
- Private evidence access always re-checks actor eligibility and subject scope, including after suspension or account closure.
- No broad export or generic cross-company API is justified by a search page. Pagination and normal filters are enough.

## Data, legacy records, and recovery

- Do not backfill KYC records for restriction, moderation, closure, or legacy activity events. Use their actual recorded sources.
- Retain stable subject/reviewer references even when personal fields are anonymized by the closure policy.
- Avoid logging raw document content/storage paths or sensitive search inputs. Inspect response projections explicitly.

## Exclusions

- Writing/reversing decisions, new support tickets/disputes, bulk downloads, and secrets/private-document exports.
- Future COD/settlement reports (B18), actor impersonation, and live-presence analytics.
- Redesigning all portals or adding a generic permission editor.

## Acceptance cases

| Case | Required result |
|---|---|
| Mixed decision history | Each real event has correct source, actor, subject, before/after, time, and reason. |
| Separate current context | KYC, activity, shop/company, placement, and duty remain distinguishable. |
| Validated filters/pagination | Correct scoped results and stable order; bad filters reject without leaking records. |
| Company isolation | Foreign account/resource history and evidence deny. |
| Inactive admin/stale session | List, detail, and document links deny after fresh eligibility check. |
| Private evidence history | Authorized link verifies retained content; no raw path/token appears in payload. |
| Legacy status only | Explicit unavailable/legacy provenance; no invented decision. |
| Closed/anonymized subject | Audit references and historical facts remain readable by authorized reviewer. |
| Read-only guarantees | Requests cannot modify/delete/reverse history or confer custody action. |
| Empty/missing source | Honest empty/unavailable state; no sample history. |

## Verification and review

Start with KycDecisionGovernanceTest, SharedPrivilegedAccessTest, private-document access cases, and new audit/context scope tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after verified read-only context, filters, pagination, and privacy. Decision-writing fixes belong to their owning branches; do not hide missing records with synthetic history.

```text
Implement only B11 from docs/admin-plan/11-governance-audit-viewer.md on admin/governance-audit-viewer.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
