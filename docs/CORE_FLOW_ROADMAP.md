# BagooPH Core Flow Roadmap

This document is the single source for current implementation gaps and delivery order across buyer, seller, courier, logistics, and admin. Stable business rules remain authoritative in `SYSTEM_FLOW_AND_SPECIFICATIONS.md`; physical custody rules remain authoritative in `SORTING_CENTER_LOGISTICS_FLOW.md`.

## Project Delivery Target: October 4, 2026

At the user's direction, planning begins **October 4, 2026**, with expected project completion around **November 20, 2026**. November 20 is the latest planned task deadline. The main target is in [README.md](README.md#project-delivery-target), and [the admin plan](admin-plan/README.md#delivery-window) divides the window into governance, prerequisite, later-admin, and final-review checkpoints. The allocation is 18 bounded admin tasks on 13 planned branches, or 14 Git deliveries including the existing foundation; B06+B07, B08+B09, B10-B12, and the selected B17+B18 batch each share one branch.

Every planned task uses **October 4, 2026** as its common start date and extends to its individual deadline. These overlapping planning windows do not authorize simultaneous implementation or bypass dependencies. The checkpoint windows describe intended execution and review order; estimates, acceptance gates, and completion evidence remain unchanged.

**Capacity risk:** the current 18-task effort estimate is 360 hours. October 5-November 20 contains 35 weekdays, providing 140-210 focused hours at the earlier assumption of 4-6 hours per weekday, before other required work. The date therefore requires an explicit capacity and priority review; it is not evidence that one developer can complete the entire baseline in that time. Keep estimates honest and identify additional help or an approved scope decision rather than shrinking estimates to fit the calendar.

Reserve November 18-20 for regression fixes, review, and demonstration preparation. Required safety, custody, recovery, notification, COD, and settlement gates remain unchanged. Missing or failing prerequisites stay visible; do not claim completion or silently extend the target. This documentation change leaves runtime readiness and the existing test baseline unchanged.

## Definition of Done

```text
Buyer checkout
-> Seller fulfillment
-> Pickup Rider
-> Origin Bayan Hub
-> Mother Hub
-> Destination Bayan Hub
-> Delivery Rider or Self-Pickup Counter
-> Buyer confirmation
-> COD reconciliation
-> Seller settlement
```

The core flow is complete only when the entire chain passes cross-role tests without a portal inventing statuses, bypassing custody, settling COD early, or relying on sample success data.

## Current Implementation Audit

Audit baseline: September 24, 2026. Later scoped reviews below update the relevant areas; they do not certify every role.

Scoped rider lifecycle lockdown review: October 3, 2026. This review updates entry-point, portal-access, tracking-privacy, and delivery-evidence controls; it is not a fresh audit of every role.

Scoped account/resource restriction review: October 5, 2026, B06+B07. This updates governance decisions, independent activity, and retained work; overall phase and cross-role ratings remain unchanged.

Scoped reviewed-identity and closure review: October 6, 2026, B08+B09. This adds separate correction requests and conservative role-wide closure decisions; overall phase and cross-role ratings remain unchanged.

Scoped product compliance, account context and overview review: October 6, 2026, B10-B12. This adds reasoned listing restrictions, searchable retained decisions and recorded admin metrics; overall phase and cross-role ratings remain unchanged.

Scoped B13 acceptance audit: October 6, 2026. Deferred buyer/seller modules are honest, saved checkout addresses match account identity, and eleven old failure identities are repaired. Phase 0 remains incomplete; the matrix, remaining 48 identities and owning gates are recorded below.

Scoped Phase 0 commerce repair: October 6, 2026. Checkout and saved addresses now share canonical input rules, successful checkout confirmations retain their original orders, and optional address saving participates in the checkout transaction. The fresh full-suite comparison retains the same 48 failures; overall phase and cross-role ratings remain unchanged.

Scoped B13 cross-role fixture follow-up: October 6, 2026. Real requests now establish normal order/custody evidence. The final full suite has 47 failures: 15 retained failed cases and 32 newly exposed missing-gate cases, with 33 baseline failures resolved. Phase 0 and later prerequisites remain incomplete; the exact comparison and owners are recorded below.

Scoped logistics sorting repair: October 7, 2026. Both operational sorting failures are fixed; 45 new cases pass. The final full suite has 45 unchanged later-gate failures, with no removed cases or new failures. Wider Phase 0 acceptance and custody/recovery prerequisites still require their own verification before B14.

Scoped profile/Shopping Bag repair: October 7, 2026. Existing profile writers share canonical rules, generic profile access requires current eligibility, and Bag quantities reject non-integer formats. All 71 added cases pass; the same 45 full-suite failures remain. Broader operational inputs/history and B14 prerequisites remain open.

Scoped B14 source and oversight review: October 7, 2026. Verified manifests, attempts/retries/returns, secure holding/counter collection and narrow restricted handover now feed an accountable exception queue. The final full run has 17 unchanged later-gate failures and zero errors; all 39 added cases pass and the actual breakdown case is resolved. Overall phase and cross-role ratings remain unchanged. See the final dated review below.

Scoped seller settlement and financial oversight review: October 9, 2026, B17+B18. Buyer confirmation and reconciled cash precede an evidenced seller payment; original cash and proceeds feed scoped read-only totals. The final full run passes 2,800 tests, resolving all 16 baseline financial failures with 36 added cases and no errors or skips. This updates the local financial gate; deployed runtime, PostgreSQL concurrency and overall role/project ratings retain their separate verification limits.

Scoped native Rider backend review: October 9, 2026. All five core API batches have 25 implemented methods, a validated contract, a green 2,834-case regression and four actual disposable PostgreSQL claim/outcome races. Publication, legacy-proof release review, Azure rollout and Flutter/device acceptance remain separate. See the native review below; existing overall role ratings are unchanged.

Scoped buyer/seller BS01-BS02 review: October 9, 2026. Completed-purchase reviews, persisted seller replies and verified rating summaries now join grouped, paginated owned-order workspaces. The final 521-case commerce regression, 63 frontend checks and production build pass. The complete 2,904-case run had one obsolete pagination assertion, corrected and verified in the subsequent retests below. These scoped improvements do not change the overall role scores or start BS03-BS05.

- **Implemented:** active code and focused tests cover the required baseline behavior.
- **Partial:** a usable foundation exists, but at least one required invariant or persistence record is missing.
- **Missing:** the required baseline behavior is not represented by enforceable application logic or persistence.
- **Deferred:** intentionally outside the core baseline.

| Area | State | Evidence and gap |
|---|---|---|
| Account roles and approval | Partial | Shared root/subdomain gates enforce fixed roles, approval, and activity. KYC retains private evidence and immutable decisions. B01-B05 add category/application rules, independent shop review, resource eligibility, and buyer access alignment. B06+B07 add separate reasoned account/resource activity decisions, current-parent checks, last-admin continuity, and retained affected-work responsibility. B08+B09 add evidence-backed reviewed corrections, guarded closure, retained identity/evidence, and blocked unresolved work/cash. B10-B12 add product compliance decisions, account context, scoped history and recorded overview data. The Phase 0 acceptance gate remains. See the scoped implementation reviews below. |
| Cross-cutting input and mutation safety | Partial | B02 shares canonical application rules. The supplemental commerce repair extends the applicable rules to checkout and saved addresses, including direct services, persisted checkout retries and transactional address/default changes. The profile/Shopping Bag repair extends canonical contact/name/email rules, current generic-profile eligibility and strict quantity parsing. Other operational mutations and retained custody sources still require their applicable validation, stale-state and history gates. |
| Alternate lifecycle entry points | Implemented | Simulator advance/reset routes, public tracking actions, and direct Platform Admin parcel overrides are removed on root and applicable subdomain routes. Guest and all-role tests verify repeated requests cannot change assignment, custody, checkpoints, payment, or commission records. |
| Secret and KYC protection | Implemented | Registration, resubmission, and buyer ID uploads use private storage. Document access requires the applicant or an active Platform Admin; raw paths are hidden from serialized data. Legacy public URLs are blocked, with a tested migration command for stored files and references. OTP, verification-link, and password-reset mail reject logging transports and logging fallbacks; failures log only safe identifiers and exception classes. Deployment must apply the web-server rules and legacy-file migration described in `VERIFICATION_DOCUMENT_SECURITY.md`. |
| Multi-shop checkout | Implemented | Checkout transactionally creates an independent order, delivery, waybill, shipping fee and route per shop, plus any requested saved address and the retained submission result. Failures roll back every selected shop. A buyer/Bag-scoped confirmation returns the original orders on identical retries after Bag consumption; changed or foreign confirmations reject. |
| Voucher allocation | Implemented | Shop vouchers are isolated to their owning shop, while platform discounts are proportionally divided without exceeding the calculated discount. |
| Seller fulfillment | Implemented | A central lifecycle service enforces `PLACED -> CONFIRMED -> PREPARING -> READY_FOR_PICKUP`, shop ownership, and the pre-custody cancellation boundary. |
| Purchase reviews and seller replies | Implemented; locally verified | BS01 binds one review to an owned completed order item, preserves unchanged retries and legacy content, persists one owned seller reply, and derives public averages/counts from verified purchases. The October 9 commerce review records migration, privacy, rollback and interface evidence, with deployment and PostgreSQL limits. |
| Buyer and seller order workspaces | Implemented; locally verified | BS02 groups seller items under original orders, paginates owned history, separates delivery/completion and exceptions, and exposes existing-authority action flags. Seller batches commit together; cancellation validates current reasons and retains exactly-once stock/custody rules. Mixed-shop legacy history is read-only. See the October 9 commerce review below. |
| Pickup rider handoff | Implemented; scoped verified | Actual waybill matching and retained root custody checkpoints now prove pickup and original-hub receipt. Claims retain order/parcel locks, current approval/company/hub scope and the seller cancellation boundary. Narrow restricted-courier handover is verified separately below; it does not open routine work. |
| Facility routing | Implemented | Checkout requires a complete origin Bayan Hub, Mother Hub and destination Bayan Hub route and rejects incomplete routing. Destination province/city and configured barangay coverage must agree; coordinates, repeated barangay names and partial city names cannot override coverage. A selected self-pickup counter must serve the stated destination. |
| Manifest custody | Implemented; scoped verified | Durable manifests, retained membership/events, actual scans, sealed departure, partial destination receipt and supported discrepancy/closure controls are verified below. True extra/wrong-hub physical recovery and later exception paths remain open. |
| Scanned hub custody | Implemented | Waybill scans use separate inspect and confirm steps, enforce expected status, owned facility/company scope, route order, and idempotent duplicate confirmation. |
| Destination sort and rider assignment | Implemented | Destination sorting and final-mile assignment enforce parcel state, destination facility, logistics-company scope, and assigned-rider ownership. |
| Failed delivery and RTS | Implemented; scoped verified | Numbered attempts, private original proof, actual destination-hub returns, reviewed retries, the three-attempt loop, frozen reverse manifests, origin staging and owning-seller receipt are verified below. Third failure/refusal cannot become RETURNED through an admin status edit. Unsupported restricted resource/carrier recovery remains open. |
| Hub self-pickup | Implemented; scoped verified | Actual destination receipt and configured counter hours precede a hashed one-time buyer claim, identity/waybill verification and source-backed exact COD collection. Seven-calendar-day holding, day-three/day-six notices, lock/retry behavior and actual expiry-return initiation are verified below. Codes remain private; only the owning buyer completes a genuine collection. |
| Persistent notifications | Implemented; scoped verified | A durable event/recipient outbox delivers real order, custody, failure, pickup, return and governance notices into one owned paginated center. Read acknowledgement and current linked authorization are checked independently. B16 adds cash collection, handover, review and reconciliation notices; B17 adds release, payment and linked receipt-correction notices with retained recipient/source keys. The October 7 B15 and October 9 finance reviews record the relevant acceptance evidence. |
| Native Rider operational API | Implemented; locally verified | Shared Home/claims, parcel commands, retained Trips/private proof, phase-linked messages, durable notices and journal-backed cash offers have 25 native methods. The October 9 native review records regression, contract and disposable PostgreSQL races; earnings/restricted recovery, rollout and mobile acceptance remain separately bounded. |
| COD reconciliation | Implemented; scoped verified | B16 records exact rider/counter collection, confirmed rider-to-hub and hub-to-platform handovers, preserved differences and linked reviews, and independent Platform Admin reconciliation. Original sources remain immutable; buyer completion and seller settlement stay separate. Legacy evidence and PostgreSQL execution limits are recorded in the October 8 B16 review. |
| Seller settlement and financial oversight | Implemented; locally verified | B17 requires actual buyer completion, reconciled cash, the original eligible recipient and private payment evidence. B18 reads distinct cash/proceeds stages, exact cents and original source dates with current admin/company/seller scope. The October 9 review records the green full suite, legacy-unavailable behavior, manual-payment boundary and remaining deployment/PostgreSQL limits. |
| Buyer-only completion | Implemented | Only the owning buyer advances genuine doorstep delivery or source-backed counter collection to COMPLETED. A legacy collected label lacks authority. Completion remains separate from pending COD reconciliation and settlement. |
| Admin governance and audit | Partial | KYC, shops, fixed-role/resource eligibility, reasoned restrictions, reviewed corrections/closure, product moderation and scoped retained history are implemented. B14 links actual restriction, attempt, manifest and counter sources to retained follow-up responsibility and evidence-based resolution. Platform cross-company and Company Admin own-company scope are verified; physical scans and buyer completion remain actor-owned. B15-B18's local notice and financial gates have evidence below. Broader Phase 0, portal cleanup and deployed acceptance retain their separate boundaries. |
| Cross-role presentation | Partial | Courier work is separated into company/hub-scoped pickup and final-mile queues with persistent duty state, delivery-linked messages, real profile data, real proof, and truthful trip history. Other portals still need canonical-status and unfinished-feature cleanup. |
| Normal cross-role delivery | Implemented | A focused test covers checkout, seller fulfillment, two separately scoped riders, origin/Mother/destination hub custody, proof of delivery, and buyer completion. |

## Quality Baseline and Target

These scores measure the approved core flow, not deferred enterprise features. Documentation changes do not raise the current implementation score until the corresponding controls are implemented and tested.

| Role or flow | Current implementation | Target after required phases | Main improvement required |
|---|---:|---:|---|
| Buyer | 7/10 | 10/10 | Private tracking, secure self-pickup, notifications, and COD-aware completion/settlement visibility |
| Seller | 8/10 | 10/10 | RTS receipt, settlement states, archival history, and notifications |
| Pickup Rider | 9/10 | 10/10 | Claim release/recovery, complete handoff evidence, and durable notifications |
| Delivery Rider | 7.5/10 | 10/10 | Attempt records, hub-return custody, retry/RTS, and COD remittance |
| Hub Handler | 7/10 | 10/10 | Manifest lifecycle, discrepancy handling, and secure counter release |
| Logistics Company Admin | 6/10 | 10/10 | Tenant-safe personnel/fleet controls, manifest supervision, exceptions, and remittance reconciliation |
| Platform Admin | 5/10 | 10/10 | Phase 0 acceptance, operational exception oversight, governance notifications, COD audit and source-backed financial oversight |
| End-to-end cross-role flow | 7.5/10 | 10/10 | Normal delivery is enforced and tested; recovery, self-pickup, COD reconciliation, and settlement remain |

Before this validation audit, the documents described the happy path well but left malformed input, duplicate requests, concurrency, alternate endpoints, privacy, and recovery behavior open to interpretation. `CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` closes those design gaps; the phases below close the implementation gaps.

### Rider Lockdown Comparison: October 3, 2026

These review scores compare the earlier rider audit with the scoped implementation below. They are engineering assessments, not test coverage percentages. Scores stay unchanged when the underlying workflow was not completed in this branch.

| Rider area | Before | After | Evidence or remaining gap |
|---|---:|---:|---|
| Pickup claims and job board | 8/10 | 8/10 | Scoped claims remain enforced; order locking now serializes claims with seller cancellation. Claim release and recovery remain. |
| Pickup custody and hub handoff | 7/10 | 7/10 | Pickup evidence is atomic; submitted barcode matching and complete handoff evidence remain. |
| Final-mile assignment | 8/10 | 8/10 | Existing company, destination-hub, barangay, availability, and active-work checks remain. |
| Successful delivery | 6/10 | 7/10 | Shared transitions require a stored proof file and the canonical commercial source state; retries preserve proof, notes, timestamps, and checkpoint counts. Recipient relationship evidence remains. |
| Failed delivery, retry, and RTS | 2/10 | 2/10 | Rider failure submission, attempt records, retry scheduling, hub-return custody, and seller return receipt remain incomplete. |
| COD remittance and earnings | 2/10 | 2/10 | Delivery still cannot settle COD or commission; collection and remittance ledgers remain absent. |
| Messages and notifications | 6/10 | 6/10 | Pickup messages share the custody transaction; persistent lifecycle notifications remain missing. |
| Profile and rider UI compliance | 6/10 | 6/10 | Public tracking was rebuilt; this branch does not complete the rider-profile or rider-portal style review. |
| Enforcement across lifecycle entry points | 3/10 | 8/10 | Alternate mutations are removed; both courier portals require an active approved courier. Terminal or mismatched commercial states cannot resume custody. Wider Phase 0 gaps and PostgreSQL concurrency verification remain. |

Public tracking accepts tracking codes only, enforces a shared web/API limit of 30 requests per minute per trusted client IP, and returns the same masked parcel data to every role. Untrusted forwarded headers cannot create fresh limiter budgets; only explicitly configured proxy addresses or CIDRs may supply client information. The public contract excludes database IDs, order numbers, line items, prices, payment details, actor identities, arbitrary checkpoint notes, exact addresses, and proof URLs. The page shows recorded checkpoints, canonical commercial status, nullable stored estimates, and links into authorized portals; it exposes no custody actions or predicted timeline events.

Simulator mutations are unavailable in every environment. Platform parcel corrections are unavailable until an audited workflow exists. Existing parcels with legacy or inconsistent order/delivery states cannot bypass the canonical gates; they need an authorized reconciliation workflow rather than a direct status edit.

Verification used isolated SQLite `:memory:` throughout:

- The 229-test courier/logistics/flow/tracking/entry-point integration set passed with 1,872 assertions. Four canonical delivery-boundary checks passed separately; the final 77-test public tracking set also passed, including a real buyer-completion milestone. These runs cover 234 distinct focused tests.
- The production TypeScript/Vite build passed in Docker. Browser UI testing was not performed.
- The full suite ran 881 tests and 4,248 assertions: 58 failures and one error remain. The pre-change baseline had 61 failures and one error; failure-ID comparison found no new failures or errors. Existing checkout fixtures, legacy route/state/settlement expectations, and the null-order error in `ChallengerM1StressTest::test_standard_product_without_variants` still prevent a green full suite.
- SQLite verifies stale submissions, duplicate results, and transaction rollback, but does not prove PostgreSQL row-lock behavior under simultaneous requests. Production/development PostgreSQL was not used for tests.

### Push-Readiness Review: October 3, 2026

The two related fixes are completed on `fix/rider-lifecycle-lockdown`:

| Priority | Fix | Confirmed evidence and acceptance |
|---|---|---|
| P1 | Harden trusted proxy configuration for public tracking throttling | `config/trustedproxy.php` defaults to an explicit empty allowlist and rejects wildcard, invalid (including a standalone zero), and zero-prefix entries. Configuration tests also cover IPv4/IPv6 addresses and CIDRs. HTTP tests verify rotating untrusted headers cannot evade the shared 30-request web/API budget, configured proxy chains preserve client budgets and HTTPS, and untrusted peers cannot spoof a trusted proxy or scheme. Raw forwarded-proto scheme forcing is removed. |
| P2 | Use one approval policy across courier entry points | `User::isEligibleCourier()` and its query scope require an active courier with reviewed `approved` or legacy `verified` KYC. Portal access, claims, messaging, courier custody, rider selection, and assignment share this policy. Tests cover both portal URLs, pickup collection and final-mile delivery while off duty, unchanged account approval, and rejection of inactive, suspended, unapproved, wrong-role, and wrong-hub riders. Approval authority and other roles' custody policy are unchanged. |

Deployment configuration: set `TRUSTED_PROXIES` to the actual proxy IP addresses or narrow CIDRs for the deployed chain, then rebuild the configuration cache. Leave it empty when requests reach the application directly. Do not use `*`, `REMOTE_ADDR`, or all-address CIDRs. Production still forces HTTPS; local forwarded HTTPS is honored only through trusted proxies.

Later branches should retain the phase order:

- Finish Phase 0: shared input validators, approval/suspension audit, and removal of live sample-success paths. Private verification files and secret-mail protections are implemented in the review below; deployment must apply legacy-file migration and non-logging mail configuration.
- Rider evidence and Phase 2 custody: require submitted waybill matching, record recipient name/relationship, and add durable manifest dispatch/receive records. Current pickup checkpoints fill the stored tracking number rather than validating a submitted scan.
- Phase 3: implement audited claim recovery, failed attempts, hub return, retry/RTS, and secure self-pickup. Counter release currently creates `delivery.status = customer_collected` and `order.status = delivered`, but `OrderLifecycleService::buyerComplete()` accepts only a parcel status of `delivered`; a separate SQLite service probe confirmed that buyer confirmation is blocked after counter collection. Secure code, identity, and COD evidence remain required before counter release is complete.
- Phase 4 and 5: persistent lifecycle notifications, append-only COD collection/remittance, reconciliation, seller settlement, and audited admin corrections.

Follow-up verification, rechecked after the proxy configuration correction: 279 focused tests passed with 2,263 assertions using isolated SQLite `:memory:`. The full suite ran 918 tests with 4,579 assertions and retained exactly the previous 58 failure IDs and one error; no new failures or errors appeared. Changed PHP files passed formatting checks, and the diff passed whitespace checks. No frontend code changed in this follow-up; the earlier Docker build remains the frontend evidence. PostgreSQL concurrency and deployment proxy configuration still require environment-specific verification. The counter-completion gap remains later Phase 3 work. This review does not declare the application ready for production.

### Verification Document Security Review: October 3, 2026

Scoped work on `fix/private-verification-documents` follows the Phase 0 security contract. It does not implement later rider handoff, failed-attempt, manifest, notification, or COD workflows.

- Registration, buyer ID uploads, and role resubmission store reviewed file types on the private local disk. Unvalidated role-specific attachments are not stored. Storage failures return a field error and leave account approval unchanged.
- Authenticated document routes allow the applicant (including pending/rejected applicants) and active Platform Admin reviewers. Wrong users, other roles, inactive reviewers, unsafe paths, missing files, and public-storage fallbacks are denied. Responses use private no-store caching and explicit allowlisted content types.
- Generic user/company serialization hides stored file paths. Owner pages and the review queue receive protected links. Logistics applicants can resubmit permits/franchise certificates, and reviewers can inspect accreditation documents.
- Nginx and Apache rules block the legacy public document namespace. `verification:privatize` offers a dry run, checksums private copies, retires public originals, updates references without altering approval, preserves unreferenced evidence privately, and supports retries. Missing or conflicting files fail instead of overwriting evidence. Real deployment data was not migrated during this implementation.
- Secret-bearing mail refuses log transports, logging fallbacks, cyclic configuration, and non-delivery array transports outside testing. OTP failures retain no newly generated code; failed password-reset delivery removes its newly issued token. Previously delivered tokens survive a rejected transport. Registration preserves the account and reports an undelivered verification email accurately. Secret values are excluded from old-input flash and generic OTP serialization.

These scores are scoped engineering assessments of implemented controls:

| Area | Before | After | Meaning |
|---|---:|---:|---|
| Verification document privacy and reviewer access | 2/10 | 8/10 | Public uploads are replaced by private storage and tested authorization; actual deployment migration/configuration remains an operator gate. |
| Secret-mail failure and logging protection | 6/10 | 8/10 | Existing OTP failure safety is preserved and expanded to logging transports, verification/reset links, secret flash, and token cleanup. |
| Rider lifecycle enforcement | 8/10 | 8/10 | Existing custody guards remain; this branch protects rider identity/license/vehicle documents rather than completing new custody states. |
| Complete rider operations | About 6/10 | About 6/10 | Pickup and normal delivery remain usable, while recovery, handoff evidence, notifications, and COD persistence still limit readiness. |

Verification: 419 focused tests passed with 2,967 assertions using isolated SQLite `:memory:`. The Docker TypeScript/Vite build passed. Nginx syntax passed, and a synthetic existing legacy file returned 404. The full suite ran 949 tests with 4,809 assertions and retained the same 58 failures and one error as the 918-test baseline; failure-ID comparison found no additions. PostgreSQL concurrency, an Apache runtime, browser UI checks, and real deployment migration were not tested.

Deployment and recovery steps are in `VERIFICATION_DOCUMENT_SECURITY.md`. Phase 0 remains partial until the other required controls pass their acceptance paths.

### Rider Mobile Interface Review: October 3, 2026

**State: Scoped implementation verified on `frontend/rider-mobile-ux`.** The rider portal applies the requested soft, rounded direction: 24px working cards, 12–16px controls, short finite entrance/press motion, warm surfaces, and reduced-motion support. `STYLE_GUIDE.md` and `RIDER_UI_DESIGN.md` describe the presentation contract. Other portals retain their existing presentation until separately scoped.

- The shared layout uses natural page scrolling, actual company/hub context, one duty control, labelled navigation, safe-area spacing, and an absolute account menu with the existing pointer grace period. Task cards put the stop, parcel, cash due, and permitted action together. Counts reflect returned queues; selecting a map job does not reorder them.
- The dashboard has an interactive selected-stop map with keyboard pan and 48px zoom/recenter controls. Seller pickup uses the authorized seller address; collected pickup uses the origin hub, final-mile collection uses the destination hub, and delivery uses the immutable checkout buyer destination. Hub and saved buyer coordinates are supplied only through authorized payloads. Missing or unprojectable pins use address-based directions; tile failures preserve the stop/address and offer retry. Available work is explicitly a preview. There is no live GPS, ETA, route optimization, default city, or fabricated rider position.
- Proof submission keeps notes and the chosen image after rejection, supports preview/replace/remove, blocks repeats, and closes only on a confirmed server result. Off-duty riders can finish existing custody work. Buyer confirmation, COD remittance, and settlement remain separate backend steps.
- Profile, contact, and password forms show actual account data, reviewed approval, and clearly missing managed vehicle/assignment fields. Settings mutations work on root and courier-subdomain portals. Generic profile access redirects couriers to their portal; self-service account deletion is blocked until controlled closure and handover exist.
- Completed trips describe the current company/destination-hub final-mile scope, with working search, payment filters, pagination, and confirmed clipboard results. Daily counts use the Philippine calendar day, matching displayed timestamps. No lifetime, perfect-performance, payout, or credential claims are invented.
- Phone messages use a conversation-list/detail flow, labelled send controls, per-thread drafts, and explicit errors. Inbox reads no longer mark every thread read. Authorized acknowledgement stops at the displayed message boundary and excludes unopened threads and new arrivals. Sending includes the selected phase, so a stale seller draft cannot be rerouted to the buyer after a phase change.

These scores compare the previous rider presentation with this scoped implementation. They are engineering assessments, not coverage percentages or results from rider usability research.

| Area | Before | After | User-visible improvement and limit |
|---|---:|---:|---|
| Rider task presentation and phone navigation | 6/10 | 8/10 | Softer surfaces, larger labelled controls, relevant stop/action, safe-area spacing, and recoverable proof errors; rendered phone/keyboard usability still needs user review. |
| Directions and selected-job context | 4/10 | 8/10 | Stage-specific saved destinations, selectable street map, and honest address/tiles fallbacks; seller pins, live GPS, optimized routes, and ETA are not implemented. |
| Profile and settings | 6/10 | 8/10 | Truthful data, consistent working forms on both portals, and protected account closure; a managed closure workflow remains. |
| Messages | 6/10 | 8/10 | Phone list/detail flow, retained drafts, selected-thread read acknowledgement, and stale-phase rejection; refresh is manual and persistent lifecycle notifications remain absent. |
| Rider lifecycle enforcement | 8/10 | 8/10 | Existing approval, custody, commercial, and payment rules are preserved; this presentation work does not complete later operational phases. |
| Complete rider operations | About 6/10 | About 6/10 | Handoff evidence, claim recovery, failed attempts/returns, notifications, and COD ledgers remain in their existing phases. |

Verification:

- 93 focused courier/flow/password/profile tests passed with 1,107 assertions using isolated SQLite `:memory:`. They include both portal contexts, approval rejection, immutable destinations, unauthorized and phase-specific message access, stale draft rejection, Philippine-day boundaries, and account-closure protection.
- 16 frontend helper and server-render checks passed via `npm run test:courier`. They verify stop/action states, invalid and missing pins, map selection after refresh/removal, available-job preview labels, exact COD amounts, honest history/profile states, and selected message phase. The production TypeScript/Vite build passed in Docker; map code is loaded lazily.
- The full suite ran 971 tests and 5,182 assertions, retaining exactly the baseline's 58 failures and 1 error. Failure-ID comparison against the 949-test private-document baseline found no new or resolved failures/errors. Existing fixtures, legacy expectations, and the null-order stress-test error still prevent a green full suite.
- Changed PHP files passed formatting and the diff passed whitespace checks. Static inspection covered responsive classes, touch sizing, focus/keyboard paths, map cleanup/resize, finite motion, and reduced-motion handling. Browser automation, screenshots, physical-device checks, actual tile-service availability, and PostgreSQL concurrency were not tested.
- Full application route caching still encounters the pre-existing duplicate seller route name `shops.switch`; scoped courier named-route compilation and HTTP portal checks pass. This branch does not repair unrelated seller routing.
- Dependency audit reports seven existing findings (six high, one moderate) in Axios, qs, and the Tailwind dependency chain. Their locked versions are unchanged; the newly added Leaflet runtime/types have no audit findings. Dependency remediation remains separate, including evaluation of the suggested Tailwind major upgrade.

The scoped branch is ready for review and a user-managed GitHub push; the final regression comparison confirms no new failing tests. Existing suite failures, dependency findings, and unperformed rendered/device checks prevent declaring the complete application bug-free or ready for production.

### Rider Dashboard Reference Review: October 4, 2026

**State: Scoped frontend implementation on `frontend/rider-mobile-ux`.** The two supplied screenshots informed the dashboard composition directly. The rider workspace now has a slim white sidebar, a blush canvas, lighter headings, an actual-name greeting and initials, a Philippine-date label, and four compact work counters. The shared shell also presents the existing Trips, Messages, and Profile pages consistently.

- One current-parcel panel combines the authorized leg's two stops, actual custody stage, contact/directions controls, permitted next action, and saved-stop map. Pickup shows seller to origin Bayan Hub; final-mile work shows destination Bayan Hub to the saved buyer destination. A parcel selector preserves queue order, and search still filters tracking numbers, names, and addresses. Missing pins and tile errors retain usable address information.
- The lower panels show recent delivery activity, actual pickup capacity and awaiting-intake counts, and a responsive recent-delivery table. The activity period switches between seven and fourteen Philippine calendar days. The chart explicitly covers only the latest ten returned records; the table shows up to five with a link to the existing scoped trip history. Server-provided daily counts are independent of this limited record sample. Unavailable limits and amounts remain visibly missing.
- Duty control, pickup claims, collection/start/record actions, delivery proof validation and recovery, phase-specific messaging, and buyer-only order completion retain their existing backend contracts. The map uses grayscale street tiles and an original red saved-stop pin; it does not assert live location, a calculated route, or arrival time. Financial rewards and rider ratings are not fabricated from the reference.

| Area | Before | After | User-visible improvement and limit |
|---|---:|---:|---|
| Rider dashboard presentation | 8/10 | 8.5/10 | A clearer overview and one combined stop/action/map panel replace the separate task/map card grid. Recent activity and capacity remain visible below the current job; rendered phone and desktop review remains outstanding. |
| Rider lifecycle enforcement | 8/10 | 8/10 | Existing custody, authorization, proof, commercial, and payment rules remain unchanged. |
| Complete rider operations | About 6/10 | About 6/10 | Claim recovery, stronger handoff evidence, failed attempts/returns, notifications, and COD reconciliation remain in their existing phases. |

Verification: 21 frontend helper/server-render checks passed, including Philippine-midnight activity grouping, period changes, invalid/future records, actual-name greeting, server counters, capacity, queue order, correct phase destinations/contact permissions, available-job restrictions, and missing/exact COD amounts. All 82 courier feature tests passed with 969 assertions using isolated SQLite `:memory:`. The final TypeScript/Vite production build passed in Docker. Static review covered responsive stacking, visible borders, accessible labels, 48px controls, reduced motion, map sizing/cleanup, and safe-area navigation.

This is a scoped presentation assessment, not a full operational or rendered-device audit. Browser automation, physical-device review, actual map-tile availability, and PostgreSQL concurrency were not tested. The October 3 full-suite, seller route-cache, and dependency limitations above remain recorded; this frontend refresh does not resolve or re-audit those separate gaps.

### Rider Radius and Duty-Control Polish: October 4, 2026

**State: Scoped frontend follow-up verified on `frontend/rider-mobile-ux`.** The supplied dashboard screenshot confirmed a missing white knob in the header duty control. Its unanchored, translated span is replaced by an explicit-size SVG track and white thumb whose geometry remains inside the track in both saved duty states. The switch exposes its checked and busy state, keeps the existing off-duty confirmation, and blocks interaction during a pending update.

The earlier radius balance used 20px primary cards, 12px buttons/inputs/navigation/date and period controls, 24px dialogs, and 16px inset panels/maps/message bubbles. Metric hover surfaces matched their parent card corners. Short badges, avatars, and icon controls retained their appropriate circular shapes. The shared primitives covered Dashboard, Trips, Messages, and Profile without changing global theme radii or other portals. The subsequent rider corner refinement below supersedes this scale.

| Area | Before | After | User-visible improvement and limit |
|---|---:|---:|---|
| Rider presentation and header controls | 8.5/10 | 8.7/10 | More consistent corner proportions and a legible on/off switch with a bounded white knob; the final rendered/device review remains with the user. |
| Rider lifecycle enforcement | 8/10 | 8/10 | Existing duty persistence, custody, proof, and payment rules are unchanged. |
| Complete rider operations | About 6/10 | About 6/10 | Recovery, stronger handoff evidence, failed attempts/returns, notifications, and COD reconciliation remain open. |

Verification: all 23 frontend helper/server-render checks and the TypeScript/Vite production build passed. New regression checks verify both duty states, white-thumb containment, disabled busy behavior, and integration into the rider layout. All 82 courier feature tests had already passed today with 969 assertions using SQLite `:memory:`; no backend code changed in this follow-up. The diff passed whitespace checks. No browser automation or new screenshots were used.

Branch review: October 3 commits provide the rider mobile screens, saved-stop maps, proof recovery, profile/account safeguards, and selected-thread message acknowledgement. October 4 adds the supplied-reference dashboard and this polish. Read-only remote inspection confirmed `main` still matches the branch base. The branch is recommended for a user-managed push and pull-request review against `main`; the previously recorded full-suite failures, dependency findings, seller route-cache issue, and unperformed device checks remain separate limitations.

### Rider Corner Refinement: October 4, 2026

**State: Scoped frontend follow-up verified on `fix/rider-corner-radius`, created from the updated `main`.** The initial 4px adjustment was refined to 8px after the user requested slightly softer corners. Rider cards, dialogs, buttons, inputs, navigation, badges, maps, notices, message bubbles, and profile verification surfaces use explicit 8px corners across Dashboard, Trips, Messages, and Profile. The supplied empty-state screenshot also identified a decorative six-degree tile rotation: both the tile rotation and the icon's counter-rotation have been removed, keeping the shared empty-state tile and icon upright. Metric hover surfaces match their parent cards. Avatars, status dots, timeline markers, progress tracks, and duty-switch shapes retain their functional round shapes.

Rider presentation assessment: **8.7/10 before -> 8.7/10 after**. The slightly softer box edges and upright empty-state tile address the user's screenshot feedback; this cosmetic change does not increase lifecycle or operational readiness. The final rendered/device review remains outstanding. `AGENTS.md` records the revised preference for subsequent rider work.

Verification: all 23 existing frontend helper/server-render checks and the TypeScript/Vite production build passed again after the 8px refinement. Static inspection confirmed only corner and rotation changes in the frontend diff, no remaining decorative rotation in rider surfaces, and the remaining circular classes serve the shapes listed above. The diff passed whitespace checks. The shared email-verification component changes only its comfortable variant, which is currently used by the rider profile. Global theme radii, other portals, and backend behavior are unchanged. No browser automation or new screenshots were used.

### Rider Dashboard Surface and Navigation Polish: October 4, 2026

**State: Scoped frontend follow-up verified on `fix/rider-corner-radius`.** The dashboard canvas is now an almost-white blush (`#FFFAFB`). Work counters, current-parcel and overview cards, the dispatch card, and map surfaces use soft shadows and transparent outlines. Inset notices and icon backgrounds are quieter, while form boundaries, focus outlines, status indicators, and light table separators remain readable. The latest 8px corners and upright icons are retained.

The shared rider shell now uses one persistent hamburger in the header; the duplicate inside the desktop sidebar was removed after screenshot feedback. The desktop sidebar is widened from 240px to 280px and glides in/out over 260ms while the content margin moves with it. Collapsed navigation is hidden from assistive technology, made inert, and cannot intercept pointer interaction. The toggle stays mounted and focused in the header. The desktop preference is stored locally with a fallback when storage is unavailable; restoring it initially does not animate.

Phones use the existing dialog library for a wider drawer capped to the viewport, a slide transition, a fading backdrop, a close button, outside-click/Escape dismissal, focus management, and scroll locking. Selecting a drawer link closes it, and switching to desktop closes the mobile drawer. Reduced-motion settings disable the sidebar, content, drawer, and backdrop transitions. The existing bottom navigation, account menu, refresh, and duty controls remain available.

Scoped dashboard presentation/navigation assessment: **8.7/10 before -> 8.8/10 after**. The quieter canvas and card edges reduce visual clutter, and the sidebar toggle gives more space to parcel information. Complete rider operations remain **about 6/10** because this work does not implement recovery, handoff evidence, notifications, or COD reconciliation. Trips, Messages, and Profile retain their page content and surface treatment; only the shared navigation changes apply to them.

Follow-up sidebar correction assessment: **8.8/10 before -> 8.8/10 after**. One toggle removes the duplicate control, and the wider animated sidebar addresses the user's screenshot feedback within the same scoped presentation assessment. Rendered/device review remains outstanding.

Verification: all 25 frontend helper/server-render checks passed after the correction. They include sidebar expanded state, controlled targets, mobile dialog semantics, reopening callbacks, and a full-dashboard regression requiring exactly one hamburger outside the sliding sidebar while retaining all four navigation destinations. The TypeScript/Vite production build and whitespace checks passed. No backend code changed. Static review covered responsive visibility, synchronized width/motion, inert collapsed navigation, reduced motion, initial preference restoration, dashboard-only surface selectors, storage fallback, and reuse of the existing navigation paths and dialog controls. Rendered/device interaction review remains with the user; no browser automation or new screenshots were used.

### Rider Trips, Messages, and Profile Redesign: October 4, 2026

**State: Scoped rider interface follow-up verified on `fix/rider-corner-radius`.** All four rider pages now use the dashboard's almost-white blush canvas, soft card shadows, and quiet outlines. Search fields, the parcel selector, and the seven/fourteen-day activity selector use light boundaries with distinct hover and brand focus states. Explicit 8px corners, the single header hamburger, the 280px sidebar, and reduced-motion glide behavior are retained. Other portals and global theme settings are unchanged.

