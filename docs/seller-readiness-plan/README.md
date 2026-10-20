# Seller catalogue and worklist plan

The selected SE01 and SE02 batches share `feat/seller-catalogue-workspaces`.
Implement listing and worklist controls before connecting store categories and
complete listing details. Use separate logical commits and one combined
acceptance gate. Current results and engineering ratings belong in
[the roadmap](../CORE_FLOW_ROADMAP.md).

## Selected outcomes

| Batch | Outcome | Acceptance references |
|---|---|---|
| SE01 | Private incomplete drafts and duplicate-to-draft editing | `SEL-PROD-009`, `SEL-PROD-012` |
| SE01 | Owned delete/bulk actions, unfinished-order protection and readable results | `SEL-PROD-014`, `SEL-PROD-016` |
| SE01 | Variant price ranges before selection and the chosen price afterwards | `SEL-VAR-007` |
| SE01 | Server order search/date filters, matching counts and review star/option filtering | `SEL-ORD-001`, `SEL-REV-001` |
| SE02 | Independent store category creation, naming, uniqueness, rename and order | `SEL-SCAT-001`–`SEL-SCAT-006` |
| SE02 | Visibility, retained position, safe leaf/tree deletion and two-level nesting | `SEL-SCAT-007`–`SEL-SCAT-011` |
| SE02 | Up to three owned memberships, separate from the approved master category | `SEL-SCAT-012`, `SEL-SCAT-013` |
| SE02 | Saved brand/condition, declared weight/dimensions and complete publication requirements | `SEL-PROD-001`, `SEL-PROD-002` |

Publishing retries must retain one original result. Invalid input and failed
uploads must not replace saved values or leave new public files behind.
Category deletion requires a fresh affected-product preview and never deletes
products. Hidden categories and their children cannot expose public category
URLs; the products retain their independent marketplace eligibility.

Preserve the seller portal's layout and controls and the buyer storefront's own
style. Use Inertia navigation, retained fields, visible feedback, clear empty
states, labelled controls and responsive layouts. See the [seller contract](../SELLER_FLOW.md),
[form review](../FORM_VALIDATION_REVIEW.md) and
[validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md).

## Supporting declared-weight work

Retain declared unit weight in new order items and print the summed original
product weight on the waybill. Later product edits cannot rewrite that value.
Unknown legacy weights remain unknown, and declared product weight must not be
represented as a hub's measured parcel weight. This supports `SEL-ORD-002` but
does not establish its unperformed printing and cross-portal observation.

Weight validation does not introduce a shipping tariff. `SEL-PROD-008` still
contains a different weight-based charging assumption; keep Bagoo's existing
subtotal-based fees unless a separate policy change is selected.

## Separate follow-up scope

SE03 proposes independent combination prices/SKUs, precise row feedback and
persisted low-stock crossing alerts. SE04 proposes percentage authoring,
complete-variant discounts and tighter authored flash quotas. Neither batch is
selected by the SE01/SE02 delivery.

Code-only/product-scoped vouchers, bundles and cash withdrawal requests remain
separate features. Keep approval, the sole shop, the 14 master roots, buyer-only
completion, original financial evidence and the agreed 10% commission.

Automated tests, a build and source inspection are distinct from a live browser,
printer or simultaneous PostgreSQL check. Preserve the separate status of
unperformed procedures; implementing a prerequisite does not automatically
turn its entire original case into a pass.
