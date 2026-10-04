# BagooPH Core Flow Roadmap

This document is the single source for current implementation gaps and delivery order across buyer, seller, courier, logistics, and admin. Stable business rules remain authoritative in `SYSTEM_FLOW_AND_SPECIFICATIONS.md`; physical custody rules remain authoritative in `SORTING_CENTER_LOGISTICS_FLOW.md`.

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

- **Implemented:** active code and focused tests cover the required baseline behavior.
- **Partial:** a usable foundation exists, but at least one required invariant or persistence record is missing.
- **Missing:** the required baseline behavior is not represented by enforceable application logic or persistence.
- **Deferred:** intentionally outside the core baseline.

| Area | State | Evidence and gap |
|---|---|---|
| Account roles and approval | Partial | The October 4 shared-governance audit confirms inconsistent non-courier root/subdomain gates, an admin suspension bypass, and unaudited review/profile changes. See the scoped review below; courier eligibility coverage remains intact. |
| Cross-cutting input and mutation safety | Partial | Many controllers use basic string validation, but canonical phone/postal/text rules, idempotency, stale-state conflicts, and adversarial authorization are not consistently enforced. |
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
| Admin governance and audit | Partial | Platform logistics views remain read-only for parcel custody. Unsafe direct overrides and their UI are removed; replacement corrections require lifecycle validation and immutable audit records in Phase 5. The October 4 review identifies approval evidence/idempotency/audit gaps, unrestricted role/status editing, and guessed metrics. |
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
| Platform Admin | 5/10 | 10/10 | Approval/suspension audit, immutable overrides, COD audit, and removal of fake operations |
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

Verified pre-implementation code gaps, rather than specification changes to match the bugs. The account-access closure is recorded in the subsequent implementation review below:

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

- **Next focused task: make KYC approval/rejection a reasoned, auditable decision.** Require private evidence and current submission state, lock/update the account and related profile atomically, retain reviewer/time/reason/history, preserve independent restrictions, and reject conflicting stale decisions while returning an identical retry's original result. The unchanged direct updates are at `app/Http/Controllers/Admin/AdminKycController.php:65` and `:96`; resubmission still resets activity at `app/Http/Controllers/Auth/RegisteredUserController.php:333`.
- **Resource eligibility remains separate from account eligibility.** Static inspection shows the seller shop resolver selects owned/default shops without a positive shop-status check and creates an active fallback (`app/Http/Controllers/Seller/HasSellerShop.php:15`). The hub resolver filters active hubs/handlers but does not require parent-company eligibility (`app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php:75`). A complete negative resource/facility matrix was not run in this task; do not treat the account tests as its acceptance evidence.
- **Other Phase 0 gaps:** shared canonical input validators, buyer onboarding/holding and other buyer-only entry-point alignment, reasoned role/status changes, active-work deletion protections, and live sample-success removal. The tested owned-order exception does not certify every buyer endpoint. Suspended-buyer sign-in/recovery access still needs review separately from the preserved authenticated exception.
- **Later phases:** durable manifests, custody recovery/retry/RTS and secure counter claims, basic in-app notifications, COD reconciliation/settlement, and finance/dashboard corrections. Existing operational/financial evidence must be preserved while these remain unavailable.

## Delivery Phases

Work on one phase at a time. Do not begin a later phase until the current phase has focused tests and its cross-role acceptance path passes.

Every phase must also pass the mandatory acceptance gate in `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`. A happy-path test alone is not completion.

### Phase 0: Security and Lifecycle Entry-Point Lockdown

**State: Partial.** The main rider lifecycle mutation-path closures are implemented. Simulator, public tracking, and direct admin custody mutation paths are removed; root and subdomain seller, courier, hub, and admin portals share positive account eligibility, including non-active admin denial and role/action boundaries. Rider transitions lock order and parcel, reject terminal or mismatched commercial states, require stored delivery proof, and preserve completed evidence on retries. Proxy-aware tracking throttling and consistent legacy courier approval checks are now implemented and tested. Private verification uploads, authorized document access, legacy-file migration, and secret-mail protections are also implemented. Shared validators, related shop/company eligibility, buyer-entry alignment, auditable approval/restriction decisions, and other live sample-success paths remain.

- Keep simulator advance/reset routes removed in every environment; use real role flows in tests.
- Keep public tracking read-only, masked, and rate-limited; authenticated actions belong in their authorized portal and lifecycle service.
- Keep both courier portals restricted to active approved couriers, including read endpoints; going off duty must not prevent finishing an existing custody assignment.
- Keep direct admin custody overrides unavailable until lifecycle validation and immutable correction audit records exist.
- Keep verification files private with applicant/reviewer authorization; deploy the legacy-file protection and migration before exposing the updated review flow.
- Preserve secret-mail transport guards and safe OTP/reset failure handling; never log secret-bearing email or flash OTP, verification, reset, or claim codes into old input.
- Establish shared canonical validators for names, phones, postal codes, codes, plain text, files, and role-specific registration fields.
- Keep shared positive root/subdomain account gates; complete related shop/company/facility eligibility and buyer-entry alignment while keeping holding and existing-order/recovery exceptions narrowly authorized.
- Make approval/rejection and suspension/reactivation separate reasoned decisions with required private evidence, transaction/locking, immutable audit, and safe identical retries.
- Block role changes/deletion that would lose transactional history; preserve active work and recovery responsibility during restrictions.
- Remove fake proof, sample dispute/message success, and seeded operational fallbacks from live paths.

Acceptance: direct URLs, stale pages, alternate portals, simulators, and malformed inputs cannot bypass ownership or lifecycle rules; secrets and KYC files are not publicly exposed.

Next Phase 0 work: implement auditable approval/rejection with required evidence and restriction preservation, then complete shared input validators, related resource and buyer-entry eligibility, role/deletion protections, and removal of live sample-success paths. Rider waybill scan evidence follows after Phase 0; retry/RTS and COD persistence retain their later phase order.

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
- Cover only meaningful order, custody, failure, pickup, completion, and RTS events.
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
