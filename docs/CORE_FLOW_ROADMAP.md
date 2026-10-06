# BagooPH Core Flow Roadmap

This document is the single source for current implementation gaps and delivery order across buyer, seller, courier, logistics, and admin. Stable business rules remain authoritative in `SYSTEM_FLOW_AND_SPECIFICATIONS.md`; physical custody rules remain authoritative in `SORTING_CENTER_LOGISTICS_FLOW.md`.

## Project Delivery Target: October 4, 2026

At the user's direction, planning begins **October 4, 2026**, with expected project completion around **November 20, 2026**. November 20 is the latest planned task deadline. The main target is in [README.md](README.md#project-delivery-target), and [the admin plan](admin-plan/README.md#delivery-window) divides the window into governance, prerequisite, later-admin, and final-review checkpoints. The allocation is 18 bounded admin tasks on 14 planned branches, or 15 Git deliveries including the existing foundation; B06+B07, B08+B09, and B10-B12 each share one branch.

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

Audit date: September 24, 2026.

Scoped rider lifecycle lockdown review: October 3, 2026. This review updates entry-point, portal-access, tracking-privacy, and delivery-evidence controls; it is not a fresh audit of every role.

Scoped account/resource restriction review: October 5, 2026, B06+B07. This updates governance decisions, independent activity, and retained work; overall phase and cross-role ratings remain unchanged.

Scoped reviewed-identity and closure review: October 6, 2026, B08+B09. This adds separate correction requests and conservative role-wide closure decisions; overall phase and cross-role ratings remain unchanged.

- **Implemented:** active code and focused tests cover the required baseline behavior.
- **Partial:** a usable foundation exists, but at least one required invariant or persistence record is missing.
- **Missing:** the required baseline behavior is not represented by enforceable application logic or persistence.
- **Deferred:** intentionally outside the core baseline.

| Area | State | Evidence and gap |
|---|---|---|
| Account roles and approval | Partial | Shared root/subdomain gates enforce fixed roles, approval, and activity. KYC retains private evidence and immutable decisions. B01-B05 add category/application rules, independent shop review, resource eligibility, and buyer access alignment. B06+B07 add separate reasoned account/resource activity decisions, current-parent checks, last-admin continuity, and retained affected-work responsibility. B08+B09 add evidence-backed reviewed corrections, guarded closure, retained identity/evidence, and blocked unresolved work/cash. Moderation, searchable audit, overview cleanup, and the Phase 0 acceptance gate remain. See the scoped implementation reviews below. |
| Cross-cutting input and mutation safety | Partial | Application registration/correction/readiness share canonical text, phone, postal, email, identifier, and enum rules through B02. Other account/profile, saved-address, shopping, and operational mutations still need their own validation, idempotency, stale-state, and authorization audit. |
| Alternate lifecycle entry points | Implemented | Simulator advance/reset routes, public tracking actions, and direct Platform Admin parcel overrides are removed on root and applicable subdomain routes. Guest and all-role tests verify repeated requests cannot change assignment, custody, checkpoints, payment, or commission records. |
| Secret and KYC protection | Implemented | Registration, resubmission, and buyer ID uploads use private storage. Document access requires the applicant or an active Platform Admin; raw paths are hidden from serialized data. Legacy public URLs are blocked, with a tested migration command for stored files and references. OTP, verification-link, and password-reset mail reject logging transports and logging fallbacks; failures log only safe identifiers and exception classes. Deployment must apply the web-server rules and legacy-file migration described in `VERIFICATION_DOCUMENT_SECURITY.md`. |
| Multi-shop checkout | Implemented | Checkout transactionally creates an independent order, delivery, waybill, shipping fee, and route per shop; routing failure rolls back the checkout. |
| Voucher allocation | Implemented | Shop vouchers are isolated to their owning shop, while platform discounts are proportionally divided without exceeding the calculated discount. |
| Seller fulfillment | Implemented | A central lifecycle service enforces `PLACED -> CONFIRMED -> PREPARING -> READY_FOR_PICKUP`, shop ownership, and the pre-custody cancellation boundary. |
| Pickup rider handoff | Implemented | Pickup claims lock the commercial order before the parcel, matching seller cancellation; ready state, assigned rider, logistics company, and origin-hub scope remain enforced. Pickup state, checkpoints, notes, and seller messages commit or roll back together. A submitted waybill scan and stronger handoff evidence remain follow-up work. |
| Facility routing | Implemented | Checkout requires a complete origin Bayan Hub, Mother Hub, and destination Bayan Hub route and rejects incomplete routing. |
| Manifest custody | Missing | Manifest numbers exist as delivery/checkpoint fields; there are no manifest and manifest-parcel records with dispatcher, receiver, vehicle, and close/receive control. |
| Scanned hub custody | Implemented | Waybill scans use separate inspect and confirm steps, enforce expected status, owned facility/company scope, route order, and idempotent duplicate confirmation. |
| Destination sort and rider assignment | Implemented | Destination sorting and final-mile assignment enforce parcel state, destination facility, logistics-company scope, and assigned-rider ownership. |
| Failed delivery and RTS | Partial | Failure count and a third-attempt trigger exist; attempt records, hub-return custody, retry dates, reverse manifests, and seller receipt are missing. |
| Hub self-pickup | Partial | Counter staging and release screens exist; the claim code is optional and lacks secure hashing, expiry, reuse prevention, identity enforcement, and COD custody. |
| Persistent notifications | Missing | Rider boards provide operational tasks, but persistent buyer/seller lifecycle notifications and notification-center records are absent. |
| COD reconciliation | Partial | Normal delivery no longer marks COD paid or creates settled commission entries. Append-only custody, remittance, discrepancy, and platform reconciliation records are still missing. |
| Buyer-only completion | Implemented | Only the owning buyer can advance a delivered order to `COMPLETED`; normal-flow coverage verifies delivery remains financially pending before reconciliation. |
| Admin governance and audit | Partial | Platform logistics views remain read-only for parcel custody. KYC/shop review, positive resource eligibility, and B06+B07 reasoned restrictions have transactional history and scoped authorization. Restriction subjects/history cannot be deleted through model or raw database writes. B08+B09 add reviewed correction and role-wide closure controls, with closed-account access and immutable retained history. Searchable governance audit, product moderation, and accurate metrics remain. Custody corrections retain Phase 5. |
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
| Platform Admin | 5/10 | 10/10 | Product moderation, searchable governance audit, truthful overview data, COD audit, and Phase 0 acceptance |
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

