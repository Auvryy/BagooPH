# Administrator Governance Flow

This document defines the admin behavior to build for the core BagooPH project. It specifies decisions, authority, evidence, and acceptance checks; it does not claim that the corresponding screens or records already exist. Current gaps and delivery phases belong in [CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md).

Platform Admin governs marketplace access and financial audit. Logistics Company Admin manages its own network. Hub Handlers perform assigned facility operations. These are responsibilities within the existing `admin` and `logistics` account roles, not additional public registration roles. Pickup and delivery remain phases of the same `courier` role.

Lifecycle ownership follows [SYSTEM_FLOW_AND_SPECIFICATIONS.md](SYSTEM_FLOW_AND_SPECIFICATIONS.md), safety follows [CORE_FLOW_VALIDATION_AND_EDGE_CASES.md](CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and physical custody follows [SORTING_CENTER_LOGISTICS_FLOW.md](SORTING_CENTER_LOGISTICS_FLOW.md).

## 1. Approval Authority and Evidence

Platform Admin owns marketplace account approval. A logistics company's acceptance or assignment of a rider is an operational placement of an already approved courier; it cannot grant platform KYC approval or undo a suspension.

| Applicant or responsibility | Approval authority | Evidence and scope |
|---|---|---|
| Buyer | Active Platform Admin | Identity document and validated account details before transactional access |
| Seller and shop | Active Platform Admin | Identity, business permit, shop details, and an approved root category from the 14 master categories; each shop needs its own valid scope |
| Courier | Active Platform Admin | Identity, driver's license, vehicle details, and OR/CR documents; company/hub placement does not replace this review |
| Logistics company | Active Platform Admin | Company and contact details, business permit, and franchise evidence where applicable to the documented application; no additional accreditation module is implied |
| Hub Handler | Platform Admin for account eligibility; approved Logistics Company Admin for facility assignment | An eligible `logistics` account with an active handler assignment at an active facility in that company; facility assignment alone cannot grant marketplace approval |
| Platform Admin | Controlled existing admin access | No public admin registration; active account status and explicit admin role are required for every privileged action |

Registration, email verification, document upload, and company placement do not each constitute approval. Applicants may sign in to their own review/resubmission holding screen; transactional portal access waits for the required approval. Public catalogue browsing follows the buyer guide and grants no transactional permission.

Reviewers must inspect the required private evidence before deciding. Missing or invalid evidence prevents approval. Document access uses authorized private links as described in [VERIFICATION_DOCUMENT_SECURITY.md](VERIFICATION_DOCUMENT_SECURITY.md); files and raw storage paths must not be exposed through dashboards or audit exports.

Worker approval also requires a valid birth date proving age 18 or older under the shared validation contract; the review uses server-calculated age. An unreviewed worker may correct a birth date while pending. Corrected details invalidate stale review and document-inspection confirmations, so the reviewer must inspect the current submission again. A completed review cannot be changed through applicant resubmission.

## 2. Approval and Account State

KYC review, account activity, shop/company eligibility, facility assignment, and rider duty are distinct checks. A reviewed account may still be inactive or suspended. An active account without the required approval remains ineligible.

Use the existing `pending_approval`, `approved`, and `rejected` KYC values. Existing reviewed `verified` records retain compatibility; clients cannot choose an approval flag. Admin accounts do not use applicant KYC, but that exemption never bypasses the active-status or action-authority checks.

| Decision | Required source and result |
|---|---|
| Approve an application | Review the current pending submission and required evidence. Record approval and activate the eligible application/profile together, without clearing an independent inactive or suspended restriction. |
| Reject an application | Review the current pending submission, record a meaningful reason, and keep transactional access blocked. Preserve any existing suspension. |
| Resubmit after rejection | Applicant supplies corrected application details or evidence; the submission returns to pending review. Preserve the earlier decision and its evidence references. Resubmission never reactivates a suspended account. |
| Reverse a reviewed decision | Explicit correction with reason, prior decision reference, and current evidence. Preserve history and apply the same active-work protections as suspension. |
| Correct reviewed identity or shop scope | Review a versioned correction request against current evidence and canonical field/category rules. Record prior provenance and before/after values; preserve earlier decisions, independent restrictions, and affected-work obligations. |
| Suspend or deactivate | Explicit reason and affected-work review; remove new-work eligibility while preserving orders, custody, cash, and evidence. |
| Reactivate | Separate reasoned decision after valid approval and profile/company scope are checked. It cannot silently approve rejected KYC or reactivate unrelated shops, facilities, personnel, or vehicles. |

For every decision:

1. Authenticate an active authorized reviewer and validate the target, action, source state, and reason.
2. Re-read and lock the account and affected shop/company records in a consistent transaction order.
3. Validate required evidence and dependent eligibility. Apply the decision and append its audit record atomically; failure leaves no partial profile activation.
4. Record actor, role, server time, subject, submission/evidence references, before/after values, and reason where required. These are required audit contents, not a claim about an existing table or new public fields.
5. Commit before any retryable notification. Notification failure must not erase a committed decision or produce a second review event.

An identical retry against the same submission returns the original result, reviewer, time, and reason. It cannot rewrite feedback or create duplicate history. A competing decision based on stale state returns a conflict and the current permitted action. Changing a reason after a completed review is an explicit audited correction.

## 3. Access and Operational Boundaries

Every protected read and write applies the same server-side eligibility and action checks on root and subdomain portals, including direct URLs, bulk actions, and stale sessions. A hidden button or a successful login is insufficient. Unknown account states deny access; suspension invalidates sessions where supported.

| Actor | Permitted work | Boundary |
|---|---|---|
| Platform Admin | Account/shop/company review, reasoned suspension/reactivation, product compliance, cross-company oversight, and financial audit | Read oversight does not confer seller, rider, or handler custody powers. No routine parcel scans, impersonated fulfillment, direct history edits, or unexplained cash changes. |
| Logistics Company Admin | Own Mother/Bayan Hubs, handler assignments, eligible rider placement, vehicles, manifests, exceptions, and operational remittance | Own company only. Cannot approve platform KYC, override global suspension, access another company's private records, or settle seller proceeds. |
| Hub Handler | Expected-facility inbound, sorting, manifest, outbound, counter, failed-return, and seller-return scans | Active assigned facilities only, with current parcel and route checks. Cannot manage company approval or platform finance. |

New routing or assignment requires an eligible account, company, hub, handler/rider, and vehicle where applicable. Going off duty removes a courier from new work but permits the documented completion of existing assignments; suspension instead requires controlled recovery.

Account approval does not automatically approve additional seller shops. Company approval does not authorize every future handler, rider placement, or facility assignment. Scope changes must be authorized and must preserve assignment and custody history. Service coverage stays within supported contiguous roads; no sea or air routing is permitted.

## 4. Suspension, Account Roles, and Deletion

Before suspension, identify affected orders, assignments, parcel custody, and unreconciled cash. Record the restriction and recovery responsibility together. The exception process must retain the original custodian until a valid handoff; an admin status edit cannot stand in for a scan.

| Restricted subject | New work | Existing work and evidence |
|---|---|---|
| Buyer | Block new orders | Preserve owned tracking and required receipt confirmation unless an explicit security review restricts them. The exception grants no checkout or foreign-order access. |
| Seller or shop | Block new listings and new orders in the affected scope | Put unfulfilled orders into an admin exception queue. Do not silently cancel orders, restore stock, or erase obligations. |
| Courier | Block claims and assignments | If carrying a parcel, require the expected hub's custody-recovery scan before reassignment. Preserve delivery proof, assignment history, and any cash still held. |
| Logistics company | Block new routing and dispatch into the restricted network | Send active parcels and cash to platform-supervised exception handling; retain facility, route, and checkpoint history. |
| Hub, handler, or vehicle | Block new work using that resource | Arrange an authorized custody recovery before replacement or reassignment; do not delete active manifests or checkpoints. |
| Platform Admin | Block privileged reads and writes when inactive or suspended | Preserve prior decisions and audit history. KYC exemption gives no suspension bypass. |

Recovery is a narrowly authorized action with evidence and audit, not unrestricted access for a suspended actor. Reinstatement must re-check eligibility and cannot silently resume a stale assignment or skipped lifecycle step.

An account's role is assigned when it is created and remains fixed, including while pending, rejected, inactive, or suspended and before it has any transaction history. Platform Admin cannot convert an existing buyer into a seller, courier, logistics account, or admin, or switch any other existing account to another role. The project has no role-conversion or role-migration workflow.

Someone needing another public role registers a separate account with its own required profile, evidence, and approval. Public registration cannot select the admin role. Existing orders, shops, parcel custody, cash, and review history remain attached to their original accounts. Role filters and read-only role labels are permitted; role selectors and conversion actions for saved accounts are not.

Routine restriction, closure, or reviewed-identity correction cannot remove the last eligible Platform Admin. Re-evaluate eligibility under the privileged-access policy and serialize competing decisions; counting every account labelled admin is insufficient. When verified evidence would invalidate the final admin, require explicit controlled replacement/recovery rather than falsifying identity, converting a public account, or adding a hidden access bypass.

Account deletion is blocked while orders, custody, COD, or settlement remain active. Later privacy handling may anonymize eligible personal fields while retaining transactional evidence.

## 5. Commission and Payout Governance

- Commission base is the order product subtotal.
- Platform commission is 10%; seller share is 90%.
- Shipping and handling are tracked separately.
- Payout eligibility requires order `COMPLETED` and platform-level COD reconciliation.
- Rider earnings and logistics revenue do not reduce or merge into the seller/product split.
- Refunds, reversals, shortages, and corrections use traceable adjustment records.

Admin displays distinguish cash collected, cash held, cash remitted, reconciled COD, pending seller proceeds, and settled proceeds. Use recorded amounts in PHP/₱; an order count multiplied by a guessed fee is not a financial ledger. No fixed shipping revenue split is introduced by this document.

## 6. Admin Review Screens and Dispute Evidence

The core admin interface should support a small, understandable workflow:

- Overview: real pending review counts and unresolved core exceptions, with links to the affected records.
- Applications: role/status filters, authorized evidence, current review state, explicit decision, reason, and decision history.
- Accounts: fixed registered role, approval, and activity shown separately, affected-work summary before suspension, and explicit reactivation checks. No role-conversion control.
- Shops and companies: their own review state, category/parent scope, resource restrictions, and affected work; account approval does not imply approval of every dependent resource.
- Products: explicit reasoned compliance removal/reinstatement, current source state, eligible seller/shop/category, and decision history. Preserve purchased-item snapshots; seller edits cannot silently clear a platform compliance restriction.
- Governance history: read-only subject/actor/type/date filters and pagination across actual review, restriction, correction, closure, and moderation sources, with authorized evidence links.
- Orders and parcels: read-only lifecycle, waybill, custody, proof, and exception evidence; no routine scan controls.
- Finance: separate pending and reconciled amounts with references to original cash and settlement records when that phase is delivered.

Empty queues show an empty state. Unavailable modules show unavailable. Do not substitute example disputes, invented trends, sample balances, or success responses for unimplemented actions. Ordinary searchable lists and pagination are sufficient; enterprise analytics and a redesign of every portal are outside this task's baseline.

The account inspector distinguishes current state from historical decisions. Legacy records without recorded review provenance must be identified honestly; do not manufacture an old reviewer, decision time, birth date, or category approval. Controlled reviewed-identity corrections use a separate current request and evidence review, rather than silently rewriting approved fields through generic profile edits. An identical correction retry preserves its original outcome; stale competing requests conflict.

Product reinstatement cannot approve a rejected seller, reactivate a restricted shop, or clear another eligibility gate. Referenced products are retained or safely archived under the actual schema; compliance actions cannot change historical order prices/quantities, transfer ownership, or erase evidence. Product decisions and their audit commit together.

Audit search is read-only and applies current privileged eligibility and company/subject scope on every request. History links re-authorize private evidence access; no raw file paths, passwords, tokens, or plaintext self-pickup codes appear in results or exports. Different decision sources retain their own provenance rather than being presented as invented KYC events.

Admin review may include account documents, order items, waybill and hub checkpoints, manifest history, rider assignment, delivery proof, failure reasons, COD custody records, buyer confirmation, and role messages.

The core baseline preserves evidence and may show dispute handling as unavailable until the separate post-delivery dispute milestone is approved. Complete refund, exchange, and automated dispute processing must not be implied by placeholder screens or fake success responses. Any future resolution cannot erase the original audit trail.

## 7. Acceptance Checks and Delivery Order

| Scenario | Required result |
|---|---|
| Pending, rejected, inactive, suspended, or unknown-state account opens a protected portal directly | Deny transactional access on both root and subdomain URLs; allow only the explicitly documented holding/recovery actions. |
| Inactive or suspended admin uses an existing session | Deny privileged reads and writes, including approval and restriction decisions. |
| Company Admin attempts platform approval or foreign-company assignment | Deny; no approval, assignment, or unrelated record changes. |
| Reviewer approves missing or invalid evidence | Validation failure; account and related profile remain unchanged. |
| Worker application has a missing, invalid, future, or underage birth date, even with a claimed adult age | Reject registration or approval without changing eligibility; a pending correction requires a fresh review. |
| Review is submitted twice | Return one recorded decision with unchanged reviewer/time/reason. |
| Two reviewers submit conflicting decisions | One valid decision commits; the stale request conflicts without overwriting it. |
| Related profile or audit write fails | Roll back the complete decision. |
| Account is approved while separately suspended | Preserve the restriction; explicit reactivation is still required. |
| Seller, courier, company, handler, hub, or vehicle is suspended during active work | Block new work, preserve custody/cash, and require the documented recovery path. |
| Suspended buyer tracks or confirms an owned delivered order | Apply the narrow existing-order exception; reject new checkout, foreign orders, and premature completion. |
| Admin attempts to change any saved account's role, including their own or a pending account with no history | No conversion action is available. Direct requests cannot change the role, activate the account, or transfer related records. |
| Account deletion would lose active transactional history | Reject without changing related work or evidence. |
| Two restrictions/closures/corrections would remove the last eligible admin | Serialize the current eligibility check and block the routine action that removes final oversight; controlled recovery remains explicit. |
| Applicant edits reviewed identity through an ordinary profile request | No silent replacement; require the evidenced, versioned correction workflow and preserve prior decisions. |
| Reviewed correction applies while the subject is independently suspended | Preserve restriction and active-work responsibility; a correction alone cannot reactivate the account. |
| Product is reinstated under a restricted seller/shop or invalid category | Deny without changing product/audit; no eligibility bypass. |
| Seller edits a platform-restricted product or admin retries moderation | Preserve compliance restriction; one recorded decision for an identical retry. |
| Audit search or historical evidence link is used outside current scope | Deny without leaking private paths, secrets, or unrelated records; history cannot be edited. |
| Admin attempts routine scan or direct financial/custody history edit | Deny; require the separately authorized correction workflow. |

Use isolated SQLite `:memory:` tests for implemented behavior and the full adversarial acceptance gate in the validation contract. Simultaneous PostgreSQL locking needs a design review beyond SQLite tests.

Deliver approval/access/audit foundations in Phase 0. Manifests, delivery recovery/self-pickup, notifications, and COD reconciliation retain Phases 2, 3, 4, and 5 respectively. Preserve active work during restrictions; do not enable direct admin overrides while waiting for later recovery or correction modules. Complete post-delivery disputes, refunds, exchanges, advanced rates, and analytics remain deferred.

## 8. Implementation Status

Current admin and logistics-company gaps and their approved delivery phase are tracked only in `docs/CORE_FLOW_ROADMAP.md`.

The bounded delivery breakdown is in [admin-plan/README.md](admin-plan/README.md). Its branch allocation does not replace these contracts or imply that the planned functions are implemented.
