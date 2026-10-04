# B17: Buyer-Completed and Reconciled Seller Settlement

This is a bounded execution plan, not an implementation-status report. Use the [index](README.md) for order, [workflow](WORKFLOW.md) for Git/testing, and [roadmap](../CORE_FLOW_ROADMAP.md) for current evidence.

## Delivery boundary

| Item | Requirement |
|---|---|
| Git branch | `admin/seller-settlement` |
| Phase | 5 |
| Minimum prerequisites | [B16](16-cod-reconciliation.md) |
| Result | Recorded seller proceeds settle only after buyer completion and platform COD reconciliation. |

## Purpose

Turn proven product proceeds into a controlled, auditable settlement decision. Distinguish pending eligibility, authorized release, and recorded completed settlement; do not pretend a screen click transfers money.

## Contracts and inspection targets

Read [ADMIN_FLOW.md](../ADMIN_FLOW.md), [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and [SYSTEM_FLOW_AND_SPECIFICATIONS.md](../SYSTEM_FLOW_AND_SPECIFICATIONS.md).

- [SELLER_FLOW.md](../SELLER_FLOW.md)
- [BUYER_FLOWCHART.md](../BUYER_FLOWCHART.md)
- [SORTING_CENTER_LOGISTICS_FLOW.md](../SORTING_CENTER_LOGISTICS_FLOW.md)

Inspect these existing files before proposing schema or routes. The list is a starting point; trace their callers, migrations, middleware, and tests. Planned persistence is not a claim that a model already exists.

- [app/Models/CommissionLedger.php](../../app/Models/CommissionLedger.php)
- [app/Models/Order.php](../../app/Models/Order.php)
- [app/Services/Orders/OrderLifecycleService.php](../../app/Services/Orders/OrderLifecycleService.php)
- [app/Services/Orders/CheckoutOrderService.php](../../app/Services/Orders/CheckoutOrderService.php)
- [app/Http/Controllers/Admin/AdminDashboardController.php](../../app/Http/Controllers/Admin/AdminDashboardController.php)
- [app/Http/Controllers/Seller/SellerDashboardController.php](../../app/Http/Controllers/Seller/SellerDashboardController.php)
- [tests/Feature/E2E/Support/AssertsCommissionLedgers.php](../../tests/Feature/E2E/Support/AssertsCommissionLedgers.php)

## Implementation sequence

1. Inspect actual CommissionLedger calculation/status and the B16 reconciled cash source. Define eligible product proceeds using immutable server order snapshots and documented voucher/product-subtotal accounting.
2. Require both buyer-owned COMPLETED and platform-level COD reconciliation. Check unresolved discrepancy, held cash, prior settlement, and current authorized payout/recipient scope.
3. Calculate 10% platform and 90% seller shares on product subtotal using reviewed decimal/cent rounding, with shares summing to the product basis. Keep delivery, handling, rider earnings and logistics revenue separate.
4. Design immutable settlement/release records referencing orders, reconciliation, recipient, recorded amount, actor/time, reason, proof/reference and retry identity. Lock eligible sources so simultaneous requests cannot release the same proceeds twice.
5. Distinguish approval/intention from actual recorded settlement. If using a manual workflow, require verifiable payment reference/evidence under explicit authorization before marking settled; no new external banking/payment provider.
6. Expose accurate pending/eligible/settled states to authorized admin/seller and integrate real notices. Corrections append linked adjustments and retain original amounts/history.

## Decision and scope rules

- DELIVERED is insufficient. Only buyer receipt confirmation establishes COMPLETED, and completion alone is insufficient without platform reconciliation.
- Client seller share, commission rate, payment proof claims, totals, and recipient ID are never authoritative.
- An existing commission row is not proof of cash custody or actual seller transfer. Inspect its semantics before adopting it as the release source.
- Reconciliation/settlement transitions cannot clear account/shop restrictions, rewrite order snapshots, or edit original cash entries.
- Identical release retries return the original record; conflicting/stale/duplicate requests cannot release again. Post-commit notices do not undo settlement.

## Data, legacy records, and recovery

- Preserve legacy ledger rows with explicit provenance and verify their basis. Do not auto-certify legacy settled status or fabricate payment evidence.
- Audit discrepancies before enabling release; no reset of pending commission or bulk status update to populate a dashboard.
- Keep immutable recipient/order/actor evidence after closure/anonymization. Migration/rollback cannot erase recorded financial history.

## Exclusions

- External payment/banking automation, actual transfers during tests, refunds/exchanges/dispute adjudication, and rates editors.
- Changing 10%/90%, deducting shipping from product split, forced buyer completion, or status-only proof of payment.
- Read-only financial aggregation (B18) and broad seller/admin dashboard redesign.

## Acceptance cases

| Case | Required result |
|---|---|
| COMPLETED plus reconciled | Only fully eligible product proceeds enter the authorized release path. |
| Only delivered/completed/reconciled | Missing either mandatory gate blocks release. |
| Buyer/admin actor ownership | Admin cannot confirm receipt for buyer to manufacture eligibility. |
| Exact split/rounding | Seller plus platform equals product basis; shipping/handling remain separate. |
| Client rate/amount/recipient injection | Server calculation/scope rejects authoritative manipulation. |
| Duplicate competing release | One settlement; identical retry preserves original result. |
| Unresolved discrepancy | No payout eligibility from incomplete cash evidence. |
| Manual proof/reference absent | No settled claim or fake transfer success. |
| Financial/audit failure | Release/settlement rollback has no partial ledger change. |
| Linked adjustment | Original proceeds and cash entries retained with actor/reason/reference. |
| Seller/company privacy | Only authorized recipient records visible; Company Admin cannot settle seller proceeds. |

## Verification and review

Start with buyer-completion/order-lifecycle tests, AssertsCommissionLedgers consumers, B16 reconciliation tests, and new settlement acceptance cases. Add focused behavior tests for uncovered acceptance cases; do not assume an existing suite covers the proposed feature.

Use isolated SQLite `:memory:`. Follow [WORKFLOW.md](WORKFLOW.md) for explicit environment overrides, broader affected tests, exact failure-identity comparison, and the frontend build when UI changes. Review transaction/lock ordering separately; SQLite does not prove simultaneous PostgreSQL concurrency.

Split backend/domain tests, affected UI, and documentation evidence into small logical commits where useful. Report every local commit and actual check. Keep implementation status and scoped before/after ratings in the roadmap, not in this plan.

## Stopping point and bounded prompt

Stop when recorded settlement eligibility and release evidence are verified, with no early payout or fictitious transfer. Financial read aggregation is the separate B18 task.

```text
Implement only B17 from docs/admin-plan/17-seller-settlement.md on admin/seller-settlement.
Read AGENTS.md and the plan's contracts. Verify dependencies and inspect executable code first.
Complete this branch's acceptance cases, required checks, local commits, and roadmap evidence.
Preserve unrelated changes. Do not push, merge, or start the next branch. Report blockers without expanding scope.
```