- Trips presents the actual assignment-scoped totals and destination hub above a quieter history toolbar and delivery cards. Search, payment filters, ten-record pagination, tracking copy, recorded details, missing values, and the distinction between delivery evidence and buyer receipt/remittance/payout remain intact.
- Messages occupies the available viewport directly below the global header. Its page heading, subtitle, date chip, footer, and enclosing floating card are removed. Contacts and conversation history scroll independently; the labelled composer sits outside the history scroll area. Mobile list/back navigation, unread counts, per-thread drafts, selected-phase sending, read acknowledgement/retry, and read-only assignments retain their existing behavior. Stored participant avatars are returned only within the existing authorized conversations, with initials and image-failure fallbacks. Visual-viewport resize handling and a compact mobile header/composer make room for the keyboard; short mobile viewports temporarily hide bottom navigation while the header sidebar toggle remains available. Refresh remains manual.
- Profile has an original parcel cover illustration, the rider's stored avatar or initials, actual account ID and identity summary, and separate Information, Edit information, Privacy and security, Assignment, and Vehicle sections. Name/mobile editing, verification-email requests, verified-email password changes, busy guards, validation feedback, and logistics-managed read-only records use the existing actions. Hidden sections retain form drafts while switching. The cover and avatar are display surfaces; photo-upload, fabricated ID credentials, and unsupported privacy settings are not introduced.

| Area | Before | After | User-visible improvement and remaining limit |
|---|---:|---:|---|
| Trips presentation | 8/10 | 8.5/10 | Quieter history cards, clearer scoped totals, and less prominent filter boundaries; pickup and previous-assignment history remain outside the current data contract. |
| Messages presentation and navigation | 8/10 | 8.5/10 | Integrated chat, contact identities, independent scrolling, and a composer that stays outside the history; manual refresh and device/keyboard review remain. |
| Profile presentation and navigation | 8/10 | 8.5/10 | Cover and identity summary with dedicated sections for existing editable and managed fields; rendered/device review remains. |
| Dashboard presentation | 8.8/10 | 8.8/10 | Search and dropdown boundaries now match the quieter dashboard without changing its functions. |
| Complete rider operations | About 6/10 | About 6/10 | Recovery, stronger handoff evidence, failed attempts/returns, notifications, and COD reconciliation remain in their existing phases. |

Verification: all 31 frontend helper/server-render checks passed, including the integrated chat/composer structure, selected-phase deep links, avatar/initials displays, read-only/empty conversations, profile identity/section targets, verified and unverified password controls, managed records, and bounded trip pagination. All 82 courier feature tests passed with 971 assertions using isolated SQLite `:memory:`, including the authorized participant-avatar payload and existing messaging, account, approval, custody, and duty protections. The final TypeScript/Vite production build and whitespace checks passed. Source and compiled-style inspection covered the light input boundary, full-height flex layout, mobile navigation clearance, short-viewport behavior, resize cleanup, and reduced motion.

These are scoped implementation assessments. No browser automation, new screenshots, physical-device keyboard checks, or PostgreSQL concurrency tests were performed. The previously recorded full-suite, seller route-cache, dependency, and operational limitations remain separate work.

### Rider Tablet Navigation and Chat Divider: October 4, 2026

**State: Scoped responsive follow-up verified on `frontend/rider-portal-polish`.** The local branch was renamed from `fix/rider-corner-radius` to reflect today's dashboard, sidebar, Trips, Messages, and Profile work.

Desktop navigation now begins at 1280px. Smaller widths, including portrait and typical landscape tablets, use the existing mobile drawer and bottom navigation with no reserved sidebar margin. Header navigation, page-bottom clearance, chat viewport spacing, and short-viewport handling follow the same threshold. The shell and Messages share one JavaScript media query, so tablet conversations use the list/Back flow and hidden threads are not treated as visible for read acknowledgement. Drafts, selected conversations, desktop collapse preference, drawer dismissal, and reduced-motion transitions retain their existing behavior.

Side-by-side desktop contacts and chat now have one slightly clearer 1px divider using slate at 60% opacity. Tablet and phone conversations occupy one pane, keeping the composer outside the scrolling history.

Scoped chat/tablet layout assessment: **8.5/10 before -> 8.6/10 after**. The divider clarifies the two desktop areas, and removing the fixed sidebar gives tablet screens their full content width. Complete rider operations remain **about 6/10**; this change does not address the previously recorded operational gaps.

Verification: all 31 frontend helper/server-render checks and the TypeScript/Vite production build passed. Source and compiled-CSS inspection checked 375, 768, 820, 1024, 1180, 1279, 1280, and 1440px widths for sidebar visibility, bottom navigation, reserved margin, desktop chat-pane visibility, divider width/opacity, and shared media-query alignment. Whitespace checks passed. No backend code changed. These are automated and static checks; rendered tablet, orientation, and keyboard interaction review remains outstanding, and no browser automation or new screenshots were used.

### Admin Governance Documentation and Phase 0 Review: October 4, 2026

**State: Documentation aligned; runtime gaps remain.** `ADMIN_FLOW.md` now defines approval authority/evidence, review and activity as separate checks, transactional audit/idempotency, suspension recovery, company/facility limits, financial display rules, and acceptance scenarios. Buyer, seller, courier, logistics, system, and validation contracts use the same decisions. Company placement applies to an already platform-approved courier and cannot grant KYC or reverse suspension. Existing `verified` courier compatibility and the five account roles are preserved.

The validation contract now makes the suspended buyer's owned tracking/receipt-confirmation exception explicit at the mutation gate. It also separates off-duty completion of an existing courier assignment from new-work availability, and distinguishes required failed-delivery RTS from deferred post-delivery returns/disputes.

Verified pre-implementation code gaps, rather than specification changes to match the bugs. Account-access, fixed-role, and scoped KYC decision closures are recorded in the subsequent implementation reviews below; this table describes the earlier code snapshot:

| Priority | Gap | Evidence and required boundary |
|---|---|---|
| P1 | Unequal portal eligibility and admin suspension bypass | `app/Http/Middleware/RoleMiddleware.php:26` returns for admin before suspension and does not require a positive active state for other roles. `app/Http/Middleware/SubdomainRoleMiddleware.php:39` checks role without approval/activity. `routes/web.php:71`, `:165`, `:194`, `:369`, and `:430` expose seller, hub, and admin groups without the courier's dedicated approval gate. Apply active/approved and action scope consistently to protected reads and writes. |
| P1 | Review can approve missing evidence, reactivate restrictions, and overwrite a retry | `app/Http/Controllers/Admin/AdminKycController.php:65` unconditionally changes approval/activity/review time and related profiles. It has no evidence prerequisite, transaction/lock, reviewer identity, or append-only decision record. `:96` rejection similarly replaces feedback/state. Preserve separate restrictions, atomically record decisions, and return the original decision on identical retries. |
| P1 | Role/status edits bypass governance and active-work protections | `app/Http/Controllers/Admin/AdminDashboardController.php:74` directly updates role/status without reason, retained review history, or order/custody/cash checks. `app/Models/User.php:105` and `app/Http/Controllers/ProfileController.php:59` restrict courier self-deletion but do not apply the documented active-work check to every other role. |
| P2 | Registration and resubmission do not consistently enforce the shared contract | `app/Http/Controllers/Auth/RegisteredUserController.php:87` accepts basic phone/postal/text strings; `:179` permits an active buyer with pending/absent KYC. `:334` resubmission resets activity to pending without preserving an independent suspension. Record these as implementation gaps; do not redefine upload or email verification as approval. |
| P2 | Admin metrics imply financial/operational evidence that is absent | `app/Http/Controllers/Admin/LogisticsHubController.php:56` hard-codes riders as online; `:61` derives revenue/payouts from fixed counts and guessed fees. `resources/js/Pages/Admin/Dashboard.tsx:61` displays dollar currency. Use recorded PHP amounts and show missing reconciliation explicitly. |

Evidence limits: middleware/controllers/models and relevant tests were inspected, and the earlier read-only HTTP probes in this audit reproduced seller subdomain access/mutation and suspended-admin role editing. These docs do not prove every tenant/facility combination or a production concurrency result. `tests/Feature/Admin/AdminKycApprovalTest.php:47` covers happy-path approval without required document fixtures; `tests/Feature/Auth/RoleMiddlewareGateTest.php:100` covers root seller suspension, not a cross-role/root/subdomain denial matrix. `tests/Feature/Courier/CourierPortalAccessTest.php` provides the existing stronger courier baseline.

| Scoped assessment | Before | After | Improvement and limit |
|---|---:|---:|---|
| Admin specification quality | 6.5/10 | 9/10 | Responsibility lists become implementable decisions, scope rules, recovery behavior, and acceptance checks. Runtime enforcement is still pending. |
| Cross-role approval/suspension documentation | 8/10 | 9/10 | Review, placement, activity, duty, and existing-work exceptions use consistent meanings. Recovery, notifications, and finance retain their phase order. |
| Platform Admin implementation readiness in this audit | 4/10 | 4/10 | No backend or UI code changed. This scoped review does not raise the older overall flow scores. |

Ratings are engineering judgments of authority clarity, consistency, actionability, and testable acceptance, not measured usability or proof of implementation. Documentation checks cover local links, Markdown structure, retained lifecycle/category rules, and whitespace. The previously run isolated SQLite full suite remains a non-green baseline: 971 tests, 5,182 assertions, 58 failures, and one error. It was not rerun for these Markdown edits; the earlier fixture/legacy-state failures still require separate work.

**Focused follow-up chosen for this review (now verified below):** enforce one account-eligibility policy on seller, hub, and admin root/subdomain reads and mutations. Require an active admin before any privileged action, preserve the tested courier policy, and allow only explicitly scoped holding/existing-order/recovery exceptions. Test active, pending, rejected, inactive, suspended, unknown-state, and wrong-role accounts on both entry points, including a stale authenticated admin session. This task does not redesign dashboards, add later-phase COD/manifests, or enable direct custody overrides.

### Project Scope Documentation Review: October 4, 2026

`README.md`, `SYSTEM_FLOW_AND_SPECIFICATIONS.md`, `PROJECT_PLAN.md`, `ARCHITECTURE.md`, and `MASTER_LOGISTICS_SPECIFICATION.md` now describe a small, realistic ecommerce project. A bounded set of accounts and supported road routes is sufficient; thousands of users, enterprise infrastructure, and a national commercial fleet are not acceptance requirements. Boats, ports, RORO, sea crossings, and air freight remain excluded.

The supporting plans no longer conflate company registration with rider registration, promise routine admin access to every role's actions, require OCR/map-pin onboarding, prescribe automatic unpaid-order expiry/restocking, or present complete disputes and enterprise navigation as baseline work. Basic recovery, self-pickup, notifications, and COD remain required in their existing phases. No new route, role, model, external service, shipping split, or runtime behavior was implemented. Historical ERP source documents were preserved.

| Scoped documentation assessment | Before | After | Improvement and limit |
|---|---:|---:|---|
| General project plan | 5/10 | 8.5/10 | Clear bounded scope, distinct onboarding, complete road transaction, and explicit financial/deferred gates replace enterprise and premature dispute claims. |
| Application architecture overview | 6/10 | 8.5/10 | All five roles, per-request authorization, backend decisions, and current-schema boundaries are explicit; executable code still needs the roadmap fixes. |
| Logistics architecture reference | 6/10 | 8.5/10 | Company review, handler scope, optional automation, stock, and address guidance align with the normative contracts; small-scale operation remains the target. |

Verification: static checks passed for all 12 changed Markdown documents, 20 local links, and 15 code references, with balanced code fences and a clean whitespace diff. The canonical 13-status table, 14 master categories, seed accounts, and executable application/test files are unchanged. Manual contract review checked approval authority, activity/duty distinctions, existing-work recovery, mandatory Mother-Hub custody, buyer-only completion, COD settlement, and deferred scope. These scores cover the edited documents; untouched schema/style/history references are not re-rated. No browser testing, frontend build, or new PHP test run was needed for Markdown-only changes.

### Shared Account Access Implementation: October 4, 2026

**State: Focused account-access task verified; Phase 0 remains partial.** `User::canAccessPortal()` requires a known role, active status, and reviewed `approved`/legacy `verified` KYC. Active admins retain the applicant-KYC exemption. Root and subdomain seller, courier, hub, and admin guards now use this policy; admin no longer bypasses seller/courier authority or suspension. The shared account guard logs out suspended users and non-active admins. Other restricted workers reach their own holding screen. Password and OAuth worker sign-in, the universal dashboard, and the authenticated `/hub` landing apply the same gate. Reviewed legacy accounts leave holding correctly.

Shared routes cannot reopen those permissions: storefront edits require an eligible seller who owns the shop; admin order oversight requires an active admin; shared Inertia network props require account eligibility. Checkout and receipt confirmation require the buyer role in the backend, including their services. An authenticated inactive/suspended buyer still reads only owned orders and may confirm a physically delivered parcel without gaining new checkout permission. Existing courier duty/assignment behavior and read-only admin hub oversight are retained. No dashboard redesign, new custody override, schema change, commission change, or later-phase finance/recovery module is included.

Implementation evidence: `app/Models/User.php:130`, `app/Http/Middleware/EnsureApprovedAccount.php:13`, `app/Http/Middleware/RoleMiddleware.php:20`, `app/Http/Middleware/SubdomainRoleMiddleware.php:16`, `routes/web.php:313`, `:340`, `:444`, `app/Http/Controllers/Buyer/OrderHistoryController.php:20`, and `app/Http/Middleware/HandleInertiaRequests.php:40`. `tests/Feature/Auth/SharedPortalAccessTest.php` covers root/subdomain reads and real writes across approved, verified, pending, rejected, inactive, suspended, unknown-state, and foreign-role accounts, including an admin session restricted in the database after login. `SharedPrivilegedAccessTest.php` checks shared-route denial, unchanged records, owned buyer completion/retries, and withheld privileged props. `WorkerOAuthPortalAccessTest.php` covers alternate sign-in. The historical portal-bypass gap above is closed at the account boundary; related resource eligibility and decision governance below remain open.

| Scoped admin implementation assessment | Before | After | Change and remaining limit |
|---|---:|---:|---|
| Portal approval/activity and session enforcement | 3/10 | 9/10 | Consistent account gates protect both portal entry points and block stale restricted admin sessions; SQLite HTTP tests do not prove simultaneous production revocation. |
| Role/action separation on the tested entry points | 4/10 | 8.5/10 | Admin oversight no longer grants seller/courier actions, storefront edits, checkout, or buyer receipt confirmation. Resource eligibility still needs its own checks. |
| KYC evidence and decision audit | 3/10 | 3/10 | The reviewer must be active, but required evidence, atomic decisions, immutable history, and safe retries are not implemented. |
| Suspension/reactivation and active-work recovery workflow | 2/10 | 2/10 | Portal restrictions work; reasoned restriction decisions, exception queues, custody/cash recovery, and separate reactivation are still missing. |
| Role-change/deletion governance | 3/10 | 3/10 | A restricted admin cannot edit roles, but an active admin can still change history-bearing roles and non-courier self-deletion lacks active-work protection. |
| Dashboard and financial accuracy | 3/10 | 3/10 | Guessed logistics fees, hard-coded online states, dollar display, and absent COD/settlement evidence remain unchanged. |
| Overall admin implementation readiness | 4/10 | 5/10 | One security foundation is verified; the complete governance and financial flow is not ready. Admin specification quality remains 9/10. |

Verification: 185 new access tests passed with 1,695 assertions. The focused run also passed all 82 courier tests (969 assertions), 34 logistics tests (506), six existing admin tests (26), 32 seller tests (418), and the seeded cross-role order/delivery test (85). Four older buyer checkout fixture failures in that run already existed in the baseline. The final complete SQLite `:memory:` run executed 1,156 tests with 6,889 assertions; the same 58 failures and one error remain, with no new or resolved failing test identities compared with the fresh 971-test/5,182-assertion baseline. The error is the existing null-order `items()` call at `tests/Feature/ChallengerM1StressTest.php:180`; legacy lifecycle/checkout fixtures remain a separate test-maintenance task. Laravel Pint passed for the new middleware and three new test files, and whitespace checks passed. No frontend files changed. Browser/device testing and PostgreSQL concurrency were not performed.

Work deliberately left for later:

- **Follow-up selected here: make KYC approval/rejection a reasoned, auditable decision.** Its decision foundations are delivered in the KYC review below. The earlier snapshot's unconditional updates were at `app/Http/Controllers/Admin/AdminKycController.php:65` and `:96`; resubmission reset activity at `app/Http/Controllers/Auth/RegisteredUserController.php:333`. Those paths now use transactional services; complete application-field/category validation remains required.
- **Resource eligibility remains separate from account eligibility.** Static inspection shows the seller shop resolver selects owned/default shops without a positive shop-status check and creates an active fallback (`app/Http/Controllers/Seller/HasSellerShop.php:15`). The hub resolver filters active hubs/handlers but does not require parent-company eligibility (`app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php:75`). A complete negative resource/facility matrix was not run in this task; do not treat the account tests as its acceptance evidence.
- **Other Phase 0 gaps:** shared canonical input validators, buyer onboarding/holding and other buyer-only entry-point alignment, reasoned status changes, active-work deletion protections, and live sample-success removal. The tested owned-order exception does not certify every buyer endpoint. Suspended-buyer sign-in/recovery access still needs review separately from the preserved authenticated exception. The earlier role-change gap is closed by the fixed-role task below; account conversion is no longer a project requirement.
- **Later phases:** durable manifests, custody recovery/retry/RTS and secure counter claims, basic in-app notifications, COD reconciliation/settlement, and finance/dashboard corrections. Existing operational/financial evidence must be preserved while these remain unavailable.

### Fixed Account Roles: October 4, 2026

**State: Focused role-conversion removal verified; Phase 0 remains partial.** At the user's direction, the admin, validation, and system contracts now keep every account's role fixed at creation, including pending accounts without transaction history. Another public role requires a separate registration and its normal evidence/approval. Account conversion and role migration are not project features. The five roles and the existing courier phases remain unchanged.

Before this task, the admin Users modal could switch roles and activity together, including converting a buyer into a seller or admin and activating a restricted account. Both root and subdomain `PATCH /users/{user}/role` routes and their controller method are removed. `User::booted()` rejects changed roles on normal Eloquent updates before any account fields are persisted. The Users page keeps search, role filters, account details, and fixed role/status labels, with clear copy about separate registration. The coupled status selector is removed with the role modal; no replacement restriction or reactivation shortcut is introduced.

Evidence: `app/Models/User.php:63`, `resources/js/Pages/Admin/Users.tsx:27`, `routes/web.php:194`, and `:425`. `tests/Feature/Admin/AccountRoleImmutabilityTest.php` covers every different pair of the five roles for active and pending accounts, old routes on both portals, status-only attempts against those routes, admin self-conversion, ordinary creation/profile updates, ignored profile role injection, and retained directory filters. Existing access tests now exercise real product moderation writes rather than the removed role route. Private-document and logistics fixtures create their intended role initially, preserving their authorization and facility-scope checks.

| Scoped assessment | Before | After | Improvement and remaining limit |
|---|---:|---:|---|
| Account role immutability | 3/10 | 9/10 | Saved accounts cannot switch role through the admin UI/routes or normal model updates, even without history. This is application enforcement, not a database constraint; direct SQL and event-bypassing maintenance writes are not certified. |
| Active-work deletion protection | 3/10 | 3/10 | Removing conversion prevents that shortcut, but non-courier deletion still needs the documented active-order/custody/cash checks. |
| Approval/restriction decision governance | 3/10 | 3/10 | The combined role/status shortcut is closed. Required evidence, immutable decisions, safe review retries, independent suspension preservation, and recovery remain. |
| Overall admin implementation readiness | 5/10 | 5/10 | One additional security boundary is verified; the remaining review, deletion, recovery, and financial flows are still incomplete. |

Verification: all 59 new role tests passed with 186 assertions; 51 of these tests reproduced the flaw before implementation. The focused admin/auth/logistics/profile/seeded cross-role run passed 430 tests with 3,205 assertions. The TypeScript/Vite production build, Laravel Pint on all six changed PHP implementation/test files, and whitespace checks passed. A fresh full SQLite `:memory:` baseline ran 1,156 tests with 6,891 assertions; the final run ran 1,215 tests with 7,083 assertions. Both retained the exact same 58 failing tests and one error, with no new or resolved failing identities. The existing error remains `tests/Feature/ChallengerM1StressTest.php:180`. No browser/device or PostgreSQL concurrency checks were performed. Existing account data and any historical role conversions were not audited or repaired.

The next focused work selected here is implemented in the scoped KYC decision review below. Role conversion is removed from the backlog; active-work deletion and reasoned suspension/reactivation remain separate Phase 0 work.

### KYC Decision and Resubmission Foundations: October 4, 2026

**State: Decision foundations verified; complete KYC validation and Phase 0 remain partial.** `KycDecisionService` replaces unconditional controller updates with a locked, transactional decision on the current pending application. A server-generated review token binds the submission time, role, account/application details, private file references, and content hashes. Approval requires valid private evidence for the applicant's role, the original dependent profile, vehicle type/plate for couriers, an explicit review confirmation, and document accesses by the current reviewer in the same session. Missing, replaced, or invalid evidence cannot be approved. Rejection requires a meaningful reason and can describe missing evidence.

`KycDecision` retains reviewer identity/role/name, subject and role, server review time, submission/evidence references, reason, and before/after state. Unique subject/submission decisions, model guards, SQLite triggers, and a PostgreSQL trigger prevent replacement/deletion of the recorded review. An identical action/reason retry by the original reviewer returns the original decision without rewriting status, time, feedback, duty, or profiles. Another reviewer, competing action/reason, changed submission, or attempted reversal of a reviewed/legacy account conflicts. No review history is fabricated for existing approved/verified accounts.

Approval and rejection preserve inactive, suspended, and unknown account restrictions. Approval activates only the original pending shop/company when the account is active; independent shop/company restrictions and additional shops remain unchanged. Courier document verification does not turn duty on or modify company/hub/vehicle placement. Rejection blocks account eligibility without rewriting separate profile restrictions or custody/cash records. Reasoned suspension/reactivation and controlled recovery remain separate work.

`KycSubmissionService` locks the account and original profile before resubmission. The birth-date extension below adds pending worker corrections and rejected birth-date-only resubmission alongside corrected role-appropriate uploads. Initial/unreviewed buyer ID uploads use the same service; approved/verified buyer uploads cannot silently replace a completed review. Activity restrictions remain unchanged, old decisions/files stay private, and failure rolls back profile/account references and removes only newly stored files. The authenticated user is refreshed after commit so subsequent profile props contain the saved document link. Resubmission notifications and durable notification delivery are not implemented here.

The admin queue removes approval from the table's quick actions. Its inspector shows required documents, readiness errors, account/profile activity separately, original application details, an explicit review confirmation, and decision history with authorized historical evidence links. Historical evidence is bound to its subject and checked against its recorded hash; authorization precedes history lookup, including stale restricted reviewers. Approved/verified records have no routine approve/reject controls. Applicant counts exclude admins and include legacy reviewed accounts. Holding copy describes evidence review instead of a one-click signature and displays empty-resubmission errors.

Implementation evidence: `app/Services/KycDecisionService.php`, `app/Services/KycSubmissionService.php`, `app/Models/KycDecision.php`, `database/migrations/2026_10_04_000001_create_kyc_decisions_table.php`, `app/Http/Controllers/Admin/AdminKycController.php`, `app/Services/VerificationDocumentService.php`, and `resources/js/Pages/Admin/KycQueue.tsx`. Reviewer and subject foreign keys restrict deletion; `User::canDeleteOwnAccount()` and the profile action return an explicit closure error for accounts with review history. This preserves KYC evidence but does not complete order/custody/COD/settlement deletion safeguards.

| Scoped admin assessment | Before | After | Evidence and remaining limit |
|---|---:|---:|---|
| Atomic KYC decisions, immutable history, and retries | 2/10 | 8/10 | Current submission checks, consistent locks, atomic account/profile/audit writes, original-result retries, and stale conflicts are tested. Simultaneous PostgreSQL execution is unverified. |
| Private evidence review and inspector | 3/10 | 7/10 | Required private files, content type/hash, reviewer access, confirmation, history, and safe links are checked. Full role/account/category validation is still missing. |
| Independent restrictions during review/resubmission | 3/10 | 8/10 | Account/profile restrictions and duty remain separate; approval does not activate additional shops or assignments. This is not a suspension or recovery workflow. |
| Portal account eligibility | 9/10 | 9/10 | The earlier shared access policy is retained; resource eligibility remains its own task. |
| Fixed account roles | 9/10 | 9/10 | Role conversion remains unavailable. |
| Reasoned suspension/reactivation and active-work recovery | 2/10 | 2/10 | Restriction decisions, exception queues, custody/cash recovery, and separate reactivation remain unbuilt. |
| Active-work deletion protections | 3/10 | 3/10 | KYC history deletion is blocked; other transactional-history checks remain incomplete. |
| Financial/operational metrics | 3/10 | 3/10 | Guessed amounts and live placeholders were not changed by this task. |
| Overall Platform Admin readiness | 5/10 | 5/10 | KYC decision foundations improve; incomplete application validation, governance, recovery, metrics, and finance still prevent broad readiness. |

Verification: 143 focused KYC/auth/lifecycle checks passed with 991 assertions in isolated SQLite `:memory:`. They include 72 new governance checks with 536 assertions, both admin entry points, missing/invalid documents, reviewer/session binding, changed evidence/profile conflicts, identical retries, restriction/duty preservation, additional-shop isolation, account/profile/audit failure rollback, immutable database history, retained resubmission evidence, historical-link privacy, stale reviewer denial, and review-history deletion protection. Older KYC tests now use real private evidence and review tokens, preserve rider duty, reject empty resubmission, and reject reversal/feedback overwrite. The TypeScript/Vite production build, focused Laravel Pint, and whitespace checks passed. The full SQLite run increased from 1,215 tests/7,083 assertions to 1,287 tests/7,669 assertions and retained exactly the same 58 failing tests and one error, with no new or resolved failing identities; the error remains `tests/Feature/ChallengerM1StressTest.php:180`. The additive audit-table migration was applied successfully to the verified local PostgreSQL application, including its immutable-history trigger and foreign keys. No development database reset or application-record edits were performed. No browser/device or simultaneous PostgreSQL concurrency tests were performed.

**Remaining required validation, not waived by this foundation:** the birth-date gap is addressed in the scoped review below. Seller registration does not capture `root_category_id`, and the current seeder defines one demo category rather than populating the 14 master-category approval choices. The review service records existing application details but does not yet enforce the complete canonical name/phone/postal/shop/company/category contract. Franchise applicability still follows existing application data; generated accreditation defaults need their own validation cleanup. Existing approved accounts and legacy evidence have not been retroactively audited.

The next focused work selected here is verified in the birth-date review below. Seller master-category setup and complete profile scope follow as the next prerequisite. Related resource/buyer-entry eligibility, active-work deletion, reasoned restrictions, and sample-success removal remain Phase 0 tasks. Notification/recovery/COD implementation retains its documented phase order. Broader admin implementation remains incomplete.

### Adult Worker Birth Dates: October 4, 2026

**State: Focused adult eligibility verified; complete KYC validation and Phase 0 remain partial.** `BirthDateEligibility` and the shared `BirthDate` rule require a real past `YYYY-MM-DD` date and calculate completed years using the Philippine calendar. Seller, courier, and logistics registration requires age 18 or older. Buyer birth dates remain optional on the backend and do not inherit the worker adult minimum. Posted age is ignored; model age writes and worker approval derive it from birthday. No migration, seed-account change, or development business-record edit is required.

The worker registration forms collect a date of birth, use server-provided calendar limits, and return to the relevant step when the server rejects that field. The buyer age display uses the same server calendar. The KYC inspector shows a freshly calculated review age rather than trusting a stored age. Missing, impossible, future, or underage worker dates prevent a new approval, including document-only resubmission of a rejected application without a valid date.

Pending workers may submit a changed valid birth date; rejected workers may correct the date without replacing already valid evidence. The locked submission updates birthday, derived age, and submission version together, preserves suspension, profile restrictions, duty, old files, and review history, and requires the reviewer to inspect the changed submission again. An unchanged pending date cannot be used to replace documents. Approved/legacy verified accounts cannot silently edit reviewed identity through this resubmission path. A related fix keeps successfully committed private uploads intact if the later authenticated-user refresh fails; only failed transaction uploads are removed.

Known underage or invalid dates block seller, courier, logistics, and admin portal eligibility on both root and subdomain routes. The shared courier query applies the adult cutoff, and privileged decision/document services re-read admin eligibility instead of trusting cached identity. **Compatibility limit:** already reviewed accounts and controlled admins with no birth date retain the existing access policy pending a controlled audit. No birth dates or historical decisions are invented for these accounts. This task does not certify all legacy users as adults. Known invalid reviewed dates need a controlled audited correction workflow; generic profile editing must not become an approval bypass.

| Scoped assessment | Before | After | User-visible improvement and remaining limit |
|---|---:|---:|---|
| New worker birth-date and adult eligibility | 2/10 | 8/10 | Real required dates, server-derived age, Philippine-day boundaries, and approval checks reject forged age. Legacy missing-date accounts still need a controlled audit. |
| Pending/rejected birth-date correction | 1/10 | 8/10 | Applicants can fix unreviewed details while earlier evidence, restrictions, and decisions remain intact. Reviewed identity correction and notifications remain separate work. |
| Admin birth-date review | 2/10 | 8/10 | The inspector shows server-calculated age, and changed details invalidate stale decisions and document inspections. Complete application/category validation remains incomplete. |
| Portal account eligibility | 9/10 | 9/10 | The shared policy also denies known underage reviewed workers/admins and filters courier choices. Missing-date legacy compatibility and related resource eligibility remain explicit limits. |
| Overall Platform Admin readiness | 5/10 | 5/10 | One application prerequisite improves; remaining category, governance, recovery, metrics, and finance work still prevents broad readiness. |

Implementation evidence: `app/Services/BirthDateEligibility.php`, `app/Rules/BirthDate.php`, `app/Models/User.php`, `app/Services/KycDecisionService.php`, `app/Services/KycSubmissionService.php`, `resources/js/Components/BirthDateInput.tsx`, the registration/holding forms, `resources/js/Pages/Admin/KycQueue.tsx`, and `tests/Feature/Auth/WorkerBirthDateEligibilityTest.php`.

Verification: 614 focused checks passed with 4,256 assertions in isolated SQLite `:memory:`, including 68 new birth-date checks with 482 assertions. Coverage includes all three worker registrations, forged/missing/invalid dates, exact eighteenth birthdays, leap years, Philippine midnight, buyer age derivation, root/subdomain eligibility, courier-query agreement, fresh privileged-actor checks, changed-submission inspection, immutable previous decisions, persisted before/after age, failed-correction rollback, and post-commit file retention. Existing registration/KYC fixtures now supply valid adult dates rather than bypassing the new rule. The full SQLite run increased from 1,287 tests/7,669 assertions to 1,355 tests/8,151 assertions and retained exactly the same 58 failing tests and one error, with no new or resolved failing identities; the error remains `tests/Feature/ChallengerM1StressTest.php:180`. The TypeScript/Vite production build, focused Laravel Pint, and whitespace checks passed. No browser/device or simultaneous PostgreSQL concurrency tests were performed.

**Recommended next focused task:** [B01: seller master-category and original-shop approval](admin-plan/01-seller-category-approval.md). Seed and validate the 14 master root-category choices, collect the seller shop's `root_category_id` during registration, and require a valid shop category before KYC approval. Inspect existing category/shop migrations and services first; preserve products and legacy shop assignments. Complete canonical name/phone/postal/shop/company validation, controlled legacy birth-date auditing, reviewed identity correction, buyer profile/holding alignment, resource eligibility, reasoned restrictions, active-work deletion, and sample-success removal remain separate Phase 0 work. Select one bounded branch rather than running the entire backlog as one goal.

### Bounded Admin Branch Planning: October 4, 2026

**State: Documentation delivery complete; no runtime feature added.** [admin-plan/README.md](admin-plan/README.md) allocates 18 future admin-focused branches after the existing `admin/governance-improvements` foundation: B01-B13 for governance and the Phase 0 gate, then B14-B18 for phase-gated exceptions, notifications, COD, settlement, and finance. This is 19 admin checkpoints including the foundation, not a count of every remaining project branch. Role-owned custody/recovery prerequisites and optional additions are explicitly outside that count.

The folder contains 18 detailed branch documents plus an index, [Git/testing workflow](admin-plan/WORKFLOW.md), and [capability/role map](admin-plan/CAPABILITIES.md). Each branch defines dependencies, actual inspection targets, implementation sequence, decision rules, data/legacy handling, exclusions, acceptance cases, required verification, and a bounded prompt/stopping point. Its 186 acceptance scenarios are planned requirements, not newly executed tests. No future implementation branches are created by this documentation task.

The admin and validation contracts now explicitly require protection of the last eligible admin, controlled reviewed-identity correction, reasoned product moderation/reinstatement, and read-only scoped governance history. These are target safeguards to implement, not new executable controls. Fixed roles, mandatory Mother-Hub custody, buyer-only completion, private evidence, 10%/90% product proceeds, and separate shipping accounting remain authoritative.

Verified starting gaps and branch ownership:

| Gap | Executable evidence | Planned owner |
|---|---|---|
| Original-shop category gap at the planning checkpoint | Resolved by the B01 review below: registration/correction and KYC readiness enforce an active canonical root. Canonical application validation and independent shop review are verified in the B02 and B03 reviews below. | B01-B03 implemented |
| Extra shops and fallback context could gain active eligibility without independent review at the planning checkpoint | Resolved by the B03 review below: pending submissions, immutable shop decisions, owned positive context, and sale/category eligibility replace the unsafe creation/fallback paths. Legacy shops require explicit review. | B03 implemented |
| Resource eligibility needs positive company/facility/assignment checks everywhere | Resolved by the B04 review below: shared positive parent, placement, and action checks replace active-child and global fallback paths. Reasoned restrictions/reactivation remain B07. | B04 implemented; B07 remains |
| Buyer holding/new-work and restricted existing-order entry points need full alignment | Resolved by the B05 review below: shared current-account checks, own application correction, aligned sign-in, and separate owned-order access. Reasoned restriction/security decisions remain B06. | B05 implemented; B06 remains |
| Reasoned activity decisions and final-admin protection are absent | `AdminDashboardController` exposes no suspension/reactivation decision workflow. Ordinary activity flags are not an immutable governance decision. | B06, B07 |
| Reviewed/legacy identity repair and role-wide closure remain incomplete | Generic profile deletion uses `User::canDeleteOwnAccount()`; current reviewed identity cannot silently self-correct. Missing-date legacy compatibility is not an adulthood audit. | B08, B09 |
| Product toggle lacks reason/source-state/audit and eligible-parent reinstatement | `AdminDashboardController::toggleProductStatus()` blindly switches active/draft. | B10, B11 |
| Overview includes unsupported online/financial claims | `Admin/LogisticsHubController` supplies unconditional online status and count-based guessed fee totals; admin dashboard currency/basis needs alignment. | B12 |
| Cross-role acceptance and later custody/notification/finance foundations remain required | The existing non-green suite and Phases 2-5 below remain the evidence/gate sources. | B13; role-owned prerequisites; B14-B18 |

| Scoped assessment | Before | After | Evidence and limit |
|---|---:|---:|---|
| Admin branch execution planning | 4/10 | 9/10 | A phased roadmap now has 18 bounded scopes, ordered dependencies, migration/privacy rules, planned negative/retry/rollback cases, and stopping prompts. Estimates of effort and simultaneous PostgreSQL behavior remain unverified. |
| Admin specification quality | 9/10 | 9/10 | More explicit continuity/correction/moderation/history safeguards improve actionability; no usability or runtime proof follows from Markdown. |
| Overall Platform Admin readiness | 5/10 | 5/10 | Documentation does not close category, resource, restriction, closure, recovery, notification, or finance gaps. |

Verification: local links and referenced repository files, 18 unique branch names, consecutive IDs, index/dependency agreement with no forward dependency, required scope/acceptance/stopping sections, Markdown headings/fences, and whitespace passed. Repository changes are Markdown only. PHP tests and the frontend build were not rerun for this task. The latest runtime evidence remains the birth-date task's 614 focused checks and full SQLite baseline of 1,355 tests/8,151 assertions with 58 failures and one error; these failures are not resolved by this plan. Browser/device and simultaneous PostgreSQL checks remain unperformed.

**Publication recommendation:** publish the reviewed foundation with these planning docs for user-managed review. Keep the known full-suite failures and legacy/deployment limits visible; this is not whole-project release certification. Once the foundation is merged and local `main` is updated, create only `admin/seller-category-approval` and complete B01. Stop after its verification/report; do not automatically continue B02 or the entire admin plan.

### B01 Seller Category and Original-Shop Approval: October 4, 2026

**State: B01 implemented and verified on `admin/seller-category-approval`; Phase 0 and the wider admin flow remain partial.** The branch includes the existing planning commits `f330485` and `204ffc1`. The earlier B01 recommendation and foundation publication instructions describe the preceding checkpoint; the next selected implementation task is B02 after user-managed review/merge.

Before this change, seller registration created an original shop without collecting its master category, and the KYC service could approve it without that prerequisite. `MasterCategoryService` now returns only active, unambiguous roots whose exact names match the 14 documented categories. Missing, child, inactive, legacy, duplicate-name, malformed, and nonexistent category choices fail server validation. `SellerApplicationService` rechecks the selected category under lock and saves the user and original shop in one transaction; failed creation rolls back both and removes only newly uploaded evidence.

Owned pending/rejected sellers can correct the original shop's category. `KycSubmissionService` updates the category and submission version atomically while preserving account/profile restrictions, additional shops, retained evidence, and previous decisions. Foreign shop targets and reviewed-account corrections are denied. The fresh KYC snapshot includes category identity and eligibility: category correction, deactivation, renaming, reparenting, or a duplicate root invalidates an old review. New approval requires an eligible category and fresh document inspection. An identical completed-decision retry returns its recorded result even after taxonomy deactivation and cannot reactivate a subsequently restricted account/shop.

Visible changes are limited to the seller registration category selector, pending/rejected application correction, and category/readiness feedback in the admin inspector. The server remains the approval authority. This is predominantly backend/domain work with a small UI surface, not a portal redesign.

| Scoped assessment against the B01 contract | Before | After | Evidence and remaining limit |
|---|---:|---:|---|
| Seller category registration and original-shop approval | 2/10 | 9/10 | Required server-selected root, atomic persistence, readiness rejection, stale-review protection, original-shop-only activation, safe retries, and rollback are tested. Simultaneous PostgreSQL concurrency remains unverified. |
| Pending/rejected category correction | 1/10 | 9/10 | Owned corrections preserve history/files/restrictions and require a fresh review. Reviewed identity changes remain B08; additional shops remain B03. |
| Admin category feedback | 2/10 | 8/10 | The inspector shows the saved category and precise blockers. TypeScript build and Inertia response checks passed; rendered/device usability was not tested. |
| Admin specification quality | 9/10 | 9/10 | The existing B01 contract was implemented without relaxing its acceptance cases. |
| Overall Platform Admin readiness | 5/10 | 5/10 | Shared validation, resource eligibility, restrictions, closure, recovery, metrics, and finance remain incomplete. |

