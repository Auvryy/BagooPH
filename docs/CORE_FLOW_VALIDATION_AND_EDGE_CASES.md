# BagooPH Core Flow Validation and Edge-Case Contract

This document defines the adversarial validation, authorization, concurrency, idempotency, and recovery rules for the buyer, seller, courier, logistics, and admin flows. It is authoritative for how the system rejects invalid input and prevents illegal state or custody changes.

It does not add new business modules. It hardens the approved road-based ecommerce flow in `SYSTEM_FLOW_AND_SPECIFICATIONS.md` and the custody flow in `SORTING_CENTER_LOGISTICS_FLOW.md`.

## 1. Universal Mutation Contract

Every transactional or privileged request that changes data must pass all of these gates in order. Public registration and authentication use their own validated, rate-limited entry points; they cannot grant client-selected privileges or approval.

1. Authenticate the actor.
2. Confirm the account is active and approved for the requested role, or authorize a narrowly documented existing-order/recovery exception under Section 8. An exception never grants new-work or general portal access.
3. Normalize and validate every input on the server.
4. Authorize ownership, tenant, company, facility, assignment, and order scope.
5. Re-read the current database state; never trust state rendered on an older page.
6. Lock records that can be claimed, consumed, counted, or financially changed.
7. Verify the exact allowed transition and required evidence.
8. Apply the business change and its required ledger/checkpoint records atomically.
9. Commit before dispatching notifications or other retryable side effects.
10. Return a clear result that is safe if the client retries.

Frontend restrictions improve usability but never replace server validation. Client-provided prices, discounts, totals, stock, roles, statuses, ownership, hub IDs, rider IDs, commission values, COD amounts, timestamps, or approval flags are never authoritative.

## 2. Input Normalization and Validation

### Text Safety

- Trim leading and trailing whitespace and normalize Unicode to NFKC before validation.
- Reject null bytes, control characters, bidirectional-control characters, and invisible zero-width characters except ordinary line breaks in approved multiline fields.
- Store user text as plain text and escape it on output. Do not accept executable HTML, script, CSS, data URLs, or event-handler markup.
- Enforce database-safe maximum lengths on the server. Truncation is forbidden because it can change names, addresses, codes, or evidence silently.
- Reject values made only of whitespace or punctuation when meaningful text is required.

### Canonical Field Rules

| Field | Accepted input | Canonical storage and rejection rules |
|---|---|---|
| Person or recipient name | Latin letters with diacritics, spaces, apostrophe, hyphen, and period | NFKC normalized; 2–100 characters; reject digits, emoji, controls, markup, and punctuation-only values |
| Philippine mobile number | `09XXXXXXXXX`, `639XXXXXXXXX`, or `+639XXXXXXXXX` | Normalize to `+639XXXXXXXXX`; reject letters, extensions, too few/many digits, premium/service numbers, and mixed scripts |
| Shop/company contact number | Valid Philippine mobile or landline | Normalize to one E.164-compatible representation; store no decorative spaces or punctuation |
| Email | Valid address | Trim and lowercase the domain; reject controls, whitespace inside the address, and duplicate accounts under case-insensitive comparison |
| Philippine postal code | Four ASCII digits | Reject letters, Unicode look-alike digits, signs, decimals, and values with other lengths |
| Address and landmark | Letters, numbers, spaces, and normal address punctuation | NFKC normalized; meaningful street/address length 5–500; reject controls, markup, and punctuation-only values |
| Barangay, city, province | Existing supported location or normalized approved free-text value | Compare case-insensitively; do not infer serviceability from client text alone |
| Quantity | ASCII integer | Minimum 1, maximum 99 per cart line, within current stock and any variant stock; reject decimals, signs, exponent notation, and text |
| Money | Server-calculated decimal | PHP, non-negative, two decimal places, stored with decimal/integer-cent arithmetic; reject floats from the client as authority |
| Percentage | Server-controlled decimal | Range 0–100; platform commission remains exactly 10% and seller share 90% of product subtotal |
| Identifier | Integer or server-generated opaque code expected by the route | Must exist, belong to the actor's scope, and match the parent record; reject foreign, deleted, or mismatched IDs |
| Enum/status/reason | Exact allowlisted value | Normalize case only where documented; reject unknown values instead of creating new statuses |
| Voucher code | Visible ASCII letters, digits, hyphen, or underscore | Trim and uppercase; reject controls and overlong values; revalidate activity, scope, limit, and minimum spend at checkout |
| Tracking/waybill code | Server-generated visible ASCII | Trim and uppercase for lookup; never accept a client-selected tracking number for creation |
| Notes and explanations | Plain text | NFKC normalized; 1–1,000 characters when required; reject controls and markup; notes cannot replace a required reason code |
| Latitude/longitude | Decimal in geographic range | Latitude -90 to 90 and longitude -180 to 180; coordinates never override the textual destination or assigned hub |
| Date/time | Server-generated or validated ISO date where scheduling is allowed | Persist a single canonical timezone representation; display in Asia/Manila; reject impossible or past retry dates |

