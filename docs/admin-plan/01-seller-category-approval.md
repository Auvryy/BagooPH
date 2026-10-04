# B01: Seller Master Category and Original-Shop Approval

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/seller-category-approval` |
| Phase | 0 |
| Minimum prerequisites | Existing foundation reviewed and merged |
| Result | A seller's original shop has a valid master category before account approval. |

## Purpose

Make the documented category requirement part of registration, applicant correction, and the current KYC decision. This branch establishes a valid root scope; it does not build the complete independent review system for additional shops.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [CATEGORIES.md](../CATEGORIES.md)
- [SELLER_FLOW.md](../SELLER_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/Category.php](../../app/Models/Category.php)
- [app/Models/Shop.php](../../app/Models/Shop.php)
- [database/migrations/2026_01_01_000002_create_categories_table.php](../../database/migrations/2026_01_01_000002_create_categories_table.php)
- [database/seeders/DatabaseSeeder.php](../../database/seeders/DatabaseSeeder.php)
- [app/Http/Controllers/Auth/RegisteredUserController.php](../../app/Http/Controllers/Auth/RegisteredUserController.php)
- [app/Services/KycDecisionService.php](../../app/Services/KycDecisionService.php)
- [app/Services/KycSubmissionService.php](../../app/Services/KycSubmissionService.php)
- [resources/js/Pages/Auth/SellerRegister.tsx](../../resources/js/Pages/Auth/SellerRegister.tsx)
- [resources/js/Pages/Auth/PendingApproval.tsx](../../resources/js/Pages/Auth/PendingApproval.tsx)

## Implementation sequence

1. Compare the category schema, parent relations, active flags, uniqueness, existing seed behavior, and shop foreign references with the 14 exact master names. Choose an additive idempotent seed approach; never execute destructive product replacement to install taxonomy.
2. Return server-selected active master root choices to seller registration. Validate a required root ID on the backend and save it on the shop created in the registration transaction; rollback user/shop creation together on failure.
3. Provide owned pending/rejected applicants a category correction path. Treat a changed category as a changed application version and preserve existing files, restrictions, and earlier decisions.
4. Require a valid active master root category for the original dependent shop in KYC readiness. Bind relevant category identity/eligibility to the current review snapshot so deactivation or correction invalidates a stale decision.
5. Show the current category and a precise readiness blocker in the admin inspector. Require current evidence inspection after a submission change; frontend selection never establishes approval.
6. Verify preserved legacy references, rejected retry behavior, and original-shop-only activation; record exact remaining independent-shop work for B03.

## Decision and scope rules

- Only one of the 14 documented master root categories is valid; a child, inactive category, foreign/nonexistent ID, or legacy non-master root cannot substitute.
- An approved account does not approve additional shops. Keep the existing original dependent profile boundary until B03 delivers independent shop review.
- Known category invalidity blocks a new approval. Identical completed-decision retries still return the original recorded result without re-running activation.
- Applicant correction requires ownership and an allowed unreviewed source state. Reviewed identity/category changes require B08 rather than a generic self-edit.

## Data, legacy records, and recovery

- Preserve existing category rows, children, product links, images, shop references, and administrator-chosen inactive flags. Do not silently reactivate or rename an ambiguous legacy category.
- Report shops missing a master scope as review candidates. Do not guess a category or stamp an old approval to make them eligible.
- Exercise seeding twice in isolated tests. Adding master choices does not authorize running the entire legacy DatabaseSeeder against development records.

## Exclusions

- Independent additional-shop approvals/context selection (B03), complete shared text/contact validation (B02), and reviewed identity correction (B08).
- Product category redesign, reclassification of existing products, public account role conversion, or changes to order/custody/commission rules.
- Bulk production reseeding, arbitrary SQL data fixes, new category-count analytics, or a seller portal redesign.

## Acceptance cases

| Case | Required result |
|---|---|
| All 14 master choices | Exact documented names appear once as roots; a second installation creates no duplicates. |
| Preserved legacy taxonomy | Existing products/children/references and inactive settings remain intact. |
| Valid registration | User and original shop persist together with the chosen active master root. |
| Invalid category injection | Missing, child, inactive, non-master, or foreign ID rejects without partial registration. |
| Owned pending/rejected correction | A valid change updates the application version; old review confirmation is unusable. |
| Foreign or reviewed correction | Another seller's shop and reviewed identity cannot be changed through this path. |
| Missing category at review | Approval fails without changing account/shop state or creating a decision. |
| Category changes after inspection | Stale review conflicts; admin must inspect the current submission. |
| Independent restriction and extra shop | Approval preserves suspension and does not activate other shops. |
| Retry or partial failure | Identical completed decision stays unchanged; failed registration/review rolls back all scoped writes. |

## Verification and review

Start with the existing KycRegistrationTest, AdminKycApprovalTest, KycDecisionGovernanceTest, and AccountRoleImmutabilityTest plus isolated taxonomy tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when registration/correction and original-shop KYC category readiness pass these cases, legacy references are preserved, and the roadmap records evidence. B02 and B03 remain separate tasks.

```text
Implement only B01 from docs/admin-plan/01-seller-category-approval.md on admin/seller-category-approval.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
