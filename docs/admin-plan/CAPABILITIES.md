# Admin Capabilities and Scope Boundaries

Use this document to understand why the [planned branches](README.md) exist. It is a product/implementation plan under [ADMIN_FLOW.md](../ADMIN_FLOW.md), not an implementation-status list or a declaration of new executable models/routes.

## Recommended core functions

| Function | Admin value | Planned delivery | Boundary |
|---|---|---|---|
| Application readiness and category review | See exactly which verified prerequisite prevents approval | B01, B02 | Missing data is a blocker; uploads and email verification do not constitute approval. |
| Independent shop approval | Review each seller shop/category separately | B03 | Account approval cannot approve additional shops. |
| Company/facility/personnel eligibility | Apply the same parent and ownership checks throughout the network | B04 | Company placement never grants platform KYC or foreign-company authority. |
| Buyer access and holding alignment | Give applicants a useful correction path while blocking new transactions | B05 | Preserve the narrow owned-order tracking/receipt exception. |
| Reasoned account restrictions | Explain who restricted an account, why, and what work it affects | B06 | Preserve custody/cash; reactivation is a separate decision. |
| Last eligible admin protection | Prevent a routine action from removing all eligible platform oversight | B06, B08, B09 | No public admin registration, role conversion, shared password, or hidden bypass. |
| Independent resource restrictions | Restrict one shop, company, facility, assignment, or vehicle without silently changing its parent/children | B07 | Recovery responsibility must remain explicit. |
| Controlled reviewed-identity correction | Repair legacy/mistaken birth dates or reviewed shop details with evidence and history | B08 | No silent self-edit of reviewed identity or fabricated legacy approvals. |
| Safe account closure | Block closure while work, cash, or evidence would be lost | B09 | Preserve referenced history; no cascading financial/custody deletion. |
| Reasoned product moderation | Explain compliance removals and safe reinstatement | B10 | No seller impersonation, price/stock override, or activation under an ineligible shop. |
| Account context and audit search | Investigate recorded decisions across subjects using normal filters and pagination | B11 | Read-only history; authorized private evidence; no secret/raw-path export. |
| Truthful overview | Show real queues, unresolved work, and actual availability in PHP/₱ | B12 | No invented online status, trends, revenue, payout, or fake success. |
| Exception oversight | Assign and monitor accountable recovery work | B14 | Only the actor-owned scan records a custody handoff. |
| Governance notifications | Tell the correct account about real review/restriction/recovery events | B15 | Persistent in-app only; no new paid or external delivery service. |
| Platform COD reconciliation | Verify recorded money movement and discrepancies | B16 | A delivery flag is not proof of remittance. |
| Seller settlement control | Release recorded proceeds only after buyer completion and reconciliation | B17 | 90% seller/10% platform on product subtotal; shipping separate. |
| Financial evidence review | Trace displayed totals to immutable records and authorized adjustments | B18 | No editing/deleting original cash or commission entries. |

These recommendations fit a small ecommerce project: searchable lists, evidence inspectors, explicit decisions, and accountable exception records. They do not require enterprise staffing, nationwide infrastructure, AI, or a separate role hierarchy.

## What governing each role means

| Subject | Platform Admin responsibility | Action ownership that stays with the subject |
|---|---|---|
| Buyer | Identity approval, restrictions, narrow recovery/closure decisions, order/finance oversight | Shopping Bag/checkout and the buyer's own receipt confirmation |
| Seller | Account and individual shop/category review, compliance, restrictions, proceeds oversight | Shop-owned product/stock management and permitted pre-pickup fulfillment |
| Courier | Identity/license/vehicle review, restrictions, custody/cash oversight | Assigned pickup/final-mile scans, real proof, delivery result, and recorded remittance |
| Logistics account | Company/account eligibility and cross-company governance | Approved company scope, facility operations, placement, manifests, scans, and operational remittance |
| Admin | Explicit eligible access, reasoned decisions, immutable history, safe continuity/closure | No added ability to impersonate another role or complete another actor's transaction |

Hub Handler remains a facility-scoped responsibility of `logistics`. Pickup and delivery remain phases of `courier`. An additional public role requires separate registration and normal approval; this plan never converts an existing account.

## Data and control separation

An account inspector should distinguish role, KYC, activity restriction, shop/company review, placement, duty, active work, and recorded finance. One "active" badge must not imply all these prerequisites passed. Review details use current server values; stored client claims never become authority.

Decision history records who decided, on which subject/evidence/version, when, why, and the resulting before/after values. Add required persistence only after inspecting the applicable existing models and migrations. The existing account KYC record cannot be assumed to represent every shop, resource restriction, moderation, exception, or settlement decision.

Permissions use the existing roles, ownership, company, facility, assignment, and source-state checks. Do not introduce a generic permission editor or more public roles to avoid implementing these rules.

## Useful additions to revisit after the core plan

| Optional idea | Why it may help | Prerequisite and scope decision |
|---|---|---|
| Manual support case references linked to existing evidence | Organize inquiries without manufacturing refund/dispute outcomes | Reuse B11/B14 evidence first; approve a separate support scope before new ticket workflows. |
| Controlled account security recovery notices | Make verified recovery actions visible to the affected account | Auth/session design review; no admin view of plaintext passwords, reset tokens, or OTPs. |
| Small redacted report downloads | Share an authorized audit view when a real user need exists | Privacy/retention/filter review after B11/B18; no bulk private-document or secret export. |

These optional ideas have no allocated branch and are not required to complete the 18-branch admin plan. Selecting one requires updating the plan and its branch count explicitly.

## Excluded functions

- Account role conversion, silent privilege escalation, public admin signup, and user impersonation.
- Direct admin delivery completion, routine hub/rider scans, buyer receipt confirmation, or arbitrary order-state editing.
- Full refunds, exchanges, post-delivery disputes, chargebacks, or invented resolution/payment success.
- External SMS/email/push services, new paid services, live GPS, AI dispatch, automated warehouses, and advanced analytics.
- Destructive resets, direct historical cash/audit replacement, uncontrolled bulk deletion, or guessed legacy identity/category data.
- Broad redesigns of all portals or restyling the merged rider interface.

The core objective is credible governance over actual users, resources, decisions, work, and money. The branch plan reaches that objective through bounded features and phase gates.