## Delivery Phases

Work on one phase at a time. Do not begin a later phase until the current phase has focused tests and its cross-role acceptance path passes.

Every phase must also pass the mandatory acceptance gate in `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`. A happy-path test alone is not completion.

### Phase 0: Security and Lifecycle Entry-Point Lockdown

**State: Partial.** The main rider lifecycle mutation-path closures are implemented. Simulator, public tracking, and direct admin custody mutation paths are removed; root and subdomain seller, courier, hub, and admin portals share positive account eligibility, including non-active admin denial and role/action boundaries. Account roles are fixed at creation; admin conversion controls/routes and their coupled status shortcut are removed. Rider transitions lock order and parcel, reject terminal or mismatched commercial states, require stored delivery proof, and preserve completed evidence on retries. Proxy-aware tracking throttling and consistent legacy courier approval checks are implemented and tested. Private verification uploads, authorized document access, legacy-file migration, and secret-mail protections are implemented. KYC decisions now have evidence/session/version checks, atomic history, safe retries, and independent restriction preservation. Adult worker birth dates are validated at registration/correction/approval; known underage reviewed users cannot access worker/admin portals, while missing-date legacy accounts still need a controlled audit. B01 enforces master-category scope for new registration, owned unreviewed correction, and original-shop KYC approval. B02 shares canonical application-field rules across registration, correction, and review. B03 implements independent shop decisions, fresh owned context, and positive shop/category commerce eligibility; legacy shops require actual review. B04 shares positive company/facility/assignment checks across resource reads, placement, routing, dispatch, and custody actions. B05 aligns buyer holding, sign-in, protected work, and narrow owned-order access. B06+B07 implement separate reasoned account/resource restrictions, last-admin continuity, and retained affected-work responsibility. B08+B09 implement reviewed correction and role-wide closure decisions, including evidence retention and unresolved cash/work blockers. Moderation, searchable audit, truthful overview data, and other live sample-success paths remain.

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

Next Phase 0 work: B01-B09 are merged into `main` at `1d53c70`. The selected B10-B12 batch uses `admin/moderation-audit-and-overview` for product moderation, searchable governance audit, and truthful overview data, with separate focused gates before each dependent task. Preserve fixed roles, adult-worker validation, and the verified KYC/shop/resource foundations. After combined verification and user-managed review/merge, B13 must verify all applicable Phase 0 requirements rather than treating branch publication as phase completion. Rider waybill scan evidence follows after Phase 0; retry/RTS and COD persistence retain their later phase order.

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

**State: Partial.** Ordered hub scans and mandatory Mother-Hub custody are enforced; durable manifest records and dispatch/receive controls remain.

- Add feeder and line-haul manifests with source, destination, vehicle, dispatcher, receiver, timestamps, and included parcels.
- Require manifest outbound and receiving-facility inbound scans.
- Require at least one Mother Hub and enforce handler facility and logistics-company scope.
- Keep internal movement under buyer-facing `AT_SORTING_CENTER`.

Acceptance: same-region and cross-region parcels cannot skip their required Mother-Hub custody.

### Phase 3: Delivery Exceptions and Self-Pickup

**State: Partial.** Basic failure counting and counter screens exist; the required attempt, retry, reverse-custody, and secure claim contracts remain.

- Record each delivery attempt with rider, number, reason, notes, proof, attempt time, hub return, and retry date.
- Allow retries after attempts one and two; begin reverse routing after the third failure.
- Set `RETURNED` only after authenticated seller receipt.
- Secure self-pickup with a hashed one-time code, correct-hub and ready-state checks, identity confirmation, COD collection, seven-day expiry, and day-three/day-six reminders.

Acceptance: custody always returns to the hub after failure; expired or invalid pickup claims cannot release a parcel.

### Phase 4: Basic In-App Notifications

**State: Missing.**

- Persist unread/read notifications linked to orders, deliveries, and tasks.
- Cover meaningful order, custody, failure, pickup, completion, and RTS events.
- Connect recorded review, restriction, correction, and accountable recovery decisions through B15 after the persistent notification foundation; decision retries must not duplicate notices.
- Keep rider boards and assignment queues as operational task notifications.

Acceptance: each event produces one notification for the correct recipient without duplicates.

### Phase 5: COD and Admin Reconciliation

**State: Partial.** Early COD payment and commission settlement were removed from normal delivery; the custody ledger and reconciliation workflow remain.

- Add append-only COD entries for collection, rider-to-hub remittance, counter collection, hub-to-platform remittance, and adjustments.
- Keep commission pending until the buyer completes the order and COD reaches platform reconciliation.
- Apply the 90%/10% split only to product subtotal; track shipping, logistics revenue, and rider earnings separately.
- Route admin corrections through lifecycle services and immutable audit records.

Acceptance: delivery never marks COD reconciled or seller proceeds settled early, and every correction retains actor and reason.

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
