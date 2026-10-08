# B03: One-Shop Seller Approval and Access

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/shop-approval-eligibility` |
| Phase | 0 |
| Minimum prerequisites | [B01](01-seller-category-approval.md), [B02](02-application-validation.md) |
| Result | One shop per seller, reviewed category scope and positive eligibility for new work. |

## Purpose

Enforce the one-account, one-shop seller contract. Registration creates the sole shop with its chosen master category, and the initial account review covers that shop. Retain current shop review evidence, legacy resubmission, restrictions and product/checkout gates without extra-shop creation or switching.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/Shop.php](../../app/Models/Shop.php)
- [database/migrations/2026_01_01_000001_create_shops_table.php](../../database/migrations/2026_01_01_000001_create_shops_table.php)
- [app/Models/KycDecision.php](../../app/Models/KycDecision.php)
- [app/Services/KycDecisionService.php](../../app/Services/KycDecisionService.php)
- [app/Http/Controllers/Seller/HasSellerShop.php](../../app/Http/Controllers/Seller/HasSellerShop.php)
- [app/Http/Controllers/Seller/SellerDashboardController.php](../../app/Http/Controllers/Seller/SellerDashboardController.php)
- [app/Http/Controllers/Seller/SellerProductController.php](../../app/Http/Controllers/Seller/SellerProductController.php)
- [app/Services/Orders/CheckoutOrderService.php](../../app/Services/Orders/CheckoutOrderService.php)
- [resources/js/Pages/Admin/KycQueue.tsx](../../resources/js/Pages/Admin/KycQueue.tsx)

## Implementation sequence

1. Trace registration, original application review, seller context, product writes and buyer checkout. Keep account approval, shop review and activity restrictions distinct.
2. Enforce one shop per seller at the database boundary. Report existing duplicate ownership for reviewed resolution before installing the constraint; never delete, merge or transfer referenced records automatically.
3. Remove additional-shop creation and seller switching. Resolve the sole owned shop on every request; old picker sessions confer no authority. A missing shop produces a clear review/support response without fallback creation.
4. Retain versioned shop evidence and immutable decisions. Initial seller application approval records its registered shop together; legacy review and reviewed category corrections require current evidence.
5. Re-read account/shop eligibility for mutations, direct URLs, root/subdomain portals and new checkout. Product categories remain under the approved master root and active descendants.
6. Show one shop with its category, account/shop states, feedback and history. Preserve owned existing-order obligations under restrictions. Buyers can still check out across different sellers' shops.

## Decision and scope rules

- Account, shop review, activity restriction, and category scope remain independent. An approved seller may have a pending or restricted sole shop.
- Only Platform Admin grants shop review approval. A seller-supplied status/approved flag is ignored or rejected and cannot activate a shop.
- Product scope must match the approved root and supported descendants under existing category contracts; a null category cannot mean unrestricted selling.
- A registered shop is not perpetual authority. Revalidate ownership and eligibility for direct URLs, bulk actions, root/subdomain portals, and checkout.
- Reinstatement/correction preserves separate restrictions. Reviewed category changes need B08; routine shop edit cannot erase their review provenance.

## Data, legacy records, and recovery

- Preserve old shops, products, order snapshots, and seller history. Report missing/ambiguous review provenance rather than inventing approval events.
- Assess legacy active shops explicitly and define their review path. Do not silently map arbitrary first-category values or fabricate identity/contact defaults.
- Backfill only facts supported by existing evidence and approved migration policy; maintain rollback-safe schema changes without deleting referenced records.

## Exclusions

- Controlled reviewed-category correction (B08), reasoned suspension/reactivation (B06/B07), and product compliance workflow (B10).
- Shop impersonation, marketplace category redesign, order cancellation/repricing, and additional-shop creation or switching.
- Broad storefront redesign or inferred approval of legacy records from status alone.

## Acceptance cases

| Case | Required result |
|---|---|
| Extra creation or switching | Both portal hosts reject requests; no shop or uploaded permit persists. |
| Database ownership | Second insert and conflicting owner update fail; legacy duplicates retain all records and block installation. |
| Initial account approval | Its sole registered shop activates with linked review evidence; other sellers' shops are unchanged. |
| Eligible context | Owned reviewed active shop allows scoped actions; pending/rejected/unknown state blocks new work. |
| Old session/foreign ID | Old picker sessions cannot choose a shop. Explicit foreign or malformed IDs deny access; no fallback is created. |
| Direct product/checkout calls | Backend denies ineligible shop and category even when UI/session claims eligibility. |
| Independent shop review retry | One decision, unchanged reviewer/time/reason; competing stale decision conflicts. |
| Related write fails | Shop state and decision roll back together; account and other shops stay unchanged. |
| Category altered during review | Readiness/version mismatch conflicts and requires current inspection. |
| Suspended seller or shop | Review cannot clear restriction; existing owned work/history remains available only by policy. |
| Legacy absent provenance | Explicit review candidate, no invented approval/contact/category or lost product/order reference. |

## Verification and review

Start with AdminKycApprovalTest, KycDecisionGovernanceTest, BuyerCheckoutTest, BuyerCheckoutKycGateTest, and shared seller portal gates plus new shop-specific acceptance cases. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop once one-shop ownership, retained shop decisions and positive seller/shop access checks are verified across the affected entry points. B06/B07 provide reasoned restrictions later; do not add their controls here.

```text
Implement only B03 from docs/admin-plan/03-shop-approval-eligibility.md on admin/shop-approval-eligibility.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
