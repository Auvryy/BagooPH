# B10: Reasoned Product Compliance and Reinstatement

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/product-moderation` |
| Phase | 0 |
| Minimum prerequisites | [B02](02-application-validation.md), [B03](03-shop-approval-eligibility.md), [B06](06-account-restrictions.md), [B07](07-resource-restrictions.md) |
| Result | Product compliance changes have a current source state, reason, and immutable audit. |

## Purpose

Replace an unqualified product status toggle with explicit compliance decisions. Removing or reinstating a listing must respect seller/shop/category eligibility and preserve items already bought.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [CATEGORIES.md](../CATEGORIES.md)
- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/Product.php](../../app/Models/Product.php)
- [app/Models/Shop.php](../../app/Models/Shop.php)
- [app/Http/Controllers/Admin/AdminDashboardController.php](../../app/Http/Controllers/Admin/AdminDashboardController.php)
- [app/Http/Controllers/Seller/SellerProductController.php](../../app/Http/Controllers/Seller/SellerProductController.php)
- [app/Services/Orders/CheckoutOrderService.php](../../app/Services/Orders/CheckoutOrderService.php)
- [resources/js/Pages/Admin/Products.tsx](../../resources/js/Pages/Admin/Products.tsx)
- [routes/web.php](../../routes/web.php)

## Implementation sequence

1. Inspect product schema/status enums, storefront and checkout availability, seller edit/restore paths, and referenced order-item behavior. Define a compliance restriction separately from ordinary seller draft state.
2. Create explicit reasoned remove/reinstate decisions with current version/source state, actor/time, before/after and retry identity. Use actual statuses or justify additive persistence; do not treat every non-active state as eligible for activation.
3. Require fresh Platform Admin authority and lock target plus relevant shop/category eligibility. Commit product eligibility and audit together; no price/stock mutation accompanies moderation.
4. Prevent seller edits or old toggle endpoints from clearing a platform compliance restriction. Show the decision reason and permitted next action to the seller under ownership checks.
5. Reinstate only when compliance prerequisites and account/shop/category scope are valid. A valid product alone cannot bypass a suspended seller, shop restriction, or out-of-scope category.
6. Archive/preserve referenced products where the existing schema permits; define safe retention before adding any deletion action. Test storefront, Shopping Bag, and checkout consistency after moderation.

## Decision and scope rules

- Reason and permitted source state are mandatory; no blind active/draft toggle and no arbitrary client-selected status.
- Admin moderation does not grant seller impersonation, price edits, stock repair, order cancellation, or category reassignment.
- Historical purchased item snapshots, amount, quantity, and evidence remain unchanged after listing removal.
- Reinstatement is explicit and cannot clear independent account/shop restrictions or invent category review.
- Private seller context and admin authority are re-read; identical retry preserves the original decision and stale competing action conflicts.

## Data, legacy records, and recovery

- Retain product IDs/references and order snapshots. Inspect delete cascades before any archive/retention implementation.
- Do not invent reasons for old draft/active transitions or classify every seller draft as platform misconduct.
- Preserve images/evidence needed by existing orders or audit; a failed decision rolls back all moderation writes.

## Exclusions

- Refunds, exchanges, disputes, automatic penalties, admin pricing/stock overrides, and full catalogue redesign.
- Bulk destructive product deletion, changing the 14 master taxonomy, or retroactive reclassification of order items.
- AI moderation, external commercial services, or generic permission management.

## Acceptance cases

| Case | Required result |
|---|---|
| Reasoned compliance removal | Listing loses new-purchase eligibility with recorded reason/actor/source state. |
| Unknown state or empty reason | No mutation and no fake success. |
| Valid reinstatement | Explicit compliance decision restores only permitted listing eligibility. |
| Restricted parent/category | Reinstatement and direct checkout deny until the independent prerequisite is resolved. |
| Seller attempts bypass | Editing/status update cannot clear the platform restriction. |
| Historical bought item | Snapshot, price, quantity, and order evidence remain unchanged. |
| Same/competing retry | One original decision; stale opposite action conflicts. |
| Audit write failure | Product eligibility rolls back with no partial moderation. |
| Direct URL/inactive admin | Privilege gate denies across root/subdomain routes. |
| Referenced product retention | Archive/retention preserves references; unsafe deletion is blocked. |

## Verification and review

Start with SharedPrivilegedAccessTest, AccountRoleImmutabilityTest, BuyerCheckoutTest, InventoryCheckoutTest, and new product-compliance tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after reasoned product decisions and storefront/checkout consistency pass. Do not expand into customer dispute resolution or order/stock correction.

```text
Implement only B10 from docs/admin-plan/10-product-moderation.md on admin/product-moderation.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