### File Evidence

- KYC documents, product images, and delivery proof use allowlisted MIME types verified from file content, not only filename extension.
- Enforce purpose-specific size and image-dimension limits, generate storage filenames, and prevent path traversal or public execution.
- KYC files are private and available only to the applicant and authorized reviewers.
- Proof files become immutable evidence after the related transition; corrections append evidence rather than replacing the original.
- SVG, HTML, scripts, archives, and disguised executable files are rejected unless a later specification explicitly permits them.

### Authentication, Password, and OTP

- Passwords are 12–128 characters, may use passphrases, are confirmed at creation/reset, and are stored only with the framework password hash.
- Login errors do not reveal whether an email exists. Login, password reset, OTP request, and OTP verification are rate-limited by normalized account identifier and network source.
- Successful login and privilege changes regenerate the session; logout and suspension invalidate active sessions where supported.
- Email OTP is exactly six ASCII digits, cryptographically generated, hashed at rest, single-use, expires after ten minutes, and allows at most five verification attempts.
- OTP resend has a 60-second cooldown and at most five requests per 15 minutes per email/purpose. A new OTP invalidates the previous active OTP.
- OTPs, passwords, reset tokens, self-pickup claim codes, and verification tokens never appear in logs, URLs, analytics, notifications, or support screens.
- If OTP delivery fails, the system reports that delivery failed and offers a safe retry; it must not claim success or log the secret code.
- Birthday is a real calendar date in `YYYY-MM-DD` before the current Philippine (`Asia/Manila`) date. The server derives completed years from birthday; a client-submitted age never overrides it. Seller, courier, logistics, and controlled admin accounts require a valid birth date proving age 18 or older. Buyer purchasing eligibility follows the approved KYC policy; the worker adult minimum does not apply to a buyer birth date.
- Sex, role, vehicle type, and other categorical registration inputs use explicit allowlists rather than arbitrary text.

### Operational Identifiers

- Company codes, SKUs, plates, license numbers, facility codes, bins, and manifest numbers use documented visible ASCII formats and uniqueness rules.
- Normalize codes to uppercase and collapse permitted separators. Reject emoji, control characters, path characters, and visually empty values.
- Server-generated order, tracking, manifest, checkpoint, audit, cash, and settlement references cannot be selected or replaced by the client.

## 3. Shared State, Concurrency, and Idempotency Rules

