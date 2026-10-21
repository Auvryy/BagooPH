# Seller catalogue and worklist plan

The selected SE01 and SE02 batches share `feat/seller-catalogue-workspaces`.
Implement listing and worklist controls before connecting store categories and
complete listing details. Use separate logical commits and one combined
acceptance gate. The selected SE03 and SE04 follow on the combined
`feat/seller-variants-and-sales` branch. Current results and engineering ratings belong in
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

## Selected variant and sale follow-up

The user selected SE03 and SE04 together on `feat/seller-variants-and-sales`,
after the SE01/SE02 branch was merged. Keep separate logical commits and one
combined acceptance gate. SE03 retains complete independent combination prices
and SKUs, precise row feedback and durable low-stock crossing alerts. SE04 adds
whole percentage authoring from 1% to 99%, discounts the complete selected
variant price, permits running store-sale edits for future purchases and limits
flash quotas to actual stock without reserving it.

Cover the nine targeted cases: `SEL-VAR-001`, `SEL-VAR-005`, `SEL-VAR-006`,
`SEL-INV-008`, `SEL-DISC-001`, `SEL-DISC-005`, `SEL-DISC-008`, `SEL-DISC-009`
and `SEL-FLASH-002`. Preserve legacy surcharge listings, original purchased
SKU/price snapshots, stock restoration and retry safety. The two-group maximum,
exact option errors and variant image selection also need focused checks.

Buyers must see saved brand, new/used condition, declared weight, dimensions and
department on the actual product route, with readable units and honest missing
values. Store grouping stays distinct from the approved department. Verify
product selectors, public price ranges, search, Shopping Bag and checkout use
the same current prices; prior orders remain unchanged.

Code-only/product-scoped vouchers, bundles and cash withdrawal requests remain
separate features. Keep approval, the sole shop, the 14 master roots, buyer-only
completion, original financial evidence and the agreed 10% commission.

Automated tests, a build and source inspection are distinct from a live browser,
printer or simultaneous PostgreSQL check. Preserve the separate status of
unperformed procedures; implementing a prerequisite does not automatically
turn its entire original case into a pass.
