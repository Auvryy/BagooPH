# Buyer and Seller Improvement Plan

These plans divide commerce improvements into five bounded deliveries. Read the
[documentation map](../README.md), [buyer flow](../BUYER_FLOWCHART.md),
[seller flow](../SELLER_FLOW.md) and
[validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md) first. Plans
describe intended work, not completed implementation. Current findings, ratings
and verification evidence belong in [the roadmap](../CORE_FLOW_ROADMAP.md).

## Delivery order

| Batch | Plan | Suggested branch | Dependency |
|---|---|---|---|
| BS01 | [Review integrity](01-review-integrity.md) | `fix/commerce-review-integrity` | Existing approved commerce accounts and completed order items |
| BS02 | [Order workspaces](02-order-workspaces.md) | `fix/commerce-order-workspaces` | Existing lifecycle and custody services; follow BS01 |
| BS03 | [Seller inventory and drafts](03-seller-inventory-and-drafts.md) | `feat/seller-inventory-and-drafts` | Existing shop/category eligibility; follow BS02 |
| BS04 | [Buyer discovery and saved products](04-buyer-discovery-and-saved-products.md) | `feat/buyer-discovery-and-saved-products` | Existing catalogue eligibility; follow BS03 |
| BS05 | [Buyer Buy again](05-buyer-buy-again.md) | `feat/buyer-buy-again` | BS03/BS04 inventory and catalogue rules |

Select one batch by default. Explicitly selected adjacent batches may share one
delivery branch while retaining separate commits, scope and acceptance gates.
The user selected BS01 and BS02 together; their bounded delivery branch is
`feat/buyer-seller-review-and-orders`. This does not start BS03-BS05.

The separately selected BS04 and BS05 delivery uses
`feat/buyer-discovery-and-buy-again`, with BS04 implemented and accepted before
BS05 starts. Each batch retains its own checks and logical commit; this
selection does not authorize additional commerce or logistics features.

## Design and boundaries

Buyer and seller retain distinct portal styles. New pages, controls and dialogs
must feel like part of their own portal. Preserve its layout, navigation,
spacing, surfaces, controls and feedback; use BagooPH, `#E00D42`, Plus Jakarta
Sans and PHP amounts. Preserve approved-account, one-shop, category, custody,
buyer-confirmation and separate financial-authority rules.

Refunds/exchanges, wallet writers, new payment methods, advanced analytics,
Flutter work and new external services are separate scope. Follow the
[shared workflow](WORKFLOW.md). The project delivery target remains November 20,
2026, with time reserved for fixes and the demonstration. Actual task windows
are scheduled when work is selected; they are not historical time records.