- Checkout uses one server-verifiable idempotency token. Retrying the same submission returns the same generated orders and cannot decrement stock or consume a voucher twice. This token is not a user-facing checkout group.
- Products, voucher counters, job claims, delivery assignments, manifests, COD balances, and settlement gates use transactional locks or equivalent atomic constraints.
- Every mutation verifies its allowed source state. Repeated, skipped, backward, and terminal-state transitions are rejected.
- Repeating a successful scan with the same actor, parcel, action, and facility returns the existing result without duplicating custody or checkpoints.
- A different action using stale page data receives a conflict response containing the current state and the permitted next action.
- Each parcel may have only one active pickup assignment, one active final-mile assignment, one active manifest, and one current custodian.
- Notifications use an event idempotency key. A committed business event produces at most one notification per recipient and event type.
- A notification failure does not roll back a committed order, scan, custody, or money event; it enters a retry path.
- Terminal orders cannot re-enter active fulfillment. `CANCELLED`, `COMPLETED`, and `RETURNED` reject further operational transitions.
- Every status-changing route, including public tracking, admin tools, bulk actions, and development simulators, must call the same lifecycle and authorization services. Alternate controllers cannot implement weaker rules.
- Production disables simulator/reset routes and seeded shortcuts. Public tracking is read-only unless an authenticated role action delegates to the canonical lifecycle service.
- The system never invents proof images, sample disputes, sample messages, successful scans, or financial entries in a live operational path.

## 4. Buyer Flow Debug Contract

### Account, Profile, and Address

- Registration rejects duplicate email/mobile identities, unsupported roles, malformed phone numbers, invalid files, and incomplete required consent.
- An unapproved, rejected, suspended, or deleted buyer cannot place an order.
- Saved-address create/update/delete actions are owner-scoped. A buyer cannot submit another user's address ID.
- Checkout snapshots the recipient and address. Later profile edits never rewrite an existing order or waybill.
- A serviceability check uses normalized location data and the logistics network, not a buyer-controlled province label or GPS pin alone.

### Shopping Bag and Checkout

- Search and filter terms are trimmed, length-limited, treated as literal values, and never interpreted as raw query syntax. Invalid numeric ranges return validation feedback instead of being silently ignored.
- A buyer may mutate only their own Shopping Bag and cart lines.
- Product, variant, shop activity, current price, and stock are revalidated during checkout under one database transaction.
- A multi-shop selection creates one independent order, parcel, waybill, route, and shipping fee per shop; failure in any selected shop rolls back the complete checkout.
- Unknown, duplicate, foreign-cart, inactive-product, or zero selected item IDs are rejected.
- Shop vouchers affect only their matching shop order. Platform voucher allocation is deterministic, proportional, rounded to cents, and never exceeds the original calculated discount.
- Voucher usage is consumed once per successful checkout request. Cancellation never silently reallocates a discount to sibling shop orders.
- COD is the baseline payment method. Submitting another payment value cannot change the server-selected method.
- An incomplete logistics route blocks checkout with a clear error. The system must not create an unroutable order and rely on manual repair.
- Double-clicks, browser retries, and network timeouts cannot create duplicate orders or duplicate stock deductions.

### Tracking, Delivery, and Completion

- Buyers can view and act only on their own orders.
- Public tracking requires a high-entropy tracking code, is rate-limited, and exposes only masked recipient/location and non-sensitive status data. It never exposes full phone, exact address, claim code, private proof, COD custody, KYC, or internal actor data.
- Public tracking is read-only. Authenticated role actions open their authorized portal flow and still pass the canonical lifecycle service.
- Internal Mother-Hub checkpoints are read-only to the buyer and map to the canonical commercial status.
- A buyer cannot select a rider, change custody, approve a retry, or mark an order delivered.
- Receipt confirmation is accepted only for the buyer's `DELIVERED` order. It is idempotent and creates one completion checkpoint.
- Buyer confirmation may occur before COD reconciliation, but seller settlement remains blocked until both conditions are satisfied.
- Orders never auto-complete because only the buyer may advance `DELIVERED -> COMPLETED`.
- Buyer self-service cancellation is not part of the current core baseline and must not be represented by a fake success action. Seller cancellation remains limited to the pre-custody boundary.

### Reviews, Messages, and Deferred Disputes