Implementation evidence: [MasterCategoryService.php](../app/Services/MasterCategoryService.php), [SellerApplicationService.php](../app/Services/SellerApplicationService.php), [MasterCategory.php](../app/Rules/MasterCategory.php), [MasterCategorySeeder.php](../database/seeders/MasterCategorySeeder.php), [RegisteredUserController.php](../app/Http/Controllers/Auth/RegisteredUserController.php), [KycSubmissionService.php](../app/Services/KycSubmissionService.php), [KycDecisionService.php](../app/Services/KycDecisionService.php), [MasterCategorySelect.tsx](../resources/js/Components/MasterCategorySelect.tsx), [SellerRegister.tsx](../resources/js/Pages/Auth/SellerRegister.tsx), [PendingApproval.tsx](../resources/js/Pages/Auth/PendingApproval.tsx), [KycQueue.tsx](../resources/js/Pages/Admin/KycQueue.tsx), and [SellerCategoryApprovalTest.php](../tests/Feature/Auth/SellerCategoryApprovalTest.php).

Verification:

- Two initial behavior checks reproduced the missing-registration-category and missing-category-approval gaps before implementation. The completed focused set passed 246 tests with 1,604 assertions, including 34 new category checks. All tests used isolated SQLite `:memory:`. Coverage includes exact names, idempotent installation, preserved legacy data/slug collisions/inactive roots, root/subdomain registration, forged choices, category recheck, ownership/reviewed-state denial, stale evidence, correction/review rollback, restrictions, additional-shop isolation, and completed-result retries.
- The fresh full-suite baseline ran 1,355 tests with 8,151 assertions; the final run ran 1,389 tests with 8,452 assertions. Both have exactly the same 58 failures and one error, compared by failing test identity and failure/error type, with no new or resolved failures. The error remains `ChallengerM1StressTest::test_standard_product_without_variants` at `tests/Feature/ChallengerM1StressTest.php:180`. The full suite remains red; B01 does not close the wider release gate.
- The TypeScript/Vite production build, focused Laravel Pint, and whitespace checks passed. Formatting adjustments after the full run did not alter behavior. Browser/device checks and simultaneous PostgreSQL concurrency tests were not performed. Lock order was inspected: decisions/corrections lock users, original profile, then selected category; new registration locks its category before creating new account/profile records. SQLite does not prove concurrent taxonomy installation or duplicate-root insertion safety.
- The dedicated additive installer ran twice in the verified local application inside a transaction. It added 14 eligible roots, preserved the existing legacy category byte-for-byte, and left all user/shop/product rows unchanged; the second run made no changes. One local shop remains a scope-review candidate. No category was guessed for it, no old approval was stamped, and no database reset or destructive demo seeding occurred.

Deployment: install missing taxonomy with `php artisan db:seed --class=MasterCategorySeeder` (or the Docker equivalent). Do not run the entire legacy `DatabaseSeeder` to install these choices: its demo product replacement is outside this feature. The installer preserves existing roots, children, images, references, custom slugs, and inactive flags; ambiguous duplicate roots are preserved and unavailable until controlled review. Existing reviewed shops are not retroactively certified by the new approval gate. No schema migration is required.

Local implementation commits: `ac2b80c fix: enforce seller master category before KYC approval`; `062de72 feat: show seller category selection and review feedback`. This roadmap update is a separate documentation commit. Nothing was pushed or merged.

**Next recommendation:** [B02: shared application validation](admin-plan/02-application-validation.md). Complete canonical names, contact/postal text, uniqueness, and application/correction/review agreement in its own branch after B01 review. B03 remains responsible for independent additional-shop approval/context. B01 stops here; its category checks do not certify existing shops or finish Phase 0.

### B02 Shared Application Validation: October 5, 2026

**State: B02 implemented and verified on `admin/application-validation`; Phase 0 and the wider admin flow remain partial.** B01 is included through the user-merged `main` commit `7977e2d`. This task covers application data and its review, not the whole admin portal or Phase 0.

Previously, registration accepted digit-bearing names and arbitrary courier vehicle labels, while correction and readiness checked different subsets of the application. `ApplicationValidationService` now supplies the same normalization, field rules, current values, and field errors to all three entry points. Approved text receives NFKC normalization and space trimming; controls, bidi/zero-width characters, markup, unsafe URI/handler text, and meaningless required values reject. Phone and postal digits are checked before Unicode normalization can hide look-alikes. Invalid input is rejected without truncating applicant data.

The inventory below follows the actual migrations/models and role payloads. All listed canonical fields are covered by registration, allowed unreviewed correction, and readiness. Registration derives the initial shop/company contacts from its corresponding account inputs; correction can explicitly change the separate saved business contacts. Readiness validates a normalized copy without rewriting saved legacy values.

| Actual fields and persistence | Shared rule and limit | Role/payload boundary |
|---|---|---|
| `users.name`, existing `varchar(255)` | Required Latin name, approved diacritics, spaces, apostrophe, hyphen, period; 2-100 characters | Every applicant; buyer composite name is checked as a full name |
| `users.first_name`, `last_name`, `middle_name`, nullable `varchar(255)` | Same name characters; optional 1-100 characters, allowing initials | Existing buyer parts; other roles may omit them |
| `users.sex`, nullable string | Exact `Female`, `Male`, `Other` when supplied | No new demographic enum |
| `users.birthday`, nullable date; derived `age` | Existing valid past ISO date; workers at least 18 using Asia/Manila completed years | Buyer optional, with no adult minimum; posted age is not authority |
| `users.email`, `varchar(255)` | Valid address, maximum 255, no controls/internal whitespace, domain lowercase, case-insensitive account uniqueness | Saved legacy email is not bulk rewritten; password/OAuth lookup uses database case comparison |
| `users.phone`, nullable string | Canonical `+639` mobile; business contacts also permit geographic landlines with full area code | Required for workers; buyer optional; courier personal contact remains mobile-only |
| `users.address`, `shops.address`, `logistics_companies.address`, widened to nullable `text` | Meaningful approved address text, 5-500 characters | Worker account and original business address required; buyer account address optional on the backend |
| `users.city`, `province`, `municipality`, `barangay`; `shops.city`, existing strings | Approved normalized free text, 2-255 characters | Worker city/original shop city required; other location parts optional; text does not establish serviceability |
| `users.postal_code`, nullable string | Exactly four ASCII digits when supplied | Unicode digits, signs, decimals, and other lengths reject |
| `shops.name`, `varchar(255)` | Meaningful approved business text, 2-255 characters | Original seller application only; generated slug fits its existing 255-character column without shortening the name |
| `shops.phone`, nullable string | Required canonical Philippine mobile or geographic landline | Separate shop contact after initial registration |
| `shops.root_category_id`, existing nullable foreign key | Existing B01 active, unambiguous canonical master root | Original owned shop; the 14-category contract is preserved |
| `courier_profiles.vehicle_type`, existing string | Exact `Motorcycle`, `Scooter`, `Sedan / Van` | Existing registration choices; no manufacturer/model text or new vehicle category |
| `courier_profiles.plate_number`, `license_number`, existing nullable strings | Uppercase ASCII letter/digit groups separated by hyphens, 2-50 characters | Plate required; declared license number optional; private license evidence remains independently required |
| `logistics_companies.name`, `varchar(255)` | Meaningful approved business text, 2-255 characters | Original owned company; generated slug remains within its column |
| `logistics_companies.code`, unique `varchar(20)` | Uppercase ASCII code, 2-20 characters, exact existing code uniqueness | Optional at registration with server-generated fallback; an existing company must retain a valid code |
| `logistics_companies.contact_email`, `contact_phone`, existing nullable strings | Required valid email up to 255 and canonical mobile/landline | Company email is not a second account identity |
| `accreditation_details.operating_province`, existing JSON field | Optional approved normalized location text, 2-255 characters | Initial declared province, independent correction thereafter; a city is not fabricated as a province |
| `accreditation_details.franchise_number`, existing JSON field | Optional uppercase ASCII code, 2-100 characters; reject fabricated `PENDING-LTFRB` placeholder | Existing applicable private franchise-evidence rule reused; no new accreditation requirement |
| `accreditation_details.fleet_size`, `vehicle_types`, existing JSON fields | Optional integer 1-10,000; up to three distinct exact `motorcycle`, `l300_van`, `wing_truck` values | Declared information only, without fabricated fallback fleet/evidence |
| Existing private ID, permit, license, OR/CR, franchise uploads | Existing verified MIME, 5 MB limit, purpose-specific required evidence, private generated storage paths | Existing role/document policy reused; failed writes delete only newly stored files |
| `kyc_decisions.reason`, existing text | NFKC plain text, maximum 1,000; retain the foundation's meaningful rejection minimum of 5 | The general 1-character notes minimum does not weaken the existing rejection rule |
| Posted role/resource/status fields | Exact public signup roles; correction cannot choose a role or foreign account/shop/company/hub/vehicle ID | Server creates ownership and pending state; existing account roles remain fixed; no client coordinates grant routing eligibility |

Registration now saves every worker account and original profile transactionally. Case-equivalent email races are stopped by a database uniqueness index and return an email error without leaving an account/profile or new private files behind. Permitted pending/rejected corrections update only supplied canonical fields on the original owned application, preserve independent account/resource restrictions and earlier evidence, and refresh the submission version. Missing original courier/company profiles cannot discard posted details and report a successful correction. A deliberate new account email clears email verification and the old OAuth link; verification of the new address is still required.

The review token and inspection session bind every listed canonical value, including company declarations. A changed field makes an earlier review stale. Required private documents must be inspected again before approval; completed decisions remain immutable. B01-format recorded decisions retain their original identical-retry comparison without creating a new approval or reactivating restricted resources. Ordinary resubmission cannot replace approved/legacy-verified identity; controlled reviewed repair remains B08.

Affected registration forms return applicants to the field with a server error. Seller/company phone controls accept permitted landlines; buyer birth date is optional; nested fleet errors remain visible. Worker holding forms expose only permitted application corrections. The admin inspector shows actual application details and field blockers alongside existing document/category readiness. These changes preserve the current authentication layout and add no role-conversion control.

| Scoped B02 assessment | Before | After | Evidence and limit |
|---|---:|---:|---|
| Canonical application validation | 2/10 | 8.5/10 | Shared rules, schema-safe boundaries, enums, ownership rejection, email constraint and rollback; simultaneous PostgreSQL requests remain unverified |
| Pending/rejected detail correction | 1/10 | 9/10 | Original-application changes, stale inspection, immutable history, and independent restrictions; reviewed repair remains B08 |
| Applicant/admin field feedback | 2/10 | 8/10 | Server field errors, Inertia props, and production build; rendered/device usability was not tested |
| Admin specification quality | 9/10 | 9/10 | Existing contracts preserved; the executable field matrix and legacy limits are recorded here |
| Overall Platform Admin readiness | 5/10 | 5/10 | Other resource, restriction, closure, recovery, notification, metrics, and finance branches remain incomplete |

Implementation references: [ApplicationValidationService.php](../app/Services/ApplicationValidationService.php), [ApplicationText.php](../app/Rules/ApplicationText.php), [PhilippineContact.php](../app/Rules/PhilippineContact.php), [UniqueAccountEmail.php](../app/Rules/UniqueAccountEmail.php), [ApplicationRegistrationService.php](../app/Services/ApplicationRegistrationService.php), [KycSubmissionService.php](../app/Services/KycSubmissionService.php), [KycDecisionService.php](../app/Services/KycDecisionService.php), [field-integrity migration](../database/migrations/2026_10_04_000002_enforce_application_field_integrity.php), [ApplicationFields.tsx](../resources/js/Components/ApplicationFields.tsx), and [ApplicationValidationTest.php](../tests/Feature/Auth/ApplicationValidationTest.php).

Verification:

- Two initial behavior checks reproduced accepted digit-bearing names and unknown courier vehicle labels before implementation. The final focused set passed **591 tests with 4,418 assertions**, including **81 B02 checks with 707 assertions** in `ApplicationValidationTest`. Coverage includes legitimate diacritics/punctuation and mobile/landline forms; unsafe text and boundaries; postal look-alikes; case-equivalent email validation/constraint failures; registration and correction rollback; exact enums/foreign IDs; agreement across registration/correction/readiness; stale inspection; immutable and B01-format decision retries; missing original profiles; field-error presentation; and root/subdomain fixed-role/adult/restriction guards.
- The fresh baseline ran **1,389 tests with 8,452 assertions**; the final full run ran **1,470 tests with 9,162 assertions**. Both have exactly the same **58 failures and one error**, compared by failing test identity and failure/error exception type. There are no new or resolved failures. The error remains `ChallengerM1StressTest::test_standard_product_without_variants` at `tests/Feature/ChallengerM1StressTest.php:180`. The full suite remains red and the wider release gate stays open.
- The TypeScript/Vite production build, focused Laravel Pint for all 26 changed/new PHP files, whitespace checks, and B02 documentation-link checks passed. Positive test fixtures were aligned with real name/contact/vehicle rules; tests were not skipped or weakened to accept malformed applications. Factory passwords remain raw inputs for the existing model hash cast.
- PHP suites ran sequentially because Laravel fake storage uses a shared test directory. Every test used isolated SQLite `:memory:` with explicit testing/database/cache/session/mail overrides. Source lock order was inspected: correction/decision lock the user and original profile, then the selected seller category; account/profile registration shares a transaction and database email uniqueness handles write conflicts. Constraint-failure simulation and local index inspection do not prove simultaneous PostgreSQL requests or every Unicode collation. No browser/device checks were performed.

Deployment and legacy limits:

- Run the targeted additive migration with `php artisan migrate --path=database/migrations/2026_10_04_000002_enforce_application_field_integrity.php` or its Docker equivalent. It refuses existing case-equivalent account emails before making changes; resolve ambiguous identity through controlled review rather than merging/renaming accounts automatically. Rollback removes the new index but retains wider address columns to avoid data loss.
- The migration was applied in the verified local PostgreSQL application. All 9 user, 1 shop, and 1 company records matched their before/after checksums. Three address columns are `text` and the case-insensitive unique index was inspected. This was schema verification, not a PostgreSQL test reset or concurrency test; no destructive seeding occurred.
- A read-only local audit identified seven reviewed worker records without valid birthdays and the original seller shop without its required root. Three reviewed logistics accounts also lack an owned company in this company-applicant audit; their possible handler placement needs B04 scope inspection before classifying them as invalid company applications. No legacy identities, placements, restrictions, files, birthdays, categories, or approval provenance were replaced.
- Valid legacy formatting is checked on a normalized copy; invalid legacy values block a new approval and require an explicit permitted correction or controlled review. Landline validation establishes format, not ownership or reachability. Free-text location acceptance does not certify a route, coverage, or contiguous-road serviceability.
- Buyer holding/transactional-entry alignment, duplicate personal-mobile policy, broader password/OTP/reset validation, ordinary profile edits, saved addresses, and reviewed identity repair remain separate Phase 0 work. B02 does not certify those paths or finish Phase 0. Independent additional-shop review/context is B03.

Local implementation commits: `57699a6 fix: share canonical application validation and review rules`; `d10b2b3 feat: show application corrections and field validation feedback`. This evidence update is a separate documentation commit. Nothing was pushed or merged by the agent.

**Next recommendation:** [B03: independent seller-shop review](admin-plan/03-shop-approval-eligibility.md), after user-managed publication/review of B02 and an updated `main`. Stop B02 here; do not start B03 automatically. The October 4-November 20 delivery window remains unchanged.

### B03 Independent Shop Review and Eligible Context: October 5, 2026

**State: B03 implemented and verified on `admin/shop-approval-eligibility`; Phase 0 and the wider admin flow remain partial.** Work started from clean, updated `main` at the user-merged B02 commit `3c13db5`. The scope follows [B03](admin-plan/03-shop-approval-eligibility.md); the preceding B02 recommendation describes its earlier checkpoint.

Before this change, extra-shop creation could immediately activate and select a shop using invented contact defaults, missing shop context could create or select an unreviewed fallback, and a missing category root could remove product scope. Additional shops now require explicit canonical name, phone, address, city, an active canonical master root, and their own private business permit. Creation saves a pending shop without switching the seller's selection. Pending, rejected, and legacy shops can submit a current version for review; reviewed identity/category changes require the separate B08 correction workflow.

Shop review and account review/activity remain separate. Platform Admin inspects the seller's current private identity evidence and the particular shop's permit in the current session before granting approval. The submission token binds canonical shop and seller identity, birth date, master-category state, private evidence hashes, and a monotonic submission version. Changed details, evidence, category, or same-second resubmission invalidate a stale review. Review decisions retain actor, time, reason, evidence, category, before/after state, and their actual original-KYC source when applicable. Database and model guards prevent changing or deleting this history. An identical completed request returns its original decision; competing or changed requests conflict. A new original-account decision records only the actual original shop and leaves extra shops untouched; existing account-decision retries do not fabricate shop provenance.

Approval never clears independent account/shop restrictions. Activity and review are displayed separately, and pending activity becomes active only when the owner can access the seller portal. An approved review on an inactive or suspended shop does not grant new work. Legacy activity alone is insufficient proof of approval.

Seller context now re-reads ownership, account approval/activity, shop review/activity, the actual current shop decision, canonical reviewed details, and an eligible master root. Explicit stale, deleted, malformed, and foreign session IDs deny access without creating or selecting a replacement. When no selection exists, only an owned eligible shop can be selected; otherwise the seller reaches the Shops page. Product writes, stock, voucher mutations, and routine branding use a locked shop transaction; branding cannot silently change reviewed contacts or category. Product categories must belong to the active root or an active supported descendant, including active ancestors. Products with retained order, review, or Shopping Bag references are archived rather than losing that history through deletion.

Public catalog/storefront/product/SEO entry points, adding or updating a Shopping Bag item, and new checkout share the positive shop/product/category policy. Checkout locks and revalidates selected inventory and its sellers/shops before committing orders, stock, routes, discounts, and Bag changes. Eligibility or later-write failure rolls the transaction back. The seller mutation transaction encloses the actual controller write: a routing middleware transaction was insufficient because Laravel can render an exception before middleware sees it. A rendered-500 rollback test verifies the chosen service callback.

The seller Shops page exposes real details, separate review/activity states, feedback, private evidence, previous decisions, permitted resubmission, and eligible selection. Platform Admin has a paginated pending/legacy review queue, canonical details and current age, private inspection links, approval/rejection, and retained decision history. Owned existing orders remain readable through an explicit shop-history link without changing the active session; fulfillment controls remain unavailable for an ineligible shop. B03 does not add restriction/reactivation or recovery controls.

| Scoped B03 assessment | Before | After | Evidence and remaining limit |
|---|---:|---:|---|
| Independent shop approval and audit | 0/10 | 9/10 | Current private inspection, versioned review, immutable history, retry/conflict, and atomic rollback; simultaneous PostgreSQL review requests remain unverified |
| Positive seller/shop context and sale eligibility | 2/10 | 9/10 | Fresh owned context, current approval provenance, category boundaries, catalog/Bag/checkout checks, and no invented fallback; legacy shops deliberately require review |
| Seller/admin review feedback and status visibility | 2/10 | 8/10 | Actual details, separate states, feedback/history, owned-order reading, HTTP checks, and production build; browser/device usability was not tested |
| B03 implementation against its documented scope | 2/10 | 9/10 | All ten acceptance cases have focused coverage; controlled corrections, reasoned restrictions, and recovery remain their later branches |
| Admin specification quality | 9/10 | 9/10 | Existing contracts preserved; actual implementation, deployment, and verification limits are recorded here |
| Overall Platform Admin readiness | 5/10 | 5/10 | Other resource, buyer-entry, restriction, closure, recovery, notification, metrics, and finance branches remain incomplete |

Implementation references: [ShopReviewService.php](../app/Services/ShopReviewService.php), [ShopEligibilityService.php](../app/Services/ShopEligibilityService.php), [ShopReviewDecision.php](../app/Models/ShopReviewDecision.php), [shop-review migration](../database/migrations/2026_10_05_000001_add_independent_shop_reviews.php), [SellerShopController.php](../app/Http/Controllers/Seller/SellerShopController.php), [AdminShopReviewController.php](../app/Http/Controllers/Admin/AdminShopReviewController.php), [private shop-document controller](../app/Http/Controllers/ShopVerificationDocumentController.php), [CheckoutOrderService.php](../app/Services/Orders/CheckoutOrderService.php), [seller Shops page](../resources/js/Pages/Seller/Shops.tsx), [admin Shop Reviews page](../resources/js/Pages/Admin/ShopReviews.tsx), [ShopReviewTest.php](../tests/Feature/Admin/ShopReviewTest.php), and [ShopEligibilityTest.php](../tests/Feature/Seller/ShopEligibilityTest.php).

Verification:

- Three initial regression checks demonstrated the unsafe fallback, stale-session handling, and seller-posted approval flags. The final focused set passed **718 tests with 5,652 assertions**, including **59 B03 checks with 470 assertions**: 24 shop-review cases and 35 shop/context/commerce cases. Coverage includes canonical submission and ownership; private current/historical document scope; reviewer/session binding; original-shop isolation; changed category, evidence, owner identity and birth date; same-second resubmission; identical/conflicting retries; separate restrictions; immutable database history; late-write and rendered-exception rollback; preserved existing orders; both portal entry points; active category ancestry; public catalog URLs; and direct Bag/checkout calls.
- The fresh baseline ran **1,470 tests with 9,162 assertions**; the final full run ran **1,529 tests with 9,569 assertions**. Both have exactly the same **58 failures and one error**, compared by failing test identity and failure/error exception type, with no new, resolved, or changed-type failures. The error remains `ChallengerM1StressTest::test_standard_product_without_variants`; its older checkout fixture still lacks required checkout data. The full suite remains red, so wider release readiness is not certified.
- The TypeScript/Vite production build, Laravel Pint for all **54 changed/new PHP files**, and whitespace checks passed. Positive inventory/checkout/portal fixtures explicitly supply synthetic shop-review provenance and valid category scope; default shop factory records remain unreviewed legacy shops. Test assertions were not skipped to bypass review. Runtime seed identities and passwords were not changed.
- PHP suites ran sequentially with explicit testing/database/cache/session/mail overrides and isolated SQLite `:memory:`. Source lock ordering was inspected: shop review locks users in ID order, then shop and categories; product mutations and commerce use users, shops, categories, then products in ID order. Checkout first owns/locks its Bag and lines. Failure simulation and lock inspection do not prove simultaneous PostgreSQL requests. No browser/device tests were performed.

Deployment and legacy limits:

- Deploy the additive [shop-review migration](../database/migrations/2026_10_05_000001_add_independent_shop_reviews.php) before using these paths. It adds review fields, immutable decisions, restrictive history references, and PostgreSQL/SQLite immutability guards; it does not infer decisions from existing activity flags or rewrite old shops/products/orders.
- The migration was applied successfully to the verified local PostgreSQL application. Before/after counts remained **9 users, 1 shop, 17 products, and 2 orders**; the new decision table has **0 records** and the eligible-shop scope returns **0 shops**. The existing original shop remains an explicit legacy candidate rather than receiving fabricated approval. This verifies additive schema application and the PostgreSQL eligibility query, not whole-record checksums or concurrent transactions. No database reset or destructive seeding occurred.
- Existing legacy storefronts/products cannot accept new commerce until the shop has a valid root, canonical details, actual private evidence, and a recorded review. A seller with missing reviewed birth-date/identity prerequisites needs B08 controlled repair before a new approval; B03 does not invent those facts or bypass adult readiness. Owned existing order records and historical references are retained. Account-level restriction exceptions and recovery remain B05-B07 responsibilities.
- B06/B07 reasoned restriction/reactivation, B08 reviewed identity/category repair, B10 product moderation, company/facility/assignment eligibility, buyer-entry alignment, and the older full-suite failures remain separate work. B03 completion does not finish Phase 0.

Local implementation commits: `67446de feat: enforce independent shop review and sale eligibility`; `659ffd0 feat: show shop reviews and eligible seller selection`. The final evidence commit has subject `docs: record B03 verification and readiness`; its generated hash is reported in the branch handoff. Nothing was pushed or merged by the agent.

**Publication recommendation:** B03 is ready for user-managed push/review within this verified scope, with the legacy-commerce hold and unchanged red full suite disclosed. After user-managed merge and an updated `main`, the next bounded branch is [B04: company/facility/assignment eligibility](admin-plan/04-logistics-resource-eligibility.md). Stop here; do not start B04 automatically. The October 4-November 20 delivery window remains unchanged.

### B04 Logistics Resource Eligibility and Company Scope: October 5, 2026

**State: B04 implemented and verified on `admin/logistics-resource-eligibility`; Phase 0 and the wider admin flow remain partial.** Work started from clean `main` at the user-merged B03 commit `a67864c`. The scope follows [B04](admin-plan/04-logistics-resource-eligibility.md).

Before this change, resource selectors and actions used different checks. An unassigned logistics account could read the global fleet, a stale facility selection could fall back to another facility, active children could bypass a restricted parent, company ownership could authorize floor scans, and cached rider relationships could survive a change in owner approval. Five focused reproductions failed before implementation.

The shared eligibility service and model scopes now apply this positive matrix to facility context, private network reads, handler/courier placement, fleet assignment, checkout route selection, pickup claims, final-rider dispatch, custody transitions, duty activation, and operational messages:

| Resource | Current eligibility required |
|---|---|
| Company | Active flag and active status; active approved/legacy verified logistics owner; exactly one company for the existing single-company account relationship |
| Facility | Active, supported Bayan/Mother tier, and eligible owning company |
| Handler | Active assignment and eligible logistics account; the assignment's retained company agrees with the facility's current company |
| Courier | Active approved/legacy verified courier; eligible assigned company/facility with matching ownership; a linked fleet vehicle agrees with the same company, facility, and driver |
| Fleet | Active status, one of the four existing supported vehicle types, matching eligible company/facility, and either no assigned driver or an eligible matching courier/profile |
| New rider work | Operational placement and on duty; current pickup capacity and phase/busy-work rules also pass |

Known worker birth dates must be adult and valid. The existing reviewed-worker compatibility for missing birth dates remains until B08 controlled repair. Existing approved personal-vehicle profiles without a fleet link remain supported; placement cannot remove an existing fleet link to bypass a restriction. These scopes do not approve legacy accounts or rewrite resource restrictions.

Company administrators can manage only their eligible network and assign a final rider at the expected destination Bayan Hub. Physical scans and counter release require an actual active handler assignment, including for company owners. Platform Admin oversight does not grant floor authority. Explicit stale, foreign, malformed, or unavailable facility selections deny access without changing the previous session or selecting a replacement. Unassigned accounts receive empty scoped resources, not a global registry. Foreign parcel references and mismatched driver relationships cannot expose another network's private records.

The existing code had no handler/courier placement endpoint or form. A narrow company-owned placement action now exists on both portal variants, with an exact approved-account email, eligible target facility, matching fleet selection, and current-source check. The Network page exposes that form and displays the scan action only for handlers. Changed courier placement goes off duty until the courier explicitly activates it. Placement does not change KYC, roles, parcel custody, checkpoints, payments, or commission. Existing active parcel work, inactive handler placements, foreign/ambiguous scope, and invalid barangay coverage deny changes. Identical retries retain the original record; late history/fleet/checkpoint failures roll the transaction back.

Off duty excludes new work while narrowly authorized existing pickup/final-mile actions continue. A suspended account, company, facility, or linked vehicle cannot be restored by toggling duty. Owned recorded messaging history remains readable during a resource pause, with operational sends disabled; a currently suspended account cannot read it. Buyer's own receipt confirmation remains available for an already delivered order when the logistics company becomes restricted. Recovery responsibilities remain B06/B07/B14.

Routing re-reads the complete eligible company and all Bayan/Mother legs before committing. Missing or changed eligibility rolls back without a partial route; required Mother-Hub custody remains. Routing cannot replace an assigned route or retained custody evidence. Placement history retains actor, subject, company, before/after state, and recorded time, with model and PostgreSQL/SQLite guards against update/delete. The additive handler company stamp preserves the original assignment's company so reparenting a facility cannot silently transfer handler authority. Legacy placements get their existing known company only, with no fabricated approval or decision history.

| Scoped B04 assessment | Before | After | Evidence and remaining limit |
|---|---:|---:|---|
| Company/facility/resource eligibility and action agreement | 4/10 | 9/10 | Current positive ownership/parent checks, private reads, direct calls, route rollback, and both portals; simultaneous PostgreSQL concurrency remains unverified |
| Company placement controls and feedback | 2/10 | 8/10 | Authorized Network form, validation feedback, source-state checks, immutable history, and production build; browser/device usability was not tested |
| B04 implementation against its documented scope | 4/10 | 9/10 | All ten acceptance cases covered; reasoned restrictions and controlled recovery remain later branches |
| Overall Platform Admin readiness | 5/10 | 5/10 | Buyer entry, restrictions, closure, recovery, notifications, metrics, and finance remain incomplete |
| End-to-end cross-role flow | 7.5/10 | 7.5/10 | Resource safeguards improve; manifests, attempts/RTS, secure self-pickup, COD, and settlement still need their own acceptance gates |

Implementation references: [shared eligibility](../app/Services/Logistics/LogisticsEligibilityService.php), [placement service](../app/Services/Logistics/LogisticsPlacementService.php), [placement record](../app/Models/LogisticsPlacementRecord.php), [workstation controller](../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php), [routing](../app/Services/Logistics/LogisticsRoutingEngine.php), [custody service](../app/Services/Logistics/OrderStateMachineService.php), [courier operations](../app/Services/Courier/CourierOperationsService.php), [Network page](../resources/js/Pages/Hub/Network.tsx), and [B04 acceptance tests](../tests/Feature/Logistics/LogisticsResourceEligibilityTest.php).

Verification:

- All **60 B04 checks with 796 assertions** passed. The final full run includes **686 passing affected tests with 6,137 assertions** across authorization, courier, logistics, and the logistics seed baseline. Coverage includes the ten acceptance cases, positive parent/child states, cached and changed eligibility, foreign references, frozen handler scope, duty/phase/capacity agreement, separate KYC, active-work protection, both portals, unchanged custody on placement, route rollback, immutable model/database history, and late-write rollback.
- A separate final regression set passed **65 tests with 812 assertions**. Four older pickup fixtures required a genuinely approved company owner, and the unknown-barcode test required a real active handler before testing the lookup. Their success/404 assertions were strengthened; no acceptance tests were skipped or deleted.
- The fresh baseline ran **1,529 tests with 9,569 assertions**. The final full run ran **1,589 tests with 10,372 assertions**. Both have exactly the same **58 failures and one error**, compared by test identity and failure/error exception type, with no new, resolved, or changed-type failures. The error remains `ChallengerM1StressTest::test_standard_product_without_variants`. The full suite remains red and does not certify wider release readiness.
- The TypeScript/Vite production build, Laravel Pint for all **28 changed/new PHP files**, whitespace checks, and public-content/reachable-history privacy checks passed. Verified demo identities and passwords remain unchanged. PHP suites ran sequentially with explicit testing/database/cache/session/mail overrides and isolated SQLite `:memory:`. No browser/device tests were performed.
- Lock ordering was inspected separately: order, parcel, accounts by ID, company, facilities by ID, profile/handler, then fleet by ID. Standalone messaging locks its parcel before the same network order and does not wait on an order row. Placement serializes actor/subject accounts before changing profile/fleet. Failure simulation and SQLite checks do not prove simultaneous PostgreSQL behavior.

Deployment and legacy limits:

- Apply the additive [placement-history migration](../database/migrations/2026_10_05_070000_create_logistics_placement_records_table.php) and [handler-company migration](../database/migrations/2026_10_05_070100_preserve_handler_company_scope.php) before using these paths. They add immutable placement records and a retained company reference; they do not reset the network or infer account/company approval.
- Both migrations were applied successfully to the guarded local PostgreSQL application. Before/after counts and hashes of original non-secret columns across **24 existing domain tables** matched, including **9 users, 1 company, 3 facilities, 3 handlers, 3 vehicles, 2 courier profiles, 2 orders, 2 parcels, and 11 checkpoints**. All handler stamps match their existing facility's company, and the new history table has **0 records**. The PostgreSQL immutability trigger exists. Current positive queries return **1 company, 3 facilities, 3 handlers, 2 couriers, and 3 vehicles**. This verifies additive schema/query compatibility and record preservation, not concurrent requests or a full live lifecycle. No reset or destructive seeding occurred.
- Invalid, ownerless, ambiguous, inactive, or foreign legacy scope is excluded from new work without deleting account, route, or custody evidence. Explicit review/repair and reasoned restriction/reactivation remain B06-B08. Existing assigned work blocked by resource suspension needs its documented controlled recovery, not an automatic placement or duty override.
- Manifest custody, delivery attempts/RTS, secure self-pickup, COD/remittance/settlement, and truthful admin finance remain outside B04. Placement is administrative scope and does not establish physical parcel or cash transfer. The older full-suite failures remain separate unresolved work.

Local implementation commits: `317ab13 feat: enforce logistics resource eligibility and placement`; `62ea25d feat: add company personnel placement controls`. The evidence commit has subject `docs: record B04 verification and readiness`; its generated hash is reported in the branch handoff. Nothing was pushed or merged by the agent.

**Publication recommendation:** B04 is ready for user-managed push/review within its verified scope, with the unchanged red full suite and concurrency/UI limits disclosed. After user-managed merge and an updated `main`, the next bounded branch is [B05: buyer access alignment](admin-plan/05-buyer-access-alignment.md). Stop B04 here. The October 4-November 20 delivery window remains unchanged.

## Buyer Access Alignment Review (October 5, 2026 - B05)

Implemented [B05](admin-plan/05-buyer-access-alignment.md) on `admin/buyer-access-alignment`, based on updated `main` at `88ed187` with B01-B04 merged. B02's canonical validation and evidence-aware review are included. The remaining admin plans were reviewed for dependencies and exclusions; this branch stops at buyer access alignment.

Password and OAuth sign-in now choose the same current permitted destination. Unreviewed buyers reach their own application screen; reviewed inactive/suspended buyers reach their existing orders. Unknown state denies protected access, and an unapproved sign-in cannot follow a saved checkout destination. Email verification, registration, and ID upload do not grant platform approval.

The holding screen shows current activity, identity status, feedback, and authorized private evidence. Buyers may correct their own unreviewed canonical application and replace their ID while waiting for review. Birthday remains optional without the worker age minimum. Submission rechecks the locked account, preserves activity restrictions and old evidence, and invalidates a previously inspected application version. Reviewed identity cannot be changed through this application path.

New buyer work requires an active approved account across Shopping Bag, checkout, profile/address, messages, disputes, reviews, vouchers, and assistance routes. Shared profile/chat paths and direct checkout calls also re-read eligibility. Bag mutations recheck after account locks; checkout retains current server prices, stock validation, owned selections, and its transactional eligibility check. Public catalogue and existing guest Bag preview remain available without guest checkout permission.

Owned existing orders now have a separate paginated view with recorded PHP amounts, without the profile's address or wallet sections. Reviewed inactive/suspended buyers can track their own orders and confirm only a physically delivered parcel. This grants no new purchase or general portal access. Receipt confirmation locks order, parcel, then current buyer; foreign, non-buyer, premature, and explicit authorization-denial attempts preserve history. Identical completion retries retain one buyer checkpoint. Admin oversight remains read-only and never confirms for the buyer.

The `buyer.existing-orders` authorization gate enforces an explicit server policy denial on lists, details, receipt requests, and direct lifecycle calls. It is the denial boundary for this branch; a persisted, reasoned security/restriction decision workflow and its UI remain B06. No such decision record or recovery workflow is claimed here.

| B05 acceptance case | Verified evidence |
|---|---|
| Public catalogue | Guest catalogue works; holding/checkout require authentication and approval. |
| Pending/rejected buyer | Owned holding and private correction work; private portal and foreign application/evidence access deny. |
| Approved active buyer | Existing checkout/inventory suites pass with owned items, server totals, and stock rules. |
| Password and OAuth | Ten account/KYC combinations share destinations and retain current eligibility. |
| Suspended new purchase | Direct route/service attempts deny; stock, Bag items, orders, and parcels remain unchanged. |
| Owned tracking | Root/subdomain list/detail aliases return only owned records with stable pagination. |
| Delivered receipt | Owned delivered completion and retries pass; premature, foreign, admin, worker, and non-buyer attempts deny. |
| Security denial | The explicit gate denial reaches every existing-order path and preserves order/checkpoint history. |
| Changed application/stale actor | Stale review conflicts, fresh review preserves suspension, and current state governs checkout, receipt, shared data, and private evidence. |
| Both URL variants/private files | Root/subdomain authorization, ownership, private storage/cache headers, and restriction checks pass. |

| Scoped B05 assessment | Before | After | Evidence and remaining limit |
|---|---:|---:|---|
| Buyer approval, holding, and existing-order access | 3/10 | 9/10 | All ten scoped cases covered; simultaneous PostgreSQL concurrency is unverified. |
| Holding and restricted-order interface | 2/10 | 8/10 | Useful correction, feedback, real owned records, and production build; browser/device usability was not tested. |
| Overall Platform Admin readiness | 5/10 | 5/10 | Restrictions, controlled repair/closure, moderation/audit, overview, notifications, and finance retain their own gates. |
| End-to-end cross-role flow | 7.5/10 | 7.5/10 | Buyer access improves; custody, recovery, notification, and accounting gaps remain separate. |

Implementation references: [buyer eligibility](../app/Services/BuyerAccessService.php), [route middleware](../app/Http/Middleware/EnsureBuyerAccess.php), [application submission](../app/Services/KycSubmissionService.php), [private documents](../app/Services/VerificationDocumentService.php), [owned orders](../app/Http/Controllers/Buyer/OrderHistoryController.php), [receipt service](../app/Services/Orders/OrderLifecycleService.php), [holding page](../resources/js/Pages/Auth/BuyerApproval.tsx), [order access layout](../resources/js/Layouts/BuyerOrderAccessLayout.tsx), and [B05 acceptance tests](../tests/Feature/Buyer/BuyerAccessAlignmentTest.php).

Verification:

- Four focused cases failed against the base: buyer holding, the intended sign-in destination, unapproved Bag creation, and receipt completion from a stale unknown account. The final run passes all **58 B05 tests with 2,035 assertions**.
- A separate regression run passed **261 tests with 3,336 assertions**. After strengthened stock and root/subdomain receipt checks, the final full run includes the same **261 passing regression cases with 3,363 assertions**. The named checkout, inventory, shared-portal, and privileged-access suites also pass: **155 tests with 1,432 assertions**. These sets overlap.
- The broader Auth/Admin/Buyer/shared-profile selection contains **789 tests with 7,327 assertions** and **four unchanged baseline failures**: two saved/new-address checkout cases in `BuyerAddressTest`, and two selected/all-item cases in `BuyerCartSelectionTest`. Their older requests omit required canonical shipping/selection fields; they remain B13 triage, and were not suppressed.
- The fresh baseline ran **1,589 tests with 10,372 assertions**; the final full run ran **1,647 tests with 12,426 assertions**. Both retain exactly **58 failures and one error**, compared by full test identity, failure/error kind, and exception type. No new, resolved, or changed-type failures appeared. The error remains `ChallengerM1StressTest::test_standard_product_without_variants`. The full suite is red; Phase 0 and wider release readiness remain incomplete.
- The TypeScript/Vite production build, Laravel Pint across **31 changed/new PHP files**, whitespace checks, and tracked-content/reachable-history privacy checks passed. Tests used sequential runs with explicit testing/database/cache/session/mail overrides and isolated SQLite `:memory:`. No browser/device tests were performed.
- Lock ordering was inspected separately. Bag/checkout mutations serialize the Bag and applicable item/account/product records; actor accounts are locked in ID order with seller eligibility. Receipt confirmation follows order, parcel, then account, matching parcel mutations. Application correction locks the account before its current profile/evidence state. SQLite rollback tests do not prove simultaneous PostgreSQL row locking.

