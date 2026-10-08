# B08: Controlled Reviewed-Identity Corrections and Legacy Audit

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/identity-and-closure-safety` |
| Phase | 0 |
| Minimum prerequisites | [B01](01-seller-category-approval.md), [B02](02-application-validation.md), [B06](06-account-restrictions.md), [B07](07-resource-restrictions.md) |
| Result | Reviewed identity can be corrected with current evidence and an append-only decision. |

B08 and B09 share one selected delivery branch. B08 must pass its focused gate before B09 starts; keep separate acceptance evidence and commits. Stop after the complete batch for user review and merge before B10.

## Purpose

Offer a controlled path for genuine mistakes and legacy missing/invalid birth dates or shop-category scope. Ordinary profile/resubmission edits remain unable to replace reviewed identity, role, evidence, or approval history.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [COURIER_FLOW.md](../COURIER_FLOW.md)
- [VERIFICATION_DOCUMENT_SECURITY.md](../VERIFICATION_DOCUMENT_SECURITY.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/User.php](../../app/Models/User.php)
- [app/Models/Shop.php](../../app/Models/Shop.php)
- [app/Models/KycDecision.php](../../app/Models/KycDecision.php)
- [app/Services/BirthDateEligibility.php](../../app/Services/BirthDateEligibility.php)
- [app/Services/KycSubmissionService.php](../../app/Services/KycSubmissionService.php)
- [app/Services/KycDecisionService.php](../../app/Services/KycDecisionService.php)
- [app/Services/VerificationDocumentService.php](../../app/Services/VerificationDocumentService.php)
- [app/Http/Controllers/ProfileController.php](../../app/Http/Controllers/ProfileController.php)
- [resources/js/Pages/Admin/KycQueue.tsx](../../resources/js/Pages/Admin/KycQueue.tsx)
- [resources/js/Pages/Admin/Users.tsx](../../resources/js/Pages/Admin/Users.tsx)

## Implementation sequence

1. Inventory reviewed identity fields and invalid/missing legacy candidates using actual facts. Define which changes require correction review and which ordinary profile fields remain safely editable.
2. Design a versioned correction request linked to the subject and prior review or explicit legacy provenance. Store proposed values separately until a current authorized decision; keep original evidence accessible only by policy.
3. Validate proposed birthday/category/canonical fields under B01/B02 rules. Require reason and role-appropriate evidence; review current private files and reject missing or changed content.
4. Apply approved correction and new audit atomically with before/after values, evidence/version, prior reference, actor/time, and idempotency. Reject stale competing changes; never update/delete the original KYC decision.
5. Re-evaluate eligibility while preserving independent restrictions, duty, placements, and active-work obligations. A corrected underage/invalid identity cannot be left eligible; a valid correction alone cannot reactivate an account.
6. Provide a small admin review/candidate workflow. Embed the applicant's request and history beside identity information in existing role settings, using the portal's design. Old applicant links redirect to that section; the admin review queue remains separate. Protect last-admin continuity; when evidence would remove the final eligible admin, require controlled authorized replacement/recovery before the routine flow proceeds.

## Decision and scope rules

- Correction is not role conversion or routine approval reversal. Roles remain fixed and another public role needs a separate account.
- Contact numbers, avatars, branding and future buyer delivery addresses are direct owner edits, preserving shop approval and historical snapshots. Reviewed legal identity, birth date, shop name/category/pickup address and private evidence still require correction review. The original sign-in email remains fixed; additional contact/recovery addresses use the separate password-and-OTP verification workflow in settings.
- Legacy compatibility is explicit and temporary policy, not proof of adulthood or category approval. Never guess birth dates or category mappings.
- Fresh eligible Platform Admin reviews current evidence; a session's old reviewer eligibility or inspection does not suffice.
- Reviewed category change remains subject to shop/product scope checks. Identify affected listings without silently reclassifying products or deleting historical order snapshots.
- An identical correction retry returns its recorded result. Changed reason/proposal/evidence requires a new authorized request/version.
- Continuity protection never authorizes false identity. Escalate controlled admin recovery rather than inventing a valid date or granting a public role change.

## Data, legacy records, and recovery

- Retain original values/evidence and prior decisions. Legacy records without a real decision use explicit legacy provenance, not a fabricated historical review.
- Do not normalize or bulk update all approved profiles. Review candidates and proposed fixes individually with source evidence.
- Preserve orders, cash, immutable snapshots, and restrictions. Failure to store correction/audit leaves the reviewed identity unchanged.

## Exclusions

- Silent self-edit of reviewed birthday/category/identity, bulk fake legacy approvals, and generic privilege/role editor.
- Changing the adult threshold, reclassifying old orders, refund/settlement corrections, or removing suspension automatically.
- Shared-password admin recovery, public admin creation, and a complete support/ticketing system.

## Acceptance cases

| Case | Required result |
|---|---|
| Valid evidenced correction | One linked decision applies canonical before/after values and preserves prior review. |
| Missing/invalid proposed identity | Validation blocks without changing eligibility or evidence. |
| Legacy missing-date/category candidate | Requires actual evidence; no guessed date/master scope or fake old approval. |
| Ordinary profile bypass | Reviewed fields cannot be replaced through generic update/resubmission. |
| Foreign applicant/evidence | Ownership/private access denies; no raw file paths leak. |
| Changed file/proposal after inspection | Stale review conflicts; current evidence must be inspected. |
| Identical/competing retry | Original result persists; a stale competing correction cannot overwrite. |
| Suspended subject corrected | Independent restriction and duty/placement remain unchanged. |
| Correction invalidates eligibility | New work blocks and active-work responsibility remains; no custody edit. |
| Final eligible admin affected | Routine flow cannot remove final oversight or falsify identity; controlled recovery is explicit. |
| Partial audit/profile failure | Complete correction rolls back; prior immutable history stays intact. |

## Verification and review

Start with WorkerBirthDateEligibilityTest, KycDecisionGovernanceTest, AccountRoleImmutabilityTest, private-document authorization tests, and new controlled-correction cases. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when the controlled reviewed-change path, explicit legacy review, and continuity safeguards pass. Do not widen generic profile permissions or call all legacy identities audited without evidence.

```text
Implement only B08 from docs/admin-plan/08-reviewed-identity-corrections.md on admin/identity-and-closure-safety.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