- A review requires a completed order item owned by the buyer, a rating integer from 1 to 5, one review per order item, and validated plain-text/image evidence.
- Buyers and sellers may read or send messages only within conversations in which they are authorized participants. Messages are length-limited, escaped, rate-limited, and never replaced with fallback sample conversations.
- Seller review replies require ownership of the reviewed product's shop and preserve the original buyer review.
- Complete post-delivery disputes, refunds, and return/exchange processing remain deferred. Their routes and pages must be unavailable or clearly read-only; they cannot submit sample cases or return fake success. Failed-delivery return-to-sender remains part of the core recovery flow.

### Self-Pickup

- The claim code is cryptographically generated, stored only as a hash, single-use, and never logged or returned after initial buyer presentation.
- Counter verification requires the expected destination hub, `ready_for_hub_pickup`, matching buyer identity, and unexpired code.
- Five failed code attempts lock verification for 15 minutes and create an audit event; a supervisor cannot reveal the original code.
- The holding period is exactly seven calendar days with reminders on days three and six. Expiry moves the parcel to the RTS exception queue; no silent extension is allowed in the baseline.
- COD must be recorded before counter release. A reused, expired, locked, wrong-hub, or wrong-state claim cannot advance delivery.

## 5. Seller Flow Debug Contract

### Account, Shop, Product, and Voucher

- Seller actions require an approved active seller and an approved active shop selected from that seller's shops.
- Shop switching changes scope only; it never changes ownership of products, vouchers, orders, or analytics.
- Product category must remain under the shop's approved root category.
- Product price is at least PHP 0.01, stock is a non-negative integer, variants have unique valid combinations/SKUs, and client totals are ignored.
- Product and voucher mutations verify shop ownership on every request, including bulk actions and direct URLs.
- Voucher dates, limits, percentage range, fixed discount, maximum discount, and minimum spend must be internally consistent.
- Products referenced by carts, orders, reviews, or ledgers are archived/deactivated rather than hard-deleted. Historical order-item snapshots survive later product, shop, category, or account changes.

### Order Fulfillment

- A seller order contains items from exactly one shop. A legacy mixed-shop order is read-only and enters an admin repair queue.
- Seller transitions are only `PLACED -> CONFIRMED -> PREPARING -> READY_FOR_PICKUP`.
- Accept, pack, ready, batch, and cancel actions all use the same lifecycle rules; batch operations cannot bypass validation.
- The waybill is generated from the order snapshot and assigned route. A seller cannot edit the recipient, COD amount, route, or tracking number.
- Seller cancellation requires an allowlisted reason and optional notes, is allowed only before pickup claim/custody, and restores stock exactly once.
- Cancellation after a pickup claim or scan is rejected even if the seller has an older page open.
- A canceled order does not silently return or move its allocated voucher discount to sibling orders.
- The seller cannot mark `PICKED_UP`, `DELIVERED`, `COMPLETED`, or `RETURNED` without the actor-specific custody/receipt event.

### Return and Settlement

- Seller return receipt requires the expected reverse route, origin facility staging, authenticated recipient, and scan.
- Damaged or discrepant returns append an exception record but do not erase the original outbound or reverse custody trail.
- Seller balances distinguish delivered, buyer-completed, COD-pending, settlement-eligible, and paid.
- No displayed revenue or payout may treat `DELIVERED` alone as seller-settled income.
- Time passing alone never auto-confirms, auto-prepares, auto-cancels, or changes stock in the baseline. Stalled fulfillment is surfaced for explicit seller/admin resolution with an audit reason.

## 6. Courier Flow Debug Contract

### Eligibility and Assignment

- A rider must be approved, active, available, and within the required company/hub/barangay scope to claim or receive new work. Recheck approval, active status, assignment, and scope at custody scans; going off duty may not prevent finishing an existing authorized assignment. Suspension instead uses the controlled recovery rules in Section 8.
- Pickup and final-mile work are phases of one courier role; a pickup assignment does not grant final-mile authority.
- Pickup claims are atomic. Exactly one rider wins; all concurrent losers receive the already-claimed result without assignment changes.
- An unavailable, suspended, at-capacity, wrong-company, wrong-hub, or incompatible-barangay rider cannot claim or receive work. Pickup riders may hold multiple active pickup assignments up to the configured capacity; final-mile assignment remains phase-separated.
- Before pickup custody, a rider may release a claim only with an allowlisted reason; the release is audited and returns the job to the eligible board. After pickup scan, assignment release is prohibited until a hub records custody recovery.