Deployment and remaining limits:

- No migration, database reset, seed change, or legacy approval rewrite is required. Existing account, address, Bag, order, parcel, evidence, and decision references are preserved. Existing reviewed `approved`/`verified` compatibility remains; previous purchases never establish approval.
- Retain the earlier private-file protection and legacy-file migration. Unreviewed buyers see only their own authorized evidence; reviewed restricted buyers gain no general identity-document permission through order access. Unknown activity/KYC state needs controlled review.
- Reasoned account/security decisions and recovery remain B06; resource restrictions remain B07; reviewed/legacy identity repair remains B08. Generic reviewed-profile identity repair, broader review/dispute rules, truthful wallet/finance data, and the existing full-suite failures are not certified by this branch. Wider Phase 0 acceptance remains B13.

Local implementation commits: `2424f65 feat: align buyer approval and existing-order access`; `cbb941c feat: add buyer holding and limited order screens`. The evidence commit has subject `docs: record B05 verification and readiness`; its generated hash is reported in the branch handoff. Nothing was pushed or merged by the agent.

**Publication recommendation:** B05 is ready for user-managed push/review within its verified scope, with the unchanged red full suite and concurrency/UI limits disclosed. After user-managed merge and an updated `main`, continue with [B06: account restrictions](admin-plan/06-account-restrictions.md). Stop B05 here; the October 4-November 20 delivery window remains unchanged.

## Account and Resource Restriction Review (October 5, 2026 - B06+B07)

Implemented [B06](admin-plan/06-account-restrictions.md) and [B07](admin-plan/07-resource-restrictions.md) on `admin/governance-restrictions`, from `main` at `16b502c` with B01-B05 merged. B06's focused acceptance gate passed before B07 implementation. Each task retains its own acceptance checks and logical commits; the final regression/build review covers the combined branch.

**State: Scoped restriction decisions verified; Phase 0 remains partial.** Account activity and resource activity have separate, reasoned decisions. A fresh authorized actor, a canonical plain-text reason, explicit work-review confirmation, and a server-generated source token are required. The token binds the current target, reviewed identity/parent scope, restriction versions, and affected work. Identical actor/action/reason retries return the original decision; conflicting retries or stale target/parent/work return a conflict without another mutation. Reactivation requires a separate decision and current eligibility.

The shared transaction locks the stable governance guard, affected orders/parcels, accounts in ID order, then company, facilities, profiles/handlers, fleet, shop, and categories where relevant. The work set and parent/assignment identity are checked again after locks. Activity/version, immutable before/after history, actor/time/reason, and affected-work responsibility commit together. Audit or responsibility-write failure rolls back all changes. The last eligible Platform Admin cannot be restricted; a self-restriction with another eligible admin records that reviewer as responsible. Restricting an admin with earlier recovery responsibility creates a new responsibility record while retaining the earlier record.

B06 supports buyer, seller, courier, logistics, and admin account restrictions without changing roles, KYC, duty, placement, child activity, stock, commercial states, or cash. Suspended buyers retain the narrow B05 owned-order/receipt exception. Worker reactivation checks current reviewed identity and profile/network structure without silently reactivating a separately restricted resource. Legacy approved/verified accounts with missing birth dates retain the earlier compatibility; invalid known dates and unknown states cannot bypass review.

B07 uses each resource's existing activity fields plus its own restriction version:

| Resource | Scope and retained behavior |
|---|---|
| Shop | Platform Admin; current seller account, independent reviewed identity/category, and local shop activity stay separate. Listings/commerce and direct seller fulfillment re-read positive eligibility. |
| Company | Platform Admin; status and active flag change together. Restoration requires an eligible logistics owner with one company and leaves facilities, assignments, fleet, and duty unchanged. |
| Hub | Platform Admin or eligible own Company Admin; existing active flag governs routing/facility work. The decision distinguishes suspension from deactivation without adding another activity flag. |
| Handler assignment | Platform Admin or eligible own Company Admin; original account/company/facility remain fixed. Reactivation requires the approved handler account and matching eligible company/facility. |
| Fleet | Platform Admin or eligible own Company Admin; linked operational eligibility requires supported vehicle kind and matching company/facility/driver/profile. Reactivation restores a recorded active/idle/maintenance mode; idle and maintenance stay unavailable. Legacy restrictions without a recorded mode require an explicit valid activation and are described as such. |

Current orders, parcel route/assignment, checkpoint actors and hubs, proof presence, and available manifest references are retained with expected COD/payment/commission context. These are review records, not physical handovers. Cash holder and reconciliation remain explicitly unverified: payment labels and commission rows cannot prove cash custody. Feeder/linehaul manifest references have no durable vehicle link yet, so fleet review identifies company/facility manifest work for attention without claiming that the restricted vehicle carried it. Full manifests and recovery retain their later phase boundaries.

Company Admin can review and restrict only its own hub, handler, and fleet resources, including locally inactive resources. It cannot decide account/shop/company activity, lift platform restrictions, read foreign resource history, or view seller settlement rows. Malformed foreign-parent assignments are withheld from company review and remain for controlled platform repair. Handler-only and unplaced accounts receive no governance permission. Restriction history has database and model guards against update/delete; referenced account/resource deletion and parent cascades cannot erase recorded subjects. Migration rollback refuses to remove retained history/version safeguards after decisions exist. General closure safety remains B09.

The account review and resource review screens show activity, approval/parent eligibility, affected work, reasoned actions, and recorded history separately. Conflict responses require reloading the current review before resubmitting. Root and subdomain endpoints share services while links keep the current portal. Company resource navigation remains available when every facility is paused; floor-handler accounts do not gain company controls. No custody/reassignment/finance-success controls were introduced.

| B06 acceptance case | Verified evidence |
|---|---|
| All-role suspension/deactivation | All five roles on root/subdomain; role and approval unchanged. |
| Invalid reason/action/state | Canonical reason boundaries, unsafe text, malformed source, unknown state, missing confirmation, and role/status injection deny without mutation. |
| Identical retry | Same decision returned, including an old restriction retry after valid reactivation. |
| Stale decision | Current target, parent scope, and work are compared; changed work conflicts. |
| Active work/cash | Buyer, seller, courier, company owner, handler, and prior admin responsibility scopes retain only affected records and existing custody/money facts. |
| Buyer receipt exception | Owned delivered completion remains available; foreign orders and new purchase deny. |
| Separate reactivation | All five roles; invalid approval/age/profile denies; independent resource restrictions and duty persist. |
| Inactive reviewer | Fresh route/service authorization denies a cached restricted admin. |
| Last eligible admin | Pending/underage admins cannot count as replacements; sequential competing decisions preserve eligible oversight. |
| Audit/recovery failure | Both write failures roll back activity/version/history/work. |
| Fixed roles | Posted role conversion denies; no role-conversion action. |

| B07 acceptance case | Verified evidence |
|---|---|
| Every resource type | Reasoned independent suspension/deactivation, each on both admin portals, stops positive eligibility. |
| Restricted parent | Parent activity/approval, reviewed shop/category, handler membership, and linked fleet/driver gates deny bypasses. |
| Parent reinstated | Company/hub restoration leaves restricted children and off-duty rider placement unchanged until valid separate decisions. |
| Company ownership | Each own resource on root/subdomain passes; foreign, company-level, shop, and floor-handler attempts deny. |
| Active parcel/manifest/cash | Existing order, parcel, stock, checkpoint, proof and ledger attributes remain identical; responsibility and unverified cash context persist. |
| Reason/unknown source | Malformed/injected inputs and unknown string/boolean states deny without mutation. |
| Stale target/parent | Changed work and company/account restriction-reactivation version cycles invalidate old sources. |
| Identical/competing retry | One original decision; different actor/reason/action cannot overwrite or reapply it. |
| Audit/recovery failure | Atomic rollback for both failed history and failed affected-work writes. |
| Portal/service agreement | Root/subdomain review/actions, selectors, handler scans, linked-fleet pickup, routing, and direct seller fulfillment enforce current scope. |

| Scoped assessment | Before | After | Evidence and remaining limit |
|---|---:|---:|---|
| Reasoned account restriction and admin continuity | 2/10 | 9/10 | All B06 cases; simultaneous PostgreSQL requests remain unverified. |
| Independent resource restriction and parent scope | 4/10 | 9/10 | All B07 cases; durable vehicle-manifest linkage and operational recovery remain later work. |
| Restriction review interface | 2/10 | 8/10 | Real activity, parent/work context, conflict handling, and production build; browser/device usability was not tested. |
| Overall Platform Admin readiness | 5/10 | 5/10 | Controlled repair/closure, moderation, audit search, overview, notifications, and finance retain their own gates. |
| End-to-end cross-role flow | 7.5/10 | 7.5/10 | These decisions preserve work; they do not complete custody recovery, self-pickup, cash reconciliation, or settlement. |

Verification:

- Final restriction gate: **190 tests with 1,366 assertions**, comprising **75 B06 tests with 445 assertions** and **115 B07 tests with 921 assertions**. Coverage includes source/parent/version cycles, retries, rollback, all resource/role scopes, preservation of work, and raw-write/cascade history guards.
- The earlier combined regression selection passed **660 tests with 7,333 assertions**. The final full run contains the same affected suites plus the added guard/scope cases: **675 passing tests with 7,455 assertions** across account/resource decisions, shared access/fixed roles, courier operations, logistics resource/hub/custody, shop review/eligibility, seller fulfillment, and buyer access. These sets overlap.
- Fresh pre-batch baseline: **1,647 tests with 12,426 assertions**. Final full run: **1,837 tests with 13,792 assertions**. Both retain exactly **58 failures and one error**, compared by full test identity, failure/error kind, and exception type. No new, resolved, or changed-type failures appeared. The error remains `ChallengerM1StressTest::test_standard_product_without_variants`. The full suite is red and is not a release pass.
- TypeScript/Vite production build passed through Docker. The host attempt passed TypeScript but could not replace a generated assets directory because of filesystem ownership; the repository's Docker equivalent resolved the build. Laravel Pint passed for **24 changed/new PHP files**. Whitespace, documentation links, and tracked-content/reachable-history privacy checks passed. Tests used explicit testing/database/cache/session/mail overrides and isolated SQLite `:memory:`; no browser/device testing occurred.
- All three additive migrations applied to the guarded local PostgreSQL application. Before/after counts and hashes of original non-secret columns across **25 existing domain tables** remained identical. All restriction versions started at zero, new decision/work tables stayed empty, and **eight database history/reference guards** exist. Existing eligible counts remain **one company, three hubs, three handlers, two couriers, and three vehicles**. No database reset, seed rewrite, activity change, or synthetic live decision was performed.
- Lock order and re-read behavior were inspected separately; SQLite and additive local migration checks do not prove simultaneous PostgreSQL concurrency.

Deployment and remaining limits: apply the three October 5 restriction migrations to another environment before using these decisions. Retain private-file protections and earlier evidence migrations. Existing restrictions without recorded decisions are labeled legacy, never assigned an invented reason/actor/date. Controlled repair and general closure remain B08/B09; broad audit search and overview remain B11/B12; B13 still owns Phase 0 acceptance. Manifest/custody recovery, RTS, self-pickup, notifications, cash custody/reconciliation, and settlement are not completed by this batch.

Local commits: `37daa3c docs: group account and resource restriction delivery`; `909c38d feat: add reasoned account restrictions and admin continuity`; `b8bf349 feat: add account activity review and affected-work context`; `ad60fe6 feat: add independent resource restrictions and retained history`; `f3e6d36 feat: add scoped resource activity review screens`. The evidence commit has subject `docs: record B06 and B07 verification and readiness`; its generated hash is reported in the handoff. Nothing was pushed or merged by the agent.

Implementation references: [account decisions](../app/Services/AccountRestrictionService.php), [resource decisions](../app/Services/ResourceRestrictionService.php), [history guards](../database/migrations/2026_10_05_120100_preserve_restriction_history.php), [account review](../resources/js/Pages/Admin/AccountRestriction.tsx), [resource review](../resources/js/Pages/Governance/ResourceRestriction.tsx), [resource list](../resources/js/Pages/Governance/Resources.tsx), [B06 tests](../tests/Feature/Admin/AccountRestrictionTest.php), and [B07 tests](../tests/Feature/Admin/ResourceRestrictionTest.php).

**Publication recommendation:** B06+B07 are ready for user-managed push/review within the verified restriction scope, with the unchanged red full suite and concurrency/UI limits disclosed. After user review/merge and an updated `main`, [B08: controlled identity corrections](admin-plan/08-reviewed-identity-corrections.md) is the next bounded task. Stop this batch here; the October 4-November 20 window is unchanged.

## Reviewed Identity and Account Closure Review (October 6, 2026 - B08+B09)

Implemented [B08](admin-plan/08-reviewed-identity-corrections.md) and [B09](admin-plan/09-account-closure-safety.md) on `admin/identity-and-closure-safety`, from `main` at `7187e32` with B01-B07 merged. B08's focused gate and implementation commits preceded B09. This batch is predominantly backend services, migrations, and tests, with small admin/applicant/profile screens. It does not redesign the rider interface.

**State: Scoped correction and conservative closure verified; Phase 0 remains partial.** Reviewed corrections use separate immutable requests and decisions. A request records original/proposed canonical values, current identity version, reason, private evidence fingerprints, and a real prior-review reference or explicit legacy provenance. No old approval, birthday, or category is invented. Missing/invalid legacy birthday and category candidates come from actual records; administrators must review each proposed correction.

Approval requires a fresh eligible Platform Admin, current source/version/work, and every required evidence file inspected by that actor in the current session. Protected evidence responses enforce ownership/reviewer access, allowlisted content, SHA checks, and private no-store delivery. Stale evidence or new affected work invalidates review. Identical retries retain the original result; competing changes conflict. Applying canonical values, linked review, identity version, and append-only decision is atomic. Failure preserves original identity/history/files and removes only new orphan uploads after rollback.

Generic, buyer, seller, and courier profile writers cannot replace reviewed identity. Ordinary supported contact/email/avatar changes remain available. Corrections preserve fixed roles, independent restrictions, duty, placements, and active work. Corrected underage or missing-date workers lose new-work eligibility; the missing-date compatibility applies only to untouched legacy identity version zero. A shop-category correction requires actual linked review, leaves product categories and order snapshots intact, and cannot restore separately paused activity. Removing the final eligible admin's eligibility requires an actual eligible replacement, never a guessed identity.

B09 checks actual buyer, shop, rider, company/hub, handler, checkpoint, ledger, and recovery-responsibility references regardless of the account's nominal role. Schema-derived user foreign keys, polymorphic restriction subjects, and stored profile/private files contribute to the retention decision. The review exposes compact reference IDs/counts and explicit blockers rather than raw private records.

- **Blocked:** active/unknown orders, nonterminal custody, operating resources/placements, pending or missing proceeds, paid cancelled/returned work, and final eligible admin continuity. Every referenced COD order remains blocked because collection/remittance/reconciliation persistence is missing; completed, paid, settled, or refunded labels alone never prove cash is clear. Closure does not complete finance, transfer custody, cancel orders, or repair obligations.
- **Retained:** a referenced identity with no current blocker becomes inactive with `closed_at` set and `remember_token` cleared; `updated_at` changes and disposable database sessions for that subject are removed. Names, contact/email/address/birthday, role, KYC, profiles, duty, resource flags/placements, private documents/avatar, ownership, messages, orders, proofs, amounts, snapshots, and prior decisions remain. Completed non-COD work requires existing paid/refunded payment and settled/refunded ledger evidence. No personal-field anonymization or retention period is invented.
- **Deleted:** only an account without related records or stored files may be made inactive and deleted atomically. A unique immutable closure event retains numeric subject/actor IDs and copied role/name independently of the live user. Self-service deletion is limited to an otherwise eligible unused approved buyer; other cases require admin review. Matching peer-admin retries can return the event after deletion, while conflicting actor/source/reason retries fail.

Both services serialize through the existing stable governance guard, then lock affected orders, deliveries, and, for closure, commission ledgers before users/parent resources in deterministic order. They re-read work, identity, references, and source under locks. Last-admin decisions share this guard with restrictions. Direct model cascades cannot erase referenced accounts. Closed-account model/database guards, fresh-session middleware, sign-in/access/eligibility checks, and activity/KYC/correction checks prevent reactivation or stale-session use. Closure failure leaves the account authenticated and unchanged; self-service stale review shows a reloadable validation message.

The migrations preserve immutable history through SQL and model guards. Rollback refuses to erase recorded correction/closure history. SQLite rollback of B08 also refuses before DDL when earlier shop reviews exist: rebuilding the referenced table would otherwise disturb their SQL guards. Existing shop-review update/delete protections remain effective after that refused rollback. PostgreSQL's unreferenced user-delete trigger returns `OLD`; its definition was inspected separately.

| Acceptance area | Verified result |
|---|---|
| Canonical correction and explicit legacy review | Separate proposal/evidence, real prior review or legacy provenance, atomic before/after decision; invalid or missing required values cannot bypass validation. |
| Ordinary profile and private evidence boundaries | Reviewed identity edits and foreign files denied across relevant portals; supported contact/avatar edits preserve reviewed identity. |
| Stale inspection, retries, and rollback | Changed bytes/version/work conflict; identical retry preserves one result; audit/profile failures preserve original identity and files. |
| Restrictions, category, and worker eligibility | No role conversion, automatic reactivation, reclassification, duty/placement reset, or active-work destruction. |
| Role-wide work and cash matrix | Buyer/seller orders, rider/handler custody, company/hub obligations, ledger and recovery responsibility block with records intact; unknown COD remains explicit. |
| Closure outcomes and provenance | Unreferenced deletion and retained closure preserve the documented fields/references; event/retry identity survives live subject deletion. |
| Admin continuity and authorization | Fresh eligible reviewer, current password, canonical reason/source, root/subdomain authorization, and shared final-admin serialization. |
| New work, failed closure, and closed account | Work/source re-read catches changed responsibility; write failures roll back before sign-out; closed subjects cannot resume access or review/activity mutations. |
| Retained evidence and migration rollback | Raw writes/cascades cannot erase decisions or referenced identity; unsafe rollback refuses without removing older review guards. |

| Scoped area | Before | After | Basis and remaining limit |
|---|---:|---:|---|
| Reviewed identity corrections and legacy review | 2/10 | 9/10 | Evidence-backed requests, immutable decisions, eligibility/continuity checks, and rollback/retry coverage; actual legacy records still need individual review. |
| Account closure safety | 3/10 | 8/10 | All-role dependency checks, explicit retention/deletion outcomes, and closed-account guards; COD resolution and any future anonymization policy remain separate work. |
| Admin/applicant correction and closure feedback | 2/10 | 8/10 | Current evidence/work, blockers, conflict/reload, and recorded outcomes are visible; build/Inertia checks passed, without rendered/device review. |
| Overall Platform Admin readiness | 5/10 | 5/10 | Product moderation, audit search, overview, notifications, finance, and Phase 0 acceptance retain their own gates. |
| End-to-end cross-role flow | 7.5/10 | 7.5/10 | Preserved obligations do not complete custody recovery, self-pickup, cash reconciliation, or settlement. |

Verification:

- B08's pre-B09 gate passed **336 tests with 2,596 assertions**, including **41 correction tests with 456 assertions**. B09 adds **38 closure tests with 405 assertions**; its closure/profile selection passed **43 tests with 426 assertions**. Migration-retention and existing shop-review checks passed **28 tests with 229 assertions**, including **four new rollback tests with 12 assertions**. These selections overlap.
- The final full run contains **414 passing tests with 3,034 assertions** across corrections, closure, migration retention, account/resource restrictions, fixed roles, KYC decisions, courier closure, and generic profile behavior. Existing passing profile/avatar tests now preserve reviewed names, with separate denied-edit cases. The former courier-profile cascade test intentionally now asserts refusal and profile retention; that focused check passed with four assertions. No failing baseline cases were altered or skipped.
- Fresh pre-batch baseline: **1,837 tests with 13,792 assertions**. Final full run: **1,920 tests with 14,667 assertions**. Both retain exactly **58 failures and one error**, compared by full test identity, failure/error kind, and exception type. No new, resolved, or changed-type failures appeared. The error remains `ChallengerM1StressTest::test_standard_product_without_variants`. The full suite remains red and is not a release pass.
- TypeScript/Vite production build passed through Docker. Scoped Laravel Pint checks passed for the changed PHP files; whitespace, documentation links, and tracked-content/reachable-history privacy checks passed. Tests used explicit testing/database/cache/session/mail overrides and isolated SQLite `:memory:`. No browser/device testing occurred.
- Both additive migrations applied to the guarded local PostgreSQL application. Before/after counts and hashes of original non-secret columns across **28 existing domain tables** remained identical. Identity versions started at zero, no users were closed, new correction/closure tables remained empty, and **four new database guards** exist. Existing eligible counts remain **one company, three hubs, three handlers, two couriers, and three vehicles**. No reset, seed rewrite, synthetic live decision, or change to verified demo identities was performed.
- Lock ordering and PostgreSQL trigger definitions were inspected separately. SQLite tests and the additive local migration check do not prove simultaneous PostgreSQL concurrency; that environment-specific verification remains outstanding.

Deployment and remaining limits: apply the two October 5 correction/closure migrations, together with earlier governance prerequisites, before serving these screens elsewhere. Retain private-file protections and immutable evidence. Do not bulk-fill legacy birthdays/categories or use financial labels to force COD closure. Recovery, manifests, RTS, self-pickup, notifications, COD collection/reconciliation, and settlement retain their later phase boundaries. B10 owns product moderation, B11 searchable audit, B12 truthful overview data, and B13 Phase 0 acceptance.

Local commits: `a7653d4 docs: group reviewed identity and account closure delivery`; `c05967f feat: review identity corrections without losing work or evidence`; `fd440e0 feat: add identity correction requests and admin review screens`; `7755218 feat: protect account closure with retained history and obligations`; `4eb45e2 feat: show account closure blockers and retained outcomes`; `297cc04 fix: retain governance evidence during migration rollback`. The evidence commit has subject `docs: record identity correction and closure verification`; its generated hash is reported in the handoff. Nothing was pushed or merged by the agent.

Implementation references: [correction service](../app/Services/IdentityCorrectionService.php), [closure service](../app/Services/AccountClosureService.php), [correction migration](../database/migrations/2026_10_05_140000_create_identity_corrections.php), [closure migration](../database/migrations/2026_10_05_150000_create_account_closures.php), [admin correction screen](../resources/js/Pages/Admin/IdentityCorrections.tsx), [applicant correction screen](../resources/js/Pages/Governance/IdentityCorrection.tsx), [admin closure screen](../resources/js/Pages/Admin/AccountClosure.tsx), [correction tests](../tests/Feature/Admin/IdentityCorrectionTest.php), [closure tests](../tests/Feature/Admin/AccountClosureTest.php), and [rollback tests](../tests/Feature/Admin/GovernanceMigrationRetentionTest.php).

**Publication recommendation:** B08+B09 are ready for user-managed push/review within the verified scope, with the unchanged red full suite and concurrency/UI/financial limits disclosed. After user review/merge and an updated `main`, continue with [B10: product moderation](admin-plan/10-product-moderation.md). Stop this batch here; the October 4-November 20 delivery window is unchanged.

## Product Compliance, Governance History and Overview Review (October 6, 2026 - B10-B12)

Implemented [B10](admin-plan/10-product-moderation.md), [B11](admin-plan/11-governance-audit-viewer.md), and [B12](admin-plan/12-truthful-overview.md) on `admin/moderation-audit-and-overview`, from `main` at `1d53c70` with B01-B09 merged. B10's focused gate and commits preceded B11; B11's privacy/context gate and commits preceded B12. Business rules and read projections live in services, with small coordinating controllers and the corresponding admin, seller and Shopping Bag screens.

**State: Scoped implementation verified; Phase 0 remains partial.** Product compliance is a separate platform restriction, independent of the seller's active/draft/archived state. Removal and reinstatement require a fresh eligible Platform Admin, canonical reason, current signed source/version and an allowed transition. The decision retains actor/name, server time, before/after values and its actual prior decision. Product eligibility and immutable history commit together. Ordered parent/product locks, stale-state rejection and identical-retry identity preserve prices, stock, images, shop ownership and bought-item snapshots.

Reinstatement checks current seller, shop and category eligibility; it cannot activate a draft, revive an archived listing or clear an independent restriction. Seller writers cannot change platform flags. Restricted listings leave storefront/detail and direct checkout eligibility; existing Shopping Bag lines remain visible with unavailable feedback and cannot contribute to selected checkout totals. Owned seller feedback explains the recorded decision. Referenced products are archived or retained, with model/database guards preventing deletion of purchase or moderation evidence. The old blind toggle endpoint is removed. Hidden control characters reach reason validation rather than being silently removed by generic trimming.

B11 projects six existing immutable sources: KYC, shop review, account/resource restriction, identity correction, closure and product moderation. It preserves each source's recorded facts rather than synthesizing events from current status. Validated source/subject/actor/Philippine-date filters and deterministic pagination support review. Platform Admin sees the authorized platform scope; Company Admin sees only its own resource restrictions and cannot inspect account history or private evidence. Snapshot allowlists exclude credentials, tokens, raw document paths and plaintext claim codes. Historical document links reuse fresh authorization and content-hash checks. Prior-review links use the recorded KYC or shop decision, including legacy shop reviews without KYC.

Account context separates current fixed role, approval, activity, identity provenance, shops, company/hub placements, duty and affected-work references. Copied historical names remain distinct from current names. Deleted/closed subjects retain actual recorded audit facts; missing current identity or legacy review is explicit. History and context provide no edit, delete, reversal, export or custody action.

B12 replaces estimated fees and unsupported presence/success claims with recorded queues, all five role counts, exact stored lifecycle counts, eligible network counts, current restriction-related work and recent decisions/orders. The logistics registry has validated search/status filters and stable pagination; its bounded rider roster distinguishes stored duty, current network eligibility and unavailable online presence. Paid-order gross is an exact decimal sum of recorded order totals, explicitly separate from platform income, commission, reconciled COD and payouts. Money displays in PHP/₱ without losing cents or precision beyond JavaScript's safe integer range. Missing financial sources and later exception actions remain unavailable. Reads preserve orders, payments, ledgers, placements and duty; existing company dashboards retain their own scope.

| Plan acceptance areas | Verified behavior |
|---|---|
| B10 removal, malformed input, valid reinstatement and independent prerequisites | Recorded restrictions block new purchases; unknown/archived states, missing/control-character reasons, injected commercial fields and restricted parents fail without mutation. Valid reinstatement preserves seller state. |
| B10 seller bypass, bought items and referenced-product retention | Seller edits cannot clear compliance; existing order values/images remain; model/raw deletion and immutable-history mutation fail. |
| B10 identical/competing retries, audit failure and privileged URLs | Original retry returns one decision after a later transition; stale/opposite requests conflict; failed audit rolls back; both portal variants enforce fresh authority and remove the old toggle. |
| B11 mixed history, separate context and validated filters/pagination | Six actual sources retain distinct snapshots/provenance; current approval/activity/duty remain separate; malformed filters reject and equal-time pages do not overlap. |
| B11 company isolation, stale authority and private evidence | Own-resource-only company results; foreign/account/document access denied; fresh privilege and hash checks protect evidence; nested secrets never enter projections. |
| B11 legacy/closed subjects, read-only and empty/missing sources | Real prior shop-review links work without invented KYC; copied facts survive current-name changes/deletion; no mutation, synthetic history or fabricated current state. |
| B12 recorded/empty queues, finance availability, duty/presence and money | Actual state fixtures produce defined counts or truthful zeros; partial ledger data cannot establish finance totals; duty differs from online; decimal gross and PHP labels are exact. |
| B12 company scope, stale authority and unavailable actions | Foreign network/platform reads deny; inactive/stale admins deny on both portals; no false-success mutation endpoint. |
| B12 mixed lifecycle states and types/build | Exact stored statuses avoid alias-expanded totals; operational/recovery states stay distinct; tested response projections agree with frontend types and the production build. |

These ratings are scoped engineering assessments, not coverage percentages or rendered usability scores:

| Scoped area | Before | After | Basis and remaining limit |
|---|---:|---:|---|
| Reasoned product compliance and purchase eligibility | 2/10 | 9/10 | Independent restrictions, retention, retries, rollback and commerce checks; simultaneous PostgreSQL requests remain unverified. |
| Account context and retained decision history | 2/10 | 8/10 | Searchable real sources, current/historical separation, tenant scope and evidence privacy; unavailable legacy records remain unavailable. |
| Admin overview and logistics presentation | 3/10 | 8/10 | Recorded counts, exact money, labelled duty and unavailable finance replace unsupported claims; rendered/device review and later modules remain. |
| Overall Platform Admin readiness | 5/10 | 5/10 | B13 acceptance, exceptions, notifications, COD and source-backed finance retain their own gates. |
| End-to-end cross-role flow | 7.5/10 | 7.5/10 | This batch does not complete manifest custody, recovery, self-pickup, cash reconciliation or settlement. |

Verification:

- B10's pre-B11 gate passed **123 tests with 683 assertions** across moderation, shared privileged access, fixed roles and inventory checkout. B11's pre-B12 gate passed **365 tests with 3,268 assertions** across history/context, KYC, shared privilege, private documents, corrections, closure, shop review and resource restrictions. B12's overview/privilege/logistics gate passed **61 tests with 795 assertions**. These selections overlap.
- The final history selection passed **31 tests with 376 assertions**, including the recorded legacy shop-review link. The final moderation/shared-portal selection passed **168 tests with 1,567 assertions**, including hidden-control-character rejection. Existing portal tests now exercise reasoned moderation while retaining denial and no-mutation assertions; the removed unsafe toggle was not restored. The batch adds **92 tests**: 41 moderation, 31 history/context and 20 overview cases.
- The final full run contains **219 passing tests with 2,292 assertions** across the three new feature classes and shared portal access. The **92 new feature cases passed with 1,037 assertions**. These counts overlap the focused selections above.
- Fresh pre-batch baseline: **1,920 tests with 14,667 assertions**. Final combined run: **2,012 tests with 15,755 assertions**. Both retain exactly **58 failures and one error**, compared by full test identity, failure/error kind and exception type. No new, resolved or changed-type failures appeared. The error remains `ChallengerM1StressTest::test_standard_product_without_variants`; existing checkout fixtures and legacy route/state/settlement expectations remain red. This is not a green full-suite or release pass.
- The Docker TypeScript/Vite production build passed in **14.55 seconds**. Laravel Pint passed for **20 changed PHP files**, with later corrections checked again. Five direct frontend money-format checks passed, including negative, zero, missing and exact large decimal values. Whitespace, local documentation links and tracked-content/reachable-history privacy checks passed; the private local guide and verification artifacts remain ignored. Tests used explicit testing/database/cache/session/mail overrides and isolated SQLite `:memory:`. No browser/device testing occurred.
- The sole additive product-moderation migration applied to the guarded local PostgreSQL application. Before/after counts and hashes of original non-secret columns across **31 existing domain tables** remained identical. Existing products retain default unrestricted/version-zero state, the new decision table is empty, and **two new database guards** exist. Eligible counts remain **one company, three hubs, three handlers, two couriers and three vehicles**. Read-only history/overview/logistics projections executed against actual records. No PostgreSQL tests, database reset, seed rewrite or synthetic live decision occurred.
- Lock ordering and PostgreSQL guards were inspected separately. SQLite tests, additive migration and local read queries do not prove simultaneous PostgreSQL concurrency. The observed runtime is **PHP 8.4.24 and Laravel 13.32.0**; the repository instructions' Laravel 12 description is older than the installed framework. This batch did not upgrade the runtime.

Deployment and remaining limits: apply `2026_10_06_060000_create_product_moderation_decisions.php` with the earlier governance prerequisites before serving these screens elsewhere. Retain private-document protection and immutable prior decisions. Do not backfill legacy decision actors/reasons/times or populate missing finance with estimates. B13 owns the full Phase 0 gate; later exceptions, notifications, COD, settlement, custody recovery and financial reporting keep their existing prerequisites.

Local commits: `5ff8adb docs: group moderation audit and overview delivery`; `5dea328 feat: record reasoned product compliance decisions`; `78c92e9 feat: show product compliance reviews and purchase availability`; `bd8ccb7 feat: expose scoped read-only governance history`; `1775464 feat: add account context and decision history screens`; `1077c5c feat: report recorded admin queues and logistics counts`; `9cd5544 feat: show truthful admin overview and logistics availability`; `c0b49c7 test: check portal access through reasoned product moderation`; `df6abee fix: validate moderation reasons before generic trimming`; `e5c9d85 fix: link legacy identity history to recorded shop reviews`. The evidence commit has subject `docs: record B10 B11 and B12 verification and readiness`; its generated hash is reported in the handoff. Nothing was pushed or merged by the agent.

Implementation references: [moderation service](../app/Services/ProductModerationService.php), [moderation migration](../database/migrations/2026_10_06_060000_create_product_moderation_decisions.php), [history service](../app/Services/GovernanceHistoryService.php), [account-context service](../app/Services/AccountContextService.php), [overview service](../app/Services/AdminOverviewService.php), [moderation tests](../tests/Feature/Admin/ProductModerationTest.php), [history tests](../tests/Feature/Admin/GovernanceHistoryTest.php) and [overview tests](../tests/Feature/Admin/AdminOverviewTest.php).

**Publication recommendation:** B10-B12 are ready for user-managed push/review within the verified scope, with the unchanged red full suite and concurrency/UI/financial limits disclosed. After user review/merge and an updated `main`, continue with [B13: Phase 0 acceptance](admin-plan/13-phase0-acceptance.md). Stop this batch here; the October 4-November 20 delivery window is unchanged.

### B13 Phase 0 Acceptance and Failure Triage: October 6, 2026

**Decision: B13 audit and scoped repairs complete; Phase 0 acceptance remains incomplete.** Branch `test/admin-phase0-acceptance` starts from merged B01-B12 at `3db99d8` (`Admin/moderation audit and overview (#60)`). This is B13's bounded evidence/triage delivery under its stopping rule, with a negative phase decision. Passing governance checks and publishing this repair branch do not clear the remaining input, transaction, recovery, or financial gates. B14 has not started.

**User-visible repairs.** Buyer and seller dispute pages now explicitly report that disputes, exchanges and refunds are unavailable. Their submission/response endpoints are removed on root and applicable subdomain routes; requests cannot invent a saved case, response or pickup. The buyer profile no longer presents a sample wallet balance, invented transactions/account number or a simulated successful top-up. Checkout saving an address now uses the account name consistently with the profile address form, while preserving the submitted recipient in the immutable order/waybill snapshot.

**Requirements, entry points and evidence.** “Verified” below applies to the named paths and assertions, with documented admin/bootstrap and legacy exceptions; it does not certify every field or later workflow.

| Requirement | Executable entry points | Acceptance evidence / decision |
|---|---|---|
| Current access, fixed roles and permitted holding/existing-work exceptions | Role middleware, `User::canAccessPortal`, `BuyerAccessService`, courier operations; root and seller/courier/hub/admin subdomains | `SharedPortalAccessTest`, `BuyerAccessAlignmentTest`, `CourierPortalAccessTest`, `AccountRoleImmutabilityTest`, `WorkerOAuthPortalAccessTest`: verified for active reviewed, pending, rejected, inactive, suspended and unknown account/approval states. Four courier unknown-state cases were added. Controlled active admins retain their documented KYC exemption; it grants no floor scans or seller actions. |
| Adult worker identity and master categories | Registration/resubmission, `BirthDateEligibility`, application/KYC review, controlled identity correction | `WorkerBirthDateEligibilityTest`, `ApplicationValidationTest`, `SellerCategoryApprovalTest`, `IdentityCorrectionTest`: valid adults, underage/invalid dates, category ancestry and explicit legacy handling covered. Missing legacy birth dates are not invented. |
| Canonical application inputs and rollback | Registration, correction/resubmission, KYC and shop review; `ApplicationValidationService` | `ApplicationValidationTest`, `KycDecisionGovernanceTest`, `ShopReviewTest`: normalization and rejection of malformed text, contacts, IDs, dates, enums and categories verified for these entry points. **Broader gate incomplete:** checkout/saved-address/operational writers have not adopted that same contract; see the confirmed checkout probe below. |
| Independent approval, ownership and parent scope | Seller shop context/`ShopEligibilityService`; `LogisticsEligibilityService`, placement, routing, handler/rider/fleet actions | `ShopReviewTest`, `ShopEligibilityTest`, `LogisticsResourceEligibilityTest`, `ResourceRestrictionTest`: foreign IDs, stale selected shops/hubs, unassigned handlers/riders, invalid parents, separate shop decisions and parent reactivation covered. Account review cannot approve every shop or clear child restrictions/duty. |
| Current private evidence, review retry and audit retention | KYC/shop document controllers; KYC/shop/identity decision services; governance history | `KycDecisionGovernanceTest`, `ShopReviewTest`, `IdentityCorrectionTest`, `GovernanceHistoryTest`, `GovernanceMigrationRetentionTest`, `LegacyVerificationDocumentsTest`: reviewer-session inspection, content/version changes, foreign evidence, rollback, immutable original retries, stale conflicts and safe historical projections covered. Deployment must still apply the documented legacy-file protection/migration. |
| Restrictions and work-preserving closure | Account/resource activity decisions, identity correction and account closure; direct services and root/subdomain URLs | `AccountRestrictionTest`, `ResourceRestrictionTest`, `AccountClosureTest`, `CourierAccountClosureTest`: preserve custody/checkpoints, order snapshots, stock, cash labels, prior evidence and responsible recovery records; unresolved work/cash blocks closure. Existing payment/ledger labels are not reconciliation evidence. |
| Last eligible admin and competing decisions | Shared `platform-admin-continuity` guard; restriction/correction/closure services | Relevant restriction/identity/closure tests verify sequential competing decisions and fresh eligibility. Static inspection confirms guard first, then ordered work/parcel/account locks and rechecks; **simultaneous PostgreSQL concurrency remains unverified**. |
| Normal canonical transaction | Buyer checkout, seller accept/pack/ready, assigned pickup, hub inspect/confirm, destination sort/assignment, final-mile stored proof, buyer receipt | `CrossRoleOrderDeliveryFlowTest` and `InventoryCheckoutTest`: actual origin Bayan -> Mother -> destination Bayan route, wrong actor denials, duplicate custody confirmation, stored proof, buyer-only completion and financially pending delivery verified. Manifests, retry/RTS, secure counter collection and reconciled settlement remain separate missing gates. |
| Honest unavailable modules and overview | Buyer/seller dispute GET pages; removed POST/PATCH actions; buyer profile wallet; admin overview | New `PhaseZeroUnavailableModulesTest`: 27 cases, including guest and all five roles across four removed writes, compare business records and reject fake success. `AdminOverviewTest` covers real counts and unavailable finance/presence. The new suite failed all 27 cases before repair and passes after it. |

**Verification.** Fresh baseline was captured before tracked edits with explicit testing overrides: SQLite `:memory:`, empty `DB_URL`, array cache/session/mail, no result cache. No development/production database reset or seeder was run. Full baseline: **2,012 tests, 15,755 assertions, 58 failures and one error**. Focused unavailable/buyer-access checks: **85 tests, 2,187 assertions passed**. Corrected checkout/inventory/normal-flow checks: **30 tests, 453 assertions passed**. Governance/auth/eligibility/closure checks: **1,037 tests, 10,619 assertions passed**. The focused runs overlap; their totals are not added. TypeScript/Vite production build passed, and changed PHP files passed Pint and whitespace checks. No rendered browser/device check was performed.

