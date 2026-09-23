# BagooPH Documentation Map

Use this map before reading or changing project documentation. A document's authority depends on its category below; newer-looking examples or historical milestone claims do not override the normative specifications.

## Normative Product Contracts

| Question | Authoritative document |
|---|---|
| Canonical order statuses, actor ownership, commercial gates | `SYSTEM_FLOW_AND_SPECIFICATIONS.md` |
| Input validation, authorization, concurrency, idempotency, failure recovery | `CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` |
| Parcel custody, hubs, manifests, retries, self-pickup, COD movement | `SORTING_CENTER_LOGISTICS_FLOW.md` |
| Buyer-only actions and screens | `BUYER_FLOWCHART.md` |
| Seller-only actions and screens | `SELLER_FLOW.md` |
| Pickup/final-mile courier actions | `COURIER_FLOW.md` |
| Platform Admin, Logistics Company Admin, Hub Handler governance | `ADMIN_FLOW.md` |
| Product categories | `CATEGORIES.md` |
| UI and product language | `STYLE_GUIDE.md` |

## Planning and Current Gaps

- `CORE_FLOW_ROADMAP.md` is the only current implementation audit, phase order, rating comparison, and deferred-scope list.
- Implementation-status lists must not be copied into role or operational specifications because they become stale.

## Supporting References

- `ARCHITECTURE.md`, `PROJECT_PLAN.md`, and `MASTER_LOGISTICS_SPECIFICATION.md` explain architecture and context but cannot override normative flow contracts.
- `SCHEMA.md` is supporting context only. Migrations and models describe the current executable schema; planned data changes belong in the roadmap until implemented.

## Historical and Original Sources

- `PROGRESS.md` records historical milestones and may describe legacy states that are no longer permitted.
- `ERP_COMPONENTS_SPECIFICATION.md`, `ERP-Components-updated.pdf`, and `ERP-Flow.pdf` preserve original curriculum/source material. Simplified flows in these files are not implementation authority.

## Conflict Rule

When documents conflict, use this order:

1. `SYSTEM_FLOW_AND_SPECIFICATIONS.md` for canonical commercial status and actor ownership.
2. `CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` for safety and failure behavior.
3. `SORTING_CENTER_LOGISTICS_FLOW.md` for physical custody.
4. Role documents for role-specific presentation and permitted actions.
5. `CORE_FLOW_ROADMAP.md` for what to implement next.

When executable code differs from the intended contract, record the difference in `CORE_FLOW_ROADMAP.md`; do not silently rewrite the contract to match a bug.