### Pickup and Facility Handoff

- Pickup scan requires the assigned rider, correct parcel, `READY_FOR_PICKUP`, seller location, and unclaimed custody.
- The pickup rider may hand the parcel only to its assigned Origin Bayan Hub.
- Pickup responsibility ends only when the expected hub scans inbound; leaving the page or marking a generic transit status does not end custody.
- Duplicate scans are idempotent. A mismatched or damaged parcel enters an exception path without advancing the order.

### Final-Mile Outcome

- Only the assigned final-mile rider may scan out from the destination hub or submit a result.
- Successful doorstep delivery requires a proof image, recipient name or relationship, server timestamp, and exact COD collection. Optional coordinates or signature may support proof but cannot replace the required handoff evidence.
- A failed attempt requires one allowlisted reason code, useful notes, attempt number, proof where required, and a server timestamp.
- Failure ends rider custody only after the destination hub scans the parcel back in.
- Retryable failures on attempts one and two may be retried only after hub approval. Customer refusal is non-retryable and starts RTS after destination-hub return. The third failure always starts RTS; the rider cannot mark `RETURNED`.
- Buyer clarification may append directions or a landmark within the existing destination service area. Changing the recipient, city, province, destination hub, or COD amount after dispatch is rejected.
- Rider earnings use the assigned final-mile rider, not the earlier pickup rider or a generic courier field.

### COD

- The exact order-total snapshot is the COD amount. Partial payment is not accepted in the baseline.
- A collector may accept a larger cash tender only when correct change is returned before handoff; the ledger records the exact amount due, not the tendered amount. Suspect or unusable cash follows the COD-unavailable failure path.
- If the buyer cannot provide the required amount, the rider records the approved COD-unavailable failure reason and returns the parcel to the hub.
- Delivery records cash as held by the collecting rider; it does not mark the order paid at platform level or settle commission.
- A rider cannot edit, delete, or replace a collection/remittance entry. Discrepancies use append-only adjustments and review.

## 7. Logistics Company Admin and Hub Handler Debug Contract

### Company, Facility, Personnel, and Fleet Scope

- Logistics Company Admin actions are restricted to their own company, hubs, handlers, riders, vehicles, manifests, parcels, and COD remittances.
- Hub Handlers act only at assigned active facilities. Selecting another hub in the UI never grants access.
- A Mother Hub cannot masquerade as a Bayan Hub action or vice versa.
- Facility codes, vehicle plates, handler assignments, and active manifest numbers are unique within their required scope.
- Suspending a company, hub, handler, rider, or vehicle blocks new work but never deletes active custody; affected parcels enter an auditable exception/reassignment path.

### Manifest Lifecycle

- Manifest lifecycle is `DRAFT -> SEALED -> DISPATCHED -> RECEIVED -> CLOSED`.
- Source and destination hubs must be different, active, in the same responsible logistics route, and appropriate for feeder or line-haul movement.
- A parcel may appear once in one active manifest and must be expected at the source hub before loading.
- Sealing freezes the parcel list. Corrections require reopening before dispatch or an append-only discrepancy after dispatch.
- Dispatch requires a scoped dispatcher, active vehicle, responsible driver when used, departure time, and all included parcel outbound scans.
- Receive records the receiving handler, facility, arrival time, and each parcel inbound. Missing, extra, duplicate, damaged, or wrong-hub parcels enter discrepancy handling.
- A manifest closes only when every included parcel is received or has a documented discrepancy resolution.
- Same-region routes still require one Mother Hub. Cross-region routes require origin and destination Mother-Hub custody.

### Sorting, Retry, RTS, and Counter Release

