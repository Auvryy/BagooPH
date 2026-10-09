# BagooPH Documentation Map

Use this map before reading or changing project documentation. A document's authority depends on its category below; newer-looking examples or historical milestone claims do not override the normative specifications.

## Project Scope

BagooPH is a practical ecommerce project built to demonstrate a believable transaction across buyer, seller, courier, logistics, and admin. A small set of accounts, shops, hubs, and road routes is sufficient. Serving thousands of users, nationwide commercial operations, and enterprise infrastructure are not acceptance requirements.

Keep the rules realistic even at that scale: server-side approval and authorization, validated checkout and stock, recorded parcel custody, buyer-only completion, and separate COD reconciliation and seller settlement. The delivery network is contiguous land transport only, with at least one Mother Hub on every parcel route. Boats, ports, RORO, and air freight are outside scope.

Complete the existing phased core flow before considering extras. New services, advanced analytics, live GPS, AI dispatch, warehouse automation, or complete refunds/exchanges/disputes need separate scope approval. Supporting architecture and brainstorming ideas cannot add them to the baseline.

## Project Delivery Target

Planning starts **October 4, 2026**, with expected completion around **November 20, 2026**, in Asia/Manila. **November 20 is the latest planned task deadline** for this delivery window. Reserve time for verification, fixes, and the final demonstration before that date.

Prioritize the existing core baseline and keep optional additions deferred. Calendar targets do not waive approval, custody, recovery, notification, financial, or test requirements. If work cannot fit, report the capacity or prerequisite gap and obtain a scope decision; do not silently extend the deadline or mark unfinished work complete. Current evidence and schedule risks belong in [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md).

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
| Rider screen hierarchy, mobile interactions, and presentation acceptance | `RIDER_UI_DESIGN.md` (under the style and courier contracts) |

## Planning and Current Gaps

- `CORE_FLOW_ROADMAP.md` is the only current implementation audit, phase order, rating comparison, and deferred-scope list.
- [buyer-seller-plan/README.md](buyer-seller-plan/README.md) separates review integrity, order workspaces, seller inventory/drafts, buyer saved products and Buy again into five bounded batches. It preserves each portal's own style; selected batches keep separate acceptance gates and do not start later features automatically.
- Implementation-status lists must not be copied into role or operational specifications because they become stale.
- [admin-plan/README.md](admin-plan/README.md) divides admin work into 18 bounded tasks on 13 planned branches, with B06+B07, B08+B09, B10-B12, and the selected B17+B18 batch each sharing a delivery branch. Dependencies, scope, exclusions, acceptance cases, and stopping points remain separate for each task. These are execution plans under the normative contracts, not current implementation-status lists or authorization to run every branch automatically.
- [B13 follow-up ownership](admin-plan/13-phase0-acceptance.md#follow-up-ownership-for-an-incomplete-decision) adds four bounded repair deliveries outside that numbered allocation: commerce inputs, real cross-role fixtures, logistics sorting inputs, and profile/Shopping Bag inputs. An audit can finish with an incomplete gate; follow-up selection and later role-owned prerequisites remain separate.
- [admin-plan/WORKFLOW.md](admin-plan/WORKFLOW.md) explains the foundation review, one-branch Git workflow, isolated checks, local commits, and user-only publication. [admin-plan/CAPABILITIES.md](admin-plan/CAPABILITIES.md) maps governance functions to the existing roles and identifies optional additions.

## Supporting References

- `ARCHITECTURE.md`, `PROJECT_PLAN.md`, and `MASTER_LOGISTICS_SPECIFICATION.md` explain architecture and context but cannot override normative flow contracts.
- `VERIFICATION_DOCUMENT_SECURITY.md` explains private document deployment, migration, and secret-mail configuration.
- `WEB_AUTH_DEPLOYMENT.md` explains production web session settings, HTTPS authentication redirects, notification scheduling, catalogue visibility checks, and the targeted demo repair after deployment.
- [Native rider settings API](api/RIDER_SETTINGS_API.md) defines versioned bearer access, contact revisions, password reauthentication, additional-email verification and the mobile deployment handoff.
- [Native rider operations API](api/RIDER_OPERATIONS_API.md) and [backend delivery plan](api/RIDER_NATIVE_PLAN.md) define scoped queues, commands and the operational integration boundaries.
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

For approval and suspension changes, read `ADMIN_FLOW.md` with the validation contract, then check every affected role guide. Platform KYC, company placement, account activity, and rider duty must keep the same meaning across portals.