Final isolated full suite: **2,043 tests, 16,137 assertions, 48 failures, zero errors**. Exact JUnit comparison: **11 original failing identities resolved, zero newly failing identities, 48 persisting with unchanged failure type and assertion context** after normalizing only random fixture reference codes. Eight resolved methods pass under the same identity; three receive the documented contract-correct replacements below. All three replacements pass, and all 31 added provider/test cases pass. There are no newly skipped or suppressed tests. The full suite remains red. Ignored local JUnit/console artifacts are supplementary, not required files for a fresh checkout. Reproduce the explicit isolated command from [WORKFLOW.md](admin-plan/WORKFLOW.md).

**Confirmed missing input/transaction prerequisites.** An isolated observational checkout probe using an eligible shop, real network, owned selected item and otherwise complete request persisted a markup/digit recipient name, non-phone string and markup address. This confirms a real defect outside the application validator; the probe is not a passing normative acceptance test. `CheckoutController::store` currently applies generic string/length rules to those fields. `CheckoutOrderService::place` consumes selected Bag lines transactionally and forces COD, but neither its caller nor the order schema has the documented submission-token/result contract: a retry cannot return the original generated orders. Checkout saving an address also happens after the order transaction; a failed address write can report an error after a successful order. These are owned by `fix/phase0-commerce-inputs-and-replay` under the [bounded follow-up plan](admin-plan/13-phase0-acceptance.md#follow-up-ownership-for-an-incomplete-decision), using existing canonical rules and preserving legacy snapshots. Saved-address create/default changes also require atomic failure checks. Keep the canonical-input acceptance item open; do not claim that a passing application suite covers these writers.

**Eleven baseline identities repaired.** Request responses are now asserted before dereferencing an order. Fixtures use actually offered product variants, a reviewed shop/category and a legitimate company/Bayan/Mother network. Stock, distinct buyers/Bag lines and SKU snapshots retain their assertions. The no-selection case now asserts rejection and unchanged Bag, stock, orders and deliveries rather than restoring implicit whole-Bag checkout. Phone cases use actual checkout then seller accept/pack/ready, preserving the stored snapshot and asserting the same delivery; this does not claim canonical phone validation is implemented. The standard-product case moved from excluded island shipping to a supported road-network fixture because its requirement is variant-free SKU preservation.

| Original exact test identity | Investigated cause / verified repair |
|---|---|
| `BuyerAddressTest::test_checkout_places_order_with_selected_saved_address` | Missing province/barangay/selected IDs and route; complete eligible setup retains the submitted order address assertions. |
| `BuyerAddressTest::test_checkout_can_save_new_address_to_address_book` | Same missing prerequisites concealed the real address-name inconsistency. Complete setup exposed it; saved address uses account identity and order keeps the different submitted recipient. |
| `BuyerCartSelectionTest::test_placing_order_with_item_ids_only_orders_and_deletes_selected_items_leaving_others_in_cart` | Missing required location fields/network; retain exact selected product, amount, untouched line and stock assertions. |
| `BuyerCartSelectionTest::test_placing_order_without_item_ids_checks_out_entire_cart_for_backwards_compatibility` | Contradicts explicit-selection contract; replaced by `test_placing_order_without_item_ids_rejects_without_changing_the_cart_or_stock`. |
| `ChallengerM1StressTest::test_multi_user_concurrent_cart_and_variant_isolation` | Products offered no submitted color/size options; checkout prerequisites also absent. Repaired options/route and retained stock/isolation assertions; renamed to `test_interleaved_buyers_keep_cart_variants_and_orders_isolated` because sequential SQLite requests do not prove concurrency. |
| `ChallengerM1StressTest::test_standard_product_without_variants` | Actual baseline error was `items()` on a null order after an invalid checkout; required fields/IDs/network were absent and island shipping conflicted with scope. Correct request/response assertions now verify the original base SKU, null options, Mother route and pending payment. |
| `ChallengerM1StressTest::test_delivery_phone_format_preservation` | Manually created processing order had no routed waybill or seller-pack evidence; ready correctly refuses to invent a delivery. Actual checkout/accept/pack/ready now verifies each stored phone snapshot. |
| `ChallengerM1Test::test_cart_and_order_items_variant_fields_preservation` | Unreviewed shop/category and nonexistent product options rejected Bag additions; complete reviewed/variant/network setup retains quantities, totals, SKUs and stock assertions. |
| `ChallengerM1Test::test_delivery_phone_consistency_and_persistence` | Unreviewed shop/category, incomplete checkout and missing route; now assert checkout/seller responses and full address including province. |
| `DeliveryPhoneConsistencyTest::test_checkout_populates_delivery_phone_and_variant_fields` | Unreviewed shop, invalid options and missing checkout fields/IDs/network; same phone/color/size/SKU assertions pass with eligible setup. |
| `DeliveryPhoneConsistencyTest::test_seller_order_ready_creates_delivery_with_delivery_phone` | Unreviewed shop and manually fabricated paid/packaging order without waybill/pack checkpoint; replaced by `test_seller_order_ready_preserves_the_checkout_delivery_and_phone`, asserting real checkout, seller transitions, one unchanged delivery and pending payment. |

**Remaining baseline failure causes and owners.** Every method below was read alongside its helper setup, controller/service and normative rule. An initial fixture rejection does not prove a later feature works or exists. All remaining failed checks stay in the suite and keep the relevant gate incomplete; no skipped/deleted tests, expected-failure suppression, unsafe override or weaker status/financial contract was introduced.

| Code | Actual setup/path mismatch and gate owner |
|---|---|
| S | Seller ready receives a manually created parcel without a complete eligible route; `OrderLifecycleService` correctly requires route and seller-pack evidence. **B13 fixture follow-up / Phase 1 seller acceptance** must originate the order through checkout and assert each real response; do not create the delivery at ready time. |
| P | Courier profile and parcel have no eligible company/origin facility placement; claim/pickup scope rejects. Some cases also start from legacy `assigned` instead of a valid claim. **B13 fixture follow-up / pickup acceptance** must create legitimate placement and order/parcel source states; sequential retries do not prove a PostgreSQL race. |
| H | Bare logistics account has no active handler/hub and request omits inspect/confirm semantics. Expected legacy `hub_intake`/`in_transit` is not the canonical origin intake. **B13 fixture follow-up / Phase 2 hub acceptance** must use owned handlers, full route, actual confirmation and canonical checkpoint types. Manifest persistence remains a separate Phase 2 prerequisite. |
| B | Unassigned logistics actor and unscoped parcel fail destination-facility checks before sorting. Fixtures use generic `in_transit`/`at_sorting_center` without actual destination custody. Old `barangay_sort` checkpoint/location and arbitrary repeated-bin overrides also contradict the current route/state contract. **B13 fixture follow-up / Phase 2 sorting acceptance** must establish destination intake through the Mother route, then test authorized sorting/retry conflicts. No fallback may grant cross-facility custody. |
| D | Generic `in_transit` parcel, shipped commercial order and unplaced rider do not satisfy assigned final-mile scope/source state. **B13 fixture follow-up / final-mile acceptance** must use destination assignment and synchronized commercial state before scan-out. |
| U | Request submits an external `proof_image` URL instead of required stored `proof_image_file`; network/rider and commercial source states are also invalid. **B13 fixture follow-up / delivery evidence acceptance** must upload real isolated proof and use valid destination assignment. Canonical checkpoint is `delivered`; external URLs cannot become proof. |
| F | Invalid/missing proof and scope prevent delivery; subsequent assertions expect immediate `paid` or a settled ledger merely from delivery/repeated requests. The shared ledger helper also invents a fixed PHP 60 delivery fee. Even after fixture repair, those shortcuts violate pending COD, recorded fees and buyer/reconciliation gates. **Phase 5 COD B16 / settlement B17** must supply real custody, remittance/reconciliation and buyer completion before testing product-only 90/10 and separate handling. B13 fixture follow-up must preserve the eventual accounting/idempotency requirement, not replace these tests with a weaker delivery-only check. |
| C | Commercial order is `delivered` but has no physically delivered parcel. `buyerComplete` correctly rejects. **B13 fixture follow-up / buyer-completion acceptance** must reach actual delivery with proof before the owning buyer confirms. |
| R | HTTP status `failed` is outside the allowed picked-up/out-for-delivery/delivered controller inputs; no exposed, persisted reason-coded attempt/recovery workflow exists. **Role-owned Phase 3 attempts/RTS**, then **B14 oversight**, owns the missing feature. Align fixtures with `delivery_failed`, assigned final-mile scope, reason and recoverable history; changing only an enum cannot complete it. |
| W | “Full flow” helpers omit route/placement, hub confirmation/Mother legs, stored proof and actual buyer confirmation; one directly writes `assigned`/`completed`. **B13 cross-role fixture follow-up**, with **Phases 2-5 role-owned prerequisites** for manifests/recovery/accounting. The passing canonical flow is positive evidence but does not waive these unfinished scenarios. |
| T | Test starts commercial `placed`/parcel `ready_for_pickup`, then attempts a direct claim-to-delivered shortcut using an unplaced rider/URL proof. **B13 duty/normal-flow fixture follow-up** must retain the real lifecycle and off-duty/new-work distinction. |
| X | Cancellation test writes cancelled status directly and attempts a claim while order is still placed and parcel is not unassigned/eligible. It invokes no cancellation request; its seller-gate title and buyer comment disagree. **B13 cancellation fixture follow-up / Phase 1** must invoke the owned seller cancellation/claim APIs and assert no post-custody cancellation or stock duplication. Buyer self-service cancellation remains unavailable under the core contract. |
| A | Breakdown “reassignment” directly rewrites pickup `courier_id`; final-mile responsibility is `assigned_rider_id`, and proof/source scope is invalid. **Role-owned Phase 3 accountable recovery**, then **B14 oversight**, must record legitimate transfer/custody instead of field replacement. |


| Test class | Exact method identity | Root cause / owner below |
|---|---|---|
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f10_01_seller_marks_ready` | S |
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f11_01_courier_claims_pickup` | P |
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f11_03_state_transition_to_picked_up` | P |
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f11_04_courier_pickup_checkpoint` | P |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f12_01_hub_intake_barcode_scan` | H |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f12_02_state_transition_to_at_sorting_center` | H |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f12_03_hub_intake_checkpoint` | H |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f13_01_area_sorting_submission` | B |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f13_04_sorted_checkpoint_logged` | B |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f15_02_state_transition_to_out_for_delivery` | D |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_02_state_transition_to_delivered` | U |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_03_doorstep_handover_checkpoint` | U |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_04_payment_status_settled` | F |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_05_commission_ledger_generation` | F |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f17_02_state_transition_to_completed` | C |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f18_02_state_transition_to_delivery_failed` | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f18_03_delivery_failed_checkpoint` | R |
| [F26_to_F33_HubRoutingAndGovernanceTest](../tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php) | `test_t1_f27_01_hub_operator_sorts_area_a` | B |
| [F26_to_F33_HubRoutingAndGovernanceTest](../tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php) | `test_t1_f27_02_hub_operator_sorts_area_b` | B |
| [F26_to_F33_HubRoutingAndGovernanceTest](../tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php) | `test_t1_f27_03_hub_operator_sorts_area_c` | B |
| [F34_to_F35_E2EAndAdversarialTest](../tests/Feature/E2E/Tier1/F34_to_F35_E2EAndAdversarialTest.php) | `test_t1_f35_04_double_settlement_idempotency` | F |
| [F34_to_F35_E2EAndAdversarialTest](../tests/Feature/E2E/Tier1/F34_to_F35_E2EAndAdversarialTest.php) | `test_t1_f35_05_concurrent_claim_race_condition_prevention` | P |
| [B07_to_B11_LifecyclePlacedToPickupBoundaryTest](../tests/Feature/E2E/Tier2/B07_to_B11_LifecyclePlacedToPickupBoundaryTest.php) | `test_t2_b11_03_double_claim_race_condition` | P |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b27_01_multiple_parcels_to_same_bin` | B |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b27_02_missing_area_field_in_sort` | B |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b27_04_sorting_already_sorted_parcel` | B |
| [B34_to_B35_E2EAndAdversarialBoundaryTest](../tests/Feature/E2E/Tier2/B34_to_B35_E2EAndAdversarialBoundaryTest.php) | `test_t2_b35_03_multiple_concurrent_settlements` | F |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_06_courier_pickup_claim_and_collection_scan` | P |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_07_first_mile_delivery_to_hub_intake_scan` | H |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_08_hub_sorting_destination_area_and_bin` | B |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_11_doorstep_handover_proof_photo_tracking_update` | U |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_12_cod_payment_settlement_automated_split` | F |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_14_doorstep_delivery_failure_reason_logging` | R |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_18_fcfs_concurrency_lock_protection` | P |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_33_complete_13_stage_linear_lifecycle_walkthrough` | W |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_34_double_delivery_attempt_immutable_ledger` | F |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_14_unmapped_address_fallback_and_override` | B |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_15_courier_duty_cycle_and_shift` | T |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_16_order_cancellation_pre_vs_post_pickup` | X |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_17_hub_sorting_dock_morning_rush` | B |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_18_platform_governance_and_financial_audit` | F |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_07_seller_merchant_onboarding_and_first_sale` | S |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_08_cod_financial_lifecycle_and_remittance` | F |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_10_post_delivery_dispute_and_admin_governance` | F |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_11_courier_breakdown_hub_reassignment` | A |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_01_metro_manila_standard_delivery_lifecycle` | W |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_02_provincial_laguna_delivery_with_area_b_sorting` | B |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_06_merchant_self_managed_packaging_with_waybill` | S |

**Next boundary.** After user review of this audit branch, separately select `fix/phase0-commerce-inputs-and-replay`, then `test/phase0-cross-role-fixtures` under the [follow-up plan](admin-plan/13-phase0-acceptance.md#follow-up-ownership-for-an-incomplete-decision). The second delivery owns the fixture corrections identified above and an inspection of nominally passing fixture-only tests; creating an order/checkpoint directly is not proof of its production writer. Repeat B13's exact acceptance comparison after each repair. The index now includes these two supplemental deliveries outside the 18 numbered admin tasks. Neither branch nor another implementation task was started here. Required role-owned manifest custody (Phase 2), attempts/RTS/secure counter recovery (Phase 3) and persistent notifications (Phase 4) are still prerequisites for later oversight/finance. B14 waits for the applicable gates; no sample recovery, notification, cash or settlement record can substitute for them.

**Review assessment.** The scoped repairs and evidence are ready for user-managed push/review, with the red full suite and negative Phase 0 decision disclosed. This audit stops under B13's plan. Operational field validation remains owned by the relevant role mutation and its Phase 0 gate; simultaneous PostgreSQL races, rendered UI/device behavior and later recovery/finance are not certified by the passing SQLite checks.

**Scoped engineering assessment.** Deferred-feature honesty **3/10 -> 8/10**: real users now see unavailability instead of sample disputes, invented balances or fake successful actions; access/no-write tests and the production build support the improvement. Checkout regression evidence **3/10 -> 8/10**: eleven diagnosed baseline identities are repaired/replaced with explicit request outcomes, eligible routes, valid options and retained stock/SKU/ownership assertions. The address-save identity inconsistency is fixed. These ratings do not raise overall transaction/admin readiness: broader canonical validation, checkout retry/address-save atomicity, the remaining failed scenarios, required later persistence, rendered UI and PostgreSQL concurrency remain open.

Local implementation commits:

- `8258a22 fix: make deferred disputes and wallet clearly unavailable`
- `5bcb719 fix: keep saved checkout addresses tied to account identity`
- `0e4839e test: align acceptance fixtures with owned checkout and route rules`

Review of all **258 reachable revisions before the evidence commit** and commit messages found no private task-service name; the machine-local task guide and B13 artifacts remain ignored and untracked. The documentation/evidence commit has subject `docs: record B13 acceptance decision and follow-up scope`; its generated hash is reported in the branch handoff. No push or merge was performed.


### Phase 0 Commerce Inputs and Checkout Replay: October 6, 2026

**Decision: Scoped commerce repair verified; Phase 0 acceptance remains incomplete.** Branch `fix/phase0-commerce-inputs-and-replay` starts from merged B13 at `30d1d2a` (`Test/admin phase0 acceptance (#61)`) and completes the first supplemental delivery in the [B13 follow-up plan](admin-plan/13-phase0-acceptance.md#follow-up-ownership-for-an-incomplete-decision). The preceding B13 findings describe the audit baseline; this section updates its checkout and saved-address gates. The remaining cross-role scenarios and B14 prerequisites stay open.

**User-visible result.** New checkout and saved-address values receive the existing canonical text/contact rules before business writes. Supported mobile formats become `+639...`; valid Unicode text is normalized without truncation. Checkout requires province, city, barangay and four ASCII postal digits; optional coordinates must form a valid pair. Both forms show field errors and use actual account/address values instead of guessed locations or phone numbers. Saved-address selection keeps street separate from the other location fields and preserves zero coordinates.

A successful checkout retains the original shop orders under an opaque signed buyer/Bag-scoped confirmation. An identical retry returns those orders after Bag consumption or later stock, voucher, route and order-state changes. It cannot consume stock/vouchers twice, save another address or revive cancelled/completed fulfilment. Changed details require a new checkout; foreign, invented and tampered confirmations reject. Pending confirmations use the existing application key; persisted successful confirmations survive key rotation. Every retry still checks current buyer eligibility and ownership.

**Acceptance evidence.**

| Requirement | Implementation and verified result |
|---|---|
| Canonical HTTP/direct-service boundary | `CommerceInputService` reuses the application text/contact rules. Malformed text, controls/markup, Unicode contact/postal/code look-alikes, selection IDs, enums and coordinate pairs/precision reject without partial records. Valid Unicode/case/contact normalization and a 500-character street pass. Prices, fees, vouchers and pending COD remain server-controlled. |
| Scoped original result | `CheckoutSubmissionService` verifies the signed confirmation and canonical request hash. Only hashes and original order links are stored. Under the owned Bag lock, `CheckoutOrderService` checks replay before consumed lines or current stock/routes/vouchers. Multi-shop, changed/foreign confirmation and corrected-input retries assert exact IDs and unchanged snapshots/counters. |
| Atomic checkout/address saving | Orders, items, stock, routes, voucher use, optional address, Bag-line deletion and result links share one transaction. Failures at the second parcel, second stock update, voucher, address, result row and result links roll everything back; the same confirmation can then succeed once. A missing second-shop route also rolls back the complete checkout. |
| Address/default integrity and legacy snapshots | `BuyerAddressService` rechecks the current buyer and locks owned addresses. Forged/stale ownership and suspension cannot bypass create/default/delete. Insert, default-switch and delete failures preserve prior records/defaults. Saved names use the current account; checkout recipients remain separate snapshots. Read endpoints no longer invent legacy address records, and new address/retry actions preserve old orders/waybills. |
| Destination and counter scope | Actual province/city and configured barangay coverage must match, with supported city-name aliases. False provinces, partial cities, unserved barangays and island/GPS overrides reject. New self-pickup rejects missing, inactive, Mother-tier, counter-disabled or out-of-address facilities; a second eligible counter remains selectable. Mandatory Mother-Hub routing is retained. |
| Retained results and safe failures | Model/database guards, unique hashes and restrictive references retain result rows, links and order ownership; completed results cannot gain another legacy order. Unexpected storage errors expose a generic retry message and log only buyer ID/exception class. Confirmations are excluded from flashed input. |
| Real cross-role/cancellation evidence | The seeded five-role flow still uses seller fulfilment, separate pickup/final-mile riders, origin/Mother/destination custody, stored proof and owning-buyer completion. A later checkout replay preserves the completed order, parcel, checkpoints and stock. Cancellation uses the owning seller's action; replay neither restores fulfilment nor consumes returned stock. |

**Verification.**

- The combined focused selection passed **373 tests with 3,314 assertions**, covering the new commerce classes and existing buyer checkout/address/selection/KYC/inventory, Challenger, phone-consistency, shop, moderation, cross-role and logistics-resource eligibility checks.
- Fresh full baseline: **2,043 tests, 16,137 assertions, 48 failures and zero errors**. Final isolated run: **2,241 tests, 17,544 assertions, 48 failures and zero errors**. All **198 added cases pass**. Exact class/method/data-set identities, failure/error kinds, exception types and assertion/source context match; only generated fixture references are normalized. There are **zero new, resolved or changed failure identities**, no removed existing tests and no skipped tests. The unchanged failures retain their [B13 inventory and owners](#b13-phase-0-acceptance-and-failure-triage-october-6-2026). The full suite and Phase 0 gate remain red.
- The Docker TypeScript/Vite production build passed in **10.04 seconds**. Changed PHP style, whitespace, documentation references and tracked-content/reachable-history checks passed. Tests used explicit testing/database/cache/session/mail overrides and isolated SQLite `:memory:`; no database reset or destructive seeder ran.
- Both selected additive migrations applied to local PostgreSQL. Counts and hashes of original non-secret columns across **31 existing domain tables** match before/after. Saved streets now use `text`; both new result tables are empty and all **four checkout database guards** exist. The unrelated older pending demo-account migration was left alone. No live checkout, seed rewrite or synthetic business record was created for verification.

**Deployment and limits.** Apply `2026_10_06_090000_widen_saved_address_streets.php` and `2026_10_06_100000_create_checkout_submissions.php`, with existing governance prerequisites, before serving this checkout elsewhere. Street rollback retains the wider column; result rollback refuses once a result exists. Successful results retain their buyer, Bag and orders. Existing orders are not backfilled with invented confirmations.

Lock review found the shared owned-Bag lock serializes checkout with existing Bag mutations. New checkout then locks selected lines, sorted buyer/seller accounts, shops, categories and products before voucher/network work; address mutations lock the buyer then owned addresses. Unique references and immutable guards add persistence boundaries. Schema installation and SQLite assertions do not prove simultaneous PostgreSQL races; rendered UI/device behavior also remains unverified. Other operational inputs, the unchanged Phase 0 scenarios and later manifest, recovery, notification, COD and settlement gates remain required.

**Scoped engineering assessment.** Checkout input/submission safety **3/10 -> 9/10**: direct calls receive canonical validation, successful retries retain original results, and failure injection proves rollback across all selected shops. Saved-address integrity **4/10 -> 9/10**: new values are canonical, default/delete changes are owner-scoped and atomic, and old snapshots remain unchanged. These scores reflect automated evidence and the stated limits; overall role/admin/cross-role scores are unchanged.

Local implementation commits:

- `53f0fb1 fix: validate commerce inputs and save addresses atomically`
- `c16c358 fix: retain buyer checkout results for safe retries`
- `f20aad2 fix: show checkout and saved address validation clearly`

The evidence commit has subject `docs: record commerce repair acceptance and remaining gates`; its generated hash is reported in the handoff. The machine-local task guide and verification artifacts remain ignored and untracked. No push or merge was performed.

**Next boundary.** Stop this supplemental branch for user review and publication. The next separate acceptance delivery is `test/phase0-cross-role-fixtures`, using this checkout after its dependency is reviewed/merged. It owns the identified contradictory fixtures and nominally passing setup-only checks while preserving required recovery/accounting assertions. B14 still waits for applicable role-owned sources and gates.



### B13 Cross-Role Fixture Follow-Up: October 6, 2026

**Decision: The bounded fixture correction and evidence delivery is complete; Phase 0 acceptance remains incomplete.** Branch `test/phase0-cross-role-fixtures` starts from merged commerce repair `e50c995` (`Fix/phase0 commerce inputs and replay (#62)`). It implements the second supplemental delivery in the [B13 follow-up plan](admin-plan/13-phase0-acceptance.md#follow-up-ownership-for-an-incomplete-decision). This updates the earlier 48-failure audit; it does not implement B14 or clear role-owned custody, recovery, notification or financial gates.

**Result and evidence boundary.** This is backend acceptance-test work, with no application, migration, frontend or deployment change. Tests now follow a real Shopping Bag submission, owned seller acceptance/preparation, eligible pickup, Origin Bayan Hub, Mother Hub and Destination Bayan Hub scans, final-mile assignment, stored proof upload and the owning buyer's receipt confirmation. Users gain stronger regression evidence for those existing actions; no new screen or runtime feature is claimed.

[InteractsWithOrderActions](../tests/Feature/E2E/Support/InteractsWithOrderActions.php) creates initial approved accounts, products and eligible facility/personnel fixtures, then invokes the existing request boundaries to establish order and custody evidence. Approval fixtures do not prove the approval writer. Every normal transition checks its response and persisted state. The multi-shop cases use one Bag submission, retain distinct parcels, check stock consumption and separate shipping, and never invent direct Bayan-to-Bayan custody. Positive provincial fixtures stay in Laguna and retain at least one Mother Hub; the old Metro Manila label is corrected rather than claiming regional coverage from Laguna facilities.

Repeated scans, rider actions and buyer confirmation preserve existing rows, proof and history. Seller cancellation uses its owned endpoint, restores stock once before custody and rejects after claim; buyer cancellation remains unavailable. Wrong sellers use their own valid selected shop so a stale selection cannot hide the ownership check. Eligible competing claims, duty changes, suspension and reactivation use real requests with exact outcomes. These are sequential checks, not simultaneous PostgreSQL race evidence.

Nominally passing tests were inspected as well as baseline failures. Direct returned/rescheduled statuses, invented custody checkpoints, hand-edited completion and a manually inserted settled ledger no longer stand in for production writers in the repaired cases. The raw legacy/invalid-record helpers remain for read-only or rejection fixtures; they no longer automatically mark delivered COD paid or attach an external image URL as proof. Configuration checks, arithmetic examples, legacy-list fixtures, bare portal responses and the explicitly named courier vehicle-metadata fixture are limited evidence, not transaction, reviewed fleet, retry-limit, notification or financial acceptance. In particular, the remaining static attempt-cap/option examples do not certify a persisted three-attempt review workflow.

**Required future contracts remain failing.** The reason-coded failure requests use canonical `delivery_failed`, a legitimately assigned rider and normal outbound history. The current HTTP controller rejects that status before reason/source/actor checks; the latter failures do not demonstrate an ownership bypass. Return/retry cases retain destination receipt, reverse Mother Hub custody, authenticated seller receipt, stock-once and final-state requirements. The breakdown case proves ordinary assignment/inspection cannot transfer outbound custody and retains the requirement for recorded replacement responsibility and actual delivery. These are failing prerequisite checks, not complete future end-to-end scenarios: the owning Phase 3 work must extend them with real review, retry, reverse-route, seller-receipt and recovery commands before certifying those paths. No future route or scan-action code was invented.

Delivery and buyer completion retain pending COD. Financial cases keep their eventual ledger, paid/settled, product-only 90/10 and idempotency assertions behind the missing source-backed workflow. The Phase 5 owner must add actual collection, handoff, remittance/reconciliation and settlement actions; buyer receipt alone is not a financial trigger. [AssertsCommissionLedgers](../tests/Feature/E2E/Support/AssertsCommissionLedgers.php) removes the invented PHP 60 logistics charge and requires an independently recorded charge argument. No real recorded charge source is supplied yet. Shipping and rider/logistics charges must not be guessed or fabricated to satisfy it. The uncollected-return case covers pending accounting, not reversal of already collected or settled cash. Deferred post-delivery disputes remain unavailable and are not certified by an admin-page response.

**Verification.** Docker PHP checks used explicit `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, empty `DB_URL`, array cache/session/mail and `--do-not-cache-result`. No PostgreSQL database was reset or used. A fresh full baseline was captured before tracked edits. The final normal-flow selection passes **94 tests, 3,634 assertions**. Changed files pass Pint and `git diff --check`. No frontend build or rendered/device check is claimed for this test-only change.

| Full isolated suite | Tests | Assertions | Failures | Errors | Skipped |
|---|---:|---:|---:|---:|---:|
| Fresh baseline at `e50c995` | 2,241 | 17,544 | 48 | 0 | 0 |
| Final branch | 2,241 | 30,146 | 47 | 0 | 0 |

The full suite is **red**. Comparison used each JUnit class/method and failure context, matching renamed cases by their unchanged tier/feature/case identifier. All 2,241 cases remain; none were removed, skipped or declared expected failures. **33 baseline failures now pass, 15 baseline failed cases remain failing and 32 previously passing cases expose missing safeguards/workflows.** Twenty-four names change to describe actual coverage. A one-failure net reduction is not the readiness assessment.

| Gate code | Current evidence, exact owner and consequence | Remaining cases |
|---|---|---:|
| O | The assigned destination handler can submit a bin containing non-ASCII/control text and a barangay containing a line break; requests return 200 instead of a field-specific 422. [LogisticsHubWorkstationController](../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php) only applies generic strings. **Role-owned logistics mutation / Phase 0 operational input safety** must apply the documented field rules without changing routing or custody. Phase 0 remains incomplete. | 2 |
| A | A checkpoint produced by a real pickup can have notes edited, be deleted through the model, or have its creation time rewritten through a raw SQL update. [DeliveryCheckpoint](../app/Models/DeliveryCheckpoint.php) and its persistence need append-only protection. **Role-owned Phase 2 custody/manifest audit** owns this safeguard; retry preservation does not prove storage immutability. The durable custody gate remains incomplete. | 3 |
| R | Canonical failure status is rejected before reason validation and legitimate failure/recovery mutations. The normal outbound setup now passes, but recorded attempts, review/date, reverse route, seller receipt, repeat-restock protection and accountable replacement handoff are absent from the exercised boundaries. **Role-owned Phase 3 attempts/RTS/recovery**, then **B14 oversight**, owns the real action chain. No return/retry or recovery acceptance is claimed. | 25 |
| N | The real assigned-rider dispatch succeeds, but persistent event notification storage and a recorded buyer notice are missing. **Role-owned Phase 4 order events**, then **B15 governance notices**, owns the prerequisite. A tracking page is not durable notification evidence. | 1 |
| F | Real proof and buyer receipt succeed, with COD pending and no settlement ledger. The required cash custody, recorded fees, remittance/reconciliation and settlement actions remain absent. **Phase 5 B16 COD / B17 seller settlement**, then **B18 finance**, must establish recorded sources and extend these cases through the actual workflow. No accounting gate is waived. | 16 |

**Exact final failure identities.** “Retained” means the same stable case was failing in the baseline; its repaired setup may now fail at a later missing prerequisite. “Exposed” means it passed in the baseline but its stronger request/evidence check now fails. Class links identify the current executable file.

| Current test class | Exact current method | Comparison | Gate |
|---|---|---|---|
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f15_04_buyer_live_notification` | Exposed | N |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_04_payment_status_settled` | Retained | F |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_05_commission_ledger_generation` | Retained | F |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f17_04_seller_settlement_finalized` | Exposed | F |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f18_01_courier_reports_delivery_failure` | Exposed | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f18_02_state_transition_to_delivery_failed` | Retained | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f18_03_delivery_failed_checkpoint` | Retained | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f18_04_buyer_exception_view` | Exposed | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f18_05_hub_exception_queue` | Exposed | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f19_01_return_execution_from_hub` | Exposed | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f19_02_state_transition_to_returned` | Exposed | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f19_03_return_checkpoint_logged` | Exposed | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f19_04_inventory_reversal` | Exposed | R |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f19_05_seller_return_notice` | Exposed | R |
| [F21_to_F25_CourierOperationsTest](../tests/Feature/E2E/Tier1/F21_to_F25_CourierOperationsTest.php) | `test_t1_f25_02_reschedule_action` | Exposed | R |
| [F21_to_F25_CourierOperationsTest](../tests/Feature/E2E/Tier1/F21_to_F25_CourierOperationsTest.php) | `test_t1_f25_03_reschedule_checkpoint` | Exposed | R |
| [F21_to_F25_CourierOperationsTest](../tests/Feature/E2E/Tier1/F21_to_F25_CourierOperationsTest.php) | `test_t1_f25_04_return_action` | Exposed | R |
| [F34_to_F35_E2EAndAdversarialTest](../tests/Feature/E2E/Tier1/F34_to_F35_E2EAndAdversarialTest.php) | `test_t1_f35_04_double_settlement_idempotency` | Retained | F |
| [B12_to_B17_LifecycleHubToCompletedBoundaryTest](../tests/Feature/E2E/Tier2/B12_to_B17_LifecycleHubToCompletedBoundaryTest.php) | `test_t2_b13_02_invalid_destination_area_rejected` | Exposed | O |
| [B12_to_B17_LifecycleHubToCompletedBoundaryTest](../tests/Feature/E2E/Tier2/B12_to_B17_LifecycleHubToCompletedBoundaryTest.php) | `test_t2_b17_05_post_completion_settlement_bounds` | Exposed | F |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b18_01_empty_failure_reason_rejected` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b18_02_under_5_chars_reason_rejected` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b18_03_invalid_failure_code_rejected` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b18_04_reporting_failure_on_non_active_delivery_barred` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b18_05_non_assigned_courier_reporting_failure_barred` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b19_01_inventory_double_restoration_guard` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b19_03_uncollected_return_keeps_accounting_pending` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b19_05_return_status_immutability` | Exposed | R |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b20_01_updating_existing_checkpoint_barred` | Exposed | A |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b20_02_deleting_checkpoint_record_barred` | Exposed | A |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b20_05_tampering_with_created_at` | Exposed | A |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b27_03_bin_format_string_validation` | Exposed | O |
| [B34_to_B35_E2EAndAdversarialBoundaryTest](../tests/Feature/E2E/Tier2/B34_to_B35_E2EAndAdversarialBoundaryTest.php) | `test_t2_b35_03_delivery_retry_preserves_required_single_settlement` | Retained | F |
| [B34_to_B35_E2EAndAdversarialBoundaryTest](../tests/Feature/E2E/Tier2/B34_to_B35_E2EAndAdversarialBoundaryTest.php) | `test_t2_b35_05_zero_duplicate_ledger_records` | Exposed | F |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_12_cod_payment_settlement_automated_split` | Retained | F |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_13_buyer_confirmation_seller_settlement` | Exposed | F |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_14_doorstep_delivery_failure_reason_logging` | Retained | R |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_15_reschedule_option_selection_queue` | Exposed | R |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_16_return_option_selection_merchant_restock` | Exposed | R |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_27_voucher_discount_centavo_commission` | Exposed | F |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_34_double_delivery_attempt_immutable_ledger` | Retained | F |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_18_platform_governance_and_financial_audit` | Retained | F |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_07_seller_merchant_onboarding_and_first_sale` | Retained | F |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_08_cod_financial_lifecycle_and_remittance` | Retained | F |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_10_post_delivery_dispute_and_admin_governance` | Retained | F |
| [RealWorldLogisticsRoutingTest](../tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php) | `test_t4_11_courier_breakdown_hub_reassignment` | Retained | R |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_01_standard_delivery_through_mother_hub_and_buyer_confirmation` | Retained | F |

**Exact resolved baseline cases.** Each case remains in the suite and now passes through legitimate placement, the real actions and the preserved ownership/state/stock/evidence contract. Renames below retain the stable case identity.

| Test class | Baseline failed method | Current passing method |
|---|---|---|
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f10_01_seller_marks_ready` | `test_t1_f10_01_seller_marks_ready` |
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f11_01_courier_claims_pickup` | `test_t1_f11_01_courier_claims_pickup` |
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f11_03_state_transition_to_picked_up` | `test_t1_f11_03_state_transition_to_picked_up` |
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f11_04_courier_pickup_checkpoint` | `test_t1_f11_04_courier_pickup_checkpoint` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f12_01_hub_intake_barcode_scan` | `test_t1_f12_01_hub_intake_barcode_scan` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f12_02_state_transition_to_at_sorting_center` | `test_t1_f12_02_state_transition_to_at_sorting_center` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f12_03_hub_intake_checkpoint` | `test_t1_f12_03_hub_intake_checkpoint` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f13_01_area_sorting_submission` | `test_t1_f13_01_area_sorting_submission` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f13_04_sorted_checkpoint_logged` | `test_t1_f13_04_sorted_checkpoint_logged` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f15_02_state_transition_to_out_for_delivery` | `test_t1_f15_02_state_transition_to_out_for_delivery` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_02_state_transition_to_delivered` | `test_t1_f16_02_state_transition_to_delivered` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f16_03_doorstep_handover_checkpoint` | `test_t1_f16_03_doorstep_handover_checkpoint` |
| [F12_to_F17_OrderLifecycleHubToCompletedTest](../tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php) | `test_t1_f17_02_state_transition_to_completed` | `test_t1_f17_02_state_transition_to_completed` |
| [F26_to_F33_HubRoutingAndGovernanceTest](../tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php) | `test_t1_f27_01_hub_operator_sorts_area_a` | `test_t1_f27_01_hub_operator_sorts_area_a` |
| [F26_to_F33_HubRoutingAndGovernanceTest](../tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php) | `test_t1_f27_02_hub_operator_sorts_area_b` | `test_t1_f27_02_hub_operator_sorts_area_b` |
| [F26_to_F33_HubRoutingAndGovernanceTest](../tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php) | `test_t1_f27_03_hub_operator_sorts_area_c` | `test_t1_f27_03_hub_operator_sorts_area_c` |
| [F34_to_F35_E2EAndAdversarialTest](../tests/Feature/E2E/Tier1/F34_to_F35_E2EAndAdversarialTest.php) | `test_t1_f35_05_concurrent_claim_race_condition_prevention` | `test_t1_f35_05_sequential_competing_claims_preserve_first_owner` |
| [B07_to_B11_LifecyclePlacedToPickupBoundaryTest](../tests/Feature/E2E/Tier2/B07_to_B11_LifecyclePlacedToPickupBoundaryTest.php) | `test_t2_b11_03_double_claim_race_condition` | `test_t2_b11_03_sequential_competing_claims_preserve_first_owner` |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b27_01_multiple_parcels_to_same_bin` | `test_t2_b27_01_multiple_parcels_to_same_bin` |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b27_02_missing_area_field_in_sort` | `test_t2_b27_02_missing_area_field_in_sort` |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b27_04_sorting_already_sorted_parcel` | `test_t2_b27_04_sorting_already_sorted_parcel` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_06_courier_pickup_claim_and_collection_scan` | `test_t3_06_courier_pickup_claim_and_collection_scan` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_07_first_mile_delivery_to_hub_intake_scan` | `test_t3_07_first_mile_delivery_to_hub_intake_scan` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_08_hub_sorting_destination_area_and_bin` | `test_t3_08_hub_sorting_destination_area_and_bin` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_11_doorstep_handover_proof_photo_tracking_update` | `test_t3_11_doorstep_handover_proof_photo_tracking_update` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_18_fcfs_concurrency_lock_protection` | `test_t3_18_sequential_fcfs_claims_preserve_first_assignment` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_33_complete_13_stage_linear_lifecycle_walkthrough` | `test_t3_33_complete_13_stage_linear_lifecycle_walkthrough` |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_14_unmapped_address_fallback_and_override` | `test_t4_14_unmapped_address_rejects_without_hub_override` |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_15_courier_duty_cycle_and_shift` | `test_t4_15_courier_duty_cycle_and_shift` |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_16_order_cancellation_pre_vs_post_pickup` | `test_t4_16_order_cancellation_pre_vs_post_pickup` |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_17_hub_sorting_dock_morning_rush` | `test_t4_17_hub_sorting_dock_morning_rush` |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_02_provincial_laguna_delivery_with_area_b_sorting` | `test_t4_02_provincial_laguna_delivery_with_area_b_sorting` |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_06_merchant_self_managed_packaging_with_waybill` | `test_t4_06_merchant_self_managed_packaging_with_waybill` |

**Complete rename map.** Names are corrected for real evidence rather than leaving stronger claims such as concurrency, a fallback override, or a financially settled return on a narrower check. Delivery/confirmation checkpoints use the actual writer's types. `f20_05` covers request retry preservation; the three `b20` persistence mutations expose the still-missing append-only guard separately. Optional notes and generated bins follow the existing supported contract; non-existent IDs are tested as IDs. Broader cash reversal, region coverage, reviewed fleet changes and simultaneous race gates remain unverified.

| Test class | Previous name | Current name |
|---|---|---|
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f07_03_initial_checkpoint_creation` | `test_t1_f07_03_checkout_retains_original_order_evidence` |
| [F07_to_F11_OrderLifecyclePlacedToPickupTest](../tests/Feature/E2E/Tier1/F07_to_F11_OrderLifecyclePlacedToPickupTest.php) | `test_t1_f08_03_confirmed_checkpoint` | `test_t1_f08_03_confirmation_retry_preserves_order_and_parcel` |
| [F18_to_F20_OrderLifecycleFailureCheckpointsTest](../tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php) | `test_t1_f20_05_immutable_historical_audit` | `test_t1_f20_05_scan_retry_preserves_original_history` |
| [F26_to_F33_HubRoutingAndGovernanceTest](../tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php) | `test_t1_f26_05_default_area_fallback` | `test_t1_f26_05_unmapped_destination_has_no_fallback_hub` |
| [F34_to_F35_E2EAndAdversarialTest](../tests/Feature/E2E/Tier1/F34_to_F35_E2EAndAdversarialTest.php) | `test_t1_f34_05_exit_code_contract_adherence` | `test_t1_f34_05_actual_lifecycle_records_buyer_completion` |
| [F34_to_F35_E2EAndAdversarialTest](../tests/Feature/E2E/Tier1/F34_to_F35_E2EAndAdversarialTest.php) | `test_t1_f35_05_concurrent_claim_race_condition_prevention` | `test_t1_f35_05_sequential_competing_claims_preserve_first_owner` |
| [B07_to_B11_LifecyclePlacedToPickupBoundaryTest](../tests/Feature/E2E/Tier2/B07_to_B11_LifecyclePlacedToPickupBoundaryTest.php) | `test_t2_b08_05_concurrency_during_confirmation` | `test_t2_b08_05_accept_and_pack_retry_preserves_evidence` |
| [B07_to_B11_LifecyclePlacedToPickupBoundaryTest](../tests/Feature/E2E/Tier2/B07_to_B11_LifecyclePlacedToPickupBoundaryTest.php) | `test_t2_b11_03_double_claim_race_condition` | `test_t2_b11_03_sequential_competing_claims_preserve_first_owner` |
| [B12_to_B17_LifecycleHubToCompletedBoundaryTest](../tests/Feature/E2E/Tier2/B12_to_B17_LifecycleHubToCompletedBoundaryTest.php) | `test_t2_b13_03_re_sorting_parcel_updates_bin` | `test_t2_b13_03_re_sorting_rejects_and_preserves_bin` |
| [B12_to_B17_LifecycleHubToCompletedBoundaryTest](../tests/Feature/E2E/Tier2/B12_to_B17_LifecycleHubToCompletedBoundaryTest.php) | `test_t2_b13_04_missing_bin_identifier_rejected` | `test_t2_b13_04_unknown_delivery_identifier_rejected` |
| [B12_to_B17_LifecycleHubToCompletedBoundaryTest](../tests/Feature/E2E/Tier2/B12_to_B17_LifecycleHubToCompletedBoundaryTest.php) | `test_t2_b15_05_missing_courier_notes_validation` | `test_t2_b15_05_owned_dispatch_allows_optional_notes` |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b19_02_return_without_delivery_failure_barred` | `test_t2_b19_02_outbound_inspection_does_not_authorize_return` |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b19_03_commission_ledger_reversal_on_return` | `test_t2_b19_03_uncollected_return_keeps_accounting_pending` |
| [B18_to_B20_FailureAndCheckpointsBoundaryTest](../tests/Feature/E2E/Tier2/B18_to_B20_FailureAndCheckpointsBoundaryTest.php) | `test_t2_b19_04_non_hub_return_execution_barred` | `test_t2_b19_04_buyer_cannot_scan_return_custody` |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b26_01_empty_address_fallback` | `test_t2_b26_01_empty_address_rejects_without_fallback` |
| [B26_to_B33_HubRoutingAndGovernanceBoundaryTest](../tests/Feature/E2E/Tier2/B26_to_B33_HubRoutingAndGovernanceBoundaryTest.php) | `test_t2_b26_04_non_laguna_provincial_address_fallback` | `test_t2_b26_04_unserved_province_has_no_fallback` |
| [B34_to_B35_E2EAndAdversarialBoundaryTest](../tests/Feature/E2E/Tier2/B34_to_B35_E2EAndAdversarialBoundaryTest.php) | `test_t2_b35_03_multiple_concurrent_settlements` | `test_t2_b35_03_delivery_retry_preserves_required_single_settlement` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_18_fcfs_concurrency_lock_protection` | `test_t3_18_sequential_fcfs_claims_preserve_first_assignment` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_30_buyer_cancellation_instant_restock` | `test_t3_30_buyer_cancellation_stays_unavailable_without_restock` |
| [CrossFeatureCombinationsTest](../tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php) | `test_t3_31_courier_profile_vehicle_update_metadata` | `test_t3_31_courier_profile_vehicle_metadata_fixture` |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_13_flash_sale_inventory_contention` | `test_t4_13_sequential_flash_sale_exhaustion_rejects_sixth_checkout` |
| [RealWorldExceptionsAndFleetTest](../tests/Feature/E2E/Tier4/RealWorldExceptionsAndFleetTest.php) | `test_t4_14_unmapped_address_fallback_and_override` | `test_t4_14_unmapped_address_rejects_without_hub_override` |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_01_metro_manila_standard_delivery_lifecycle` | `test_t4_01_standard_delivery_through_mother_hub_and_buyer_confirmation` |
| [RealWorldStandardLifecycleTest](../tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php) | `test_t4_05_peak_hour_simultaneous_checkout_and_dispatch` | `test_t4_05_sequential_checkout_and_pickup_batch_preserves_stock` |

**Scoped assessment:** Normal cross-role acceptance evidence **3/10 -> 8/10**. Actual requests, valid source states, full Mother Hub routing, stored proof, buyer-only completion and rejection/retry snapshots replace contradictory setup and fabricated success. This rating concerns test evidence; no runtime/admin/UI rating is raised. PostgreSQL contention, rendered UI, optional/future role workflows, the 47 red cases and broader adversarial coverage remain outside the passing claim.

**Next boundary:** This follow-up stops for user review. Prioritize the two operational text checks under the role-owned Phase 0 logistics mutation, then durable Phase 2 custody/manifests and append-only history, Phase 3 attempts/RTS/secure counter/recovery, and Phase 4 recorded order events. B14 waits for its verified Phase 0/2/3 prerequisites; B15 consumes its recorded notification source. B16+B17 may form a later explicitly selected batch after their gates pass, with distinct task records, acceptance checks and commits. Neither that batch nor another numbered branch is started here. The October 4-November 20 delivery window is unchanged.

Local implementation commits:

- `3a3d64a test: verify checkout seller and pickup actions through real requests`
- `8ca0f47 test: verify Mother Hub custody delivery proof and buyer receipt`
- `9b44864 test: expose missing recovery audit and settlement gates`

The documentation/evidence commit has subject `docs: record cross-role fixture evidence and remaining gates`; its generated hash is reported in the handoff. The machine-local guide and test artifacts remain ignored and untracked. Current tracked content, reachable branch history and commit messages were checked for private task-service wording. Only this branch's tracking and related task notes were updated; other sessions' rider-mobile work/timers were left untouched. No push or merge was performed.

### B14 Prerequisite Selection and Logistics Sorting Repair: October 7, 2026

The selected B14 objective now includes its required role-owned prerequisites in separate verified batches before admin exception oversight. The first batch, `fix/logistics-operational-inputs`, starts from merged cross-role fixtures at `4e3a66d` (PR #63). Subsequent batches may be stacked locally on verified predecessor commits, with explicit review dependencies. This does not authorize B15-B18, establish release readiness, or remove the user-only publication/merge boundary.

**Prerequisite audit:** A fresh isolated selection of custody, courier hardening, failure/checkpoint and real-world exception suites has **105 tests, 3,686 assertions, 22 failures and zero errors**. All 22 class/method/failure-type identities match the recorded cross-role fixture result. Eighteen exercised failure/return cases stop at unsupported `delivery_failed`; three persistence cases demonstrate checkpoint mutation/deletion; one financial case lacks the later settlement ledger. Code inspection also finds generated manifest-number strings without durable manifest records and optional, unverified counter claims. These are missing prerequisites, not implemented B14 oversight or proof of a foreign-actor bypass.

**Sorting behavior:** `LogisticsSortingInputService` validates raw controls before normalization, canonical location text, bounded plain notes, positive existing parcel IDs, and visible ASCII bin identifiers. The root and subdomain sorting URLs retain controls for the validator instead of silently trimming them away. A supplied barangay must match the recorded destination case-insensitively. Omitted fields still use that destination and a generated bin; a missing/unsafe legacy destination or bin fails visibly instead of inventing `GENERAL`. Generated bin identifiers transliterate permitted location names to ASCII. Invalid requests preserve the order, parcel and custody history; stale sorting and checkpoint-write failure cannot create partial work.

**Focused verification:** **85 tests, 4,321 assertions pass**, including 45 new sorting cases and the existing routing/governance boundary suite. New cases exercise both portal URLs, Greek/full-width/control/markup/overlong values, normalized locations, valid ASCII separators, optional defaults, destination matching, wrong facility/role, stale state, legacy missing destination and audit rollback. Pint and whitespace checks pass. Tests use SQLite `:memory:` with explicit testing environment overrides; no PostgreSQL reset, frontend change or rendered/device check is claimed.

The fresh full-suite baseline at `4e3a66d` has **2,241 tests, 30,146 assertions, 47 failures and zero errors**. The final run has **2,286 tests, 33,419 assertions, 45 failures and zero errors**. All 45 added cases pass; every original case remains. Exact class/method/failure-type and stable assertion-context comparison finds **two resolved failures, 45 unchanged failures and no new failures**. The resolved cases are `B12_to_B17_LifecycleHubToCompletedBoundaryTest::test_t2_b13_02_invalid_destination_area_rejected` and `B26_to_B33_HubRoutingAndGovernanceBoundaryTest::test_t2_b27_03_bin_format_string_validation`. The operational sorting-input gate passes; the full suite remains red for retained custody-history, recovery, notification and finance contracts. No broader Phase 0, custody, recovery, counter, notification or financial gate is certified yet.

**Scoped assessment:** Logistics sorting input safety **3/10 -> 8/10** for the verified field, destination, rejection and rollback behavior. Overall admin/cross-role readiness remains unchanged. Current code preserves normal sorting while preventing malformed or misleading bin/area records; PostgreSQL contention, legacy data review, physical transfer evidence and later workflows remain separate requirements.

Local implementation commits:

- `dab7fa2 fix: validate logistics sorting inputs before custody changes`
- `31cd5a1 fix: keep sorting bin identifiers in visible ASCII`

The documentation/evidence commit has subject `docs: record logistics sorting repair and selected B14 prerequisites`; its generated hash is reported in the handoff. Local links, code fences and whitespace checks pass. No new migration, frontend rebuild, push or merge was performed.

**Next boundary:** Re-evaluate the wider Phase 0 entry-point matrix against these repairs before certifying that gate. Then verify Phase 2 submitted-waybill handoff evidence, durable draft/sealed/dispatched/received/closed manifests and retained checkpoint history. Phase 3 attempts, reviewed retry, reverse-route seller receipt, secure seven-day pickup and accountable recovery follow their source writers. B14 may assign responsibility and resolve cases only against those verified records; assigning a reviewer does not transfer parcel or cash custody. Required source notices/counter collection remain bounded prerequisite work, not permission for broader governance notifications, reconciliation, settlement or finance screens.


### B14 Prerequisite Profile and Shopping Bag Repair: October 7, 2026

**Scoped input repair verified; B14 and the wider Phase 0 gate remain incomplete.** Branch `fix/phase0-profile-and-bag-inputs` is deliberately stacked on the verified sorting/documentation predecessor `06f24dd`. This is the fourth supplemental B13 repair in the selected B14 prerequisite sequence. No publication, merge or later admin/finance implementation is implied.

**Profile behavior:** Existing buyer, seller and courier writers reuse application name, Philippine contact and case-insensitive email rules through `ProfileInputService`. Valid contacts store canonical `+63...` values; email domains normalize without changing their local part. Raw controls are retained until validation instead of being silently removed. Malformed names/phones and case-insensitive duplicate emails return field errors before account/avatar changes. Generic profile reads and updates require current positive account eligibility, and mutation validation repeats against the locked current account. Reviewed identity still requires its separate correction process. Valid contact changes preserve order and waybill snapshots. Optional contacts and the existing avatar controls remain available.

**Shopping Bag behavior:** Quantities accept positive ASCII whole numbers only, with the existing 1-99 and stock/variant limits. Signs, booleans, controls, exponent/decimal text, look-alike digits, fractions and arrays reject before line changes. Omitted/null add quantities still default to one. Existing line ownership, combined stock limits and stock reservation behavior are preserved.

**Verification:** The combined profile, buyer Bag/access, seller profile, courier hardening, identity-correction and shared-portal selection passes **368 tests, 4,761 assertions**. There are **71 added cases**: 58 profile cases and 13 Bag cases. Coverage includes root/subdomain writers, restricted generic reads/updates across all five roles, malformed legacy names, email collisions/normalization, retained avatars on invalid removal/replacement requests, immutable order contacts, valid quantity boundaries/defaults and foreign-line protection. The seller avatar assertion now expects the documented canonical contact value. Changed PHP formatting and whitespace checks pass. No frontend, migration or browser/device change is claimed.

The unchanged predecessor application supplies the full-suite baseline: **2,286 tests, 33,419 assertions, 45 failures, zero errors**. Only documentation changed between its fresh final run and this branch's start. The final isolated suite has **2,357 tests, 33,703 assertions, 45 failures, zero errors**. All added cases pass, every original case remains, and there are no skips. Exact class/method/data-set identities, failure kinds/types and stable assertion/validation context match: **zero new, resolved or changed failures**. Random generated order references are normalized for the context comparison. Custody retention, attempts/recovery, notifications and financial prerequisites remain real failures; this is not a passing full suite.

**Scoped assessment:** Profile input/access safety **3/10 -> 8/10**; Shopping Bag quantity parsing **7/10 -> 9/10**. People receive field errors instead of saving malformed contacts or hitting duplicate-email server errors, restricted accounts cannot use the generic profile bypass, and rejected quantities preserve existing lines/stock. These are engineering assessments of the exercised boundary. Full avatar-filesystem failure atomicity, broader operational input/history gates and simultaneous PostgreSQL contention are not established by these checks; overall admin and cross-role readiness is unchanged.

Local implementation commits:

- `7fa7cc6 fix: validate profile contacts and current account eligibility`
- `c8843a3 fix: require ASCII whole quantities before Shopping Bag changes`

The documentation commit has subject `docs: record profile and Shopping Bag prerequisite evidence`; its generated hash is reported in the handoff. The next bounded prerequisite is actual pickup/origin-hub scan evidence and retained checkpoint history, before durable manifests and Phase 3 recovery/secure counter work. Required phase gates remain open until their own acceptance paths pass. Local task records preserve unrelated fields and other sessions' rider-mobile work. No push or merge was performed.

### B14 Prerequisite Submitted Scans and Retained Custody Evidence: October 7, 2026

**Scoped custody evidence verified; manifests, recovery, secure counter work and B14 remain incomplete.** Branch `feat/logistics-custody-evidence` is deliberately stacked on the verified profile/Shopping Bag predecessor `5a3e1e0`. This role-owned batch establishes actual seller/pickup-rider/origin-hub evidence before the following durable manifest batch. It does not certify the wider Phase 0 gate or complete Phase 2.

**Behavior:** Pickup requires the assigned rider's submitted matching waybill on root and subdomain portals and in direct lifecycle calls. Visible ASCII codes accept ordinary surrounding spaces and case normalization; missing, foreign, hidden, look-alike, control and overlong codes reject without changing the parcel or history. Hub scans retain the actual tracking code or existing order-number alias, reject ambiguous legacy aliases, and validate bounded notes/action/source inputs. Inspection remains read-only. Actor, account, company, assigned facility, commercial-state and proof gates remain server-side. Same-actor retries retain the original handoff; another eligible handler cannot claim that record as their own retry. Going off duty still permits authorized owned work.

**Evidence:** New checkpoints have generated references, actor-role snapshots, source/target state and before/after custody snapshots. Pickup assignment retains the seller's custody rather than inventing collection. The compatibility `courier_pickup` row references its primary pickup checkpoint. Seller waybill preparation is distinguished from a submitted scan; buyer receipt confirmation records commercial completion without inventing a barcode scan or another handoff. Model and SQLite/PostgreSQL guards protect checkpoint updates/deletion, raw writes, timestamps and reference-erasing parent deletion. Migration rollback refuses to remove evidence protection while records exist. Legacy enrichment fields stay null; absent custody remains explicitly unknown. Prototype transport, unscanned final-mile handoffs and unsecured counter release remain gaps rather than fabricated manifest, recipient or counter evidence.

**Verification:** The custody/access/proof/route/history selection passes **484 tests, 8,675 assertions**. After the buyer-confirmation correction, its affected selection passes **177 tests, 2,371 assertions**. All **36 added custody cases** pass in the final suite. Coverage includes both portals, actual normalized scans, wrong/invalid codes, original retries, foreign-handler retries, model/bulk/raw history edits, retained parcel/order/item/product/shop/company/hub/actor references, audit-write rollback, guarded migration rollback, preserved legacy rows and buyer-only confirmation. Existing valid pickup fixtures now submit actual waybills; their authorization and failure assertions are preserved. PHP formatting, whitespace and the TypeScript/Vite production build pass. A host test invocation was blocked by container-owned proof fixtures; the affected checks and final suite ran through Docker using isolated SQLite `:memory:`. No browser/device or scanner-hardware testing is claimed.

The unchanged predecessor application supplies the baseline: **2,357 tests, 33,703 assertions, 45 failures, zero errors**. The final suite has **2,393 tests, 34,572 assertions, 42 failures, zero errors**. Every original case remains, with no skips or new failures. Exact class/method/data-set identities and stable failure kinds/types/contexts establish three resolved cases: `B18_to_B20_FailureAndCheckpointsBoundaryTest::test_t2_b20_01_updating_existing_checkpoint_barred`, `::test_t2_b20_02_deleting_checkpoint_record_barred` and `::test_t2_b20_05_tampering_with_created_at`. The other **42 failures retain their original context**. Later delivery recovery, notifications and financial gates remain red; this is not a passing full suite.

**Migration:** Only `2026_10_07_100000_retain_custody_checkpoint_evidence.php` was applied to local PostgreSQL. It adds eight nullable evidence fields and nine installed PostgreSQL guards; SQLite tests exercise ten guards. Before/after counts and hashes of every original column in the nine inspected source tables are identical, including all 11 existing checkpoints. No legacy fields were enriched, development transactions fabricated, or unrelated pending demo migration applied. Deploy this additive migration before using the new writers. PostgreSQL guard installation is verified through its catalogue; destructive runtime tests and simultaneous PostgreSQL contention were not performed. Existing populated history intentionally prevents migration rollback.

**Scoped assessment:** Pickup/origin-hub evidence **3/10 -> 8/10**; checkpoint retention **2/10 -> 9/10**. People must submit the parcel's actual code, while retries and rejected actions preserve the original evidence. Durable manifest departure/receipt, cross-region destination-Mother custody, final-mile recipient/scan evidence, attempts/reverse routing, secure pickup, cash foundations and accountable restricted recovery remain required work. These ratings do not raise overall admin or project readiness.

Local implementation commits:

- `c9a6d1d feat: retain actual waybill scans and immutable custody evidence`
- `160f5fb feat: collect the actual waybill in the rider pickup action`

The documentation commit has subject `docs: record custody prerequisite verification and remaining gates`; its generated hash is reported in the handoff. Local task completion concerns only the verified submitted-scan/history batch. The next selected batch is durable manifest custody; B14 remains blocked on its other applicable source gates. No push or merge was performed.

## Delivery Phases

Work on one phase at a time. Do not begin a later phase until the current phase has focused tests and its cross-role acceptance path passes.

Every phase must also pass the mandatory acceptance gate in `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`. A happy-path test alone is not completion.

### Phase 0: Security and Lifecycle Entry-Point Lockdown

**State: Partial.** The main rider lifecycle mutation-path closures are implemented. Simulator, public tracking, and direct admin custody mutation paths are removed; root and subdomain seller, courier, hub, and admin portals share positive account eligibility, including non-active admin denial and role/action boundaries. Account roles are fixed at creation; admin conversion controls/routes and their coupled status shortcut are removed. Rider transitions lock order and parcel, reject terminal or mismatched commercial states, require stored delivery proof, and preserve completed evidence on retries. Proxy-aware tracking throttling and consistent legacy courier approval checks are implemented and tested. Private verification uploads, authorized document access, legacy-file migration, and secret-mail protections are implemented. KYC decisions now have evidence/session/version checks, atomic history, safe retries, and independent restriction preservation. Adult worker birth dates are validated at registration/correction/approval; known underage reviewed users cannot access worker/admin portals, while missing-date legacy accounts still need a controlled audit. B01 enforces master-category scope for new registration, owned unreviewed correction, and original-shop KYC approval. B02 shares canonical application-field rules across registration, correction, and review. B03 implements independent shop decisions, fresh owned context, and positive shop/category commerce eligibility; legacy shops require actual review. B04 shares positive company/facility/assignment checks across resource reads, placement, routing, dispatch, and custody actions. B05 aligns buyer holding, sign-in, protected work, and narrow owned-order access. B06+B07 implement separate reasoned account/resource restrictions, last-admin continuity, and retained affected-work responsibility. B08+B09 implement reviewed correction and role-wide closure decisions, including evidence retention and unresolved cash/work blockers. B10-B12 implement reasoned product compliance, read-only audit search, separate account context and recorded overview data. B13 removes sample dispute/response/top-up success and the invented buyer wallet balance. The supplemental commerce repair now verifies canonical checkout/saved-address inputs, original-result retries and atomic optional address saving. Profile/Shopping Bag inputs now have the scoped repair described above. Other operational input/history gates and the remaining cross-role acceptance scenarios still keep Phase 0 incomplete.

- Keep simulator advance/reset routes removed in every environment; use real role flows in tests.
- Keep public tracking read-only, masked, and rate-limited; authenticated actions belong in their authorized portal and lifecycle service.
- Keep both courier portals restricted to active approved couriers, including read endpoints; going off duty must not prevent finishing an existing custody assignment.
- Keep direct admin custody overrides unavailable until lifecycle validation and immutable correction audit records exist.
- Keep verification files private with applicant/reviewer authorization; deploy the legacy-file protection and migration before exposing the updated review flow.
- Preserve secret-mail transport guards and safe OTP/reset failure handling; never log secret-bearing email or flash OTP, verification, reset, or claim codes into old input.
- Establish shared canonical validators for names, phones, postal codes, codes, plain text, files, and role-specific registration fields.
- Keep shared positive root/subdomain account gates; complete related shop/company/facility eligibility and buyer-entry alignment while keeping holding and existing-order/recovery exceptions narrowly authorized.
- Preserve transactional KYC approval/rejection, private evidence checks, immutable history, and safe identical retries; complete role/account/category prerequisites. Build suspension/reactivation as separate reasoned decisions with active-work protections.
- Keep account roles fixed at creation; do not add role conversion or migration. Another public role requires a separate account and its normal approval.
- Block deletion that would lose active transactional history; preserve active work and recovery responsibility during restrictions.
- Remove fake proof, sample dispute/message success, and seeded operational fallbacks from live paths.

Acceptance: direct URLs, stale pages, alternate portals, simulators, and malformed inputs cannot bypass ownership or lifecycle rules; secrets and KYC files are not publicly exposed.

Next Phase 0 work: B01-B12 are merged into `main` at `3db99d8`. The local B13 audit/repair branch records passed scoped governance checks, eleven resolved baseline failure identities and 48 remaining failures with individual root causes/owners. Resolve the confirmed commerce-input/idempotency/address-save gaps and canonical fixture corrections, then repeat the acceptance gate. B14 prerequisites are selected in verified local batches; admin exception oversight still waits for role-owned custody, manifest and recovery evidence. Preserve fixed roles, adult-worker validation and the verified KYC/shop/resource foundations; retry/RTS, notifications and COD persistence retain their documented phase order.

B06+B07 share `admin/governance-restrictions`; B08+B09 share `admin/identity-and-closure-safety`; B10-B12 share `admin/moderation-audit-and-overview`. Each batch retains separate acceptance gates and combined regression/build checks. Local completion does not imply user publication/merge or waive B13 and later phase gates.

### Phase 1: Normal Order and Seller Flow

**State: Implemented and covered by focused tests.**

- Split selected Shopping Bag items into one transactional order, delivery, waybill, shipping fee, and route per shop.
- Limit shop vouchers to their shop and proportionally allocate platform voucher discounts.
- Enforce `PLACED -> CONFIRMED -> PREPARING -> READY_FOR_PICKUP` centrally.
- Permit seller cancellation only before pickup claim or custody.
- Reject checkout when a complete logistics route cannot be created.

Acceptance: two-shop checkout produces two isolated fulfillment units; invalid transitions and routing failures roll back safely.

### Phase 2: Mother-Hub and Manifest Custody

**State: Partial.** Durable manifests and their actual load, dispatch, individual receipt, discrepancy and closure actions are scoped verified below. Ordered hub scans and mandatory Mother-Hub custody are enforced. Genuine extra/wrong-hub physical recovery and simultaneous PostgreSQL verification remain open.

- Add feeder and line-haul manifests with source, destination, vehicle, dispatcher, receiver, timestamps, and included parcels.
- Require manifest outbound and receiving-facility inbound scans.
- Require at least one Mother Hub and enforce handler facility and logistics-company scope.
- Keep internal movement under buyer-facing `AT_SORTING_CENTER`.

Acceptance: same-region and cross-region parcels cannot skip their required Mother-Hub custody.

### Phase 3: Delivery Exceptions and Self-Pickup

**State: Partial.** Durable attempts, actual hub returns/retries, reverse manifests/seller receipt, secure one-time pickup/holding/expiry, minimum holding/return notices, exact counter collection and narrow restricted-courier handover are scoped verified below. B14 oversees these real sources. Unsupported restricted seller/resource/carrier recovery and broader notification/COD reconciliation gates remain open; this is not whole-phase certification.

- Record each delivery attempt with rider, number, reason, notes, proof, attempt time, hub return, and retry date.
- Allow retries after attempts one and two; begin reverse routing after the third failure.
- Set `RETURNED` only after authenticated seller receipt.
- Secure self-pickup with a hashed one-time code, correct-hub and ready-state checks, identity confirmation, COD collection, seven-day expiry, and day-three/day-six reminders.

Acceptance: custody always returns to the hub after failure; expired or invalid pickup claims cannot release a parcel.

### Phase 4: Basic In-App Notifications

**State: Implemented; selected lifecycle and governance scope verified.**

- Persist owned unread/read notifications linked to actual order, delivery, decision, placement, and recovery sources; stable pagination and owned idempotent acknowledgement are implemented.
- Cover meaningful order, custody, failure, pickup, buyer completion, and RTS events without implying early reconciliation or settlement.
- B15 connects recorded review, restriction, correction, applicable closure, moderation, placement, and accountable recovery decisions after the persistent notification foundation. Source/recipient uniqueness and immutable intent retain the original event through retries.
- Rider boards and assignment queues remain operational task notifications. Remittance/reconciliation notices depend on their later Phase 5 source events.

Acceptance: the focused real-source, ownership, rollback, outage/retry, privacy, and current-link checks pass. The combined full comparison retains every original case and finds no added failures, errors or skips. Install the additive notification migration and run the existing scheduler for unattended retries.

### Phase 5: COD and Admin Reconciliation

**State: B16-B18 local financial gate verified.** Exact collection, confirmed handovers, immutable differences and independent platform reconciliation feed evidenced seller payment and scoped financial oversight. The October 8 B16 and October 9 B17+B18 reviews record the controls and green final full suite. Deployment and simultaneous PostgreSQL execution remain unverified by the selected finance batch.

- B16 preserves collection, rider-to-hub and hub-to-platform receipts, separate expected/received/held/reconciled cents and append-only adjustment evidence.
- Buyer completion and COD reconciliation are independent prerequisites for B17's product-only 90%/10% accounting. Authorization and actual recorded payment are separate, with private receipt evidence and retained source/recipient snapshots.
- Existing financial acceptance scenarios execute real handovers, reconciliation and settlement while retaining their amount, recorded shipping and duplicate-record assertions.
- B18 reads original cash and settlement records, links totals to their included sources, and applies company/seller privacy and original collection dates. Shipping charges remain separate; unsupported logistics income and rider payouts stay unavailable.

B16-B18 acceptance: delivery or buyer completion alone does not reconcile COD or settle proceeds. Current Platform Admin records complete received cash and independently evidenced payment; original records remain retained. The selected source-backed financial checks and full regression suite pass locally. Unverified legacy records, refunds, external banking, unsupported earnings and live/runtime verification are not completed by that result.

### Phase 6: Cross-Role Cleanup

**State: Partial.** Rider ownership, persistent availability, phase-limited task data, delivery-linked messaging, real proof upload, truthful profile/trip history, and normal-path cross-role validation are complete. Failed-delivery recovery, COD reconciliation, and broader portal cleanup remain.

- Use the assigned final-mile rider for delivery earnings.
- Present canonical statuses and Mother-Hub checkpoints consistently in every portal.
- Remove misleading sample dispute data and fake success responses; show unfinished dispute handling as unavailable.
- Run the complete buyer-seller-rider-logistics-admin transaction suite.

Acceptance: each portal shows the same commercial state while exposing only role-appropriate actions.

## Deferred Scope

Do not add these items while completing the core flow:

- Maritime or air freight.
- Live GPS fleet tracking or route optimization.
- AI dispatch, density prediction, or automated warehouses.
- External email, SMS, or push notification services for order events.
- Advanced rates, fleet analytics, or unrelated dashboards.
- Complete refunds, exchanges, and post-delivery dispute processing.

## Reusable Implementation Prompt

```text
Continue improving the BagooPH core cross-role transaction flow.

Read AGENTS.md, docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md, docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md, docs/CORE_FLOW_ROADMAP.md, and only the role documents relevant to the selected phase. Treat docs/SORTING_CENTER_LOGISTICS_FLOW.md as authoritative for physical custody.

Work only on Phase [NUMBER AND NAME]. Inspect current code before editing and update the roadmap evidence if the implementation differs from its audit. Preserve one order per shop, mandatory Mother-Hub custody, buyer-only completion, separate COD custody, the 90/10 product-subtotal split, and existing seed accounts.

Do not implement deferred scope or features from later phases. Keep business rules in backend services, enforce authorization and transitions server-side, test malformed input, wrong actors, stale states, duplicate requests, concurrency, rollback, and audit behavior, use isolated SQLite :memory: tests, and run the production frontend build for UI changes.

Create small logical local commits, report every hash and subject, and never push.
```


### B14 Prerequisite Durable Manifest Custody: October 7, 2026

**The scoped durable manifest writers and portal are verified; B14 and the remaining delivery recovery/secure-pickup gates remain open.** Branch `feat/logistics-manifest-custody` is deliberately stacked on custody predecessor `0c7cc73`. This role-owned batch does not complete B14 oversight or the remaining attempts/retry/RTS, secure counter, recipient handoff, cash, and restricted recovery prerequisites.

The new retained manifest, membership and event records separate draft preparation, actual outbound scans, sealed list, departure, individual receipt and closure. The server generates references and records the owning company, source and destination, actual vehicle/driver, actors, timestamps and per-parcel scans. Company managers prepare/seal/correct drafts/close; assigned facility handlers load, dispatch and receive; Platform Admin reads history without floor authority. Sealing freezes membership; original retries preserve the original event and conflicts/stale versions fail before changes. An active-membership constraint blocks duplicate loading.

Each physical departure/receipt links the checkpoint to its actual immutable manifest scan. Loading/sealing alone do not transfer custody. Normal flow uses the original Mother Hub and, when different, the destination Mother Hub before the destination Bayan Hub. A changed required hub tier cannot turn receipt into a shortcut. Preparation and departure require closed vans for feeder runs and wing trucks between Mother Hubs. Vehicle type is retained in the immutable source events; the preparation form filters the correct resource. Legacy feeder/line-haul reference fields derive from the actual manifest type, rather than the destination status.

Partial arrivals retain each valid individual receipt. Missing, damaged, duplicate and unexpected observations remain append-only and do not advance the parcel. Closure requires the actual per-parcel receiving event and linked custody checkpoint and no unresolved discrepancy. A later actual receipt supports missing/damaged resolution; an assigned-handler correction can explain a waybill-entry error only when the correctly identified parcel has an actual receipt. A genuinely extra/wrong-hub physical parcel remains open until supported handling/recovery exists; a manager reason cannot fabricate handoff. Every correction/resolution retains its original source and supporting event.

Existing restriction and closure reviews now include actual carrier driver/vehicle references and recorded custody. Restriction still preserves physical custody and unverified cash. An in-transit carrier cannot claim pickup/final-mile work or change placement. Hub navigation and scan prompts open the manifest workflow instead of offering an unsupported status-only transport action. Actual waybill controls support typed/hardware-scanner input and an optional native camera reader. A camera read fills the actual field and stops; it does not automatically record a physical handoff. Unsupported camera access uses the manual/scanner fallback. Existing lifecycle helpers exercise the real actions, including explicit destination inspection before sorting.

**Verification:** the affected run passed **158 tests / 7,341 assertions**. After vehicle compatibility refinement, manifest and seeded cross-role checks passed **70 tests / 3,464 assertions**. The final full isolated SQLite `:memory:` run has **2,462 tests / 37,665 assertions / 42 failures / zero errors**, compared with **2,393 / 34,572 / 42 / zero** before this batch. All **69 new cases pass**; no cases were removed or skipped and no new failures appeared. The 42 existing class/method, failure-kind/type and assertion-context identities remain unchanged. One recovery assertion compares generated user IDs; actual carrier fixtures shift those IDs, so its comparison preserves the unequal identity relationship rather than sequence numbers. Raw diagnostics remain available locally. The production TypeScript/Vite build passed, as did scoped style and diff checks. Camera/device behavior and rendered UI interaction were not exercised.

The first wider checks exposed a sending-hub selection left in the real-flow helper; destination inspection through the actual endpoint corrected it without weakening authorization. Another seeded lifecycle test still expected a status-only transport confirmation; it now prepares and operates real manifests while preserving its stock, payment and buyer-completion assertions. No fixture directly inserts manifest or handoff evidence to make writer acceptance pass.

Deployment: migrations `2026_10_07_110000_create_durable_logistics_manifests.php` and `2026_10_07_120000_retain_manifest_leg_type.php` installed additively locally. Counts/hashes of original columns in nine inspected existing tables and the custody guards stayed identical. The new manifest tables remain empty, and PostgreSQL catalogue inspection confirms four distinct manifest triggers. There were no synthetic development custody transactions or destructive PostgreSQL tests. Elsewhere apply the prerequisite custody migration and these additive migrations before serving the manifest screens. SQLite acceptance and a PostgreSQL guard catalogue do not prove simultaneous PostgreSQL contention or physical-device/camera behavior.

**Scoped assessment:** manifest custody **2/10 -> 8/10**. The recorded list, actors, vehicle, scans and discrepancy evidence now explain actual parcel transport and prevent unsupported closure. Overall Phase 0/2/3, B14 and project readiness remain open. Local commits:

- `c944fc2` — `feat: retain durable manifest membership and event history`
- `3a3dd48` — `feat: enforce actual manifest transport scans and receipt evidence`
- `793c990` — `feat: manage and scan hub manifests through the logistics portal`

The evidence commit has subject `docs: record verified manifest custody and remaining B14 prerequisites`; its generated hash is reported in the handoff. No push or merge was performed. The next approved bounded batches establish actual attempts/retry, reverse-route seller receipt, secure seven-day pickup and buyer receipt confirmation, together with only their necessary source notices/cash/restricted recovery foundations. B14 oversight follows those source writers; broader governance notices, finance reconciliation/settlement and later numbered branches are not selected.


### B14 Prerequisite Delivery Attempts and Reviewed Retry: October 7, 2026

**The scoped attempt and retry writers are implemented; B14 and reverse/secure-pickup prerequisites remain open.** Branch `feat/delivery-attempt-recovery` is stacked on manifest evidence commit `b70a427`. This batch does not claim seller return, counter release, recipient/COD collection, restricted recovery or admin exception completion.

Each failed attempt retains its server reference, numbered attempt, assigned rider and role, actual submitted waybill, allowlisted reason, useful notes, submitted location, private image/hash and server time. The record links the real final-mile departure checkpoint and original request. Model and database guards retain attempts and recovery events; foreign-key retention protects their parents. Direct status-only failure has no authority to manufacture an attempt. Original matching requests preserve the record; changed evidence or an additional failure for the same departure is rejected. Private proof routes enforce actor/facility scope and do not serialize file paths or request credentials. Each upload uses an independent server filename, and rejection/rollback removes only the new unused proof.

Final-mile departure now requires the rider's actual waybill and real destination-hub custody. Failure retains rider custody even on the third attempt. Only an assigned destination handler's matching return scan ends that custody and releases the rider's assignment/work. The event retains the original rider and source/target state. A non-retryable refusal or third failure enters `return_to_sender` at this actual receipt; the customer order remains `delivery_failed` until a later authenticated seller receipt. No stock or payment mutation substitutes for that path.

A company administrator reviews a retryable first/second failure only after actual hub receipt and chooses a future Asia/Manila date. The due date gates the handler's scan back into the sorting queue; ordinary barangay sorting, eligible assignment and another actual departure follow. Immutable attempt references prevent a stale receipt/retry/review from acting on a later failure that happens to have the same status. The original approval retains its reviewer, date, notes and time on identical requests. Riders cannot self-schedule or exceed three total attempts.

The rider form collects actual scan, reason, location, notes and proof and keeps a failed parcel visible until hub receipt. The owning hub recovery page shows real history/private proof and offers review only to its company administrator. Floor scans distinguish inbound return, waiting review/date and due retry. Both portal URL variants use the same rules. Existing positive final-mile tests submit actual scans; one old status-only custody fixture now reaches destination custody through real checkout and manifest actions while retaining its approval/ownership assertions.

**Verification:** affected checks pass **49 tests / 3,368 assertions**, including **32 new acceptance cases**. After fixture refinement, focused attempt/rejection checks pass **37 / 3,189**, and the attempt plus newly exercised legacy retry cases pass **35 / 3,106**. The wider regression run has **104 tests / 4,576 assertions / eight known later-flow failures / zero errors**. The final full isolated SQLite `:memory:` comparison has **2,494 tests / 40,893 assertions / 28 failures / zero errors**, against **2,462 / 37,665 / 42 / zero** beforehand. All 32 new cases pass; 14 old failure/retry gates are resolved, with no new failures and no removed or skipped cases. This is a scoped gate with disclosed later-flow failures, not a passing full suite. The production TypeScript/Vite build and scoped 23-file style/diff checks pass; rendered/device behavior was not exercised.

The checks cover both portals and the full three-attempt loop, wrong riders/hubs/reviewers, invalid reasons/notes/proof, stale attempt references, future/past/impossible dates, repeated submissions, proof cleanup, audit rollback, raw history mutation and private proof access. Existing F25 reschedule and combination cases now call the actual reviewed retry chain and its `delivery_rescheduled` scan. Invalid-code tests submit otherwise valid uploads, so unrelated missing fields cannot satisfy their rejection assertions. A shared fixture initializes private proof storage once per test instead of erasing earlier attempts.

The first full run found seven older requests/expectations that omitted the required departure scan or treated canonical failure as an unsupported alias. Those tests now submit the scan and distinguish real legacy aliases from a canonical failure forbidden on pickup work, preserving their ownership, state and evidence assertions. Ten remaining reverse-flow failures advance beyond the former unsupported failure request to the still-missing `returned`/`parcel_returned` evidence gate: F19 cases 01-05, F25 case 04, B19 cases 01/03/05 and combination case 16. Exact class/method, kind/type and assertion contexts were compared and retained locally. Their changed failure location is recorded as progress into an unfinished source contract; it is not hidden or classified as a new passing return path.

Migration `2026_10_07_130000_create_delivery_attempt_evidence.php` applied additively locally. Counts and complete row hashes of 12 checked existing tables, including custody and manifest history, stayed identical. The two new tables are empty; PostgreSQL catalogue inspection confirms their two immutable triggers. No synthetic development delivery/cash records or destructive PostgreSQL tests were used. Apply the prerequisite migrations before this migration elsewhere. SQLite tests and catalogue inspection do not certify simultaneous PostgreSQL contention or rendered/device behavior.

**Scoped assessment:** recorded attempts and reviewed retry **3/10 -> 8/10**. Actual evidence now explains each failed visit, hub return and approved retry, and the three-attempt boundary is enforced. Overall Phase 0/2/3, B14 and project readiness remain open. Reverse manifest transport, seller-return staging/receipt and secure counter handling follow in separate authorized batches. Local implementation commits:

- `d10016f` — `feat: retain immutable delivery attempts and recovery events`
- `dd3414b` — `feat: require recorded delivery attempts and reviewed retries`
- `326acc4` — `feat: show failed deliveries and reviewed hub recovery`

The evidence commit has subject `docs: record verified attempts and remaining return prerequisites`; its generated hash is reported in the handoff. No push or merge was performed.

## B14 Prerequisite Reverse Custody and Seller Receipt: October 7, 2026

**The scoped return writers are implemented and verified; B14 remains open.** Branch `feat/logistics-return-custody` is stacked on attempt evidence commit `393663f`. It adds the return path after an actual non-retryable failed-attempt hub receipt. Secure counter expiry still needs its own source writer in the following approved batch; this batch does not fabricate that evidence or complete restricted recovery, notifications, cash collection or admin oversight.

The return route freezes the original waybill and route supported by actual outbound manifest receipts. It follows destination Bayan -> destination Mother -> origin Mother when different -> origin Bayan. Reverse manifests retain an immutable direction and use the existing manager list, assigned-handler load/departure/receipt, vehicle/driver, discrepancy and closure rules. A route whose endpoint Bayan IDs coincide still visits its Mother Hub; arrival at the starting Bayan is not enough to stage the seller return. Each next leg is derived from actual linked reverse receipts. Missing or changed legacy source evidence blocks recovery instead of reconstructing a successful return from status.

The customer order stays `delivery_failed` throughout reverse transport. After all real receipts and closed manifests, an assigned origin handler scans the parcel into seller-return staging. Only the currently eligible owning seller/shop's original-waybill receipt makes the parcel and order `returned`. All items must belong to that selected shop. Retained events/checkpoints link actor, role, hub, original scan, source/target custody and request evidence. Identical receipt retries preserve the original event; changed evidence conflicts, and a failed checkpoint write rolls back the terminal state and receipt. Neither hub staging nor seller receipt changes stock, payment or settlement.

Managers can select return direction in the manifest form; the floor scanner directs reverse transfers through those recorded manifests and offers actual origin staging. The seller order page exposes receipt only for a staged return. Public tracking maps real reverse checkpoints to non-sensitive labels. A genuine failed-attempt hub receipt continues to release the former rider's workload while the parcel travels back through other hubs.

**Fixture corrections:** F19, B19, F25 and combination return cases now perform actual reverse manifest requests, origin staging and owning-seller receipt. The first retryable F25 return fixture now records customer refusal rather than inventing automatic RTS after one unreachable visit. Three older inventory expectations assumed automatic return restocking; they now require unchanged stock, following the baseline's explicit pre-pickup cancellation restoration policy and this return batch's unchanged inventory boundary. Receipt replay still retains exactly one terminal checkpoint. The original attempt rider is read from immutable attempt history after the actual hub receipt clears the live assignment. Required notice and later financial gates remain visible.

**Verification:** The final full isolated SQLite run has **2,508 tests, 42,870 assertions, 19 failures and zero errors**, compared with **2,494 tests, 40,893 assertions, 28 failures and zero errors** before this batch. All **14 added cases pass**; there are **nine resolved failure identities, no new failures, no removed cases and no skipped cases**. The resolved cases are F19 01-04, F25 04, B19 01/03/05 and combination 16. The remaining F19 05 case advances through actual seller receipt to its still-missing persistent notification gate. Its changed assertion context is recorded explicitly; the other retained failure contexts match. The known T4 11 generated-user-identity comparison preserves whether identities differ while ignoring fixture sequence offsets. Remaining failures belong to unfinished later source contracts, so this is not a passing full suite.

The final focused selection passes **29 tests and 2,689 assertions**, including **14 new return cases** covering both portal paths, distinct Mother Hubs, same endpoint Bayan, real seller receipt/replay, foreign actors, premature staging, status-only transport, stale route, wrong scan, plain notes, missing legacy evidence, skipped/direction-mismatched loads, restriction, rollback and raw-SQL retention. The affected TypeScript/Vite build passes in **15.44 seconds**; the scoped PHP style check passes for **20 files**, and `git diff --check` is clean. No browser/device check or simultaneous PostgreSQL race is claimed.

Migration `2026_10_07_140000_create_return_custody_evidence` applied successfully to local PostgreSQL. Before/after complete original-column hashes and counts match across **14 existing domain tables**, including the prior attempt/recovery records. Both new return tables have **0 rows**. Two PostgreSQL immutable-history triggers and the separate manifest-direction trigger are present. Existing manifest records receive the truthful outbound direction of their only former writer; their original columns and evidence remain unchanged, and the original outbound creation-request fingerprint remains supported for retries. No reset or destructive seeding occurred. This establishes additive compatibility and preservation, not a live concurrent custody proof.

**Scoped assessment:** reverse transport and authenticated seller receipt **2/10 -> 8/10**. A failed parcel now has an accountable path back through all required hubs and a final seller custody receipt. Overall Phase 0/2/3, B14 and project readiness remain open; secure claims, their notice/cash sources and controlled restricted recovery follow in separate approved batches. Local implementation commits:

- `b2db6ac` — `feat: retain reverse routes and actual return manifest custody`
- `b689ad5` — `feat: require origin staging and owning seller return receipt`
- `94e658f` — `feat: expose return manifests and seller receipt scans`

The evidence commit has subject `docs: record verified return custody and remaining B14 gates`; its generated hash is reported in the handoff. No push or merge was performed.


### B14 Prerequisite Secure Hub Pickup: October 7, 2026

**The scoped secure-pickup writers and both portal paths are verified; B14 remains open.** Branch `feat/secure-hub-pickup` follows return evidence `bc22ee4`. Actual destination-manifest receipt and closure, an assigned handler scan and recorded counter operating hours are required before staging. The original pickup claim retains the buyer, hub, waybill and ready checkpoint, its seven-calendar-day deadline in Asia/Manila, and a cryptographic code stored only as a hash. The owning buyer can obtain the code once through a private no-store response; later requests cannot reveal it. Losing the original code does not authorize an admin release bypass.

Release requires the actual waybill, matching buyer account/name and photo-ID attestation, unexpired/unlocked claim, and counted COD tender with correct change. Five failed verifications produce a retained 15-minute lock; matching request retries retain the original outcome and conflicting evidence fails. The cash entry records the exact order amount and the collecting handler as holder. It does not mark payment paid, reconcile cash or create seller settlement. Genuine collection moves the parcel to `customer_collected` and the order to `delivered`; only the owning buyer can subsequently confirm `completed`. A legacy collected label without its claim, cash entry and actual handoff checkpoint cannot authorize completion.

Day-three/day-six notices and expiry are retained once from actual holding-clock execution. Expiry blocks code issuance/release without inventing a physical scan. The assigned destination handler must scan the parcel to initiate its frozen reverse route; all original reverse manifests and owning-seller receipt remain required. Pickup, holding and seller-return notices contain the actual hub details, hours, deadline and private order link, never the code. The older logistics instruction to include the code in a persistent notification conflicts with the authoritative privacy contract; this implementation follows `CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` and presents the code only once to its owner. Broader dispatch/governance notices and rider collection/remittance remain separate work.

**Verification:** the full isolated SQLite `:memory:` run recorded **2,521 tests / 44,156 assertions / 18 failures / zero errors**, compared with **2,508 / 42,870 / 19 / zero**. All 13 added cases passed; none were removed or skipped and no new failure identity appeared. The actual seller-return-notice case is resolved. The dispatch-notice failure advanced from absent storage to its still-missing actual dispatch notice; the other 17 existing failure identities/types/assertion contexts remained unchanged. A final expiry-crossing check and Inertia rejection check were added afterward; the final focused run passed **16 tests / 1,489 assertions**, including all 14 secure-pickup cases and the actual counter/return-notice regressions. The TypeScript/Vite build passed in 16.41 seconds, with scoped style/diff checks. Browser/camera/device behavior and simultaneous PostgreSQL contention were not exercised.

The additive `2026_10_07_150000_create_secure_pickup_sources.php` migration installed locally in 183.04 ms. Counts and hashes of original columns in **16 existing tables** remained identical; all four new tables stayed empty, and PostgreSQL catalogue inspection confirmed their eight update/delete guard-operation rows. Existing hub hours remain unset until an owning company administrator records actual hours. Other environments need the additive migration and a running Laravel scheduler for `pickup:process-due`; a schedule definition does not establish that deployment cron is running.

**Scoped assessment:** secure collection **2/10 -> 8/10**. The buyer and counter now share an enforceable private claim, deadline, identity and cash workflow. Overall Phase 0/2/3 and B14 remain open; controlled restricted-custody recovery and the actual admin oversight follow as authorized separate batches. Local commits:

- `6477613` — `feat: retain secure pickup claims and counter cash evidence`
- `a615b0d` — `feat: enforce verified pickup, holding expiry and buyer receipt`
- `b5f4e05` — `feat: connect private buyer codes and actual counter release controls`

The documentation commit has subject `docs: record verified secure pickup and remaining B14 gates`; its hash is reported in the handoff. No push or merge was performed.
## Native Rider account access — October 7, 2026

Scoped implementation on `feat/rider-mobile-auth-api`, based on reviewed main
`4e3a66d843e368f158c0f888500c439147751ca7`. Native login and logout previously had
no executable adapter. The new account API shares registration, validators,
private document storage, users and courier profiles with the website. It issues
expiring narrow bearer sessions and constructs own-account JSON; it cannot grant
approval, placement, duty or parcel authority. Pending/rejected accounts receive
own holding information, while restricted/unknown/wrong-role accounts are denied.

`docs/RIDER_ACCOUNT_API.md` is the wire contract. Native signup requires the web
form's email-code verification step and private ID/license/vehicle documents.
Web redirects, cookie sessions and verification-email fallback are preserved.
The schema addition is the personal token table, with expiry and a password
fingerprint; browser cookies do not substitute for a bearer. Password changes
and current account restrictions invalidate native access. No existing PostgreSQL
data was migrated or reset during local verification.

Verification: the focused native plus existing registration/KYC set passed
**20 tests / 180 assertions**. The immutable clean baseline passed 2,194 of
2,241 tests, with 47 existing failures; the account adapter's full run had
2,249 tests / 30,222 assertions and the same 47 failed identities and
failure/error types/causes. Randomly generated BGO order codes were normalized
when comparing failure messages. No new failure identity or cause appeared.
The initial run without frontend artifacts was discarded and rerun against
pristine source with its required manifest available.

Real local HTTP checks passed existing rider login/me/logout/revocation, native
registration with SMTP delivery/private uploads followed by web login, and web
registration followed by native login/logout. A separate synthetic SQLite demo
and loopback SMTP inbox provide repeatable acceptance without production data
or real external recipient delivery. No backend web browser automation was used.

Scoped account-integration assessment: **0/10 before** (no native adapter),
**8/10 for local acceptance after** (shared account/process and negative checks
verified). This does not improve overall lifecycle or operational readiness.
Physical Android, deployed HTTPS/mail/token pruning and production-account
acceptance remain required before native release. Existing Phase 0/financial
fixture failures and later parcel endpoints remain owned by their prior gates.


### Native Rider Account Integration Review: October 7, 2026

The account API has been reconciled with the verified backend through `bc22ee4` in `integrate/rider-mobile-auth-api`. The original `feat/rider-mobile-auth-api` worktree and the separate Rider app were preserved. Source commits `d2c2f7b`, `52da19d` and `485a646` were integrated locally as `aae83f9`, `4edd530` and `c3a8d69`. The roadmap conflict retained both historical evidence sections. Existing native routes, status responses and account fields remain compatible with [RIDER_ACCOUNT_API.md](RIDER_ACCOUNT_API.md).

Shared registration now passes the raw password to the existing hash cast, validates the email token's shape, and consumes that token under a transactional row lock. Failed account persistence rolls back consumption and deletes the newly stored private files; the same verified token can then complete a genuine retry. Native application input retains controls for the shared text validator, a verification code requires exactly six ASCII digits, and native callers cannot supply shop or vehicle placement identifiers. No client input grants approval or changes an existing role.

**Verification:** all **519 authentication/registration tests / 3,984 assertions passed**, using isolated SQLite `:memory:` against the integration worktree and its own autoloader. This includes Rider API acceptance and the existing web registration, application validation, email verification, KYC and private-document cases. The first worktree run identified absent generated Vite assets; providing the existing generated assets corrected that local test setup. A new newline-code case exposed the permissive end anchor and was fixed before the passing run. Scoped style and diff checks passed. No development PostgreSQL data or mobile task records were changed.

**Scoped assessment:** native account integration with the current backend **0/10 -> 8/10**. Compatibility and account/document boundaries are verified locally. This rating does not certify Android behavior, deployed mail/HTTPS, token expiry pruning, operational Rider endpoints or B14. Apply the additive personal-token migration before deploying the adapter; deployment remains user-owned. The verification/fix commit and documentation commit hashes are reported in the handoff.


The Rider adapter is now also integrated into the active prerequisite stack after secure-pickup evidence `7dec923`. Its current-stack commits are `a2f780d` (account API), `2e67460` (isolated demo), `95f827e` (generated-bytecode ignore), `c724e66` (validation/transactional retry fixes), and `c6fecac` (review evidence). The additional conflict in the trim-exclusion list was resolved by retaining both the native-registration and secure-counter paths. The final combined current-stack check passed **53 tests / 1,641 assertions** for Rider API, web registration/private documents and secure pickup. All seven native endpoints were verified through the actual route registry. The additive token migration installed locally in 39.86 ms; the same 16 original-table counts/hashes stayed unchanged. No Rider app files or mobile task records were edited, and no deployment or publication was performed.


### B14 Prerequisite Restricted Custody Recovery: October 7, 2026

The separate `feat/restricted-custody-recovery` batch now supports actual pickup/final-mile parcels physically held by a suspended or inactive courier. A current Platform Admin or owning Company Admin can authorize a specific 24-hour handover from the original recorded restriction and custody checkpoint. Authorization and the rider's acknowledgement leave physical custody, cash and commercial status unchanged. Only the assigned, currently approved handler at the original receiving hub can scan the waybill and retain the receipt checkpoint. The original rider remains restricted; a separate sign-in exposes only current owned handovers, and normal web/native access remains blocked.

Original B06/B07 affected-work records remain immutable. Different company/actor, order-number aliases, exact expiry, newer restrictions and conflicting retries are rejected. A receipt/audit failure rolls back the parcel and new receipt together. Missing physical custody or an unavailable original facility remains a visible source blocker; this batch does not fabricate a delivery failure, reconcile cash, reactivate accounts or provide a generic restriction bypass.

**Verification:** all **11 focused cases / 926 assertions passed** using SQLite `:memory:`. The broader affected run passed 280 cases and exposed one setup error in the added assigned-pickup case; its rider selection was corrected and all 11 final cases then passed. The production TypeScript/Vite build passed in 21.70 seconds; scoped PHP formatting and diff checks passed. The additive recovery migration installed without changing counts/hashes in any of 21 existing PostgreSQL tables; both new tables are empty and their four update/delete guard-operation rows are present. Simultaneous PostgreSQL contention and physical device/camera operation were not tested. Full-suite validation follows the actual B14 implementation, avoiding an additional intermediate full run.

**Scoped assessment:** restricted physical handover **1/10 -> 8/10**. The parcel has an accountable, narrow path back to its original hub without opening new work for the restricted rider. Other resource/carrier restrictions remain source-specific blockers. B14 oversight and broader financial/notification gates remain open. Local implementation commits: `8ab4247` — `feat: require owned restricted handover and actual hub receipt`; `e47f2e5` — `feat: connect isolated custody recovery and receiving controls`. The evidence commit's hash is reported in the handoff. No publication or deployment was performed.


### B14 Exception Oversight: October 7, 2026

The approved source prerequisites were completed in their separate batches before `admin/exception-oversight`. B14 now provides a scoped queue for original restriction affected-work records, retained delivery attempts, actual manifest discrepancies and secure counter holding. Platform Admins can oversee companies; a currently approved Company Admin handles only their own company. Handlers, riders, buyers, sellers, foreign managers and newly restricted administrators cannot use governance commands.

A responsibility decision retains its actor, responsible person, reason and exact source snapshot. It leaves original parcel/cash custody, account restrictions, stock, orders and financial state unchanged. Resolution requires the supporting role-owned records: an original-hub restricted receipt; failed-attempt hub return, reviewed retry, handler scan and new departure; third/refused-attempt reverse transport ending in seller receipt; actual manifest receiving/correction plus its source resolution; or genuine counter collection/expired-holding reverse return. Counter codes/hashes and private proof paths never enter the oversight payload. Original attempt proof is served only to current scoped governance authority, with file identity and no-store checks.

Source changes, newer restrictions, responsibility changes and holding-clock boundaries invalidate the review token. Matching request retries retain the original reference; conflicting requests fail. Decisions are append-only with model and SQLite/PostgreSQL guards, exactly one real foreign source and retained reason/history. An audit-write failure cannot record a partial decision. Missing original custody, ambiguous legacy labels and unsupported seller/resource/carrier recovery remain visible blockers. Closing a source case records its parcel/holding obligation; it does not claim cash reconciliation, payment, settlement or buyer completion.

Verification: all **15 focused cases / 1,341 assertions passed**, including the existing breakdown case completed through actual authorized hub receipt, replacement assignment and final delivery. Root, admin and hub host paths, own/foreign scope, current eligibility, retries/conflicts, stale source, genuine retry/RTS, holding privacy/expiry, proof integrity and immutable/audit failure cases are covered. The production TypeScript/Vite build passed in **16.45 seconds**, with scoped PHP style/diff checks. The full isolated SQLite `:memory:` run recorded **2,560 tests / 46,538 assertions / 17 failures / zero errors**, compared with **2,521 / 44,156 / 18 / zero** before the integrated batches. All 39 added cases passed, none were removed or skipped, and no new failure identity appeared. The actual breakdown/replacement-delivery case is resolved; all 17 remaining identities, failure/error types and assertion contexts are unchanged and belong to dispatch notices and financial collection/reconciliation/ledger/settlement/dispute gates. The full suite remains red and is not a release certification.

The additive exception migration installed locally in **91.20 ms**. Counts and original-column hashes in **23 existing PostgreSQL tables** stayed identical; the new decision table remains empty, its update/delete guards and single-source check are present. Transaction locks follow original orders, parcels, sorted accounts, company/facilities, assignments, then manifest/claim source locks. SQLite tests and catalogue checks do not establish simultaneous PostgreSQL contention. Browser, camera/device and deployed HTTPS/mail/scheduler operation remain unverified.

**Scoped assessment:** admin exception oversight **2/10 -> 8/10**. The reviewer can identify a real problem, current custodian, known counter cash holder, recovery coordinator and supporting history without overriding physical work. B14's bounded acceptance is locally complete with the exact final comparison established. Overall project readiness and broader phase/finance/notice gates remain open. Local implementation commits: `a980489` — `feat(admin): retain scoped exception responsibility and source resolution`; `dea2f30` — `feat(admin): expose exception queue and retained recovery evidence`. The documentation commit's generated hash is reported in the handoff. No push, merge or deployment was performed.

The integrated Rider account adapter also received a bounded expiry/CORS review in `b95a2dd` — `fix(api): enforce exact token expiry and explicit browser origins`. Exact-deadline reads/logout are rejected, and browser origins are trimmed and wildcard entries excluded while preserving the wire contract. All **13 native account cases / 113 assertions passed**, including exact expiry and explicit-origin preflight checks; no mobile-app files or mobile task records were changed. Prior integration authentication and combined-account/pickup evidence remains recorded above.


The B13 audit deliverable was reconciled against its recorded requirements matrix and the current full run during final prerequisite review. All 927 tests in the inspected authentication, restriction/resource, closure, identity correction, product/history, checkout-replay and Challenger audit groups passed. The preceding B01-B12 work and the source batches are verified; B13's existing incomplete wider phase/release decision remains conservative. The audit is complete as an audit, while the 17 dispatch/financial failures, unresolved source-specific recovery and deployment/concurrency limits remain explicit gates. This reconciliation completes the existing audit record without changing those obligations or claiming whole-project readiness.

### B15 Prerequisite Persistent Order Notices: October 7, 2026

The selected Phase 4 foundation starts from merged B14 at `0132562` on `feat/persistent-notifications`. Actual checkout, seller milestones, pickup claims, canonical hub custody, dispatch, delivery, buyer completion, secure pickup, and reverse-custody events now record a durable per-recipient intent in the business transaction. Delivery to the existing `notifications` table runs after commit. A notice or logging outage preserves the committed source and leaves bounded scheduled retries; event/recipient uniqueness and retained payload identity prevent duplicate or rewritten success notices. Existing secure pickup and return keys are preserved; no historical backfill, external provider, financial event, or Flutter change is included.

The shared notification page supports owned pagination, all/unread filters, accurate counts, and idempotent acknowledgement retaining the original read time. Approved roles and known pending/restricted accounts can read their own notices without acquiring portal or transactional permissions. Shared header links expose the page. Links use fixed existing destinations and current ownership/eligibility; payloads are projected through an allowlist. Empty and unavailable storage have distinct states. The older pickup wording conflicted with the validation contract's code prohibition; the buyer/logistics contracts now explicitly direct users to private one-time code access instead of putting a code in a notice.

Verification: the affected checkout-replay, fulfillment, attempts, returns, pickup, notification, and chat run recorded **147 tests / 6,765 assertions**, with one new test expecting a denial where the existing buyer guard correctly redirects to holding. The corrected case and original Phase 4 dispatch failure pass separately (**2 tests / 88 assertions**). The final foundation notification/pickup gate passes (**36 tests / 1,716 assertions**); the added logging-outage case confirms retry survives a failed log writer. The production TypeScript/Vite build passed, with scoped PHP style and whitespace checks. The fresh full baseline is **2,560 tests / 46,538 assertions / 17 failures / zero errors**. The combined foundation+B15 and requested web-auth repair finishes at **2,625 tests / 47,987 assertions / 16 unchanged financial failures / zero errors**. Every original case remains, all 65 added cases pass, and there are no skips or changed failure contexts; the original buyer-dispatch notice failure is resolved.

Scoped persistent order-notice assessment: **2/10 -> 8/10**. Saved lifecycle updates and an owned center replace the former pickup/return-only records. Governance integration is the following verified local batch, and financial notices remain dependent on B16/B17 source events. Apply the additive `2026_10_07_180000_create_notification_deliveries.php` migration before serving the updated business writers. The existing Laravel scheduler must run for unattended retries; immediate post-commit delivery works independently. No production scheduler, deployed browser, device, or simultaneous PostgreSQL concurrency claim is made.


### B15 Persistent Governance Notices and Web Session Repair: October 7, 2026

The selected stack starts from merged B14 at `0132562`. The separately verified Phase 4 foundation is `feat/persistent-notifications` through `50596c9`; `admin/governance-notifications` is deliberately stacked on that base and includes the foundation for review. This selection stops after B15. B16-B18 financial work, publication and merge remain separate.

Real immutable KYC/shop review, account/resource restriction, identity correction, product moderation, logistics placement, exception responsibility and custody grant/receipt sources now record one durable notice per event and recipient. Linked original-shop and correction reviews reuse their account event. Closure dispatch happens after the deletion/retention outcome, so a notice cannot turn an otherwise deletable identity into a retained account. Closed accounts gain no access from the retained record.

The shared center projects safe message fields, derives links from current ownership/authorization, and allows permitted own holding/recovery reads without opening checkout or privileged work. Account-status controls return restricted applicants to holding. The authorized handover screen exposes the same owned notice center. Raw review reasons, private evidence paths, tokens, claim codes, and foreign company context are excluded.

| B15 acceptance | Executable evidence |
|---|---|
| Committed review/restriction and correct recipient | `GovernanceNotificationTest` exercises actual root/subdomain review requests across four applicant roles, real account actions, independent shop review, resource decisions, moderation and placement. |
| Rollback and original event preservation | Rolled-back source transactions retain neither decision nor delivery intent; repeated review/correction/activity requests preserve original decision and notice identity. |
| Notice outage and durable retry | A real committed review survives a notice-table insert outage. The retry command later delivers the original intent once; repeated delivery and review requests add no second notice. |
| Owned lists/read, foreign ID, empty/unavailable | `NotificationCenterTest` covers all roles, stable pagination/filtering, idempotent read time, foreign acknowledgement denial, guests/closed accounts and unavailable storage. |
| Restricted applicant and current linked access | A real rejected applicant reads/acknowledges holding while checkout remains gated. Recovery tests use actual restricted-rider sign-in, acknowledgement and hub receipt; changed manager authorization removes a sensitive link and the destination request denies access. |
| Privacy, custody and money separation | Projected payloads contain no source secrets or foreign private context. Recovery notices preserve the rider restriction and original physical workflow; buyer completion does not settle cash. |

Verification: all **73 focused PHP tests / 1,473 assertions** passed, including **41 notification cases** and **32 authentication cases**. All **37 frontend tests** passed, and the final TypeScript/Vite production build passed. The first full run retained the original **16 financial failures** with unchanged identities/types/assertion context and resolved the old buyer-dispatch notice failure; it found one legacy seller-return test reading every notice as a return event. That test now checks the actual parcel's `parcel-return` records while confirming order notices coexist, and its focused rerun passed (**1 test / 124 assertions**). The final combined suite records **2,625 tests / 47,987 assertions / 16 failures / zero errors**, compared with **2,560 tests / 46,538 assertions / 17 failures / zero errors** on unchanged merged B14. Exact class/method/data-set, failure kind/type and stable assertion-context comparison shows all 65 added cases passing, no removed cases, new failures, changed failure contexts or skips, and the resolved `F12_to_F17_OrderLifecycleHubToCompletedTest::test_t1_f15_04_buyer_live_notification`. The remaining 16 financial failures are disclosed; this is not a passing full suite. The selected lifecycle/governance gate passes. Scoped PHP style and whitespace checks pass.

The additive notification migration was applied only to local PostgreSQL. Counts and complete row fingerprints across **48 pre-existing domain tables** were unchanged; the new outbox retention trigger is installed. This catalogue verification does not prove simultaneous PostgreSQL contention. Registered pickup and notification schedules were inspected, but a live scheduler process and deployed browser result remain unverified.

Scoped governance-notice engineering assessment: **2/10 -> 8/10**. Recorded decisions now reach the correct person's saved updates, with retry and access controls shared with real order notices. This does not raise finance, overall admin, or deployed-runtime readiness.

The additionally requested web login/logout problem was traced to HTTPS pages generating HTTP authentication URLs while the deployed environment was set to local. Browser route generation now uses the actual origin; login requests stay on that origin, current-host Inertia redirects retain it, and successful session changes request a fresh document. Wrong-password and expired sign-in feedback preserve the account/role/CSRF gates. Buyer logout feedback follows confirmed server state. `WebSessionTransitionTest` verifies real requests across the five roles on root and worker hosts, session rotation, logout, holding/restriction, validation, expired CSRF, and redirect edge cases. The frontend test uses the actual route generator with HTTPS pages and an HTTP upstream. Scoped HTTPS authentication repair assessment: **3/10 -> 8/10** for the verified implementation; production settings and the user's live retest remain pending.

The missing-products report is a visibility diagnosis, not evidence of erased data: local PostgreSQL retains **17 products**, with zero sale-eligible products and a legacy seeded shop lacking its category and approved review state; the user's deployed read reports **18 products**. Deployed eligibility still requires the read-only check. No seed rerun, stock rewrite, invented approval or database reset was performed. [Web authentication deployment](WEB_AUTH_DEPLOYMENT.md) records the environment/cache steps, migration/scheduler requirements and controlled shop-review path for this handoff. The user deferred those live steps until after this branch is finished.

Local implementation commits:

| Commit | Subject |
|---|---|
| `d38be1f` | `feat(notifications): retain durable lifecycle delivery and owned reads` |
| `0cadbdc` | `feat(notifications): expose shared account notification center` |
| `50596c9` | `docs(notifications): record foundation gate and selected B15 stack` |
| `58dd993` | `feat(admin): retain owned governance decision notifications` |
| `68e3e49` | `feat(admin): link owned notices from account and recovery screens` |
| `6e59b53` | `fix(auth): preserve browser origin and refresh session transitions` |
| `d6e125b` | `test(notifications): scope seller return checks to the original parcel` |

### One-Shop Seller Policy and Demo Catalogue Repair: October 8, 2026

The user-selected `fix/seller-single-shop-and-demo-catalogue` starts from merged `main` at `fe86abf`. It supersedes the earlier B03 additional-shop policy: one seller account owns one shop, created at registration with one of the 14 master root categories. The category choice and initial account/shop review were already present and remain linked. Extra-shop creation and switching are removed on root and seller hosts, obsolete picker sessions cannot change ownership, and a unique `shops.user_id` constraint rejects a second shop or conflicting owner update. Installation explicitly stops on legacy duplicate ownership without deleting, merging or transferring shops or orders.

The seller sees one Shop page and a direct dashboard link after approval. Birth-date correction points to the existing identity review rather than a missing shop-form field. Current account/shop approval, activity, product-category scope, stale evidence, private files and immutable history retain their gates. Restricted owned order history remains available under the existing policy. Buyer checkout across different sellers' shops and company hub switching retain their contracts.

`SellerDemoSeeder` is a targeted, repeatable repair for the reserved fictional seller and known catalogue fixtures. Fresh setup uses Men's Apparel with an active accessory child. An already reviewed shop keeps its chosen root and existing decision; only known fixture products in the active legacy `backpacks-and-bags` root are reclassified. The shared legacy category, foreign shops/products, custom products, purchased-item references, prices, stock, images, passwords and restrictions are retained. A fresh/unreviewed original fixture has explicitly labelled synthetic demo provenance, a current setup timestamp, fictional adult birth date and private placeholder images. It does not claim a human document review or backdated approval. Pending/rejected/restricted or conflicting reserved accounts fail rather than receiving a review bypass.

Verification: **179 seller/review/application cases / 1,595 assertions** and **41 seeder/checkout/logistics/cross-role cases / 784 assertions** passed with SQLite `:memory:`. The seeded cross-role flow now starts from the actual seeder instead of a manual category/approval workaround. All affected seeder tests isolate private fixture storage. The first full run exposed five cases whose old fixtures created a second shop or attempted foreign-product denial without a sole shop. Their setups now use separate owners, one reused shop, a single legacy review candidate, and an eligible foreign owner, while retaining the original approval, moderation, retry and notice checks. All **259 affected governance/portal/notification cases / 2,653 assertions** then passed. The TypeScript/Vite production build passed (Vite 12.60 seconds), with scoped PHP formatting, document-link/fence and whitespace checks. The fresh unchanged-main baseline recorded **2,625 tests / 47,987 assertions / 16 failures / zero errors**. The final complete run records **2,641 tests / 48,181 assertions / 16 failures / zero errors**, in **9:27.212**. Exact class/method/data-set, failure kind/type and stable assertion-context comparison retains all 16 existing financial failures unchanged, with all 16 added cases passing, no new failure identity, and no unexpected removed cases or skips. The full suite remains red; this is a verified seller/catalogue change, not a release certification.

Three prior passing shop-context cases were renamed and changed to the explicitly approved one-shop contract: old selection sessions resolve the sole owned shop; valid extra-shop input is rejected without a saved shop/upload; and owned/foreign switching requests are unavailable. Their replacement cases pass. Other ownership, resubmission, restriction, rollback and review acceptance remains tested; no failures were suppressed or skipped.

The unique-ownership migration installed locally in **34.25 ms**, with zero duplicate owners and unchanged counts/protected original-column fingerprints across **57 PostgreSQL application tables**. Targeted repair changed sale-eligible demo products from **0 to 17** without resetting the database. Original product/order/image IDs and business values stayed intact outside the explicit demo identity/review/category fields; the model correctly derived age from the demo date. It added one category, one labelled shop decision and the existing governance notification intent/notice. Repeat repair left counts and protected fingerprints identical across the 57 tables. The PostgreSQL unique index is present; simultaneous contention was not tested.

Scoped assessment: **one-shop seller setup 3/10 -> 8/10; demo catalogue visibility 2/10 -> 8/10**. The requested ownership rule and usable demo inventory are locally verified. This does not raise whole-project, finance or Azure readiness. Production deployment, browser/device behavior and simultaneous PostgreSQL locking remain unverified. After user publication/pull, deploy the guarded migration, run the targeted `SellerDemoSeeder` command in [WEB_AUTH_DEPLOYMENT.md](WEB_AUTH_DEPLOYMENT.md), and confirm actual deployed visibility. No database refresh, Git publication or Azure deployment was performed.

Local implementation commits: `ad2efd7` — `fix(seller): enforce one registered shop per account`; `676df3a` — `fix(seller): show one shop without creation or switching`; `df8d043` — `fix(seed): restore the sale-eligible demo catalogue safely`; `236463e` — `test: align governance fixtures with one-shop ownership`. The documentation commit hash is reported in the handoff. B16-B18 and broader financial/recovery work remain separate.

### Account Settings and Saved Views: October 8, 2026

The user-selected `fix/account-settings-and-live-updates` starts from merged `main` at `af0d182`. The original registration/sign-in email now stays fixed through profile edits, resubmission and direct database writes. Existing email strings and verification are retained, including case-equivalent input. One ownership registry reserves original and additional addresses. Up to five additional addresses require the current password and a purpose/account-bound emailed code; verified addresses support preferred contact and password recovery while sign-in and original-email verification retain the registration address. Wrong attempts, expiry, retry, collisions, foreign ownership and removal are enforced. Removing an additional address revokes pending recovery credentials; an old challenge cannot transfer to a new owner.

Own correction requests, private evidence and history are embedded beside identity information in buyer, merchant, courier and generic account settings, using the corresponding portal treatment. Old own-request links and notifications lead to the settings section. The admin queue, evidence review, immutable decisions and stale-source checks remain. Approved restricted accounts receive only narrow correction settings, without contact writes, checkout, addresses, wallets or privileged access.

The buyer photo form no longer submits an invented birth date or unsupported gender field. Its picker remains mounted when the sidebar changes tabs, and successful saves replace form defaults and profile/header data with current server values. Seller profile/shop saves also synchronize saved values and clear file selections. Shop contact numbers are direct owner edits and no longer break an otherwise matching approval or hide products; the original decision remains unchanged. Branding/contact edits cannot reactivate a restricted shop. Buyer address edits preserve legal recipient identity, ownership, default selection and existing order snapshots. Eligible `/buyer/orders` and buyer-host `/my-orders` requests use the existing purchases/tracking workspace after checkout; restricted buyers retain their narrow owned-order view.

Shared buyer feedback exposes order/profile success and errors once. JSON correction, email, restriction, closure, moderation and hub scan actions wait for refreshed server props before releasing pending state. A failed refresh after a successful write reports that distinction without automatically replaying the mutation.

Verification: **183 focused settings/authentication cases / 1,401 assertions**, **15 affected delivery-recovery cases / 1,038 assertions**, and all **44 frontend cases** passed. The final TypeScript/Vite production build passed through Docker (Vite **11.95 seconds**). Scoped PHP formatting, documentation links/fences and whitespace checks pass. The unchanged-main baseline records **2,641 tests / 48,181 assertions / 16 failures / zero errors**. The complete separate run records **2,661 tests / 48,569 assertions / 17 failures / zero errors**, in **9:13.047**. Exact class/method/data-set, failure kind/type and stable assertion-context comparison retains all **16 financial failures unchanged**, with **20 added cases passing**, no unexpected removed cases, changed financial failure contexts or skips. Four previous passing email-edit tests were explicitly replaced with the approved original-email-retention policy; their replacement cases pass.

The additional full-run failure was `AccountRoleImmutabilityTest::test_profile_payload_cannot_convert_a_buyer_to_a_seller`: a successful contact save without a referring page redirected home instead of settings. The return now preserves the current settings page and falls back to `profile.edit` when the referrer is absent. The original role-protection test was retained unchanged. All **140 final profile, role, email and settings cases / 774 assertions** pass after that one-line fix. The complete suite was not repeated after this focused repair; its recorded result remains the 17-failure run above. Financial readiness remains red, and this is not a release certification.

The additive email migration installed only on local PostgreSQL in **90.05 ms**. Counts and complete protected original-column fingerprints across **57 existing application tables** stayed unchanged. All **nine existing original emails** were registered, with **six email ownership/verification guards** installed. No database refresh or seed rerun was used. Simultaneous PostgreSQL contention, browser/device behavior, real inbox delivery and Azure runtime remain unverified. After user publication/pull, deploy the migration and perform the mail/browser checks in [WEB_AUTH_DEPLOYMENT.md](WEB_AUTH_DEPLOYMENT.md#account-settings-update).

Scoped engineering assessment: **account settings and saved feedback 4/10 -> 8/10; buyer purchases landing 5/10 -> 8/10**. This fixes the reported settings/upload/contact and duplicate landing behavior without raising overall finance, admin or deployed-runtime readiness. B16-B18 remain separate.

Local implementation commits:

| Commit | Subject |
|---|---|
| `aa7dc68` | `feat(accounts): preserve sign-in email and verify recovery addresses` |
| `ae722a7` | `fix(settings): preserve approval and return current owned account views` |
| `2c0410d` | `feat(settings): place verified emails and reviewed corrections in role profiles` |
| `6dad1e8` | `fix(ui): show saved account values and await refreshed action results` |
| `50c7148` | `fix(profile): retain settings fallback after contact saves` |

The documentation commit hash is reported in the handoff. No Git publication or Azure deployment was performed.


### B16 COD Custody and Platform Reconciliation: October 8, 2026

This branch, `admin/cod-reconciliation`, starts from merged account-settings work at `85121b5`. Its boundary is [B16](admin-plan/16-cod-reconciliation.md): actual collection, two-sided cash handovers, linked discrepancy reviews and independent Platform Admin reconciliation. Seller settlement, consolidated finance, refunds and live banking remain separate work.

[CodCashService](../app/Services/Finance/CodCashService.php) keeps integer-centavo amounts and immutable original order, company, facility and collection sources. Assigned doorstep riders record the actual recipient, matching waybill, stored proof, counted tender, returned change and exact saved COD in the handoff transaction. The secure counter writer retains its existing buyer claim and original cash entry while recording the same cash obligation once. An offer leaves responsibility with the sender until the named, currently eligible recipient confirms actual counted cash. Partial handovers preserve the remaining balance; a shortage remains the sender's recorded responsibility, and an overage stays separately held without increasing the order amount.

Platform Admin reviews append to original receipts. A shortage adjustment requires a later exact receipt between the original parties; the same recovery cannot resolve another difference. Extra cash remains visibly unallocated after review. Reconciliation requires all saved COD at the platform, completed receipts, reviewed differences and the current version. It records its own immutable decision and updates the paid label atomically; it does not complete the order or create, release or settle a commission ledger. Product subtotal, discount and shipping remain distinct snapshots.

| Acceptance area | Retained evidence |
|---|---|
| Assigned rider and secure counter collection | Exact due, actual source/proof/recipient, identity/claim checks, tender/change, source uniqueness and identical retry. Failed attempts, reverse transport and seller return receipts create no collection. |
| Two-sided handovers | Original facility and company, named current recipient, explicit counted-cash confirmation, offers without balance movement, partial balances and separate platform receipt. Company Admin can offer only own hub cash with the original holder retained. |
| Injection, stale and competing attempts | Client float/precision/total/actor/time injection, wrong assignment/facility, changed retry identity, stale version and database source/sequence/retry uniqueness reject. Sequential competing requests are verified; simultaneous PostgreSQL contention is not. |
| Shortage and overage | Immutable original count, actual recovered-shortage receipt, linked signed adjustment, no reused recovery and separate excess holder. Full platform money alone cannot reconcile an unresolved difference. |
| Independent platform authority | Rider, handler and Company Admin cannot reconcile or adjust; an inactive Platform Admin cannot read/write cash. Buyer completion and paid/delivered labels do not grant reconciliation or seller settlement. |
| Atomic money and audit writes | Event, balance, notice-intent and paid-label failures roll back the entire transaction. Recorded notices use the existing durable delivery service and reauthorize their cash links. |
| Restricted and closed provenance | Verified restricted riders access only recorded responsibilities through `/cash-handover/sign-in`; operational approval is unchanged. Direct rider-to-platform recovery requires named Platform Admin authority and rechecks its 24-hour expiry at offer and receipt. Company management can remit a closed original counter holder's known cash without reopening the account. Cancelling an unconfirmed offer leaves responsibility unchanged. |
| Legacy and immutable history | Paid/completed labels become unverified review cases with no invented collection. A verified original counter source keeps its collector, original time and entry; adoption and review use current server time. Models and SQLite/PostgreSQL triggers prevent original-event/source update/delete and changes to a reconciled account. |
| Governance, closure and privacy | Restriction/resource/exception/closure reviews read current journal holders even after placement changes. Reconciliation removes the false unverified-cash reason; unresolved seller settlement and separate unallocated cash still block closure. Foreign companies/ordinary roles cannot read the journal, and cash pages/notices omit collection tokens, fingerprints and private handoff evidence. |

[Cash queue](../resources/js/Pages/Finance/Cod.tsx) and [cash detail](../resources/js/Pages/Finance/CodDetail.tsx) present saved due, current holders, awaiting receipts, differences, original history and independent reconciliation together. Admin and hub navigation expose COD cash; rider Dashboard and Trips link to handovers. The rider delivery dialog collects actual recipient and cash evidence. Cash recovery has a separate sign-in linked from rider/hub login and a restricted workspace. Styling stays within these surfaces, using Plus Jakarta Sans, PHP amounts, visible controls, responsive grids and the rider's 8px direction.

Verification: **122 cash/source/counter/rider cases / 5,652 assertions** passed. A parallel affected run found four failures caused by both PHP processes clearing the same fake-storage directories: retained identity/proof files disappeared during closure, exception and correction checks. Those processes finished before the sequential rerun; no behavior was weakened to accept missing evidence. All **194 cases / 1,663 assertions** in the sequential restriction/resource and affected-failure rerun passed. Three final legacy-closed-holder, reconciled-record and governance-retention cases passed with **288 assertions**. All **46 frontend cases** and the final TypeScript/Vite production build passed (Vite **15.34 seconds**). Scoped PHP formatting, document-reference/fence and whitespace checks pass.

The unchanged-main baseline records **2,661 tests / 48,571 assertions / 16 failures / zero errors**. The final separate complete run records **2,700 tests / 51,880 assertions / 16 failures / zero errors**, in **10:34.189**, with no skips. Exact class/method/data-set identity, failure kind/type and stable assertion-context comparison retains all 16 original financial failures, with all 39 added cases passing and no removed cases, new failures or changed stable contexts. Comparison excludes generated order numbers in the same missing-ledger messages and shifted stack line numbers; it retains expected/actual counts and assertion meaning. These existing scenarios still require actual financial handovers and B17 settlement; no early settlement assertion was suppressed. The full suite and whole financial gate remain red. B16's selected cash acceptance gate passes.

Scoped engineering assessment: **COD custody/reconciliation 2/10 -> 8/10**. Collection and counted receipts now identify responsibility, preserve original money history and expose a distinct platform decision through usable role pages. This is a scoped cash-flow assessment supported by isolated behavioral checks, static UI review and a build; it does not certify deployed PostgreSQL behavior, rendered devices, seller payout, whole-admin completion or overall release readiness.

Local implementation commits: `93bb849` — `feat(finance): record COD custody and platform reconciliation`; `eb9d84c` — `feat(finance): add cash handover and reconciliation screens`. The documentation commit's generated hash is reported in the handoff. The next suggested batch is B17 followed by B18 after this B16 branch is reviewed and merged; neither is started here.

Deployment requires the additive [cash migration](../database/migrations/2026_10_08_180000_create_cod_cash_records.php) before serving these writers. It preserves earlier cash/payment/commission records and performs no automatic historical reconciliation; rollback refuses to discard recorded cash history. No development/production database reset or migration, push, merge, deployment, banking transfer, rendered-browser/device check or simultaneous PostgreSQL execution was performed. Integer amounts, row-lock ordering and uniqueness/immutability were reviewed and exercised on isolated SQLite `:memory:`; production PostgreSQL lock/trigger execution remains a verification limit. Unverified old obligations and separate extra-cash return/refund resolution remain explicit; B17/B18 and existing unsupported restricted parcel recovery are not completed by B16.

## October 8 native rider settings API handoff

**State: backend merged and deployed; live sign-in, settings reads and sign-out passed. Live phone/password/email changes, mailbox delivery and Flutter acceptance remain pending.** Branch `feat/rider-settings-api` started from merged `main` at `a16936a` and was merged by the user as `1dba047` through #69. The proposed Flutter settings handoff was reviewed as a read-only input; the Flutter repository was not edited. The final backend-owned contract is [RIDER_SETTINGS_API.md](api/RIDER_SETTINGS_API.md).

The native `/api/v1/rider/settings` snapshot and six writes now share website contact, account-password and additional-email rules. Contact writes accept only phone plus the current opaque revision; legal identity, original sign-in email, birthday, approval, assignments, vehicles and evidence remain managed. Snapshot IDs are strings, exactly one owned original/contact fallback is presented, and no private document URLs or credential material is returned. Managed details use the same stored courier fields as the website.

The additive contact-revision migration increments a persisted counter when a phone changes, including SQL and website writes. It detects same-second edits and a phone changed away and back. A stale native request receives 409 with the saved snapshot and does not overwrite it. The one existing legacy-readiness test now refreshes its model after its fixture phone update before retaining its full raw-field snapshot; all original identity/history assertions remain intact.

Current-password validation is bound to the fresh authenticated account, including when a different browser guard is active. Website/native password changes share verification, confirmation and 12–128-character rules, use the existing password cast and atomically revoke the account's personal access tokens. Native success requires reauthentication; failure rolls password and token writes back together. Ordinary email edits preserve the five-address limit, original-email protection, scoped challenge consumption, failed-attempt persistence, ownership checks, verified preference and recovery invalidation. An unavailable/unverified stored preference falls back to the original without claiming verification.

New logins receive explicit read/contact/password/email settings abilities. Existing account-only tokens are not upgraded. Fresh eligibility, expiry and password fingerprints remain enforced; commands recheck the token under the user lock. Pending applicants retain only their existing holding behavior. Version 1 is advertised only after the required schema is installed; missing schema leaves settings unavailable. Responses and errors are private JSON, and existing route/OTP throttles remain in force. The proposed paths and snapshot were retained; deployment gating, confirmation retry behavior, current-owner password checks and numeric cooldowns are clarified in the final contract.

| Verification | Evidence |
|---|---|
| Unchanged focused account baseline | 29 tests / 250 assertions passed before implementation. |
| Native contract/security acceptance | All 63 `RiderSettingsApiTest` cases passed: own/foreign scope, bearer/browser distinction, wrong roles and holding/restricted/underage accounts, narrow and old token abilities, expiry/revocation, protected inputs, canonical/invalid phone values, stale/ABA contact writes, verified email and password boundaries, atomic password rollback, OTP actor/purpose/expiry/attempt/reuse rules, capacity, recovery removal, numeric throttles, mail failure, migration-gated advertising, oversized address IDs and account authority withdrawn during a command. The 14 account API cases also check malformed token IDs before database lookup. |
| Shared website/account regression | Latest combined isolated SQLite `:memory:` run: **740 tests / 6,243 assertions / zero failures, errors or skips**, covering the full authentication directory plus email migration/settings, saved views, profile validation, seller profile and identity correction tests. Native writes are read by website settings and website phone/email/password writes are checked through native access. The initial implementation run passed 737 tests / 6,010 assertions. |
| Source checks | All 15 changed PHP files passed scoped Pint checks; Git whitespace checks passed. There are no frontend source changes requiring a separate frontend build. |
| Azure/live | Server `main` runs `1dba047`. The frontend build and pending additive migrations completed; the settings schema is available, no migrations remain pending and maintenance mode is off. Live HTTPS health, fresh native sign-in, owned version-1 settings reads and logout passed. Missing/malformed bearer requests returned private 401 JSON. Only the verification session's token was logged out. Live phone/password/email mutations, mailbox delivery and Flutter acceptance are unverified. |

**Scoped engineering assessment: native settings implementation 2/10 -> 8/10.** Previously native account access had no settings mutations. Owned snapshots, protected contact writes, shared password/email actions and explicit failure/session behavior now have local acceptance evidence. Deployment and live reads are now verified; this rating does not certify live settings mutations, mailbox delivery, Flutter integration or overall finance/admin readiness. The existing broader financial gate was not rerun or declared fixed by this work.

Deployment installed `2026_10_08_190000_add_contact_settings_revision` alongside the account-email registry. The migration's PostgreSQL installation completed; live phone updates and their trigger behavior remain unverified. No database reset or seed rerun was used. The user published and merged the settings branch; the agent completed the authorized deployment recovery described below. Live native phone/password/mail results and Flutter integration remain separate acceptance gates.

Local source commits:

- `7ba1db6` — `feat(accounts): retain contact revisions across phone edits`
- `338bafe` — `feat(api): add native rider account settings`

### October 8 follow-up branch review

The review reproduced two missing pre-query guards: malformed token ID prefixes and address IDs outside the signed integer range reached database lookups. SQLite returned ordinary missing-record responses, which hid the PostgreSQL conversion risk. Native token lookup now rejects unsupported prefixes with private 401 JSON; email commands reject oversized IDs with private 404 JSON before lookup. The maximum supported address ID still follows normal missing-record handling. No PostgreSQL runtime execution is claimed by these SQLite checks.

A separate middleware-to-command check reproduced a settings write after the token's required `rider:account` ability had been withdrawn. Locked settings validation now checks both that account ability and the command's settings ability before writing. Three new regression cases failed before their respective fixes and passed afterward. The focused account/settings run passed **77 tests / 778 assertions**, followed by the complete account regression run recorded above.

**Scoped follow-up assessment: native settings implementation 8/10 -> 8/10.** Invalid input and withdrawn permissions now retain the documented error and no-write behavior. These local checks do not establish live mailbox delivery or Flutter acceptance. Review commits: `fdb7288` — `fix(api): reject invalid rider token and email identifiers`; `5730bd1` — `fix(api): recheck account authority during rider settings writes`. The evidence commit hash is reported in the handoff.

## October 8 Azure deployment recovery

**State: merged revision `1dba047` is deployed and serving HTTPS.** The failed deployment exhausted Node's roughly 450 MB default heap during `tsc`, before migrations. The server had approximately 893 MB RAM and 2 GiB swap. Running the asset build with a 1 GiB heap cap completed TypeScript and Vite successfully; Vite reported 1 minute 12 seconds. The build was run in a detached server process so it could finish independently of the SSH connection.

The recovery applied the four pending migrations: account emails, one-shop ownership, COD cash records and contact-settings revision. Configuration/views were rebuilt, the asset manifest check passed, a strict pending-migration check reported none and maintenance mode was disabled. Before/after counts stayed at **10 users and 19 products**. No database reset, reseeding, account credential change or settings mutation was used. Stable counts do not establish a complete comparison of every stored field.

Live HTTPS checks returned 200 for health, fresh native sign-in, owned rider settings and verification-session logout. Anonymous and malformed-token settings requests returned 401; private responses carried `no-store`. Settings advertised version 1. These checks used an isolated verification token and did not revoke other sessions. Actual phone/password/email changes, emailed-code delivery, rendered browser/device checks and Flutter acceptance remain pending.

The local `fix/azure-build-memory` branch makes the working heap cap the deployment default while preserving explicit `NODE_OPTIONS` overrides. It also makes `verify` fail when migrations are pending, using the installed Laravel command's `--pending=1` exit behavior. The previous verifier only displayed migration status and could report success with pending work. The helper changes require user publication before future server pulls include them; the live recovery already used the equivalent build and verification options.

**Scoped engineering assessment: deployment helper reliability 4/10 -> 8/10.** The observed small-server build failure is addressed and incomplete migration installation no longer passes verification. All six isolated Docker-stub command tests passed, covering container memory forwarding, explicit overrides, stopping before migrations on build failure, pending-migration rejection, a successful verification and missing-asset rejection. The three memory/pending cases failed against the original helper before the fix. `bash -n` and Git whitespace checks passed. These tests run no Docker services or databases; the actual production build and migration checks provide the separate server evidence above. This rating does not raise native settings mutation, Flutter, finance or overall admin readiness.

Implementation commit: `bb76a72` — `fix(deploy): raise build heap and reject pending migrations`. The documentation commit hash is reported in the handoff. No agent push or merge was performed.

### Seller Settlement and Financial Oversight: October 9, 2026

**State: B17 and B18 implemented and locally verified on the selected shared branch.** `admin/settlement-and-financial-oversight` starts from merged `main` at `9fe5ce5`, after B16. B17's settlement gate passed before B18 implementation. This closes the two selected finance plans; it does not certify every historical record, deployed runtime or remaining cross-role cleanup gate.

Previously no source-backed seller payment writer existed, sixteen financial acceptance cases failed, and overview finance panels were unavailable. Release approval now requires actual buyer completion, reconciled COD without unresolved differences or extra cash, and the original eligible seller/shop. Approval records an immutable product basis and source references. A separate payment record requires a reference, private receipt, explicit confirmation and reason. It records an existing manually reviewed payment; it does not perform a banking transfer. Seller and platform shares use exact 90%/10% product cents, with shipping and discounts separate.

Original order, cash, buyer checkpoint, recipient, actor and payment evidence remain retained. Identical retries return the original result; conflicting/stale requests, amount/rate/recipient injection and unproven payments reject. Linked receipt-reference corrections append evidence with zero additional payment. Audit failure rolls back the new payment/ledger/file, and downloads verify the original receipt hash. Paid history retains its original identity and basis after current records change. Closure and seller metrics use evidenced payments rather than an older ledger's status; a supported original pending ledger is preserved after payment.

The [settlement service](../app/Services/Finance/SellerSettlementService.php), [retained schema](../database/migrations/2026_10_09_000000_create_seller_settlement_records.php), and [settlement checks](../tests/Feature/Finance/SellerSettlementTest.php) establish that boundary. Existing tiered financial scenarios now explicitly execute counted handovers, reconciliation, release approval and recorded seller payment through their real services. Their original amount, split, shipping and duplicate-record assertions remain; buyer confirmation alone does not settle cash.

The [financial oversight service](../app/Services/Finance/FinancialOversightService.php) reads original cash journals and settlement records. Collection, current rider/hub custody, platform receipts and reconciliation are distinct stages of the same money. Pending, eligible, authorized and paid proceeds remain distinct. Recorded commission accompanies an evidenced seller payment; shipping charges are not represented as logistics earnings. Unsupported logistics income and rider payouts remain unavailable, and ambiguous legacy/source failures produce unavailable amounts or a service error, never a guessed zero.

Platform Admin sees global sources; Company Admin sees only its original company cash; seller sees only owned proceeds without operational cash history. Source/date/state/company/recipient filters apply before totals and stable order-ID pagination. Total links select their contributing records. Date filters use the original collection-date cohort in Asia/Manila, with an exclusive next-day boundary; orders without recorded cash use their order date. Reviewed older counter collections retain the original collection date while the actual review/recording time remains separately visible. Aggregate cents use exact integer strings, and the frontend preserves them beyond JavaScript's safe integer range.

The shared `/seller-settlements` and `/financial-oversight` pages provide source references, original/corrected receipts and read-only cash before/after histories. Oversight adds only GET routes and no financial mutation authority. Admin overview/logistics panels now link supported totals to the same evidence; unsupported categories stay explicit. The [reporting checks](../tests/Feature/Finance/FinancialOversightTest.php) and [counter checks](../tests/Feature/Finance/CodCashCustodyTest.php) include owned/foreign roles, restrictions/closure, real doorstep/counter workflows, legacy review dates, query failures, no-write reads and reproducible totals.

| Verification | Recorded result |
|---|---|
| Valid clean baseline | `9fe5ce5`: 2,764 tests / 52,545 assertions / 16 failures / zero errors / zero skips. |
| B17 affected-flow gate | 271 tests / 13,908 assertions passed; retained-history and proof-integrity follow-up: 60 tests / 1,893 assertions passed. |
| Combined finance/overview/closure/seller/notice gate | 174 tests / 8,103 assertions passed. |
| Final counter/settlement/reporting/admin gate | 95 tests / 6,805 assertions passed, including current and reviewed older counter collection through actual seller payment. |
| Final complete isolated suite | 2,800 tests / 55,848 assertions / zero failures / zero errors / zero skips, in 11:29.495. |
| Exact baseline comparison | All 16 original financial failure identities resolved; 36 added cases passed; no removed cases, new failures/errors, changed failure contexts or skips. |
| Frontend | 53 helper/server-render checks passed. TypeScript/Vite production build passed in Docker; Vite 14.35 seconds. |
| Repository checks | Scoped PHP formatting, documentation links/fences, Git whitespace, private-guide exclusion and tracked/history privacy checks passed. |

The valid baseline used the clean source snapshot's own built assets and Docker PHP 8.4; working-tree verification used separate storage and explicitly forced SQLite `:memory:`. Earlier host/missing-asset/test-key attempts are not the baseline. The first final regression attempt was superseded after the counter-date audit found a real reporting gap; the table records the complete run after that correction. Full test identities include class, method/data set, failure/error kind, exception type and stable assertion context. No acceptance cases were skipped or changed to treat unverified labels as success.

**Scoped engineering assessment: seller settlement 2/10 -> 8/10; financial oversight 2/10 -> 8/10.** The improvement is enforceable payment evidence, exact sources and appropriately scoped views with passing local acceptance. Overall role/project ratings remain their previous assessments; the numbered plan and a green automated suite do not establish deployed readiness or remove the need for later review.

Local implementation commits:

- `b994229` — `feat(finance): record verified seller settlement and payment evidence`
- `7beb8e5` — `feat(finance): show seller release decisions and private receipts`
- `2dc2abe` — `feat(finance): project scoped cash and seller proceeds from retained sources`
- `6c94d5c` — `feat(finance): display traceable totals and read-only financial evidence`
- `8c6fb63` — `fix(finance): retain original counter collection dates in reports`

The documentation commit's generated hash is reported in the handoff. Apply the additive settlement migration after user publication and review, together with the already required source migrations. Preserve private receipt storage and backups; rollback refuses to discard recorded financial history. No development/production database reset, synthetic financial seeding, PostgreSQL migration, push, merge, deployment, banking transfer or browser/device check was performed. Row-lock order, unique source/retry constraints and immutability were reviewed and exercised on SQLite; simultaneous PostgreSQL execution and its trigger/runtime behavior remain unverified. Large historical-cohort performance has not been load-tested. Refunds, exchanges, bank integrations, rider payouts and logistics earnings remain outside these two plans.


### Native Rider operations: October 9, 2026

**State: all five core backend batches implemented and locally verified.** The
[delivery plan](api/RIDER_NATIVE_PLAN.md), [accepted wire contract](api/RIDER_OPERATIONS_API.md),
[executable OpenAPI](api/rider-operations.openapi.json) and
[sanitized fixtures](api/examples/rider-operations/README.md) form the Flutter handoff.
The 25 operational methods cover Home, duty, pickup claims, distinct parcel commands,
retained Trips/private evidence, messages, durable notifications and COD offers.
Conditional future features remain in the plan, outside the executable route set.

Work is isolated on `feat/rider-native-api`, based on locally verified finance
source `8fb5193`. The original `admin/settlement-and-financial-oversight` branch
remains at that source. No push, merge or deployment was performed. Neither source
ancestry nor earlier account/Settings deployment proves these routes are deployed.
The actual dependency is Laravel `^13.17`, with Docker PHP 8.4.24; repository
instructions still describe Laravel 12. This records the mismatch without changing
framework dependencies or account contracts.

| Capability | Shared source and native behavior | State / remaining dependency |
|---|---|---|
| Account/Settings | Existing bearer, registration, holding and Settings v1; fresh command authorization and additive narrow abilities | Preserved and regression-checked. Old tokens must sign in again; operational discovery requires the existing Settings schema. Physical Android/password/mailbox acceptance remains separate. |
| Home/duty/queues/detail/claim | CourierOperationsService and LogisticsEligibilityService; private previews, shared capacity/placement, explicit duty, retained assignment checkpoints and command reconciliation | Implemented and locally verified, including real PostgreSQL competing and duplicate claims. Additive command migration and rollout remain required. |
| Pickup/departure/outcomes/failure | Shared CourierOutcomeService/state machine, genuine submitted waybill, private image proof, recipient, exact CodCashService collection and actual attempt/return instructions | Implemented and locally verified. Delivery is currently COD-only. Hub intake, retry/RTS, seller receipt and buyer completion remain the owning actors' work. Native release/restricted recovery is unavailable. |
| Trips/evidence | Immutable original assignment checkpoint identity, actor/interval attribution, Manila-day/search/payment filters and owned hash-verified binary reads | Implemented and locally verified through actual failed-return/retry/reassignment. Foreign children and altered bytes reject; ambiguous legacy attribution is unavailable. |
| Messaging | Shared phase-linked CourierMessagingService, current assignment/participant identity, exact displayed message boundary and retained send/read results | Implemented and locally verified. Stale drafts cannot change recipient silently; the existing order/actor-pair conversation and duplicate-text guard remain. |
| Notifications | Existing durable event/outbox and NotificationDeliveryService; canonical final-mile assignment notices, owned lists, exact displayed UUID reads and currently authorized targets | Implemented and locally verified. Workers/scheduler must run after rollout. Unsupported/stale native destinations are null; no push provider was added. |
| Cash/remittance | CodCashService, retained append-only accounts/events and exact-cent aggregation; own responsibility, permitted recipient, versioned offer and original result | Implemented and locally verified through actual hub/platform receipt and reconciliation. An offer does not confirm receipt; no rider reconciliation authority exists. |
| Earnings | Finance sources have no authoritative rider earnings writer | Explicitly unavailable, with null confirmed earnings. COD, shipping and commission cannot supply guessed income. Payouts and withdrawals remain outside scope. |
| Stop Mode/instructions/tags/extensions | Current authorized task stop, frozen checkout destination and actual notes; existing account/Settings and narrow website recovery | Shared task data supports Stop Mode/Doorstep Guide. Parcel Finder is planned device-only/account-plus-assignment scoped, with cleanup owned by Flutter. Server tags and native identity/recovery/reset extensions require a separate selected contract. |

Backend services own the domain decisions; controllers adapt requests and JSON.
Each command checks fresh bearer/account/role/ability and current resource state
inside the same transaction as the shared mutation and retained result. IDs and
cent amounts are strings, revisions are opaque, pages are bounded and stable,
and errors/private headers/request IDs are explicit. A seven-day actor/key retry
returns the original committed result; reconciliation remains readable after expiry.
Changed intent rejects. An uncertain custody/cash command must be reconciled before
retrying the same retained intent, never replaced by a guessed new action.

New website and native delivery proof uses genuine server-named private images
through the shared proof service. Actual file bytes, original hash and recipient/COD
facts are checked; rejected, replayed and rolled-back writes discard unused new
files. Ordinary JSON hides paths. Existing successful test fixtures now contain
genuine images, preserving their business assertions. Older public POD copies
were not moved or deleted: audit, reviewed private archival, backup/reference/hash
verification and public-copy removal remain an explicit release prerequisite.
Private new writes do not revoke already published historical URLs.

The recent source suite at `8fb5193` is reused as the valid baseline. Its executable
source matches the completed run at `8c6fb63`; the intervening commit changes only
finance documentation. This is inspected evidence reuse, not a claimed fresh
baseline run. Verification used isolated SQLite `:memory:` except for the user's
explicitly approved disposable PostgreSQL claim/outcome check.

| Verification | Recorded result |
|---|---|
| Reused clean source baseline | 2,800 tests / 55,848 assertions / zero failures, errors or skips. |
| First corrected API/account/Settings/web courier gate | 144 tests / 1,821 assertions passed. |
| Connected API and affected shared-flow gate | 78 tests / 1,918 assertions passed. |
| Native access, parcel, connected-record and executable-contract gate | 34 tests / 1,716 assertions passed; fresh post-migration-timing run completed in 17.067 seconds. |
| Forced financial rollback/proof retention follow-up | 1 test / 92 assertions passed; original private proof bytes and all pre-existing files remain intact. |
| Final complete isolated suite | 2,834 tests / 57,567 assertions / zero failures, errors or skips, in 12:13.696. |
| Exact baseline comparison | All 2,800 original class/method/data-set identities preserved; 34 added cases; no removed cases, failures, errors or skips. |
| Packaged disposable PostgreSQL verifier | 1 test / 240 assertions passed in 10.037 seconds: four simultaneous races, each with two independent PHP processes and distinct database connection IDs. |
| Executable contract | All 25 actual route/method pairs match OpenAPI. All 67 schemas compile; 34 captured/public responses and exact ID/money boundaries pass validation. |
| Repository checks | Scoped PHP formatting, shell syntax, JSON/document links and Git whitespace pass. Local task-guide exclusion and reachable-history privacy checks pass. |
| Frontend | Source, dependencies and assets are unchanged by this backend branch. The finance source's 53 frontend checks and successful TypeScript/Vite build are reused; no new frontend build or rendered-device check is claimed. |

The first focused run exposed incomplete-Settings discovery compatibility; requiring
its existing schema before operational authority fixed that case. The first full
regression run had one financial rollback-test failure because its expected private
file set omitted the newly private original delivery proof. The corrected check
compares the exact pre-existing file set and retained proof path/hash, while retaining
all payment/audit rollback assertions. The final complete run above is green;
no acceptance case was skipped or turned into a success label.

The first actual PostgreSQL conflicting-outcome race exposed a deadlock: inserting
a command acquired an immediate actor foreign-key key-share lock before the shared
domain order/parcel/user locks. Two same-actor commands could then wait on each
other. The additive actor constraint is now `DEFERRABLE INITIALLY DEFERRED` on
PostgreSQL, enforcing existence at commit after domain locks. The verifier checks
that actual constraint metadata. SQLite ignores this PostgreSQL-only timing.
Competing riders produce one pickup assignment; identical actor/key claims and
private-proof deliveries return one original result; competing delivery/failure
produces one outcome and no duplicate collection/attempt. A losing terminal-task
request may return hidden 404 or stale 409, as documented. No generic success or
weaker authorization was introduced to make the race pass.

The [packaged verifier](../scripts/verify-rider-races/README.md) uses a new random
local database, internal Docker network, no published ports, temporary database
storage, a read-only repository and isolated runtime files. It refuses an existing
schema and cleans up only its own project. The packaged run followed an earlier
passing draft run of 238 assertions; the conditional winning outcome accounts for
the assertion-count difference. These four races do not establish general financial
concurrency, trigger behavior for every workflow, historical-cohort performance,
Azure deployment or mobile acceptance. No normal development/production database
was migrated, reset or seeded by verification.

Exact local evidence commands:

```sh
bash .codex/native-api/run-php.sh final-full-corrected
bash .codex/native-api/run-php.sh final-focused --filter 'Rider(OperationsApi|OperationsBoundary|ParcelApi|ConnectedApi|NativeContract)Test'
scripts/verify-rider-races/run-approved.sh --approved-disposable-postgres
node .codex/native-api/validate-contract.cjs
node .codex/native-api/validate-openapi.cjs
```

The `.codex` runner/helpers and XML/log/comparison samples are machine-local ignored
artifacts. The SQLite runner explicitly supplies testing, `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`, empty `DB_URL`, array cache/session/mail and separate storage.
The PostgreSQL harness is tracked and separately requires express authorization.
Contract validation used existing installed AJV, without a new project dependency.
For the official OpenAPI 3.1 base schema, its unextended Schema Object meta-reference
was bound explicitly because the installed validator cannot resolve that dynamic
reference correctly. General format plugins were disabled; compiled response schemas,
explicit patterns and boundary checks were validated separately. This is not a claim
that an unmodified full OpenAPI dialect validator or production responses were tested.

**Scoped engineering assessment: native operational API 0/10 -> 8/10 locally.**
The native app can now consume real scoped work, private evidence, communication
and recorded cash through the same web-owned rules. All core adapters, regression
and the selected PostgreSQL race gate pass. This does not raise overall admin,
Rider UI or project readiness, nor prove Flutter/device/production integration.

Local commits for this backend handoff:

- `9c444fc` — `feat(rider-api): expose scoped work queues and retained pickup claims`
- `7ad7458` — `feat(rider-api): record shared parcel outcomes with private proof`
- `943fb12` — `feat(rider-api): retain scoped trip history and private evidence reads`
- `79444cd` — `feat(rider-api): bind message drafts and reads to owned assignments`
- `5bafce0` — `feat(rider-api): expose retained cash and scoped remittance offers`
- `57ea48e` — `test(finance): preserve original private proof during payment rollback`
- `824f1c2` — `fix(rider-api): avoid actor lock inversion in concurrent commands`
- `228927e` — `docs(rider-api): publish executable operations contract and integration examples`

This evidence update's generated commit hash is reported in the final handoff.
**Deployment: not performed; current VM revision and new HTTPS operational routes
were not independently observed.** After user publication/review, apply all additive
source migrations, including the inherited financial sources and new command table.
Preserve private proof/receipt storage and backups; recorded command/finance history
prevents destructive rollback. Audit legacy public POD before release, refresh
route/config caches and workers/scheduler, run the established deploy/verify workflow,
and record the actual revision, pending-migration result and fresh HTTPS bearer
reads/rejected access. No new secret or production configuration was created here.

The contract's per-batch integration checklist is the next consumer handoff: renew
tokens, decode IDs/cents/nulls exactly, replace unavailable providers with the accepted
routes, preserve selected drafts/proof bytes and reconcile uncertain outcomes.
Flutter adapters/controllers, physical Android/camera, real email and full buyer/hub
release acceptance belong to their respective maintainers and remain unverified here.
A bounded reviewed rollout/HTTPS check is an estimated 1–2-hour next backend batch,
after publication and legacy-proof retention review; this is a planning estimate,
not recorded work. Native release/recovery or identity extensions require a separately
chosen contract before implementation; no future tasks or unapproved expansion were
created automatically.

## Buyer and Seller Review Integrity and Order Workspaces: October 9, 2026

The selected [BS01](buyer-seller-plan/01-review-integrity.md) and
[BS02](buyer-seller-plan/02-order-workspaces.md) batches are implemented locally on
`feat/buyer-seller-review-and-orders`. The five separate plans and shared workflow
are saved in [buyer-seller-plan](buyer-seller-plan/README.md). BS03 inventory/drafts,
BS04 discovery/saved products and BS05 Buy again remain unstarted.

**BS01: purchase reviews.** New reviews identify an original purchased item owned
by the currently approved buyer and require its order to be completed. Order,
product, shop and buyer links come from that item. The database permits one new
review per item. Identical retries return the original text, photos and timestamps;
changed repeats reject. Invalid photos and failed rating writes retain the original
records and remove unused new files. Public review data omits private account and
order identifiers.

The additive migration conservatively links an old review only when its owned
completed purchase, matching item and review are all unambiguous. Original text,
images and timestamps remain unchanged. Other historical rows stay visibly
unverified and do not enter verified rating averages. Seller replies now persist,
enforce the current owning eligible shop and keep their original attribution when
edited. Product/store ratings, verified counts and review reply rates come from
records; unrated products show an empty state. The interfaces remove the scoped
invented rating, premium and response/delivery claims.

**BS02: owned order workspaces.** Buyers and sellers use server-side stage filters,
whole-history counts and stable date/ID pagination. Stale pages clamp to the
remaining last page after an order changes stage, including restricted history
and empty stages. Seller cards contain all owned
items under one original order, with one selection/action area and distinct unit
and order counts. Delivered, completed, delivery issue, returned and cancelled
remain separate, including explicit historical aliases. Buyer receipt controls
require physical delivery or the existing evidenced counter collection; the
existing buyer receipt writer alone completes an order.

Seller batch readiness validates the entire selection, locks its orders and
parcels consistently, and writes inside one transaction. A later invalid row or
checkpoint failure retains every earlier order, parcel, notice and outbox intent.
Stale rider claims, missing packing/route evidence, foreign selections and mixed
legacy ownership reject. Cancellation uses the existing six displayed reasons,
requires explanatory notes for Other reason, and preserves exactly-once stock
restoration, voucher allocations and the pre-custody boundary. Restricted shop
history remains readable. Mixed-shop history shows only owned products with
fulfilment controls disabled, and commission records are scoped to their seller.

Dashboard return links/counts now refer to actual reverse-custody parcels and
separate exception destinations. Post-delivery disputes remain explicitly
unavailable. Buyer purchases remain in the existing profile/tracking workspace,
including settings/address navigation and saves. Both roles keep their existing
portal layouts and styling; no global, rider or Flutter interface was redesigned.

Implementation references:
[ReviewService.php](../app/Services/Commerce/ReviewService.php),
[review migration](../database/migrations/2026_10_09_100000_link_purchase_reviews_and_create_seller_replies.php),
[Review.php](../app/Models/Review.php),
[ReviewReply.php](../app/Models/ReviewReply.php),
[OrderWorkspaceService.php](../app/Services/Orders/OrderWorkspaceService.php),
[OrderLifecycleService.php](../app/Services/Orders/OrderLifecycleService.php),
[SellerOrderController.php](../app/Http/Controllers/Seller/SellerOrderController.php),
[buyer purchases](../resources/js/Pages/Buyer/Profile.tsx),
[seller orders](../resources/js/Pages/Seller/Orders.tsx),
[seller reviews](../resources/js/Pages/Seller/Reviews.tsx) and
[commerce frontend checks](../tests/Frontend/commerce.test.mjs).

### Verification and limits

All PHP checks used isolated SQLite `:memory:` with array cache/session/mail.
The complete run also used separate ignored runtime storage. No development or
production database was migrated, reset or seeded.

| Check | Result |
|---|---|
| Fresh original commerce/lifecycle baseline | 428 tests, 6,943 assertions, passing |
| Final focused BS01 review/reply cases | 33 tests, 210 assertions, passing |
| BS01 buyer/seller regression before the final guest case | 430 tests, 5,021 assertions, passing |
| Initial BS02 workspaces plus account-settings contract retest | 44 tests, 1,125 assertions, passing |
| Complete regression run | 2,904 tests, 58,588 assertions; one obsolete order-array assertion, zero errors or skips |
| Final buyer/seller, settings, financial oversight and normal hub-to-completion regression | 521 tests, 9,944 assertions, passing |
| Frontend checks, including grouped cards, counts, receipts and restricted history | 63 passing |
| Production TypeScript/Vite build | Passing through `docker compose exec -T app npm run build` |
| Scoped PHP formatting and Git whitespace check | Passing |

The complete-run failure was
`AccountSettingsUpdatesTest::test_order_history_opens_the_purchases_workspace_with_only_owned_orders_on_both_hosts`.
Its original ownership and both-host assertions were retained and migrated from
the old array to `orders.data`, with paginator and whole-history totals added.
The entire file subsequently passed 7 tests and 290 assertions and is also in the
final passing 521-case regression. Two additional stale-page acceptance cases
were added after the complete run and pass in that final regression. The complete
run was not repeated after the fixture correction, seller commission-read
restriction and pagination follow-up. Its historical
failure remains in the artifact; final verification combines that broad evidence
with passing affected-flow retests. No case was removed, skipped or relabelled.

Exact acceptance references:
[ReviewIntegrityTest.php](../tests/Feature/Buyer/ReviewIntegrityTest.php),
[SellerReviewReplyTest.php](../tests/Feature/Seller/SellerReviewReplyTest.php),
[BuyerOrderWorkspaceTest.php](../tests/Feature/Buyer/BuyerOrderWorkspaceTest.php),
[SellerOrderWorkspaceTest.php](../tests/Feature/Seller/SellerOrderWorkspaceTest.php) and
[AccountSettingsUpdatesTest.php](../tests/Feature/AccountSettingsUpdatesTest.php).
Ignored `.codex/buyer-seller` logs/XML and the failed-case/retest comparison retain
the machine-local evidence. No browser, device or live-site acceptance was run.
SQLite verifies rollback, uniqueness, stale sequential rejection and ownership;
it does not establish PostgreSQL locking/concurrent execution. The earlier native
claim/outcome races do not certify these new review/batch paths. Large historical
backfill performance also remains unmeasured.

**Scoped engineering assessment:** purchase review integrity **2/10 -> 8/10**;
order workspace accuracy/action safety **5/10 -> 8/10** locally. Saved replies,
verified averages and purchase links replace fabricated or unbound review signals.
Original grouped orders, truthful stage counts and transactional batches remove
duplicate cards and partial batch progress. These ratings cover the selected
features; overall buyer, seller, admin and project readiness scores are unchanged.

Local implementation commits:

- `b70d204` — `docs(commerce): split buyer and seller improvements into bounded plans`
- `b5fda7c` — `feat(commerce): verify purchase reviews and persist seller replies`
- `a995067` — `feat(commerce): show recorded ratings and saved review responses`
- `8f1fce1` — `feat(commerce): group owned orders and make seller batches atomic`
- `33a00ce` — `feat(commerce): present paginated buyer and seller order stages`
- `d326a33` — `fix(commerce): keep filtered order pages valid after status changes`

The generated hash for this evidence update is reported in the final handoff.
Publication and deployment were not performed. After review/publication, the
deployment must apply the additive review migration before serving the new pages
and refresh the usual application/assets caches. Retained purchase/reply history
blocks a destructive rollback. Keep the existing deploy/verify workflow; no
database reset or new external service is part of this batch.