- A parcel advances to `SORTED` only after required Mother-Hub checkpoints and destination-bin assignment.
- Final-mile assignment requires exactly one eligible rider; reassignment preserves the previous assignment history and reason.
- Failed parcels cannot be reassigned until destination-hub inbound custody and retry approval are recorded.
- RTS uses the original waybill plus appended reverse checkpoints; it cannot overwrite outbound history.
- Self-pickup release follows every claim, identity, state, hub, expiry, and COD rule in this contract.

## 8. Platform Admin Debug Contract

- [ADMIN_FLOW.md](ADMIN_FLOW.md) defines the approval authority, review decisions, and cross-role suspension effects under this safety contract. Platform Admin owns marketplace KYC; company acceptance/assignment of an already approved courier cannot grant platform approval or reverse a suspension.
- Every privileged read and mutation requires an active authorized admin, including direct root/subdomain URLs and existing sessions. Admin's applicant-KYC exemption never bypasses activity, suspension, or action checks.
- Platform Admin approves or rejects accounts and logistics companies but cannot fabricate missing KYC evidence. Validate required private documents and role/profile scope before approval; registration, email verification, and rider duty are separate checks.
- Approval/rejection locks the current submission/account and affected profiles, and commits the decision and immutable audit record together. Rejection, reversal, suspension, and reactivation require a meaningful reason.
- An identical review retry preserves the original reviewer, time, reason, and evidence references. Conflicting stale decisions are rejected; changing a completed decision or feedback requires an explicit audited correction.
- Approval and resubmission cannot clear an independent inactive/suspended restriction. Reactivation requires a separate authorized decision and valid approval; it cannot implicitly activate unrelated shops, personnel, facilities, or vehicles.
- Admin cannot perform routine parcel scans, impersonate a seller/rider/handler transition, or silently modify custody.
- Every override requires the current state, requested correction, reason, actor, timestamp, and before/after values in an immutable audit record.
- Financial corrections are append-only adjustments. Admin cannot edit or delete original COD or commission entries.
- Platform Admin cannot access a plaintext self-pickup claim code or user password.
- Every saved account keeps the role assigned at creation, regardless of approval, activity, or transaction history. Admin and profile requests cannot convert accounts or grant a different role. Another public role requires a separate registration and its normal evidence/approval; existing records stay with the original account. There is no role-conversion or migration workflow in this project.
- Account deletion is blocked while orders, parcel custody, COD, or settlement remain active. Later privacy handling anonymizes eligible personal fields without deleting transactional evidence.
- Restriction, closure, and reviewed-identity correction use a serialized current-eligibility check to protect the last eligible Platform Admin. A role-only count or stale session is insufficient. Controlled recovery cannot fabricate identity, convert a public account, or bypass privileged eligibility.
- Reviewed identity/category corrections require a current versioned request, meaningful reason, private evidence where required, prior decision or explicit legacy provenance, and immutable before/after history. Generic profile/resubmission edits cannot replace reviewed identity; corrections preserve independent restrictions and active-work obligations. Identical retries preserve the original result; stale competing changes conflict.
- Product compliance removal/reinstatement requires current source state, reason, actor/time, immutable audit, and positive seller/shop/category eligibility. Seller edits cannot clear a platform restriction. Preserve purchased-item snapshots and referenced products; no price/stock override or destructive history edit accompanies moderation.
- Governance search/detail is read-only, currently authorized, and subject/company scoped. Historical evidence links re-check private access. Never expose raw paths, passwords, tokens, or plaintext counter claims, or fabricate old decisions for legacy records without provenance.
- Complete dispute/refund/exchange processing remains deferred. Placeholder pages must show unavailable and cannot return sample cases or fake success.

### Suspension During Active Work

