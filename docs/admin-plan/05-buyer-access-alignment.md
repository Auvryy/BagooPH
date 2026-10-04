# B05: Buyer Approval, Holding, and Existing-Order Exceptions

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/buyer-access-alignment` |
| Phase | 0 |
| Minimum prerequisites | [B02](02-application-validation.md) |
| Result | Buyers have a useful own-application path and no transactional approval bypass. |

## Purpose

Align buyer entry points with documented identity approval while retaining public browsing and the narrow owned-order exception. Sign-in, OAuth, holding, private evidence, checkout, tracking, and receipt confirmation must agree.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Http/Controllers/Auth/RegisteredUserController.php](../../app/Http/Controllers/Auth/RegisteredUserController.php)
- [app/Http/Controllers/Auth/AuthenticatedSessionController.php](../../app/Http/Controllers/Auth/AuthenticatedSessionController.php)
- [app/Http/Controllers/Buyer/BuyerProfileController.php](../../app/Http/Controllers/Buyer/BuyerProfileController.php)
- [app/Http/Controllers/Buyer/CheckoutController.php](../../app/Http/Controllers/Buyer/CheckoutController.php)
- [app/Http/Middleware/EnsureApprovedAccount.php](../../app/Http/Middleware/EnsureApprovedAccount.php)
- [app/Http/Middleware/RoleMiddleware.php](../../app/Http/Middleware/RoleMiddleware.php)
- [app/Http/Middleware/SubdomainRoleMiddleware.php](../../app/Http/Middleware/SubdomainRoleMiddleware.php)
- [app/Services/VerificationDocumentService.php](../../app/Services/VerificationDocumentService.php)
- [app/Services/Orders/OrderLifecycleService.php](../../app/Services/Orders/OrderLifecycleService.php)
- [resources/js/Pages/Auth/PendingApproval.tsx](../../resources/js/Pages/Auth/PendingApproval.tsx)
- [routes/web.php](../../routes/web.php)

## Implementation sequence

1. Trace all buyer-only routes and services plus password/OAuth redirects. Separate public catalogue, own holding/resubmission, normal approved transactions, and restricted owned-order recovery.
2. Use shared canonical buyer identity/contact validation without imposing the worker age minimum. Permit only owned current application/evidence operations while unreviewed.
3. Apply buyer account activity and KYC checks to checkout and other new transactional mutations, including direct URLs and service calls. No auth-only route may act as a transactional bypass.
4. Provide clear holding feedback and current permitted resubmission; invalidate stale admin review when a permitted application field changes.
5. Preserve narrowly authorized owned tracking and receipt confirmation after restriction unless an explicit security review denies that action. Define the sign-in/recovery path needed to reach those actions without opening the general portal.
6. Verify frontend redirects and helpful errors while retaining canonical order transitions, stock/price authority, and buyer-only completion.

## Decision and scope rules

- Public browsing does not authorize checkout. Buyer registration/email verification/document upload do not each grant platform approval.
- Pending/rejected buyers access only their own holding/application/evidence actions. They cannot inspect another account's documents.
- Restricted-buyer exceptions are action-specific and owned-order-specific. They grant no new checkout, Shopping Bag purchase, foreign-order lookup, or premature completion.
- Only the buyer confirms DELIVERED to COMPLETED. Admin, courier, and hub oversight never substitutes.
- Unknown account state denies protected work; current server eligibility is re-read for stale sessions and both URL variants.

## Data, legacy records, and recovery

- Preserve buyer orders, addresses, Shopping Bag references, evidence, and previous KYC decisions. Do not auto-approve old buyers based on prior purchases.
- Document compatibility and review candidates explicitly. Buyer birth date remains optional; do not manufacture dates to satisfy worker rules.
- Keep an existing-order exception distinct from routine account reactivation and from future notifications.

## Exclusions

- New Shopping Bag/checkout pricing behavior, address serviceability engines, refunds/disputes, and order lifecycle changes.
- Reasoned restriction decision UI (B06), generic identity repair (B08), and security-recovery modules beyond the minimal authorized existing-order path.
- Public admin signup, role conversion, or broad buyer portal redesign.

## Acceptance cases

| Case | Required result |
|---|---|
| Public catalogue | Browsing works without granting private or transactional access. |
| Pending/rejected buyer | Own holding/evidence correction works; checkout and foreign application access deny. |
| Approved active buyer | Valid checkout remains server-priced, stock-checked, and scoped. |
| Password and OAuth sign-in | Same eligibility and current permitted destination. |
| Suspended buyer new order | Direct checkout/service attempt denies with no order/stock mutation. |
| Owned existing tracking | Narrow access preserves history without opening unrelated portal actions. |
| Owned DELIVERED receipt | Buyer may complete under allowed exception; premature/foreign/admin attempt denies. |
| Security-restricted exception | Explicit denial is honored without erasing order history. |
| Changed application or stale session | Current review/access checks reject stale data; restrictions persist. |
| Root/subdomain and private evidence | Same rules and fresh actor/ownership checks apply. |

## Verification and review

Start with BuyerCheckoutTest, BuyerCheckoutKycGateTest, InventoryCheckoutTest, SharedPortalAccessTest, SharedPrivilegedAccessTest, GoogleOAuthTest, and KYC/private-evidence tests. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop after buyer entry points and the narrow existing-order path are verified. Do not treat this as approval of all buyer UI or implementation of the restriction decision service.

```text
Implement only B05 from docs/admin-plan/05-buyer-access-alignment.md on admin/buyer-access-alignment.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