- Suspending a buyer blocks new orders but preserves access to owned tracking and required completion actions for existing orders unless a security review explicitly restricts them.
- Suspending a seller blocks new listings/orders and moves unfulfilled orders to an admin exception queue; it does not silently cancel or restore stock.
- Suspending a rider carrying a parcel requires a hub custody-recovery scan before reassignment.
- Suspending a logistics company blocks new routing and sends active parcels to platform-supervised exception handling without deleting checkpoints.
- Restricting a hub, handler, or vehicle blocks new work using that resource and requires authorized recovery of existing custody, manifests, and cash before replacement. Record the restriction and recovery responsibility; never treat an account/status edit as a handoff scan.
- Recovery and the buyer's owned tracking/receipt-confirmation exception use explicit server-side action scope. They do not reopen checkout, new assignments, routine privileged access, or foreign records for a suspended account.

## 9. Failure and Recovery Matrix

| Adversarial event | Required safe result |
|---|---|
| Checkout button submitted twice | Return the same result; one stock deduction and one voucher consumption |
| Two buyers purchase the last unit | One transaction succeeds; the other receives an out-of-stock error with no partial order |
| Voucher reaches its usage limit concurrently | Only requests within the locked limit succeed |
| One selected shop has no route | Entire selected checkout rolls back |
| Seller uses stale page after rider claim | Cancellation or status edit is rejected with current custody shown |
| Two riders claim one pickup | One assignment is created; losing claims change nothing |
| Rider submits delivery twice | Existing delivery result is returned; no duplicate proof, cash, or commission entry |
| Wrong hub scans a parcel | No status advance; create an exception/audit event |
| Parcel scanned into two manifests | Second load is rejected |
| Manifest arrives with a missing parcel | Manifest remains unresolved; received parcels keep valid individual custody |
| Third delivery failure | Begin reverse routing; do not mark `RETURNED` yet |
| Expired or reused pickup code | Release is denied; custody remains with destination hub |
| Buyer confirms before delivery | Reject without creating a completion checkpoint |
| Notification creation fails | Business event stays committed; notification is retried |
| COD amount differs from order snapshot | Do not reconcile; record discrepancy and retain cash custody |
| Admin attempts direct history edit | Reject; require an append-only adjustment/override record |
| Actor is suspended mid-custody | Preserve custody and require controlled recovery; never orphan the parcel |

## 10. Error and Audit Contract

- Validation failures return field-specific messages and preserve safe user input; secrets and claim codes are never echoed.
- Authorization failures reveal no foreign order, user, parcel, hub, or financial details.
- State conflicts return the current state and permitted next action, not a generic success or server error.
- Infrastructure failures return a correlation reference while logs retain technical context without passwords, OTPs, claim codes, KYC content, or payment secrets.
- Every custody, status, approval, override, COD, and settlement event records actor, role, timestamp, source state, target state/action, related IDs, and reason/evidence where required.
- Audit, checkpoint, manifest, attempt, and cash records are append-only. Corrections reference the original record.
- Use HTTP 422 for input validation, 401 or authentication redirect for unauthenticated access, 403 for known-but-forbidden actions, 404 when a scoped record is unavailable, 409 for stale/illegal state conflicts, 429 for throttling, and 500 only for unexpected failures with a correlation reference.

## 11. Mandatory Acceptance Gate

Every implemented mutation must have focused tests for:

1. Valid happy path.
2. Missing and malformed required input.
3. Boundary values and Unicode/control-character input.
4. Anonymous, wrong-role, wrong-owner, wrong-company, and wrong-facility actor.
5. Invalid, repeated, skipped, backward, and terminal-state transitions.
6. Duplicate request and idempotent retry.
7. Concurrent claim, stock, voucher, assignment, or ledger mutation where applicable.
8. Transaction rollback after a mid-operation failure.
9. Audit/checkpoint/ledger creation and immutability.
10. Clear failure response with no unrelated data mutation.

Tests use isolated SQLite `:memory:` and must never touch development or production PostgreSQL. High-risk concurrency behavior that SQLite cannot faithfully prove also requires service-level constraints and a PostgreSQL-safe design review before deployment.

## 12. Explicitly Deferred

This contract does not introduce maritime or air freight, live GPS, AI routing, automated warehouses, advanced analytics, external order-notification services, complete refunds/exchanges/disputes, or automated fraud scoring.
