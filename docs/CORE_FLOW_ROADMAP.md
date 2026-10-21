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

The audit and quality table below retain that historical baseline; later dated entries supersede only their named areas. They are not a fresh whole-system rating. Current software versions and request/deployment architecture are verified in [TECH_STACK.md](TECH_STACK.md) and [ARCHITECTURE.md](ARCHITECTURE.md); use [the presentation guide](TECH_STACK_PRESENTATION.md) for an explanation of the existing choices.

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

Scoped seller BS03 inventory/drafts review: October 10, 2026. Validated draft creation, retained inventory filters, matching active-stock alerts and zero-preserving variant edits pass 575 affected-flow tests and 69 frontend checks, plus the production build. Inventory usability is assessed at 4/10 -> 8/10 within this batch. BS04-BS05, deployment and browser/device acceptance remain separate; overall role scores are unchanged.

Scoped dependency/database configuration review: October 10, 2026. Available security fixes are locked and installed, the shared stylesheet remains identical, and database host publication is limited to localhost. All 2,958 PHP tests, 69 frontend checks, 9 deployment checks and the production build pass. Composer has zero advisories; npm retains five high entries from one unpatched build-tool chain. The scoped configuration assessment is 6/10 -> 8/10; Azure exposure and overall role/project ratings remain unverified or unchanged. See the dated review below.

Scoped logistics parcel recovery review: October 10, 2026. Company-authorized actual intake and recovery manifests restore recognized unexpected/wrong-hub parcels to their original expected receipt while preserving route, history and commercial state. The 3,097-case full regression passes, followed by 65 final recovery/hub checks after a scoped manifest-link refinement; 80 frontend checks and the production build pass. Physical recovery is assessed at 0/10 -> 8/10 within this batch. Deployment, PostgreSQL execution and broader restricted-resource recovery remain separate.

Scoped logistics workstation review: October 11, 2026. Registry/counter guidance separates commercial state, physical custody and authorized next steps. Review corrected delayed claim-window updates and collection history ordered by parcel creation. The final 57-case affected-flow selection, 91 frontend checks and production build pass. Guidance usability is assessed at 5/10 -> 8/10; scanner cleanup, queue capacity and deployed acceptance remain separate. Known dependency advisory limits are unchanged.

Scoped scanner/counter queue completion: October 11, 2026. The scanner now uses recorded custody and shared next-step/blocker guidance, preserves real bin labels and opens the secure counter for buyer verification. Counter pages load 15 parcels, show the full matching total and label readiness for the visible page. The 396-case logistics regression and two added phone-search cases pass; all 100 frontend checks and the production build pass. Scanner/counter queue handling is assessed at 6/10 -> 8.5/10 within this batch. Cross-role tracking, deployed acceptance and overall role/security ratings remain separate.

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
| Seller inventory and drafts | Implemented; locally verified | BS03 creates validated drafts or active listings, retains scoped search/category/status/stock selections across stable pages, preserves zero variant quantities and links dashboard stock counts to matching worklists. Publishing retains approval, category and moderation gates. See the October 10 inventory review below. |
| Buyer discovery and saved products | Implemented; locally verified | BS04 shares validated literal search and stable catalogue pagination, retains buyer-owned saved products across sessions and hides unavailable listing details. Saving never reserves stock; saved references require seller archival. See the October 10 discovery review below. |
| Buyer Buy again | Implemented; locally verified | BS05 previews owned completed purchases against current catalogue, variants, prices and Bag limits. Selected items commit together, successful retries retain their original result, and normal checkout creates the next purchase. See the October 10 Buy again review below. |
| Pickup rider handoff | Implemented; scoped verified | Actual waybill matching and retained root custody checkpoints now prove pickup and original-hub receipt. Claims retain order/parcel locks, current approval/company/hub scope and the seller cancellation boundary. Narrow restricted-courier handover is verified separately below; it does not open routine work. |
| Facility routing | Implemented | Checkout requires a complete origin Bayan Hub, Mother Hub and destination Bayan Hub route and rejects incomplete routing. Destination province/city and configured barangay coverage must agree; coordinates, repeated barangay names and partial city names cannot override coverage. A selected self-pickup counter must serve the stated destination. |
| Manifest custody | Implemented; scoped verified | Durable manifests, retained membership/events, actual scans, sealed departure, partial destination receipt and supported discrepancy/closure controls are verified below. The October 10 recovery batch adds company-authorized intake and actual recovery manifests for recognized unexpected/wrong-hub parcels, retaining the original route and receiving obligation. Unknown parcels, unsupported restricted-resource recovery, deployed acceptance and simultaneous PostgreSQL verification remain separate. |
| Reviewed missed-handover recovery | Implemented; locally verified | H01 reporting, H02 seller/rider holds and H03 hub/manifest corrective receipts preserve actual custody, original transport evidence, Mother Hub routes and truthful arrival counts. H04 adds involved native reports, private evidence and named-holder acknowledgement over those services. The October 14 reviews retain separate Flutter/device, PostgreSQL simultaneous-request and deployment boundaries. |
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

| Role or flow | Baseline implementation | Target after required phases | Main improvement required |
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

Implementation references: [buyer eligibility](../app/Services/BuyerAccessService.php), [route middleware](../app/Http/Middleware/EnsureBuyerAccess.php), [application submission](../app/Services/KycSubmissionService.php), [private documents](../app/Services/VerificationDocumentService.php), [owned orders](../app/Http/Controllers/Buyer/OrderHistoryController.php), [receipt service](../app/Services/Orders/OrderLifecycleService.php), [buyer application modal](../resources/js/Components/BuyerApplicationModal.tsx), [order access layout](../resources/js/Layouts/BuyerOrderAccessLayout.tsx), and [B05 acceptance tests](../tests/Feature/Buyer/BuyerAccessAlignmentTest.php).

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

**State: Partial.** Durable manifests and their actual load, dispatch, individual receipt, discrepancy and closure actions are scoped verified below. Ordered hub scans and mandatory Mother-Hub custody are enforced. The October 10 batch adds verified physical recovery of recognized unexpected/wrong-hub parcels through assigned-handler intake and recorded transfers to their original expected hub. Unsupported restricted-resource recovery, deployed acceptance and simultaneous PostgreSQL verification remain open.

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

**State: Partial.** Rider ownership, persistent availability, phase-limited task data, delivery-linked messaging, real proof upload, truthful profile/trip history, and normal-path cross-role validation are complete. Role-owned recovery and financial flows have scoped local evidence below. Broader portal cleanup and unsupported restricted-resource recovery remain.

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

## Native Rider Deployment Verification and Feature Discovery: October 9, 2026

**The local Home discovery bug is fixed; the live operations release gate fails.**
Branch `fix/rider-api-capability-discovery` starts from local `main` at `a1eb08b`
(`Feat/buyer seller review and orders (#72)`). The request is to verify the
existing rider backend for its mobile maintainer. It adds no web map, provider,
Flutter change or live parcel mutation. The following evidence is an observation,
not a successful rollout or permission to enable mobile writes.

The authenticated Azure inspection found checkout revision
`9fe5ce5bff4104d7981f888c0f2a8b510245e1f1` (`Fix/azure build memory (#70)`).
The actual application reports production mode, debug disabled, PostgreSQL,
maintenance off and an existing asset manifest. The latest complete HTTPS/schema
observation was **October 9, 12:54:54 UTC / 20:54:54 Asia/Manila**. There are 58
recorded migrations and no pending files in that older checkout. That does not
mean the current target is installed: all three October 9 migrations in local
`main` are absent from the deployed migration history:

- `2026_10_09_000000_create_seller_settlement_records`
- `2026_10_09_010000_create_rider_commands_table`
- `2026_10_09_100000_link_purchase_reviews_and_create_seller_replies`

`rider_commands` is absent. The existing Settings revision/email/token-fingerprint
schema, delivery checkpoints/attempts, COD accounts and **`cod_cash_events`**,
messages and notifications are present. Zero of the 25 OpenAPI operational
method/route pairs is registered in the running application. The review migration
belongs to the newer commerce release; all missing migrations must be considered
when rolling out the selected current source, rather than resetting its database.

Fresh live checks used the existing documented synthetic approved courier account.
They used certificate-verified first-party HTTPS, JSON requests, no redirects and
an in-memory bearer. The report excludes the actual origin, account details,
credentials, tokens and recipient/location data.

| Live request | Observed result |
|---|---|
| `GET /up` | 200 |
| `POST /api/v1/auth/tokens` | 200, approved access and Settings version 1; operations version 1 is not advertised |
| `GET /api/v1/rider/me` and `/rider/settings` | 200 private JSON |
| `GET /api/v1/rider/home` and `/rider/pickup-jobs` | 404 JSON, with no operational data envelope or native error code |
| `GET /api/v1/rider/tasks?phase=pickup` and `?phase=final_mile` | 404 JSON, with no operational data envelope |
| `GET /api/v1/rider/tasks/{valid unknown task reference}` | 404 route-level JSON; this is a negative probe, not owned task acceptance |
| `GET /api/v1/rider/commands/{unknown UUID}` | 404 route-level JSON; this does not exercise native command reconciliation |
| `GET /api/v1/rider/me` without bearer and `/rider/settings` with invalid bearer | 401 private JSON |
| `DELETE /api/v1/auth/tokens/current` | 200; only the newly issued verification token was revoked, and the account token count returned to its prior value |

The approved account has a company/hub placement, is on duty and passes the
existing operational eligibility check; its returned account ID is a string.
The native account/Settings and unavailable operational responses carry private
`no-store` and `Pragma: no-cache`. The older release does not return operational
request-ID or `nosniff` headers. Scoped operational IDs, exact cents and current
task disclosure cannot be accepted live from these route-level 404s; their positive
checks are local only.

The inspected account has **zero owned active pickup tasks and zero owned active
final-mile tasks** in the existing company/hub/phase scope. No current owned detail
or usable navigation pin can be accepted against this live account. No task was
claimed, assigned, fabricated or reseeded to make this check pass. In the current
native source, `stop.latitude` and `stop.longitude` expose the frozen checkout
point only at a buyer stop. Seller, origin-hub and destination-hub native stops
return null coordinates, even when a hub has saved coordinates. These nulls are
not permission to invent a pin or navigate to the future buyer stop. A genuinely
owned current task with a valid supported stop is a separate mobile test-data gate.

Local inspection also found Home checking nonexistent `cod_events` for parcel,
Trips and cash discovery. The executable schema and journal model use
`cod_cash_events`. The three checks now use that actual table. Fully installed
features are advertised; missing journal schema still leaves them unavailable.
Native release, restricted recovery and earnings remain disabled. Authorization,
capacity, versions, command retries and parcel/cash writers are unchanged.
The sanitized fully installed Home example now matches the exercised true feature
flags, with its disabled extensions retained.

**Verification:** the existing rider/account/Settings/shared-courier gate passed
**164 tests / 3,112 assertions** before the change. The new installed-schema
regression, run against the original service loaded from `a1eb08b`, failed on the
incorrect false capability. After the correction, the operations file passed
**16 tests / 488 assertions**, and the final combined gate passed
**166 tests / 3,174 assertions** in 30.170 seconds, with no failures, errors or
skips. The gate covers fresh login, scoped queues/detail, duty, capacity, stale
versions, owned reconciliation, changed/repeated intents, denied roles/placement,
parcel outcomes and the executable route contract. PHP formatting and Git
whitespace checks pass. All test writes use isolated SQLite `:memory:` and
separate local runtime storage. No new PostgreSQL race, full-suite, frontend build,
browser or physical-device check is claimed. Sanitized evidence and detailed logs
are machine-local under `.codex/rider-verification/`.

**Scoped assessment: Home feature discovery 3/10 -> 8/10 locally.** Mobile clients
can discover the installed parcel, Trips and cash features without bypassing
missing-schema safeguards. This does not raise overall rider or deployment
readiness: the live version/route gate still fails, and navigation has no owned
test task.

Local implementation commit:

- `fa8b6e2` — `fix(rider-api): discover enabled features from the COD cash journal schema`

The generated documentation commit is reported in the handoff. No push, pull,
production migration or deployment was performed. After user review/publication,
roll out the approved source using the existing `./bagoo.sh deploy` and
`./bagoo.sh verify` workflow, preserve retained/private history, and complete the
existing legacy-proof retention review. Verify the actual target revision and
required migrations, refresh applicable caches/workers and confirm the scheduler.
Then obtain a fresh approved bearer, require operations version 1, inspect Home
and authorized queues/detail, and reconcile commands using authorized test data.
Mobile duty and claim writes stay disabled until those release checks pass.

## Seller inventory and drafts review — October 10, 2026

The selected [BS03 plan](buyer-seller-plan/03-seller-inventory-and-drafts.md)
is implemented locally on `feat/seller-inventory-and-drafts`, following the
published BS01-BS02 order/review work. This delivery stops at BS03; saved products
and Buy again remain separate BS04 and BS05 batches.

Sellers can choose **Save draft** or **Publish** in the existing listing drawer.
Both require the existing complete, valid listing details. Creation accepts only
`draft` or `active`; older requests without a status retain active creation.
Editing continues to support active, draft and archived listings. Drafts stay
outside catalogue queries and public product detail. Current account/shop
approval, approved category scope and platform moderation remain enforced on the
server. Publishing a moderated draft retains its restriction and immutable
review history. Duplicate custom SKUs now return a field validation error before
creation or editing, while the current product may retain its own SKU.

The inventory worklist validates search, category, listing status, stock selection
and page values. Search continues to match names or SKUs throughout the owned
catalogue. Categories come from the shop's approved active scope. Pagination
retains the normalized selections, uses updated time plus product ID as its stable
order, and clamps stale pages after matching listings change. Clearing search
retains the other selected filters; Clear filters resets the worklist.

Dashboard alerts and inventory stock filters share one definition: active listing
stock of **1-5** is low stock, **0** is out of stock, and positive stock is in stock.
Drafts and archives are excluded. These are listing quantities, not sums of
variant quantities or a claim of public moderation eligibility. Existing
restricted-product feedback remains visible. Variant quantity/availability
sections show the stored size stock and color availability separately. Opening
and saving the editor preserves an explicit numeric zero; missing/null size stock
uses the existing listing-stock fallback. No stock schema, warehouse workflow or
extra-shop controls were added.

The seller page retains its existing merchant layout, crimson actions, typography,
listing table and drawers. New filters have labels and responsive grid layout;
variant sections use native disclosure controls. Draft/publish submissions are
guarded while pending, preserve rejected input and close/reset only after the
saved response. Buyer, rider and global portal styling was not changed.

Implementation and acceptance references:
[SellerInventoryService.php](../app/Services/Commerce/SellerInventoryService.php),
[SellerProductController.php](../app/Http/Controllers/Seller/SellerProductController.php),
[SellerDashboardController.php](../app/Http/Controllers/Seller/SellerDashboardController.php),
[seller products](../resources/js/Pages/Seller/Products.tsx),
[seller dashboard](../resources/js/Pages/Seller/Dashboard.tsx),
[seller inventory helpers](../resources/js/utils/sellerInventory.ts),
[SellerInventoryTest.php](../tests/Feature/Seller/SellerInventoryTest.php) and
[commerce frontend checks](../tests/Frontend/commerce.test.mjs).

### Verification and limits

| Check | Final result |
|---|---|
| BS03 acceptance within the affected-flow run | 50 tests, 612 assertions; passing |
| Seller, buyer, variant/gallery and product-moderation regression | 575 tests, 6,965 assertions; passing |
| All existing frontend test files, including six inventory additions | 69 tests; passing |
| Production TypeScript/Vite build through Docker | Passing |
| Scoped PHP formatting and Git whitespace check | Passing |

Acceptance includes both seller portal hosts, zero/null variant stock,
create/save/reload, draft publication and archival, invalid fields and statuses,
duplicate custom SKUs, combined filters and pagination, stock boundaries,
foreign writes, fresh account/shop/category restrictions and moderated publishing.
The first acceptance run exposed fixture errors involving repeated unique SKUs,
unrefreshed model snapshots and buyer links on a seller host; those fixtures were
corrected while retaining their original acceptance intent. The final run above
includes all 50 cases and has zero failures or errors.

PHP tests used isolated SQLite `:memory:` with array cache/session/mail and
separate ignored runtime storage. No development or production database was
migrated, seeded or reset; this batch adds no migration. Machine-local logs and
XML remain under ignored `.codex/seller-inventory`. The repository-wide PHP suite
was not run for this scoped seller change. PostgreSQL concurrency, rendered
browser/device behavior and Azure acceptance were not established by these
checks. Draft creation still requires complete listing details; partial drafts,
import/export, warehouse automation and a redesigned stock model are outside the
selected batch.

**Scoped engineering assessment: seller inventory/drafts 4/10 -> 8/10.** Previously
creation always published, the editor could replace a sold-out size with listing
stock, and the low-stock dashboard count included zero-stock drafts/archives.
The selected batch now has explicit drafts, safe zero quantities, validated
worklists, matching alerts and rejection coverage. This rating measures that
bounded seller workflow; it does not raise overall seller, buyer or project
readiness or certify live deployment.

Local implementation commits:

- `00150c6` — `feat(seller): validate drafts and share inventory filters`
- `bbfaf40` — `feat(seller): expose draft actions and inventory worklists`

This evidence is saved in `docs(seller): record inventory and draft verification`;
its generated commit hash is reported in the handoff. Publication, merge,
deployment and live acceptance remain separate user-controlled steps.

## Dependency and database configuration review — October 10, 2026

The selected security cleanup follows the published seller inventory batch on
`fix/dependency-and-database-security`. It updates available dependency fixes and
restricts database host publication before the next buyer features. BS04 and
BS05 remain separate planned deliveries; this batch does not start them.

Only `league/commonmark` changes in the PHP lockfile, from 2.10.1 to 2.10.3. The
[maintainer's release notes](https://commonmark.thephpleague.com/releases/)
identify 2.10.2 as the security release for the raw HTML filtering bypass and
GFM table parsing denial of service; 2.10.3 retains those fixes. Composer's fresh
locked audit now reports no advisories or abandoned packages.

The npm lockfile updates Axios 1.19.0 to 1.20.0, qs 6.15.3 to 6.16.0 and
source-map-js 1.2.1 to 1.2.2 within their existing dependency ranges. The existing
Tailwind and nested CSS consumers use an exact `postcss-selector-parser` 7.1.6
override for the
[maintainer's linear-time selector fix](https://github.com/postcss/postcss-selector-parser/security/advisories/GHSA-rj75-hqrm-r3gf).
The parser's major version changes insertion iteration behavior; comparison of
the generated `resources/css/app.css` output before and after this update
produced identical 175,400-byte CSS without warnings. The override should be
revisited when the consuming packages support the patched parser themselves.
Tailwind remains on 3.4.19 and the portals retain their current design.

The all-dependency npm audit decreases from ten flagged package entries
(seven high, three moderate) to five high entries. These five represent the same
unpatched `braces` issue propagated through chokidar, micromatch, fast-glob and
Tailwind, not five separate demonstrated application exploits. The
[advisory](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) and fresh registry
lookup show no patched braces release. In the inspected dependency graph and
application source this path handles trusted repository build/watch patterns;
no application endpoint or browser import was found that accepts user-supplied
globs. This is a scoped reachability assessment, not proof of general immunity.
The warning remains visible: a reviewed build-tool migration or upstream patch
is still needed. The suggested Tailwind 4 migration is deferred to its own
compatibility and design work; no forced audit fix or advisory suppression is
used here. Runtime Inertia dependencies were reviewed even though the manifest
places its frontend packages under development dependencies.

Compose now explicitly binds PostgreSQL to
`127.0.0.1:${FORWARD_DB_PORT:-5432}:5432`. Both the default and a custom host port
retain the same localhost boundary. Laravel still connects to `db:5432` on the
shared bridge network, with the existing health dependency and `bagoo_pgdata`
volume. No development container or database was recreated, migrated, seeded or
reset. The
[deployment guide](WEB_AUTH_DEPLOYMENT.md#database-host-access) explains applying
the binding through the existing deploy command, checking the actual published
address, preserving the volume and separately reviewing effective Azure inbound
rules. It also records Docker's pre-28 localhost-publishing caveat.

### Verification and limits

| Check | Final result |
|---|---|
| Composer validation and locked security audit | Valid; zero advisories or abandoned packages |
| npm all-dependency security audit | Five high entries remain, all from the documented unpatched build-tool chain |
| Generated application CSS comparison | Identical before/after, without warnings |
| Existing frontend checks | 69 passing |
| Deployment checks, including default/custom DB binding and internal access/volume configuration | 9 passing |
| Production TypeScript/Vite build in Docker with the deploy helper's 1 GiB heap setting | Passing |
| Complete PHP regression | 2,958 tests, 59,380 assertions; zero failures, errors or skips |
| Git whitespace and changed-file review | Passing |

A host `npm ci` attempt hit existing Docker-owned module permissions. The
supported Docker install succeeded; it installed the locked packages with
scripts disabled, and the subsequent frontend checks and production build
passed. PHP checks used isolated SQLite `:memory:`, array cache/session/mail and
ignored runtime storage under `.codex/security-cleanup`. Audit outputs, CSS
comparison artifacts and verification logs remain ignored there.

Actual deployment, server Docker/firewall/NSG state, public reachability and
rendered browser/device acceptance were not checked. These commits do not prove
that Azure had a publicly reachable database or that its running mapping has
changed. Existing registration email enumeration and the broader production
security review remain separate scope; overall role/project ratings are
unchanged.

**Scoped engineering assessment: dependency and database configuration
6/10 -> 8/10.** The available audited dependency fixes are applied and checked
without changing the portal stylesheet, and database host publication has an
explicit localhost boundary with deployment acceptance checks. The residual
build-tool advisory and unverified live infrastructure limit this assessment.

Local implementation commits:

- `2ed7af4` — `fix(security): update vulnerable application dependencies`
- `53ceaf6` — `fix(deploy): bind database host access to localhost`

This evidence is saved in `docs(security): record dependency and database verification`;
its generated commit hash is reported in the handoff. Publication, merge and
deployment remain user-controlled steps.


## October 10, 2026: BS04 buyer discovery and saved products

The selected BS04/BS05 branch is `feat/buyer-discovery-and-buy-again`, based on
merged security and BS03 work at `4b3dd3f`. This entry accepts BS04 independently;
BS05 is the next selected batch on the same branch.

Home and the catalogue now share trimmed search, a 100-character limit, literal
`%`, `_` and escape-character handling, validated category/sort/price/rating/stock
filters and a stable ID tie-breaker. Invalid ranges produce visible feedback.
Pagination retains validated filters and recovers from a removed last page.
Home retains its New Arrivals alias. Review filtering uses verified purchases
and an explicit numeric threshold; top-rated results put unrated listings last.

Approved buyers can save products from Home, search and product details and
open Saved products from their account menu. The database retains one entry per
buyer/product. Repeated saves/removals preserve other buyers' entries, and
sign-out does not erase the list. Stock is never reserved or decremented.
Sold-out public products retain their label; archived, moderated or otherwise
ineligible products retain only an unavailable placeholder with a removal
control. Their current name, shop, image and price are not serialized. Saved
references also prevent hard deletion and cause seller removal to archive.

Verification uses isolated SQLite `:memory:` and a dedicated test storage/cache
path. The final BS04 acceptance run passed **40 tests / 644 assertions**. The
catalogue and seller inventory/eligibility regression passed **135 tests /
1,727 assertions**; the final Home alias change was separately included in the
40-test acceptance run. Commerce frontend rendering checks passed **18 tests**,
and TypeScript plus the production Vite build passed. Changed PHP formatting
and whitespace were checked. These checks do not establish browser/device
presentation or simultaneous PostgreSQL locking behavior.

Scoped engineering assessments: discovery **5/10 -> 8/10** and persistent saved
products **0/10 -> 8/10**. Buyers receive consistent filters, useful validation
and a persistent owned list. These ratings apply to those features only;
rendered-device acceptance, release and the separate Buy again gate remain.

Release requires the additive `2026_10_10_190000_create_saved_products_table`
migration and the frontend build before normal traffic uses the new shared
saved IDs. `./bagoo.sh deploy` runs the normal migrations and build, followed by
`./bagoo.sh verify`; do not reset or reseed the live database. The development
and Azure databases were not migrated during verification.


## October 10, 2026: BS05 Buy again and final commerce acceptance

BS04 and BS05 are now implemented locally on
`feat/buyer-discovery-and-buy-again`. Their separate feature commits are
`5db23fc` (`feat(buyer): validate discovery and persist saved products`) and
`b1d6de4` (`feat(buyer): rebuild the Bag from completed purchases`). This
completes the selected delivery; publication, migration and rendered-device
acceptance remain user-managed release work.

**Buy again.** An approved active buyer can open a preview from their completed
purchase list or detail page. Foreign and unfinished orders are rejected,
including delivered purchases awaiting receipt confirmation. Restricted order
viewers and admin oversight do not receive the new-purchase action. Root and
buyer-host requests retain the existing ordinary approval gate.

The preview shows original quantities/variants and previous prices beside
current eligible listing prices and the quantity that fits the buyer's Bag.
Unavailable listings and removed variants are explained without substituting
another option. Restricted current listing details are withheld. Buyers can
exclude unavailable items and change quantities. A price change after preview
requires fresh acknowledgement instead of silently changing the quoted price.

Confirmation rechecks the owned completed order, current account, shop/category
and moderation eligibility, current price, original variant and stock under
one transaction. A shared Bag-line service uses the same merge and quantity
rules as ordinary Add to Bag; first-cart creation in those paths is serialized
by the buyer lock. A later failed row or result-recording error rolls back
all earlier changes. Products referenced by the historical order are retained.

A server-signed buyer/order token and immutable original result make identical
retries return once, including after later Bag edits, listing restrictions and
application-key rotation. Changed token reuse rejects. Original purchase
snapshots, shipping, payment and voucher details are never copied into a new
order. Adding reserves no stock. Normal checkout revalidates the selected Bag,
uses a newly chosen address and route, and creates a distinct order/parcel.
The interface awaits the confirmed JSON result before opening the Bag. A known
successful write stays confirmed if navigation fails; an unknown response can
retry the same token without adding twice.

| Verification | Result |
|---|---|
| Final BS04 acceptance | 40 tests, 644 assertions; passing |
| Buy again and ordinary Bag quantity acceptance | 71 tests, 669 assertions; passing |
| Buyer/seller, product moderation, account closure, shared portal authorization and profile input regressions | 899 tests, 10,061 assertions; passing, zero failures/errors/skips |
| All existing frontend checks, including the new saved-products and Buy again rendering cases | 74 tests; passing |
| TypeScript and production Vite build | Passing in Docker with a 1 GiB Node heap cap |
| Changed PHP formatting, whitespace and branch review | Passing |

The broader 899-test run includes both new batches and the affected checkout,
review, order workspace, inventory, moderation and approval behavior. This is
the selected regression scope, not a new full-application test claim. PHP tests
used isolated SQLite `:memory:` and dedicated runtime storage/cache paths.
Browser/device interactions, real network-failure rendering, simultaneous
PostgreSQL requests and live Azure deployment were not exercised. Neither the
development nor production database was migrated or reset for these checks.

**Scoped engineering assessment: Buy again 0/10 -> 8/10.** An absent workflow is
now an owned, editable preview and atomic Bag operation with current commercial
rules and retained retry results. Discovery and saved-products ratings remain
those recorded in BS04; unrelated overall buyer/seller or platform readiness is
not raised by this work.

Release requires both additive migrations:
`2026_10_10_190000_create_saved_products_table` and
`2026_10_10_191000_create_buy_again_submissions_table`. After user publication
and pull, the existing `./bagoo.sh deploy` runs the build and migrations;
`./bagoo.sh verify` checks assets and pending migrations. Keep existing data and
do not run a database reset or demo reseed for this delivery. A used Buy again
result cannot be rolled back by deleting its history; any rollback must retain
that additive table. Smoke-check save/remove across sign-ins, unavailable
entries, completed-purchase preview and normal checkout on the deployed site.

**Next boundary.** Stop this branch for user review/publication. A separate
logistics recovery audit should confirm the outstanding physical recovery of
unexpected/wrong-hub parcels before selecting its bounded implementation.
Recording a discrepancy or correcting an observed waybill entry does not
establish actual parcel handover. This recommendation does not start logistics
work, add status overrides or authorize rider-mobile changes.

## October 10, 2026: unexpected and wrong-hub parcel recovery

The selected [parcel recovery plan](logistics-plan/01-parcel-recovery.md) is
implemented on `feat/logistics-parcel-recovery`, based on merged `main` at
`03662bf`. A company administrator can authorize a recognized same-company
waybill against its unresolved arrival observation, original dispatched manifest
and retained transport custody. Authorization alone changes no physical custody.
An assigned receiving handler must scan the actual parcel at the observed hub.

The case freezes the original manifest member, checkout/return route, source
checkpoint, observed facility and expected receiving hub. Recovery manifests use
the existing load, seal, departure, individual receipt and closure controls. Each
leg requires a Mother Hub: two Bayan endpoints use the original leg's Mother Hub;
Mother-to-Mother transfers require the responsible driver's wing truck. Unknown
waybills and unsupported sources remain unresolved. An unused authorization can
be withdrawn without changing parcel custody; received parcels require actual
transfers.

The final actual receipt at the original expected hub records the original
manifest's receiving evidence and restores its supported inbound state. Original
membership and observations remain retained. Commercial order state, inventory
and money are unchanged during recovery. Normal delivery, buyer-only completion
and reverse routing to the owning seller remain separate actual actions.
Company discrepancy resolution and admin oversight require linked recovery and
original receipt evidence, rather than a manager note or status edit.

An owned, paginated logistics worklist shows current responsibility, blockers,
the required hubs, permitted next action and retained history. Links to manifests
are shown only when the current viewer can open the existing manifest workspace. Manifest pages,
navigation and dashboard attention counts link to it. Available vehicle choices
exclude busy or unavailable drivers; command validation rechecks eligibility.
The interface uses the existing logistics layout, typeface, accent and controls.
Public tracking exposes only safe recorded recovery milestones, and existing
durable lifecycle notices receive the actual checkpoint sources.

| Verification | Result |
|---|---|
| Final recovery acceptance, including continued normal delivery and reverse seller receipt | 47 tests, 5,119 assertions; passing |
| All frontend checks, including the recovery worklist and scoped manifest links | 80 tests; passing |
| Full application regression before the final manifest-link refinement | 3,097 tests, 65,663 assertions; zero failures, errors or skips |
| Final TypeScript/Vite production build through Docker | Passing; Vite 10.87 seconds |
| Final recovery and hub workspace retest after the manifest-link refinement | 65 tests, 5,464 assertions; passing |
| Changed PHP formatting and whitespace | Passing |

The full regression includes the unchanged 69-case manifest custody suite,
14 reverse-custody cases and 14 admin exception-oversight cases. The final
manifest-link refinement affects only recovery presentation and is covered by
the subsequent 47 recovery and 18 hub workspace cases, all 80 frontend checks
and the final production build.

The recovery acceptance exercises correct-hub extra intake, wrong Bayan/Mother
receipt, required Mother detours, actual Mother-to-Mother line-haul and hub
subdomains. It rejects unknown or foreign scope, unauthorized floor actions,
restricted handlers, changed routes, stale reviews/scans, changed retries,
duplicate active recovery and duplicate/skipped transfer legs. Failed initial
and final receiving evidence rolls custody, manifest projections and case history
back together; a repeated successful receipt retains one result. Model and
direct-database checks retain case/event identity and earlier manifest guards.
Unused migration rollback/reinstall retains those guards; used history prevents
rollback. Admin support and public tracking privacy are checked independently.

**Scoped engineering assessment:** physical unexpected-parcel recovery
**0/10 -> 8/10**; hub recovery guidance **2/10 -> 8/10**. Missing physical handling
now has evidenced scans and transport, while handlers can see the responsible
next step and its blockers. These scores cover this feature and its isolated
acceptance, without raising unrelated overall role/project scores.

Release requires the additive migration
[`2026_10_10_200000_create_parcel_recovery_cases`](../database/migrations/2026_10_10_200000_create_parcel_recovery_cases.php).
After user publication and pull, `./bagoo.sh deploy` applies migrations and builds
assets, then `./bagoo.sh verify` checks assets and pending migrations. Keep existing
records; a database reset or demo reseed is unnecessary. This migration preserves
earlier manifest history and refuses rollback after any recovery case is recorded.
Production PostgreSQL trigger/locking execution, simultaneous requests, deployed
operations and rendered browser/device interactions remain unverified. All PHP
checks use isolated SQLite `:memory:`; development/production databases were not
migrated or reset.

**Next boundary:** stop for user review/publication of this recovery branch.
Remaining restricted seller/resource/carrier recovery needs its own bounded
authority and handover contract. A later Phase 6 logistics workstation cleanup
can improve canonical stage labels, responsibility and next-action guidance using
the existing screens, including replacing fixed success claims with recorded
outcomes. General redesign, rates, refunds and native mobile work
require separate selection.

Local implementation commits:

| Commit | Subject |
|---|---|
| `8c735b3` | `docs(logistics): define unexpected parcel recovery scope` |
| `60ef654` | `feat(logistics): recover misplaced parcels through recorded custody` |
| `4614210` | `feat(logistics): guide hub recovery with scoped history` |

This acceptance evidence is retained in a separate documentation commit.
Publication, merge and deployment remain user-owned release steps.

## October 10, 2026: parcel recovery review

The follow-up review compared the recovery plan with its services, migration,
hub interfaces and acceptance checks. It found three operational guidance issues:

| Finding | Before review fixes | After review fixes |
|---|---|---|
| Refreshed submission authority | An unchanged case could keep an expired manifest creation token after another manifest was prepared in the same session. Source tokens were also tied to the case reset. | Refreshed server tokens update independently, preserving entered notes, waybill and vehicle choice when the case is unchanged. |
| Manifest discrepancy links | A sending handler could see a recovery link while lacking permission to open that observation, resulting in a 404. | Links use the viewer's actual recovery scope. Assigned receivers and handlers on an authorized recovery route retain their supported links. |
| Receiving-hub attention | Counts followed only the original observed facility, hiding incoming recovery transfers and held parcels at an intermediate Mother Hub. | Counts include dispatched recovery arrivals and actual held recovery custody in the selected owned facility. The original observation remains visible until its separate review is resolved. |

Two added acceptance cases failed against the previous implementation and passed
after the fixes: scoped discrepancy links and actual two-leg recovery attention.
The attention check covers arrival, intermediate receipt, completed recovery and
the original facility's remaining discrepancy review. The token update follows
the existing manifest form pattern; rendered refresh interaction was not tested.

| Review verification | Result |
|---|---|
| Recovery, manifest custody, hub workspace, reverse custody and admin exception oversight | 164 tests, 12,032 assertions; zero failures, errors or skips |
| Recovery cases included above | 49 tests, 5,432 assertions; passing |
| All frontend checks | 80 tests; passing |
| TypeScript/Vite production build through Docker | Passing; Vite 11.10 seconds |
| Changed PHP formatting and whitespace | Passing |

All PHP checks used isolated SQLite `:memory:`. The earlier 3,097-test full
regression remains evidence for the initial implementation; this review reran
the affected suites after its presentation changes. No business transition,
custody writer, migration or financial rule changed during these fixes.

**Scoped engineering assessment:** recovery guidance **7/10 -> 8/10** after
accounting for the newly found issues. Overall physical unexpected-parcel
recovery remains **8/10**, compared with **0/10** before this branch. The fixes
make existing recovery work visible and its permitted actions usable; they do
not verify production PostgreSQL triggers, simultaneous requests, deployed
operations or browser/device interactions. Restricted-resource recovery and
unknown parcels retain the earlier stopping boundary. Deployment still requires
the additive recovery migration, without a database reset.

Review implementation commits:

| Commit | Subject |
|---|---|
| `856a466` | `fix(logistics): scope recovery links and receiving attention` |
| `fc147f1` | `fix(logistics): refresh recovery submission tokens` |

This review evidence is retained in a separate local documentation commit.
Publication, merge and deployment remain user-owned steps.

## October 11, 2026: logistics workstation guidance

The selected [workstation plan](logistics-plan/02-workstation-guidance.md) is
locally implemented on `feat/logistics-workstation-cleanup`, starting from the
merged parcel recovery delivery. This is a bounded Phase 6 interface and query
cleanup; it does not complete all cross-role cleanup or introduce new custody,
commercial, stock, cash, claim-code or settlement transitions.

| Area | Before this batch | Current behavior |
|---|---|---|
| Parcel state and holder | Registry rows used raw stage words and an assigned-rider column without a recorded-custody summary. | Physical stage, actual commercial order status, recorded custodian and next responsible actor appear separately. Assignment retains hub custody until the departure scan. |
| Operational guidance | Sorting/assignment controls followed status alone; many other parcels said "No action". | Guidance follows retained custody and existing services. Intake, Mother-Hub sorting, manifests, destination sorting, assignment, counter staging, retry review, seller receipt and parcel recovery expose their supported step or blocker. Company management does not gain handler scans. |
| Registry groups | "In hub" and "Self-pickup" submitted group names as individual statuses, hiding their members. | Groups and counts share the same owned-facility query. Recorded hub custody includes the latest authoritative checkpoint; the counter group also requires this facility to be the recorded destination. Search narrows results while queue totals retain their stated scope. |
| Outcome figures | The registry claimed "100% Fulfilled" and the counter claimed "100% Verified" regardless of their records. | The registry counts distinct parcels with recorded outgoing handovers during the Asia/Manila day. The counter distinguishes matching parcels ready for verification from blocked/staging work and displays up to five evidence-backed recent collections. No success percentage is invented. |
| Counter safety and clarity | Arriving parcels were described as ready; absent shelf data received a sample shelf label. | Actual staging, claim, held custody, expiry and verification locks govern readiness. Missing shelf data says not staged. Original incoming manifest receiving and closure appear before another handover. Client deadline checks narrow readiness and release controls without changing stored claims. |

Implementation uses
[HubParcelWorkstationService](../app/Services/Logistics/HubParcelWorkstationService.php),
the latest authoritative checkpoint relation on
[Delivery](../app/Models/Delivery.php), and a read-only readiness method reusing
the existing assertions in
[PickupClaimService](../app/Services/Logistics/PickupClaimService.php).
The existing claim mutation's identity checks retain the same conditions.
Controllers coordinate the scoped reads; every submitted sort, assignment,
scan, handover or review still passes its existing server validation and locks.

Missing or conflicting custody/commercial state has no unsupported mutation
control. Unavailable candidates and restricted assigned riders require review;
guidance never reallocates an assignment or transfers custody. Receivers finish
the actual incoming manifest before the next handover. A read cannot expire a
claim, record a collection, change money or resolve a recovery observation.
An already completed recovery resumes the original manifest work instead of
offering a redundant recovery transfer. Unknown/unsupported records retain
their explicit review boundary.

The [registry](../resources/js/Pages/Hub/Deliveries.tsx) and
[counter](../resources/js/Pages/Hub/CounterPickup.tsx) reuse
[parcel guidance components](../resources/js/Components/HubParcelGuidance.tsx)
inside their existing logistics shell. Existing typography, accent, local
geometry, responsive grids, table scrolling and forms remain in place.
Actions and filters preserve the selected facility and work on the root hub
portal and hub subdomain. Recently collected history evaluates the latest 25
scoped collection candidates and displays at most five with the existing full
collection-evidence check; it is a bounded preview rather than a total count.

| Verification | Evidence |
|---|---|
| Workstation, hub suite, secure pickup, parcel recovery, manifests, delivery attempts and returns | 218 tests, 16,842 assertions; passing on isolated SQLite `:memory:` |
| Final counter-scope review after the last query refinement | All 23 workstation cases, 2,007 assertions; passing. This overlaps the broader run and adds the stale-location acceptance case. |
| All frontend checks | 87 tests; passing, including seven server-rendered workstation cases and deadline/readiness controls |
| TypeScript/Vite production build through Docker | Passing; Vite 15.15 seconds |
| Changed PHP formatting and whitespace | Passing |

Acceptance uses real flow writers for pickup, hub/manifest custody, sorting,
assignment, restricted-rider review, pickup staging, failed code verification,
collection, buyer completion and recovery. Deliberately invalid legacy fixtures
prove missing/conflicting evidence cannot gain an action or a counter group.
Root/subdomain scoping, empty/search-filtered queues, retained custody after
assignment, Manila-day handover counts, natural expiry without read-side writes,
and completed recovery guidance are covered. No development/production database
was migrated or reset.

**Scoped engineering assessment:** logistics workstation guidance and truthful
queue presentation **5/10 -> 8/10**. Operators can identify who holds a parcel,
who must act next and what blocks that action, using current source records.
The broader physical recovery assessment remains **8/10**; this presentation
work does not raise overall project readiness. Production PostgreSQL execution,
deployment, rendered browser/device layout and live timer interactions remain
unverified. This batch adds no schema; an older deployment still needs the
already documented additive parcel-recovery migration.

**Next boundary:** stop for user review/publication and deployment verification.
Phase 6 remains partial. Broader portal cleanup and unsupported restricted
seller/resource/carrier recovery require a separate selection; this branch does
not authorize rates, refunds, native mobile work or a general redesign.

Local implementation commits:

| Commit | Subject |
|---|---|
| `34ffb96` | `docs(logistics): define workstation guidance acceptance` |
| `bdcbf9a` | `feat(logistics): derive hub work from recorded parcel custody` |
| `3c326ed` | `feat(logistics): show parcel guidance and truthful workstation counts` |
| `4407a7a` | `fix(logistics): keep counter groups within the destination facility` |

This evidence is retained in a separate local documentation commit, with its
generated hash reported in the handoff.

## October 11, 2026: workstation reliability and security review

Review of `feat/logistics-workstation-cleanup` found two reproducible reliability
defects in the implemented counter interface. Both are corrected without a new
schema or a change to role, custody, claim, COD or order-transition authority.

| Finding | Before correction | Verified behavior |
|---|---|---|
| Counter clock after a preserved page refresh | A claim expiring in 60 seconds could schedule its display update 7,260,001 milliseconds later because the effect used the earlier mount clock. | The effect schedules against the current clock: 60,001 milliseconds in the same case. It skips elapsed lock/expiry boundaries and schedules the earliest remaining deadline. Existing submit and server expiry checks remain authoritative. |
| Recent collection ordering | A newer parcel ID appeared before an older parcel collected more recently. | Candidates are ordered by the original claim's recorded `consumed_at`, with parcel ID only breaking ties. The 25-candidate/five-result limit, company/facility scope and full collection-evidence filter remain. Both root and hub-subdomain real collection flows pass. |

The production counter effect is exercised with controlled hook/clock callbacks
in [counter clock tests](../tests/Frontend/hub-counter-clock.test.mjs), rather
than a duplicated timer calculation. Actual buyer code access, handler release,
retained custody and COD writers establish the two-parcel ordering cases in
[workstation tests](../tests/Feature/Logistics/HubParcelWorkstationTest.php).
These checks do not constitute rendered-browser or background-tab testing.

Fresh verification: the initial affected-flow selection passed 55 tests and
3,643 assertions. After the history correction, all 57 workstation, hub-suite
and secure-pickup cases passed with 3,971 assertions on isolated SQLite
`:memory:`. All 91 frontend cases passed, including four new clock cases. The
Docker TypeScript/Vite production build passed in 10.17 seconds; changed PHP
formatting and whitespace passed. The before/after selections overlap and are
not additional full-project regression totals.

Server authorization, actual-facility scope, restricted/unavailable riders,
private owner-only claim access, failed-code lockouts, expiry during verification,
exact COD evidence, rollback and buyer-only completion retain passing affected
checks. Search/status values remain bound query inputs and the new guidance is
rendered as text. No new access-control or claim-consumption bypass was
reproduced within this review. Azure configuration, PostgreSQL concurrency and
whole-website penetration testing were not performed.

The current locked PHP advisory audit reports zero advisories and abandoned
packages. The production-only npm audit reports zero known advisories. The full
npm audit still reports five high entries through `braces` 3.0.3 and its
development dependency chain, matching the already documented October 10
dependency review. The [upstream advisory](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)
describes recursive-pattern process failure and lists no published patch. The
observed Tailwind content patterns are repository-controlled; this review did
not identify a public request supplying these patterns. These are advisory
snapshots, not proof that every application or deployment risk is absent.
Dependency maintenance should revisit a compatible fix when available.

**Scoped rating:** workstation guidance remains **5/10 before the cleanup ->
8/10 after implementation and this review**. The two corrections improve live
readiness feedback and trustworthy collection history. Overall role, security
and cross-role scores are not increased; Phase 6 remains partial.

Necessary next selections, in order:

1. **Scanner guidance consistency.** The existing scanner still supplies
   `STAGE: SELF-PICKUP-SHELF` when no shelf/bin is recorded, and displays that
   fallback as a location. Reuse actual custody, staging and blocker guidance
   when moving from the registry/counter into the scanner. Preserve handler
   authority and the existing logistics style.
2. **Counter queue capacity.** The counter still loads every matching parcel
   through `get()` and performs per-parcel readiness queries. Add bounded
   pagination and review query growth while preserving scoped search, truthful
   queue totals and original collection-evidence checks. This is a source-backed
   capacity gap; production load/latency has not been measured.
3. **Remaining Phase 6 tracking consistency.** Review buyer, seller, rider and
   hub views against the same commercial state and retained physical journey,
   then repair confirmed differences in each portal's own design. Separate this
   from new rates, disputes/refunds or other deferred features.

Local correction commits: `4ec6110 fix(logistics): refresh counter deadlines from
the current clock`; `0bc7bfc fix(logistics): order recent collections by recorded
handover time`. This review evidence is retained in a separate documentation
commit, with its generated hash reported in the handoff. Publication, merge and
deployment remain user-owned steps.

## October 11, 2026: scanner guidance and bounded counter queues

The selected [scanner/counter batch](logistics-plan/03-scanner-and-counter-queue.md)
is implemented on `feat/logistics-scanner-counter-queue`, based on the refreshed
`main` containing the preceding workstation cleanup. It completes the scanner
and queue-capacity selections above while keeping their operational services
and the logistics portal's existing style.

| Area | Before | Verified result |
|---|---|---|
| Shelf information | The scanner supplied `SELF-PICKUP-SHELF` as a fallback; routing also stored it before actual staging. | New self-pickup routes leave location metadata empty. The known legacy placeholder reads as missing metadata without rewriting the parcel. Real stored bin names survive; missing shelves say "Shelf not recorded". Actual staging and custody remain separate evidence. |
| Scanner guidance | Scanner results and its counter list did not share the registry's custody, next actor or blockers. | Shared guidance shows physical stage, commercial state, actual custodian and supported next work. Unsupported confirmation is suppressed; enabled/disabled counters, locked/expired claims and wrong-facility records retain truthful handling. |
| Counter handover | A duplicate scanner form asked only for name/notes and could not satisfy secure collection requirements. | Scanner results and counter previews open the existing secure counter with the selected hub and parcel search. Buyer identity, private claim code, actual waybill and exact COD remain mandatory server checks. |
| Queue capacity | Every matching counter parcel was loaded and its staging/custody evidence queried individually. | The counter loads 15 parcels in deterministic ID order. Original staging and latest custody are eager-loaded in two batches; a 16-to-22-parcel growth check adds no request queries. The scanner preview remains bounded to 20 parcels and displays the full matching total. |
| Count scope and navigation | Readiness represented the entire unbounded result list. | Full totals include all matches; readiness and attention are explicitly for the displayed page. Page links retain hub, search and portal path; empty/out-of-range pages stay truthful and offer navigation. Current expiry feedback and stale-form checks remain. |
| Lookup and history | Phone lookup existed only inside the limited scanner preview. | Tracking, order, buyer-name and phone searches reach the full scoped queue on root/subdomain routes. Actual collection-time ordering and complete collection-evidence checks remain. |

The scanner's **Self-Pickup Staged** count uses retained staging/custody at the
selected destination facility. It is a physical queue count: a locked or
naturally expired claim can remain physically staged while collection readiness
is false. Reading either workstation does not expire a claim, start a return,
consume a code, move custody or collect cash. Eager-loaded read evidence is
never substituted for the existing freshly queried mutation checks.

Verification evidence:

- All **396 logistics feature cases passed with 28,130 assertions**, covering
  custody, attempts, registry/counter, hub scope, sorting, manifests, parcel and
  restricted-custody recovery, returns and secure pickup on isolated SQLite
  `:memory:`. Two subsequently added root/subdomain phone-search cases passed
  with 358 assertions, including wrong-facility exclusion and read-only checks.
  These are **398 distinct cases and 28,488 assertions** across the two runs;
  earlier overlapping focused runs are not added to this total.
- All **100 frontend checks passed**. Actual scanner/preview components are
  server-rendered with controlled state to verify missing/recorded shelves,
  blockers, secure-counter links and retained phone lookup. Counter checks
  cover page scope, navigation, empty states and current deadline behavior.
- The final **TypeScript/Vite production build passed** (Vite: 11.88 seconds).
  Changed PHP formatting and whitespace checks passed. Documentation references
  and the local branch publication boundary are checked at handoff.

**Scoped engineering rating: scanner/counter queue handling 6/10 before ->
8.5/10 after.** Staff receive consistent guidance and secure handover navigation,
and queue growth no longer expands the loaded parcel/readiness work beyond a
page. This assessment applies to these views; overall role/security scores and
full Phase 6 readiness are unchanged.

Remaining limits: this batch verifies local source and isolated tests. Azure
deployment, PostgreSQL execution/concurrency, rendered-browser/device behavior
and production-size latency are unverified. Query-count bounding does not make
database count/search latency constant. Remaining Phase 6 cross-role tracking
consistency is the next separate selection; rates, disputes/refunds and native
mobile work retain their existing boundaries. No schema or database reset is
required by this branch.

Local implementation commits:

| Commit | Subject |
|---|---|
| `15a88ea` | `docs(logistics): define scanner and counter queue acceptance` |
| `e2a4142` | `feat(logistics): paginate counter work with bounded readiness reads` |
| `59787ce` | `fix(logistics): ground scanner guidance in actual parcel records` |
| `a1ab734` | `fix(logistics): preserve buyer phone search across counter views` |

This evidence is retained in a separate documentation commit; its generated
hash is reported in the handoff. Publication, merge and deployment remain
user-owned steps.

## October 11, 2026: consistent recorded order tracking

The selected [tracking consistency batch](ORDER_TRACKING_CONSISTENCY.md) is
implemented on `feat/order-tracking-consistency`, based on `89ffb2c`. It aligns
the buyer, seller, rider web and hub read views with the recorded commercial
state and physical journey. The scoped acceptance and regression evidence is
recorded below; publication and deployment remain separate user-owned steps.

| Area | Before this batch | Implemented behavior |
|---|---|---|
| Order labels | Separate buyer, seller and public mappings described the same order differently. Buyer preparation could look finished; the seller badge described Preparing as Packed / Ready to Ship. | A shared server vocabulary keeps seller preparation, pickup readiness, delivery and buyer receipt confirmation distinct. Known legacy aliases normalize for display without updating stored state. Unknown states require review. |
| Recorded journey | Buyer detail inferred a five-step bar, omitted the hub journey and labelled counter collection as a doorstep handover. Seller history displayed internal checkpoint codes. | Buyer and seller details show original chronological timestamp/ID checkpoints, readable Bayan-Hub and Mother-Hub transfers, actual recorded custody, the next responsible actor and a specific next step. Mirrors cannot replace or duplicate original events. |
| Assignment and custody | Assigned rider information could be mistaken for physical collection. | Only an original retained custody checkpoint establishes the displayed holder. Hub assignment remains hub custody until departure. A rider handling both legs in the same municipality is labelled by the actual handover phase; that journey still visits a Mother Hub. Missing or conflicting records show a review notice instead of invented progress. |
| Failures and returns | Failed, returned and cancelled orders could restart normal preparation guidance. | The latest actual failed attempt supplies its reason and retained retry date. A previous attempt's approval cannot schedule a new failure. Hub receipt, scheduled retry, released retry, reverse transit, seller staging and seller receipt have separate explanations and preserved history. |
| Secure counter collection | Code availability was not explicit in the buyer page and claim timing could become stale. The collected-state message used an unsupported state name. | Staging, expiry, verification lock, one-time issuance, actual collection and buyer confirmation are separate. The page rechecks expiry/unlock from the current clock and hides unavailable code controls. Code issuance also rejects an active verification lock under the existing server transaction. Reads do not expire a claim, start a return or consume a code. |
| Refresh and stale actions | Seller dialogs retained copied order objects after the page changed. Users had no consistent explicit tracking refresh. | Buyer lists/detail, restricted order history, seller workspace, rider dashboard and public tracker provide Refresh tracking. Detail views record when data was observed. Seller dialogs resolve the current row and close when it leaves the page or loses its action. Rider outcomes recheck the current task. Buyer detail refreshes portal access, receipt and code actions together and prevents duplicate refreshes. |
| Privacy and authority | Seller detail serialized raw checkpoint records; public status inference could assume progress for unknown records. | Owned tracking uses a display-only projection without raw private proof, attempt or checkpoint internals. Public JSON retains its existing masked schema, coarse milestone locations and empty action list. Rider/hub operational guidance and all existing mutation authorities remain role-owned server checks. |

Verification evidence:

- The final regression coverage includes **34 tracking acceptance cases** and
  both same-municipality rider phase cases. They exercise actual seller preparation, pickup,
  Mother-Hub transfers, delivery, failure, retry, reverse route, seller receipt,
  cancellation and buyer-only completion. Explicit read fixtures cover known
  legacy aliases, missing/conflicting records and mirrored/backdated history.
- The final same-municipality phase checks passed: **2 cases, 106 assertions**,
  using actual checkout and handover actions, the same assigned rider and a
  required Mother-Hub visit. These cases reproduce the earlier incorrect
  pickup label after final-mile departure and verify its correction.
- All **119 frontend checks passed**, including actual page/component renders,
  refreshed seller selections, current pickup claim windows, masked public
  labels, rider action checks and invocation of the buyer refresh handler.
  That handler check verifies updated access/actions and duplicate-click
  prevention. Server rendering is not browser or device testing.
- The final **TypeScript/Vite production build passed** (Vite: 19.50 seconds).
  Changed PHP formatting passed for all 17 files, and whitespace checks passed.
- The final broader isolated SQLite `:memory:` coverage verifies **1,726
  distinct cases with 63,722 assertions**, across the buyer, seller, courier,
  logistics, finance, cross-role flow, end-to-end and public tracking feature
  directories. This is combined evidence from the broad run and the complete
  affected recovery rerun, with no remaining failures, errors or skipped cases.
  The broader run passed all 1,677 cases outside `ParcelRecoveryTest` and found
  two assertions incorrectly requiring separate requests' observation times to
  be identical. Their parcel facts agreed; each timestamp differed by one
  second. The comparison now checks every shared tracking fact and validates
  each observation against its own request interval. The whole affected class
  then passed: **49 cases, 6,328 assertions**. Production code was unchanged
  between those runs. Recovery results replace the corresponding broad-run
  cases rather than being counted twice; this is not a claim of a single green
  full-project suite. Earlier overlapping or interrupted runs are not added.

The initial focused baseline had one existing cross-role assertion mismatch:
manifest guidance retained the selected facility as `/hub/manifests?hub_id=…`,
while the assertion expected an unscoped URL. The corrected expectation retains
the actual facility; no production guidance or authorization was weakened.

The scoped assessment is **order-tracking clarity 6.5/10 before ->
8.5/10 after**. The previous normal-progress
inference could mislead users; the implemented views distinguish order state,
parcel stage, recorded holder, exceptions and the next responsible action.
Overall role/security scores and full Phase 6 readiness are not increased.

Remaining limits: Azure deployment, PostgreSQL execution/concurrency,
rendered-browser/device behavior and production-scale latency are unverified.
Refresh gives a new recorded observation, not a continuously live feed.
This batch does not add rates, disputes/refunds or native Flutter features;
their existing delivery boundaries remain. No schema change or database reset
is required.

Local implementation commits:

| Commit | Subject |
|---|---|
| `f22faaa` | `docs(orders): define tracking consistency acceptance` |
| `1219468` | `fix(orders): unify recorded tracking across web portals` |
| `5533825` | `fix(logistics): block pickup code issuance during verification lock` |
| `28d0e62` | `test(flows): preserve selected hub in manifest guidance` |
| `c260b27` | `fix(orders): show truthful journeys and refresh current actions` |
| `b309ec0` | `test(orders): validate observation times per tracking request` |

The final evidence is retained in a separate documentation commit; its generated
hash is reported in the handoff. Publication, merge and deployment remain
user-owned steps.

### October 11, 2026: buyer profile navigation and separate exception filters

The buyer profile now keeps its existing marketplace styling while separating
purchase navigation from the content being read. This delivery changes profile
presentation and scrolling; the existing order and account authorities remain
the server-owned source of truth.

| Area | Before | Implemented behavior |
|---|---|---|
| Purchase filters | Eight status buttons shared a horizontally scrolling strip, pushing later choices out of view. | All, To ship, In transit, Delivered and Completed remain visible in a responsive button grid. The follow-up below replaces the initial exception dropdown with Order issues directly below My Purchases & Orders in the sidebar, retaining actual counts and the current selection. |
| Desktop purchases | Reading a long order list moved the profile navigation and filters with the page. | The two-column workspace uses the available viewport height. Its sidebar and purchase controls stay outside the independently scrolling order results, including empty states, errors and pagination. |
| Account and Security | Long settings forms moved the sidebar out of view. | Contact, identity and password settings share a focusable content scroll region beside the stationary sidebar. Other existing profile sections also retain bounded content and their card padding. |
| Footer and responsive access | Page scrolling was the only way to navigate a long workspace. | On desktop, wheel input over stationary controls or empty panels cannot spill into the outer page; overflowing panels use contained native scrolling. Scrolling outside the workspace still reaches the normal footer. Below 1024px, the profile retains ordinary stacked page scrolling. Short desktop windows allow the sidebar to scroll internally so lower links remain reachable. |
| Refresh and navigation | Existing filters, counts, receipt actions and partial reloads supplied the order workspace. | The same server-filtered requests, counts and action guards remain in use. Selecting a different status or page resets the results region to its top; a same-page refresh preserves the reading position through Inertia scroll-region handling. |

Verification evidence:

- **37 frontend checks passed**: nine new profile checks plus the existing
  commerce and account settings suites. They exercise the actual rendered
  profile/filter components, direct exception selections, disabled controls,
  request handlers, paging/refresh parameters, scroll-region placement,
  desktop-only CSS scope, native wheel boundaries, viewport/header resize
  measurement and listener cleanup.
- **36 backend regression cases passed, with 443 assertions**, for buyer order
  workspaces, addresses, avatars and profile settings, using isolated SQLite
  `:memory:` inside the local application container.
- The **TypeScript/Vite production build passed through Docker**; Vite finished
  in 14.82 seconds. The buyer profile manifest includes its dedicated stylesheet,
  and the generated asset contains the bounded workspace and scroll-containment
  rules. Whitespace and frontend syntax checks passed.
- Host-only checks encountered a Vite cache permission error and a missing PHP
  GD extension. The corresponding Docker build and complete selected PHP
  regression rerun passed. These environment errors are not counted as passing
  host checks.

The scoped assessment is **buyer profile navigation 6.5/10 before -> 8.5/10
after**. Purchase progress and exception choices are easier to find, and long
orders/settings retain their navigation context. This does not raise overall
buyer transaction, security or flow readiness ratings. Browser/device wheel,
touch and keyboard behavior and Azure deployment remain unverified; the
frontend evidence uses server rendering and controlled DOM/event checks.

Local implementation commits:

| Commit | Subject |
|---|---|
| `6a55b3f` | `fix(buyer): separate exception filters from purchase progress` |
| `ae33224` | `fix(buyer): keep profile navigation beside scrolling content` |

### October 11, 2026: centered buyer empty panels and Order issues navigation

The buyer profile's unavailable wallet notice now sits in the center of its
panel. Empty purchase results use the remaining results area, and an empty
address book shows a centered message below its title and Add New Address
button. The existing buyer colors, typography, card styling and desktop scroll
regions remain in use. Smaller screens retain stacked page scrolling and enough
space for each message; short panels can still scroll to reach their content.

Order issues is an indented sidebar button directly below My Purchases & Orders.
It opens the existing delivery issue filter, with Returned and Cancelled beside
it as visible buttons. Direct links to any of these three statuses select the
matching button and sidebar entry. Returning to the main purchase group opens
All; All still includes the buyer's complete order history. The issue badge sums
the three recorded counts. Existing server filtering, pagination, refresh and
receipt guards are retained, and switching groups resets pagination.

Verification: **39 frontend checks passed**, comprising 11 profile checks and
28 existing commerce/account settings checks. Coverage includes sidebar order,
actual counts, direct exception selections, disabled controls, requests from
other profile tabs, page reset and refresh parameters. The **TypeScript/Vite
production build passed through Docker**, with Vite finishing in 14.66 seconds.
Whitespace checks passed. This follow-up changes frontend presentation only;
the earlier backend regression results above were not rerun or counted as new
evidence. Browser/device layout and Azure deployment remain unverified.

Scoped assessment: **buyer empty-panel clarity 6/10 before -> 8.5/10 after**;
**purchase issue navigation 7/10 before -> 8.5/10 after**. The empty messages
occupy their panels more clearly, and exception orders have a named sidebar
destination. These ratings do not certify wallet functionality or raise overall
buyer transaction readiness.

Local implementation commits:

| Commit | Subject |
|---|---|
| `e54a64b` | `fix(buyer): move order issues into profile navigation` |
| `21a6f4e` | `fix(buyer): center empty profile panel messages` |

### October 11, 2026: active purchase submenu

Order issues is visible whenever the purchases section is active, including
the first/default visit and every progress or issue filter. This supersedes the
initial click-to-toggle behavior: the submenu now follows the selected section
without separate expansion state. A subtle chevron reflects visibility, and a
light vertical line marks Order issues as a nested destination. Account,
addresses, wallet and vouchers hide the submenu. Native buttons expose
`aria-expanded`/`aria-controls`, and the hidden container keeps its child out of
keyboard navigation. Existing header destinations remain explicit: My Orders
opens purchases, while Profile & Settings opens Account & Security.

Verification: **40 frontend checks passed**, including immediate expansion
across all five progress filters and three issue filters, hiding in all four
other profile sections, disabled navigation and existing commerce/account
settings cases. The **TypeScript/Vite production build and whitespace checks
passed**. Browser/device interaction remains unverified.
Scoped assessment: **purchase navigation hierarchy 7.5/10 before -> 8.5/10
after**; the relationship is clearer and follows the current purchase context.
The follow-up activation assessment is **8/10 before -> 8.5/10 after**: a first
visit to purchases no longer needs an extra click to reveal Order issues.
This does not change transaction readiness or the empty-panel rating above.

Initial disclosure implementation: `ac2f332 fix(buyer): collapse nested purchase navigation`.

Active-section implementation: `2345e14 fix(buyer): expand submenu for active purchases`.

### October 11, 2026: voucher experience audit and proposed deliveries

The [voucher experience plan](voucher-plan/README.md) is a researched proposal,
not implemented campaign management or authorization to execute every batch.
It defines V01 accurate quotes, V02 redemption and funding records, V03 seller
and Platform Admin campaigns, and V04 buyer saving/selection, followed by a
combined acceptance gate. Official public references and their regional
limits are recorded in [the source notes](voucher-plan/REFERENCES.md).

Static findings at the start of the voucher plan:

- [Buyer profile](../resources/js/Pages/Buyer/Profile.tsx) displays two
  hardcoded offers with a fixed “3 Available” badge. These do not establish
  actual buyer ownership, expiry, redemption eligibility or quota.
- [Checkout](../resources/js/Pages/Checkout/Index.tsx) provides code entry,
  computes discounts locally and posts one voucher_code. Its shipping
  offer preview zeroes the fee without applying the voucher's actual cap.
  The [checkout controller](../app/Http/Controllers/Buyer/CheckoutController.php)
  supplies active/unexpired offers without the final service's complete
  selected-shop eligibility, and its aggregate fee preview differs from
  final per-shop fees.
- [Buyer voucher apply](../app/Http/Controllers/Buyer/VoucherController.php)
  previews the entire Bag and validates amount without an explicit shop
  scope. Generic minimum-spend errors can hide another invalidity reason.
- [CheckoutOrderService](../app/Services/Orders/CheckoutOrderService.php)
  already locks voucher use inside its transaction, applies shop scope,
  allocates platform discounts and counts one use per successful checkout.
  Signed submissions preserve original-result replay. Existing platform
  allocation weights every discount kind by merchandise; a shipping
  discount needs weights and bounds based on eligible shipping instead.
- The [voucher schema](../database/migrations/2026_08_23_111910_create_vouchers_table.php)
  has nullable shop scope and global used_count, but no saved offers,
  per-buyer redemption history, campaign revision or funding budget.
  Its null-on-delete shop link makes an immutable issuer essential before
  introducing broader campaign discovery.
- [Seller campaign writes](../app/Http/Controllers/Seller/SellerVoucherController.php)
  use approved owned-shop mutation gates and basic creation/toggle/delete.
  They lack an upper percentage bound, scheduled start, per-buyer limit,
  funded budget and immutable published terms. Code uniqueness is checked
  before uppercase normalization. The
  [seller screen](../resources/js/Pages/Seller/Vouchers.tsx) aggregates the
  current page, calls uses claims and displays a static shipping offer.
  No dedicated Platform Admin voucher writer was found in the inspected
  routes/controllers.
- [Seller settlement](../app/Services/Finance/SellerSettlementService.php)
  preserves original-product 10%/90% gross accounting and records aggregate
  discounts separately. It does not establish an explicit issuer-funded
  promotional expense. Stacking requires reviewed funding and net-payout
  records, not a cosmetic second selector.
- [Seller cancellation](../app/Services/Orders/OrderLifecycleService.php)
  restores stock before custody but does not restore voucher use. The
  proposal preserves that behavior and sibling allocations; any future
  unspent funding release needs its own exact-once recorded boundary.

Recommended first release: one fixed/capped product offer per selected shop
plus one Bagoo product OR shipping offer per checkout. Buyers save and choose
real offers, with optional code entry and an explicit No voucher choice.
Saving reserves neither quota nor funding. Preserve original gross commission,
separate promotional expense from shipping, and keep all old order/settlement
snapshots. Rich targeting, cashback, wallet, quota-restoration and refund
automation remain deferred.

Planning assessment from static evidence: **buyer voucher experience 4/10**,
**seller voucher management 5/10**. These scores cover truthful offer discovery,
preview consistency and campaign controls, not overall buyer/seller readiness.
Planning does not improve those implementation scores. The proposed
**8.5/10 target** applies only after the scoped acceptance cases pass.

Verification for this planning delivery: relevant code/contracts and official
sources inspected; documentation links and whitespace checked. The existing
**12 buyer-profile frontend checks passed**, including immediate submenu
expansion for active purchases and hiding in other sections. No application
code or schema changed; no new voucher runtime checks, production build,
browser/device check or deployment is claimed.

### October 11, 2026: V01 honest offers and accurate purchase quotes

Implemented [V01](voucher-plan/01-quotes-and-honest-offers.md) on
`fix/voucher-quote-consistency`. The first batch preserves one optional voucher
per checkout and uses the existing checkout, Bag and buyer-profile routes.

Before this change, two PHP 800 purchases from different shops could preview
free shipping on their combined subtotal even though final checkout charged
PHP 50 per shop. A capped shipping offer could also appear to waive the whole
fee. The shared [CheckoutQuoteService](../app/Services/Orders/CheckoutQuoteService.php)
now supplies current selected-item prices, fees per shop, eligibility feedback
and exact cent allocations to the Bag, preview and locked final checkout.
That example consistently totals PHP 1,700. A PHP 30 shipping discount against
PHP 100 in fees leaves PHP 70 payable; naturally free parcels receive none of
that discount. Shop minimum spend and discounts use only that shop's selected
items. Platform product discounts use merchandise weights; shipping discounts
use actual eligible fees, with deterministic remainder handling and caps.

The [buyer offer list](../resources/js/Components/VoucherOfferList.tsx) shows
real terms, issuer, minimum spend, cap and expiry. Public discovery excludes
paused, expired, exhausted and malformed offers, returns at most 24 cards,
and provides the actual count independently. The profile replaces its fixed
promotion cards and badge with available offers and a centered empty state.
Source inspection corrected one planning assumption: checkout had code entry
but no rendered selector. V01 adds basic selection from real offers while
keeping optional code entry and removal.

[Quote refresh handling](../resources/js/hooks/usePurchaseQuote.ts) invalidates
confirmation when items, quantities or delivery context change, cancels older
requests and prevents late responses from restoring stale totals. Invalid
vouchers are removed with the actual reason; failures leave totals unavailable.
Item rows use the quoted current prices. The buyer shell, active purchase
submenu and portal styling remain intact.

Verified on the final implementation:

- **370 isolated SQLite checks passed, 7,064 assertions**: the new
  [BuyerVoucherQuoteTest](../tests/Feature/Buyer/BuyerVoucherQuoteTest.php),
  CheckoutReplayTest, BuyerCheckoutTest, BuyerCartQuantityTest,
  BuyerCartSelectionTest, CommerceInputValidationTest, InventoryCheckoutTest,
  BuyerAccessAlignmentTest, BuyerCheckoutKycGateTest, SellerSettlementTest and
  Tier3 CrossFeatureCombinationsTest. Coverage includes foreign selections,
  current prices, shop minimums, invalid offers, shipping caps and zero-fee
  shops, self-pickup, exact allocation, persisted per-shop totals, signed replay,
  stock rollback and one usage increment per successful checkout. Settlement
  checks preserve original-product 10%/90% gross accounting.
- **43 frontend checks passed** across buyer-vouchers.test.mjs,
  buyer-profile.test.mjs and commerce.test.mjs. These include real/empty offers,
  item prices, capped totals, confirmation guards, controlled request races,
  unavailable responses and purchase-navigation regression checks.
- TypeScript/Vite production build, focused PHP style checks and whitespace
  checks passed. Docker handled existing host file-permission restrictions.
  No development database reset, browser/device check or deployment occurred.

Scoped engineering assessment: **V01 offer truthfulness and quote consistency
4/10 before -> 8/10 after**. Buyers can understand the amount due and the real
saving before placing an order. This does not establish the full voucher plan's
8.5/10 target or improve seller campaign management. Preview remains
informational; final checkout rechecks under its existing locks. SQLite tests
do not establish PostgreSQL concurrency behavior.

V02-V04 remain separate deliveries: saved offers, per-buyer redemptions,
immutable issuer/history, reviewed funding and budget records, stacking,
seller/Platform Admin campaign controls and the richer buyer picker. The legacy
nullable shop link and uncertain funding history remain V02 concerns; no
historical orders or settlements were rewritten.

Implementation commits: `e535acc docs: require readable commit descriptions`,
`8e8f834 fix(checkout): share accurate voucher quotes across purchases`, and
`1617c20 fix(buyer): show real offers and refreshed purchase totals`.

### October 11, 2026: seeded products in department browsing

The demo seeder already places its catalogue in an approved shop's active
accessory child category. The buyer discovery filter matched only the selected
category slug, so selecting the parent master department returned no fixture
products while products added directly to the parent could appear. The focused
regression reproduced **0 instead of 17 products** before the fix.

[BuyerDiscoveryService](../app/Services/Commerce/BuyerDiscoveryService.php) now
includes active descendants using the existing shop category traversal. Buyer
home, search, related suggestions and department counts use that scope. Shop
approval, product restrictions, inactive ancestors and normal stock filters
remain authoritative. No seed rows, credentials, prices, stock or order history
were changed, and a database reset is unnecessary. The read-only local check
returned **17 department-visible products**, with **0 direct-parent products**.

Verification: **53 isolated SQLite checks / 790 assertions passed** across the
seller demo seeder and buyer discovery/saved-product suites; focused PHP style
checks passed. Coverage includes fresh and repeat seed preservation, both buyer
pages, deeper descendants and exclusion under an inactive ancestor. No frontend
source changed. Azure deployment and browser/device checks are not claimed.
Scoped assessment: **department discovery consistency 5/10 -> 8.5/10**; seeded
and manually created products follow the same approved category tree. Existing
older shops without approval still need their documented review or reserved
fixture repair, as described in [deployment guidance](WEB_AUTH_DEPLOYMENT.md).

### October 11, 2026: V02 voucher redemptions, funding and stacking

Implemented [V02](voucher-plan/02-redemptions-and-funding.md) on
`feat/voucher-redemption-foundation`, after V01 was merged. Immutable campaign
revisions retain the explicit shop/platform issuer, payer, creator, period,
money terms, finite budget and global/per-buyer limits. Buyer bookmarks are
unique and removable without reserving funding or quota; new saving controls
remain V04 work.

[VoucherSelectionService](../app/Services/Orders/VoucherSelectionService.php)
validates one product offer per selected shop and one Bagoo product or shipping
offer. Sorted selections enter a versioned checkout fingerprint. Shop discounts
run first; platform product percentages use remaining merchandise, while
qualification uses original eligible merchandise without shipping. Integer
cents and deterministic allocation retain exact order totals, actual fee
reductions and each order/parcel's payer. Offer cards calculate their own saving
alongside the other selected slots.

Final checkout locks campaigns and balances in consistent ID order after its
existing owned Bag and product locks. Per-buyer uses are checked while holding
the same campaign balance lock. Orders, stock, redemptions, commitments, usage
counters and confirmation links persist atomically, backed by unique
constraints. Each campaign consumes one use per checkout, including multi-shop
allocations. Original results survive expiry, pause and exhaustion; altered
input stays rejected. Version-one confirmations retain their original result.

[VoucherFundingService](../app/Services/Vouchers/VoucherFundingService.php) records
append-only commitments, payment expenses and cancellation releases. Settlement
retains the original 10% commission and 90% gross seller share, with separate
promotional costs and a visible net payout. The documented example remains
PHP 1,000 products + PHP 50 shipping - PHP 100 shop - PHP 50 Bagoo = PHP 900 COD,
split into PHP 800 net seller, PHP 50 net platform and PHP 50 separate shipping.
Shop offers cannot create a negative seller payout; a funded platform subsidy
may exceed commission without inventing logistics income. Buyer confirmation,
original COD reconciliation, admin authority and private payment evidence remain
required. Expenses and payment evidence roll back together.

Authorized seller cancellation before custody releases only the cancelled
allocation's unspent commitment, once, without restoring use counters or
redistributing discounts to sibling orders. Claimed parcels, physical returns
and failed delivery do not authorize that release or a cash refund. Buyers see
this policy at checkout. Gross/net amounts also appear in settlement lists,
detail and financial oversight evidence, using existing portal styling.

The additive migration preserves original totals, legacy voucher references,
usage baselines and paid settlements. Existing campaigns become archived with
unknown funding; no buyer history or payer is fabricated. Old paid records
remain evidenced at their recorded amount; zero-discount authorizations can
still record payment. Unfunded historical discounts require review before a new
seller release. New seller offers are clearly drafts until V03 supplies reviewed
publication. Published/history records are archived instead of deleted.

Verification:

- **876 isolated SQLite memory tests / 20,805 assertions passed** across buyer,
  seller, finance, seeders and selected lifecycle/combination suites. Coverage
  includes budget and quota boundaries, ownership, stacking and cent allocation,
  first-use uniqueness, storage-failure rollback, expired replay, partial
  cancellation, expense rollback and legacy migration.
- **75 frontend checks passed** across buyer vouchers/profile, commerce,
  finance and order tracking. The nine finance checks were rerun successfully
  after adding net amounts to the read-only evidence view.
- PHP style checks, `git diff --check`, and the TypeScript/Vite production build
  passed. No browser/device or production concurrency tests were performed.
- Applied only the new migration to confirmed local PostgreSQL. Read-only
  before/after checks retained **9 verified demo accounts, 17 products and 3
  orders**, with matching original stock/prices, order amounts/references and
  usage fingerprints. There were no local settlement records to migrate;
  historical settlement preservation is established by isolated tests. No reset,
  reseeding, Azure migration or deployment occurred. This operational migration
  does not establish PostgreSQL concurrency behavior.

Scoped assessment: **V02 funding and redemption integrity 3/10 -> 8/10**.
Discounts now have accountable payers, exact allocations, finite spending/use
limits and evidenced net seller amounts. This does not rate the complete voucher
experience or the whole buyer/seller flows. V03 campaign publication, V04 saving
and richer selection, PostgreSQL concurrency checks and post-custody refund
policy remain separate work.

Implementation commits: `c427981 feat(vouchers): retain immutable campaign and funding records`,
`cbaa289 feat(checkout): allocate funded shop and platform vouchers`, and
`67fca1e feat(vouchers): show stacked savings and funded net proceeds`.

### October 11, 2026 — V03 campaign management verification

V03 now has seller-owned drafts and publication, scheduled starts, confirmed
pause/resume, retained campaign endings, and new draft revisions for changed
published terms. Platform Admin manages explicitly Bagoo-funded product or
capped shipping offers. A reasoned shop-campaign suspension notifies its seller
and cannot be bypassed with seller resume. Budget increases retain their actor,
reason, old/new ceiling and current use counters without changing discount terms.

The shared writer service rechecks account/shop approval and ownership, normalizes
codes before uniqueness, enforces dated finite limits and affordable seller
reductions, retains immutable audit records and rejects changed request retries.
Lifecycle reporting excludes restricted shops from active offers. Campaign totals
span the authorized query, with distinct bookmarks, successful uses, cancelled
allocations, committed funding and settled expense. Seller and admin screens use
the existing governance/seller layout with bounded forms and paginated history.

Verification: 77 isolated SQLite campaign/quote/governance-notification checks and
39 funding regression checks passed. The 35 seller checks were rerun after the
final restriction-state adjustment. Seventeen frontend campaign/finance checks,
PHP style checks, `git diff --check` and the TypeScript/Vite build passed. The
additive campaign migration retained the local PostgreSQL application's 9 users,
17 products, 3 orders and their original approval, stock and monetary values;
there were no local voucher records. This operational migration does not prove
PostgreSQL concurrency. No Azure deployment or browser/device check occurred.

Scoped assessments: campaign writer integrity **3/10 -> 8/10** and campaign
management interface **2/10 -> 8/10**. V04 owned collections, checkout pickers,
best-combination selection and the combined acceptance handoff remain in progress
on the same authorized branch. These scores do not rate the whole buyer/admin
flows or deferred refund/targeting features.

Backend commit: `9b9ab8f feat(vouchers): publish scoped campaigns with recorded budgets`.

### October 12, 2026 — V03/V04 voucher acceptance and handoff

The authorized combined batch now includes owned buyer bookmarks, paginated
Available/Saved/Used/Expired collections with whole-query counts, shop discovery
beside products and shop content, and offers for selected Bag shops. Saves reserve
no quota or money. Saved or used old revisions remain explainable after expiry,
pause, archival or publication of replacement terms; another buyer cannot remove
their bookmarks or read their orders.

Checkout groups its items by shop, with a Shop voucher picker in each group and
one Bagoo voucher picker in the summary. Labelled radio cards, explicit Apply,
No voucher and secondary code entry use a bounded desktop dialog/mobile sheet.
Unavailable choices retain a reason. A failed preview removes only invalid slots;
valid sibling choices and explicit No voucher remain. Final placement still
reloads and rejects unavailable selections without partial orders or commitments.

Use best vouchers enumerates every allowed combination using integer discount
amounts from the shared quote. Equal savings prefer fewer vouchers and then sorted
revision IDs. The limit is 40 available campaigns and 4,096 combinations; larger
searches explicitly require manual choice, rather than claiming a truncated
result is best. Stale responses after item, delivery or manual-choice changes
cannot replace the buyer's current selection.

Each shop has its own merchandise, discounts, shipping before/after and COD total;
the summary is the authoritative exact sum. Receipts use immutable recorded terms
and allocations rather than current campaign definitions. Partial shipping
reductions retain a payable fee. Old unknown-funder discounts remain recorded
without a guessed product/shipping allocation. Original commission, seller share,
custody, cancellation and buyer-only completion rules remain intact.

The focused demo seeder publishes one approved shop offer and Bagoo product and
capped shipping offers through the real writer. It keeps dated finite budgets
and limits, skips existing codes on repeat runs, and creates no fictional saves,
redemptions or expenses. The existing verified demo accounts and raw-password
cast convention remain. No application database was reset or reseeded here.

Verification evidence:

- The combined **220 isolated SQLite checks passed (6,039 assertions)** across
  buyer selection/quotes, immutable funding, discovery, tracking, campaign
  ownership/management, seeders and seller settlements. These include quota and
  budget rejection, altered retry inputs, original-token replay after pause or
  expiry, transactional rollback, partial cancellation, recorded funding release,
  legacy monetary preservation and buyer-confirmed/reconciled COD settlement.
  The 12 selection checks passed again (157 assertions) after adding replacement
  publication to the recorded-receipt case.
- Best selection matches exhaustive bounded fixtures, including caps, ties,
  shipping differences, a budget that only fits after a shop reduction, a
  one-cent shipping remainder and zero-fee parcels. Too many campaigns or
  combinations reject without reservations. Receipt preservation also checks
  replacement publication with changed discount terms.
- **68 frontend checks passed** across buyer vouchers, profile/navigation,
  tracking, campaign management and finance. Eighteen focused voucher checks
  passed again after the final picker/pending changes. PHP style checks,
  `git diff --check` and the final TypeScript/Vite build passed.
- The V03 additive local migration retained all 9 users, 17 products, 3 orders
  and original approval, stock and monetary values. V04 needs no new migration.
  PostgreSQL concurrency and interactive browser/device behavior remain
  separately unverified. No GitHub publication or Azure deployment occurred.

Scoped assessments: **buyer voucher discovery/choice 3/10 -> 8/10** and
**voucher summary/receipt clarity 5/10 -> 8/10**. V03's writer and management
assessments remain 8/10; this does not raise whole buyer/seller/admin readiness.
Targeting, loyalty points, wallet/cashback, shop-funded shipping and automatic
refunds remain deferred. See [rollout and retained records](voucher-plan/ROLLOUT.md)
for additive deployment and stopping new operations without deleting history.

Implementation commits:

- `9b9ab8f feat(vouchers): publish scoped campaigns with recorded budgets`
- `edbc62e feat(vouchers): add seller and admin campaign controls`
- `efa2a34 feat(vouchers): retain buyer saves and calculate exact choices`
- `dc91a79 feat(vouchers): seed bounded demo offers through campaign publication`
- `06b6d93 feat(vouchers): add buyer collections and checkout pickers`

The final acceptance/documentation commit identifies its own scope in Git; its
short hash is also included in the local branch handoff. The prepared PR title
and description are a local draft, not a saved GitHub pull request.

### October 12, 2026: essential form review and authentication planning

[Essential form validation and verification](FORM_VALIDATION_REVIEW.md) now
defines the crucial review method for field purpose, presence, format, limits,
normalization, uniqueness, ownership, saved values and recovery. Repository
instructions and the documentation map route affected form work to this guide.
The separate [authentication plan](auth-plan/README.md) proposes bounded access
and verification recovery, shared field validation, terms acknowledgement and
combined acceptance deliveries. Its dated windows are planning assumptions.

The source review identifies concrete follow-up work:

- Registration and password reset use Laravel's default password rule, which
  currently accepts eight characters. Registration helpers also describe that
  minimum. The normative contract specifies 12–128 characters and permits
  passphrases; accepting the shorter value is a contract mismatch. The contract
  does not require arbitrary character-type combinations.
- [PhoneInput](../resources/js/Components/PhoneInput.tsx) removes non-digits and
  limits the extracted value to ten national digits before validation. An invalid
  or overlong paste can lose its original meaning. Raw-input rejection and
  consistent role-specific contact feedback need follow-up.
- Registration steps check some names for presence and use a partial email
  pattern, while the server applies meaningful text, syntax, current ownership
  and case-insensitive email checks. Bring early feedback into agreement with
  those server checks before avoidable OTP requests.
- Public registration currently has no explicit terms/privacy acknowledgement.
  Adding it needs readable content, server enforcement, recorded policy version,
  retry semantics and an existing-account compatibility policy, including
  first-time OAuth account creation. No historical acceptance may be invented.

Policy disagreements are not automatic defects. Full names and name components
have different current limits; shared contacts and business display names are
not globally unique sign-in identities. Buyer review and courier application/
company placement retain their existing documented authorities. New legal
identifier formats or onboarding rules need their own reviewed scope.

Fresh isolated SQLite `:memory:` verification passed **347 tests / 3,201
assertions** across application validation, authentication, registration, shared
portal/privileged access, OTP, worker birth dates and web session transitions.
The existing protected-hub cross-role guards are covered. A reachable public
registration screen does not establish a protected-data leak; an alleged live
access failure still needs its exact endpoint and account state checked.
Real mail delivery, Azure behavior and interactive browser/device behavior were
not verified. No application behavior, approval policy or development/production
database was changed; the proposed fixes remain unimplemented and readiness
ratings are unchanged.

### October 12, 2026: AF01 and AF02 registration and verification repairs

The selected first [authentication batch](auth-plan/README.md) now implements
shared registration feedback and verification recovery. The earlier planning
entry describes the prior state; the evidence below records these repairs.

- Buyer, seller, courier and logistics forms validate each step through the same
  server rules used by final registration. The complete check validates current
  fields and evidence before OTP, without creating accounts, storing evidence or
  sending mail. Final submission validates again. Errors focus a summary and
  return to the relevant step, including derived contact and nested fleet fields.
- Complete email syntax, control rejection and case-insensitive ownership are
  shared with web/native verification and additional contact email submission.
  Custom domains and `@bagoo.test` demos remain eligible. No provider allowlist
  or DNS check is introduced; format acceptance does not establish inbox ownership.
  Verification requests also check legacy original identities and additional
  addresses before sending a registration code.
- New and reset passwords follow the documented 12–128-character passphrase
  policy. Reproduction showed bcrypt accepted a changed suffix beyond its byte
  limit. The default Argon2id configuration verifies the complete accepted value,
  including Unicode. Existing shorter bcrypt credentials and active bcrypt OTP
  challenges still verify; successful web login upgrades a legacy hash.
- The shared mobile input preserves invalid characters and surplus digits for
  rejection instead of deleting or truncating them. Normal Philippine mobile
  formatting remains; seller/logistics business contacts retain landline support.
- Verification shows sent status only after explicit success, distinguishes
  session expiry, unreadable responses, connection failures and delivery failure,
  respects cooldown and blocks concurrent or stale requests. Malformed codes
  cannot turn into a six-digit code by filtering. Web aliases share network send
  and verification limits in addition to existing per-address/purpose controls.
- Recovery forms show challenge errors, allow obtaining a new code and explain
  the password policy. Roles, approval authorities, original email identity and
  account creation/holding behavior retain their existing contracts.

Verification: **918 isolated SQLite `:memory:` tests passed / 7,191 assertions**
across authentication, registration, web/native sessions, role hosts and aliases,
account emails/settings, identity corrections, immutable roles, demo seeders and
buyer checkout gates. The new checks cover side-effect-free preflight, invalid
and custom emails, evidence limits, role/resource tampering, raw control/code
rejection, shared OTP limits, long-password suffix sensitivity and legacy hash
compatibility. Valid registration/reset fixtures now use compliant raw passwords;
legacy login fixtures retain shorter credentials. No authorization assertion was
removed to obtain a pass.

**20 frontend checks passed**, covering authentication request recovery, stale
and duplicate verification, phone preservation, error mapping, registration
markup and ordinary account settings. The TypeScript/Vite production build,
focused PHP style checks and `git diff --check` passed. These are automated and
static checks; no interactive browser/device check was performed.

Scoped assessments: **registration field feedback 5/10 -> 8/10** and
**verification failure recovery 4/10 -> 8/10**. Earlier forms could advance with
values the server would reject and could display misleading mail failure or
sent feedback. Shared early validation and explicit request outcomes improve
those behaviors; these ratings do not raise whole buyer/seller/admin readiness.

Readable terms/privacy acknowledgement and recorded acceptance remain AF03.
The combined live session/mail release gate remains AF04. Additional legal
identifiers, invitation-only onboarding, uniqueness-policy changes and broader
location coverage remain separate reviewed scope. The protected access checks
passed locally; an alleged live leak still requires its exact protected endpoint
and deployed account state. Original external acceptance results were not
reclassified from these different automated checks.

No development/production reset, GitHub publication or Azure deployment occurred.
The separate Flutter repository was not changed. Deployment hashing overrides,
legacy native-token reauthentication and the native password-policy handoff are
documented in [authentication rollout](auth-plan/ROLLOUT.md) and the
[rider account API](RIDER_ACCOUNT_API.md). The branch PR title/body remain a local
draft until the user publishes it.

### October 12, 2026: Separated registration names and immediate feedback

`fix/registration-name-feedback` starts from merged `main` at `8646337`.
Buyer, seller, courier and logistics registration now collect required first
and last names with an optional middle initial. All three fields immediately show
an inline error for digits, unsupported symbols, hidden characters or invalid
length. First/last name edits stop at 50 characters, initial edits at 2, and overlong paste is rejected
without shortening it. Invalid characters stay visible for correction.
Latin letters with accents, spaces, straight/curly apostrophes, hyphens and
periods remain accepted. Labels, autocomplete, field error associations and
polite announcements accompany the controls; each existing portal shell and
accent is retained. On shared rows, the optional middle-initial field is narrower
than both required fields; small screens keep stacked, full-width controls.

The server checks every supplied name component on the first step for every
role and at final submission. It requires first and last names when components
are supplied, normalizes them and constructs the stored display name from the
parts. A conflicting client display name cannot create inconsistent identity.
Existing name-only web/native requests remain supported. First and last names
require 2–50 characters each. The web form's optional middle initial accepts
one Latin letter and an optional period (M or M.), at most 2 characters.
The new request alias takes precedence over a conflicting supplied middle name,
is checked at preflight and submission, and stores the validated initial in the
existing column. Existing full middle names and native component requests remain
supported with a 1–50-character middle-name limit. The combined name retains its 2–100-character limit, including
separators. These shared component rules also govern application correction and
approval. Shop and company display-name rules are separate.

New signup email entries are trimmed at the edges, lowercased and limited to
255 characters. The server repeats normalization and case-insensitive ownership
checks; existing sign-in addresses are not rewritten. Internal whitespace and
hidden characters still fail validation. Shared mobile controls allow ten
national ASCII digits after `+63`, require a leading 9 and show incomplete-number
feedback. Supported prefixes/separators work on paste, while letters, unsupported
prefixes and extra digits are rejected without creating a different number.
Existing invalid stored input remains visible for correction, and business
contacts continue to support valid area-coded landlines.

Before sending preflight, the forms now catch malformed or missing personal
names, incomplete email addresses, impossible/future dates, worker age below
18, required contact/address details and invalid buyer/courier mobile numbers
for the relevant step. A passing local check still requires server confirmation;
preflight and final submission retain file, ownership and domain validation.
Combined-name errors appear with the name group, while component errors return
to the identity step. Correcting one name retains unrelated field errors.

The unreadable-response failure has a reproduced session cause. Role-host
registration pages can render with an existing signed-in account, while the
guest-only preflight and OTP routes redirected background requests to HTML.
Five checks first failed with 302 responses. JSON/API guest requests now return
409 with an explicit sign-out message instead of redirecting; the existing
session stays intact and ordinary page-navigation redirects are preserved.
Guest form requests still return 422 JSON field errors on all four local role
hosts. The illustrated invalid identity is also rejected locally before any
request. Real unreadable, expired-session or unavailable responses still fail
safely and cannot advance registration or claim verification success. The
original browser's session and response were not captured.

Verification: **695 isolated SQLite `:memory:` tests / 6,200 assertions passed**
across the complete authentication suite, including application validation,
preflight, web signup, KYC, worker birth-date eligibility, role/session guards,
account settings and the native account API. Coverage includes every role's
component errors, optional initial shape and length, legacy full middle names,
punctuation/accents, normalized stored
identity, conflicting display names, exact length limits, malformed component
types, multipart JSON errors, normalized emails, case-equivalent duplicate
rejection without rewriting existing identity, and legacy name-only compatibility. Native name
rejection leaves the verified token and uploads usable for a valid retry.
**82 frontend checks passed** across authentication, account settings, buyer
profile and courier UI checks, including immediate invalid-name rendering,
accessible field feedback, rejecting overlong edits without truncating pasted
names/numbers, email edge/case normalization, numeric mobile edit boundaries,
preserving invalid stored input, no request for malformed
identity, server confirmation for valid input, calendar/phone boundaries and
three name inputs on every registration page and signed-in-session feedback.
The TypeScript/Vite production build, focused PHP style checks and
`git diff --check` passed. The host build
hit an existing dependency-folder permission error; the supported app-container
build passed. No interactive browser, device, SMTP or Azure check was performed.

Scoped registration input and feedback assessment: **5/10 -> 8/10**. The earlier worker
forms had one name box and feedback after submission; all registration roles
now identify the incorrect component while typing and preserve valid names.
Email and mobile controls also follow the stored contract and prevent excess input.
This does not raise whole-role readiness or complete AF03/AF04. Deployment uses
the normal code and asset build with no new migration or database reset.
The separate Flutter repository and development/production records were not
changed. Publication and deployment remain pending user action.

Local implementation commits:

- `c2bc4f8 fix: validate registration names initials and email`
- `6ad8cb2 fix: return JSON feedback for signed-in signup requests`
- `e976e07 fix: show immediate signup errors and bound name and mobile input`

The documentation commit's generated hash is reported in the handoff. The
combined pull request title and description are a local ignored draft, not a
saved GitHub pull request.


## October 12, 2026: complete registration fields and policy acknowledgement

**Scope: `feat/registration-consent-validation`, AF03 and the local AF04 gate,
with the selected field-boundary follow-up.** Read the
[registration field review](auth-plan/REGISTRATION_FIELDS.md) for the exact
contract and the [rollout](auth-plan/ROLLOUT.md) for deployment/native handoff.
The stronger password policy selected here supersedes the earlier AF01/AF02
character-composition statement; existing sign-in credentials stay valid.

Initial buyer, seller, courier and logistics forms now offer optional sex and
postal code rather than introducing them only in correction. Four-digit postal
values remain strings, including leading zeroes. First Google signup also asks
for name parts and optional buyer details before creating an account; verified
provider email stays fixed. It does not guess legal-name components from the
provider display name. Platform Admin access remains controlled.

The correction screen removes the duplicate editable full-name input, uses a
narrower middle field and explicit server-supplied limits, and derives the name
from changed components. It preserves approval authority and original email.
Changed company/vehicle identifiers and fleet declarations use the application
bounds; unchanged legacy identifiers remain eligible under their previous rules.
Name components stay 2–50 with a combined 100-character limit; new web initials
stay one letter and optional period, at most two characters. Existing full
middle names remain supported at 1–50.

New passwords require 12–128 characters with uppercase/lowercase, number and
punctuation/symbol. Spaces alone do not satisfy symbols, controls are rejected,
and passphrase spaces are preserved. The shared rule governs signup, both
reset paths and website/native settings. Company initials use 2–10 ASCII
letters; declared fleet size is a real whole number 1–10,000 without silent
fallback. Vehicle identifiers have supported Philippine formats and visible
limits. These format checks do not prove issued/owned identity: private evidence
review remains necessary. Overlong paste is rejected rather than silently cut;
ASCII code casing retains invalid Unicode for feedback. Existing mobile
prefix/paste handling is preserved.

Application documents must be non-empty and no larger than 5,242,880 bytes,
with matching allowlisted extension and actual MIME content. Images must
identify correctly within the documented dimensions/pixel count. Rejection
happens before file storage or account creation. Real-content disguise checks
use real uploaded-file MIME detection, because framework fake files report MIME
from the supplied extension. Existing private inspection/authorization remain.

AF03 has readable versioned Terms/Privacy pages, unchecked acknowledgement and
server validation on web, native and first Google signup. Acceptance records
store versions, server time and source inside account/profile creation. Failure
rolls back the account, uploaded files and acceptance and leaves valid email
verification available for retry. Current versions are `2026-10-12`. A failed
preflight, duplicate retry or old account cannot manufacture acceptance. Policy
history is retained by the existing account-closure review. Google completion
uses a session-bound nonce and ten-minute expiry; provider email must be verified
and a conflicting existing provider identity cannot be replaced.

Verification: **773 isolated SQLite `:memory:` authentication tests / 8,223
assertions passed**. The wider repository run covered **3,451 tests / 81,444
assertions**, with **3,432 passes and 19 failures in stale valid-input fixtures**
that omitted acknowledgement, mocked no provider verification, used new passwords
missing required groups, or used placeholder images/vehicle formats. Those
fixtures were updated without weakening their access/recovery/state assertions.
The complete authentication directory and all six affected outside directories/
files were then rerun: **983 tests / 11,436 assertions passed**, including every
previous failure. A final correction-metadata adjustment was additionally verified by **91 tests /
921 assertions passed**. The unchanged remainder of the wider run passed earlier;
the entire 3,451-case run was not repeated after fixture updates. This is not a
claim of a second passing full-suite run.

**191 frontend checks and the TypeScript/Vite production build passed.** They
cover all public form shells, Google application fields, immediate errors,
policy checkbox state/links, optional demographics, postal leading zeroes,
identifier/password/file boundaries and rejecting overlong paste without changing
it into another value. Focused PHP formatting and `git diff --check` passed.
No browser automation, interactive device, live SMTP or Azure check was performed.

The additive policy-history migration was applied to the confirmed local
PostgreSQL service only. Before and after counts remain **9 users and 17
products**, and the new acceptance table has **zero records**. No database reset,
reseeding or historical acceptance backfill was performed. The separate Flutter
repository was not changed. Its application form must adopt demographics,
strong-password rules, required licence input and explicit current-policy
acknowledgement; old clients receive actionable 422 field errors.

Scoped registration completeness and field-safety assessment: **6/10 -> 9/10
locally**. Applicants now see the fields up front, malformed types/values fail
on the server, and correction has matching limits and review behavior. Scoped
policy acknowledgement: **0/10 -> 8/10 locally**; readable content and transactional
proof exist, while the operator still needs controller/contact, processing-basis
and retention decisions before broader public operation. These ratings do not
increase buyer/seller/logistics lifecycle readiness or establish legal compliance.

AF04's local combined acceptance is verified. Publication/deployment, actual
delivered email, live sessions/role-host retests and the separate native client
remain visible release follow-ups. The user publishes the commits; the combined
PR title/body remains an ignored local draft, not a saved GitHub PR. Local implementation commits:

- `ef22325 feat: validate and record complete account applications`
- `3bc432f fix: align signup and correction form fields and feedback`

The documentation commit's generated hash is reported in the final handoff.

## October 13, 2026: registration field grouping and concise name feedback

Scope: `fix/registration-field-layout`. Buyer, seller, courier, logistics and
first Google signup place sex immediately after the separated name fields.
Postal code appears beside barangay in the address grid; the street address
uses the next full-width row, and narrow screens stack the controls. Other
address-selector callers keep their existing layout.

The long permanent name instructions are removed. Required fields, 2–50-character
first/last-name limits, the shorter optional initial, combined-name bounds,
permitted punctuation, paste restrictions and accessible inline errors remain.
There is no dangling accessibility reference to the removed paragraph. Sex
validation and error navigation now use the first step on both client and server;
postal validation and navigation remain on each role's address step.

Verification: **103 isolated SQLite `:memory:` tests / 2,225 assertions passed**
for registration preflight, field boundaries/persistence and Google signup.
**36 authentication frontend checks, the TypeScript/Vite production build, two
changed PHP files' style checks and `git diff --check` passed.** Rendered markup
checks cover sex placement on every initial role form, Google field order,
postal grouping, leading zeroes, optionality and name feedback after the paragraph
is removed. All five pages' address-control wiring was also inspected.
No interactive browser or Azure check was performed for this follow-up.

Scoped engineering assessment: **registration field grouping/copy 7/10 to 9/10
locally**. Related details are now together and names have less permanent copy;
validation strength and whole-role readiness do not receive an unrelated increase.
No migration, database reset, policy-version change or Flutter source change is
needed for this presentation follow-up.

Implementation commit: `d4946a3 fix: group registration details with names and addresses`.

## October 13, 2026: accented-name acceptance and email ownership review

Scope: `test/registration-name-acceptance`. Existing frontend and backend name
rules already allow Latin letters including ñ, accents, ordinary hyphens and
supported apostrophes. The signup persistence regression now explicitly combines
these characters in first/last names across buyer, seller, courier and logistics;
frontend checks also cover decomposed ñ and preserve the displayed surname.
Required-name length, numeric/hidden-character rejection and combined-name limits
remain enforced. No application validation rule needed changing.

Existing email ownership was reviewed and exercised without changing email code.
A registration email is reserved globally across roles, including case/outer-space
variants and existing verified additional contact addresses. The database's unique
indexes and normalized ownership ledger also reject competing ownership; additional
verified contact addresses retain the existing owner/password/OTP rules. This does
not create multi-role accounts or replace original sign-in identities.

Verification: **75 isolated SQLite `:memory:` tests / 1,016 assertions passed**
for signup/preflight and account-email ownership. **36 authentication frontend
checks, the changed PHP test's style check and `git diff --check` passed.**
Only tests and documentation changed, so no new production build was required.
No interactive browser, real email delivery or Azure verification was performed.
Name acceptance assessment stays **9/10 to 9/10 locally**; this adds explicit
regression evidence rather than changing the accepted-character policy.

A duplicate-email lookup after typing pauses or on blur remains a proposed UI
feature. Format feedback already exists; successful mailbox verification establishes
control of an address. No live availability lookup or mailbox-checking service was
implemented in this follow-up.

Regression commit: `06c73be test: verify accented and hyphenated signup names`.

## October 13, 2026: early signup email availability feedback

The automatic checker described in this historical entry was subsequently
removed at the user’s request; see the removal entry below.

Scope: `feat/registration-email-feedback`, carrying the preceding accented-name
regression evidence. Buyer, seller, courier and logistics web signup now show
email availability after a 500 ms typing pause or when leaving the field.
Checking, available, duplicate and recovery messages use the existing email
control and portal layout. Existing field errors take priority. Google signup
keeps its fixed provider identity; the separate Flutter project was untouched.

The new read-only web endpoint validates the same normalized email format and
global ownership rules as signup, including verified additional addresses.
It returns only the submitted normalized address and availability. Guest/CSRF
protection and an independent 30-per-minute limiter remain in place; successful
responses disable caching. Checks do not reserve addresses, create accounts,
send mail or alter/consume OTPs. Final signup/preflight validation and database
uniqueness still reject an address taken after the earlier availability result.
The lookup exposes the existing duplicate-signup outcome without owner details;
it does not establish that a mailbox exists or is verified.

Blank/incomplete/invalid input does not start a lookup. Editing or leaving the
active step cancels pending work, and responses for old requests are ignored
even if cancellation is delayed. A ten-second timeout supplies retry feedback.
Network/session failures, malformed responses and server field errors never
claim availability; retry cooldown also applies after editing the address.

Verification: **235 isolated SQLite `:memory:` tests / 3,887 assertions passed**
across signup/preflight, field consistency, policy/Google signup, guest feedback,
OTP recovery, web sessions and account-email ownership/migrations. They include
all role hosts, malformed email types/controls, no-write/mail/OTP behavior,
CSRF rejection, independent rate limiting and stale availability at final signup.
**All 204 frontend checks passed**, including delayed/out-of-order responses,
blur deduplication, cancellation, cleanup/resume, timeout, retry, cooldown,
error precedence and each role's accessible feedback markup. The TypeScript/Vite
production build, five changed PHP files' Pint checks and `git diff --check` passed.
No browser UI, Azure deployment or real delivered-email verification was run.

**Scoped assessment: signup email feedback 7/10 -> 9/10 locally.** Applicants
learn about duplicate ownership while entering the address instead of waiting
for step submission. Existing signup/OTP/approval authority is preserved; this
does not raise overall platform readiness or certify deployed signup and mail.
Live role-host signup/session and delivered-OTP acceptance remain pending.

Implementation commits: `2701966 feat: add read-only signup email availability checks`;
`6b6bd89 feat: show email availability during signup`. The documentation commit
and combined branch pull request draft are reported in the handoff. Publication
and deployment remain user actions.

## October 13, 2026: remove automatic signup email checks

Scope: `fix/registration-email-checker-removal`, created after pulling `main`
through `3c7a1f0`. The user requested removing the automatic availability checker.
A signed-in browser session could open the registration form, then receive the
existing guest-session conflict as soon as a valid-looking email was typed.
That warning depended on the browser session, not whether the typed mailbox
existed. No account session was fabricated by the checker.

Buyer, seller, courier and logistics forms again use their ordinary email field
errors, without automatic typing/blur requests or availability status. The unused
lookup endpoint, coordinator/hook/component and dedicated lookup tests were
removed. Shared normalization, local format feedback, preflight/final signup
uniqueness, account-email ownership and OTP/approval requirements remain intact.
The preceding accented-name regressions are retained. Existing authenticated
sessions still need to sign out before submitting a separate registration;
removing early feedback does not silently discard their login.

Verification: **93 isolated SQLite `:memory:` tests / 1,113 assertions passed**
for registration/preflight, guest feedback, OTP and account-email settings.
**36 authentication frontend checks, the TypeScript/Vite production build,
changed PHP style and diff checks passed.** Static inspection found no remaining
automatic checker references in application code, routes or tests. No database
reset, browser automation, Azure deployment or real mail check was performed.

**Scoped assessment: email-field usability with an existing browser login
6/10 -> 8/10 locally.** The distracting automatic warning and retry control are
removed; format feedback and submission protection remain. Early duplicate
availability feedback is now deferred. This does not certify live registration
or raise unrelated platform readiness.

The removal was initially saved locally as `84f0790 revert: remove automatic
signup email feedback` and `fff0504 revert: remove signup email availability
endpoint`, then moved onto the updated-main branch as `b5e908a` and `f704995`
with the same subjects. The documentation commit is reported in the handoff.
The combined PR description remains a local draft; publication and deployment
are user actions.

## October 13, 2026: restore email feedback and route existing sessions

Scope: `fix/auth-page-session-routing`, created from updated `main` at `ccfcd6f`.
The user requested restoring early email checks and preventing existing sessions
from opening login or registration forms, including a new tab and role entry
pages. The underlying mismatch was that domain-specific authentication routes
rendered forms without the guest middleware, while the JSON registration
endpoints already rejected authenticated sessions. The typed email did not
create a login or establish that a mailbox existed.

All buyer, seller, courier, logistics and admin login entries, the four public
registration flows, role aliases and worker landing entries now use the same
session routing. An eligible account returns to its own portal; pending,
restricted and closed accounts keep their existing approval and denial rules.
Normal navigation, Inertia requests and JSON conflicts use the same destination.
Old intended URLs cannot return an existing session to an auth form or another
website. Guest authentication posts cannot replace that session with another
account or create an additional account. Domain routing preserves the request's
scheme and port and only navigates between known portal siblings.

The four editable signup email fields again check after a 500 ms pause or on
blur. The lookup applies existing normalized format and global ownership rules,
including verified additional addresses. It remains guest-only, CSRF-protected,
independently rate-limited and private, with no account, mail or OTP writes.
Cancellation, stale-response rejection, timeout, field-error priority and retry
cooldown remain in place. Availability does not reserve an email or prove a
mailbox exists; final validation, database uniqueness and OTP still apply.
Google signup retains its fixed provider identity.

Guest-only forms additionally read session state on mount, focus, visibility
return and page restoration. The read is rate-limited and uncached, uses a
ten-second timeout and does not poll. Network failures preserve entered values.
If another tab signed in, the form returns to the account's permitted destination;
an already-running email/preflight/OTP request also handles that session response
by navigating instead of displaying the old sign-out warning and retry button.
Authenticated verification, password-confirmation and holding pages keep their
existing access requirements.

Verification: **870 isolated SQLite `:memory:` authentication and account-email
tests / 10,349 assertions passed**, without warnings. These cover all role
hosts and path aliases, Inertia and JSON handling, approval/restriction states,
unsafe intended URLs, final signup uniqueness, guest feedback, OTP and native
authentication regressions. **Five additional checks / 80 assertions passed**
using actual login session cookies: each role's second-tab form redirects, the
session probe preserves the account, and logout reopens guest forms. **All 213
frontend tests passed**, covering redirect safety, stale email/OTP requests,
visibility/focus checks, cancellation, timeout and role-specific feedback.
The TypeScript/Vite production build, changed PHP style checks and
`git diff --check` passed. No PostgreSQL reset, browser UI test, Azure deployment
or real delivered-email test was performed; the Flutter project was untouched.

**Scoped assessments: existing-session auth routing 5/10 -> 9/10 locally;
signup email feedback 7/10 -> 9/10 locally.** Signed-in users return to their
account and guests receive early duplicate feedback while existing approval
and OTP authority is preserved. These assessments do not raise unrelated
platform readiness. Live role-host session/signup and delivered-OTP acceptance
remain pending. Local implementation commits and the combined pull request
draft are reported in the handoff; publication and deployment are user actions.

Implementation commits: `7daca36 fix(auth): route signed-in users away from guest
pages`; `809be05 fix(auth): restore email feedback with session redirects`.
The documentation commit and local combined PR draft are reported in the handoff.

## October 13, 2026: required sex in account applications

Scope: a follow-up on `fix/auth-page-session-routing`. The user changed sex from
optional to required. Buyer, seller, courier, logistics and first Google signup
now require an explicit selection. The existing `Female`, `Male`, `Other`
allowlist remains authoritative; no default selection is supplied to applicants.
The shared web control shows the required marker and browser/accessibility
attributes, and first-step feedback blocks an empty selection before continuing.

Shared server rules reject missing, null, blank, unknown and malformed sex on
preflight, final signup and native rider application submission. Correction and
review metadata also mark sex required; an application correction cannot clear
a saved selection. Pending applications missing sex need a selection before
resubmission or approval. Native registration options advertise
`sex_required: true`. The Flutter project was untouched; its client must submit
a valid sex and follow the updated registration options.

Verification: **872 isolated SQLite `:memory:` authentication tests / 10,730
assertions passed**, including new rejection/retry checks across all four public
roles, Google signup and native rider submission, correction persistence and
the native options contract. An older throttle-test fixture initially omitted
sex; it was updated and the full authentication run then passed. Registration
fixtures and fictional user factories now supply valid values without changing
the production database. **59 frontend checks, the TypeScript/Vite production
build, changed PHP style checks and diff checks passed.** No database reset,
browser UI test, Azure deployment or Flutter UI verification was performed.

**Scoped assessment: required-sex enforcement 4/10 -> 9/10 locally.** Previously
only invalid supplied choices failed; applicants could omit sex. The form,
server, correction metadata and native contract now agree that a choice is
mandatory. Live signup/session and delivered-email acceptance remain pending;
this does not raise unrelated platform readiness. Local commits and the updated
combined pull request draft are reported in the handoff.

Implementation commit: `4aa51b0 fix(auth): require sex in account applications`.
The documentation commit is reported in the handoff.


## October 13, 2026: account ownership and upload guidance

Scope: `fix/registration-contact-uniqueness`, starting from merged `main` at
`0571f75`. The user selected an eight-character password minimum, globally
unique account mobiles and shop names, and subtle format/size guidance beside
every web upload control.

New registration, reset and password-change writers accept **8–128 characters**
with uppercase, lowercase, a number and a symbol. Web controls, the shared
password service and native registration options agree. Existing sign-in
credentials remain usable. This is an authorized policy change from the previous
12-character minimum, rather than a claim of stronger password security.

Every account role stores a personal mobile as `+639XXXXXXXXX`. Complete local
`09XXXXXXXXX` and supported country-prefix inputs identify the same number.
Preflight, final signup, native rider signup, profile/contact edits and model
writes reject another account's mobile, including inactive/closed ownership.
Unchanged owner edits succeed; optional blanks remain null. Separate public
shop/company contacts and order-recipient numbers retain their sharing and
landline rules. Hub counter searches accept a complete local number while
matching canonical storage within the authorized destination facility.

Shop names are platform-wide unique after Unicode normalization, case folding
and spacing normalization. Display names retain the existing 2–255 length
contract. Signup and reviewed corrections exclude the current owner, recheck
ownership before saving and preserve approval/history on rejection. Final
mobile and name-key constraints translate competing insert conflicts into field
errors. The additive migration refuses invalid legacy contacts or equivalent
owners/names before changing records; it never deletes, renames or reassigns
ownership automatically.

All web file controls now have subtle allowed-format and maximum-MB text,
including registration, resubmission, correction, buyer verification/checkout,
review photos, seller products/branding, rider delivery proof and existing
finance evidence. Private documents allow JPG, PNG, WEBP or PDF up to **5 MB**;
avatars/logos use **3 MB**, banners and product/review images use **5 MB**.
Existing image-only controls retain their actual image format allowlists.
Private document writers check non-empty content, actual MIME type, matching
extension and readable image limits before storing any files. Videos and
renamed non-documents fail with field errors; browser filters are supplementary.

Verification:

- The affected Docker/SQLite run exercised **1,130 cases**, with **1,128 passing**
  and two counter-fixture errors. Creating both orders with their original buyer
  fixed those fixtures; the final counter/authentication run passed **64 tests /
  7,374 assertions**, including both counter cases and three corrected
  subdomain-registration scenarios.
- A broader host scan exercised **3,605 tests**. Its 19 failures and 240 errors
  included missing host GD support, stale fixtures and the corrected shop-review
  validation ordering. A Docker retry of every failing method exercised **305
  cases**: 302 passed, and three registration fixtures omitted required sex.
  Those three now pass with explicit no-validation-error assertions. All
  identified failures were cleared in their retries; there is no single passing
  full-suite run for this branch.
- Migration refusal/retention checks passed **4 tests / 33 assertions** on
  isolated SQLite `:memory:`. No PostgreSQL test or database reset was used.
- All **214 frontend checks**, the TypeScript/Vite production build, changed
  PHP style checks and whitespace checks passed.
- The normal local PostgreSQL migration succeeded. Before/after audits retained
  **9 accounts, 1 shop, 17 products and 17 visible products**, with identical
  account identity, shop business values and shop-review fingerprints.

**Scoped assessments: mobile/shop ownership checks 4/10 -> 9/10 locally;
upload guidance and private-document consistency 6/10 -> 9/10 locally.** These
ratings reflect tested rejection, retry and saved-value behavior. Phone
ownership by SMS, rendered browser/Flutter acceptance, Azure deployment and
simultaneous PostgreSQL requests remain unverified or outside this change.
Apply normal migrations before serving the new ownership checks; installation
conflicts require explicit record correction and retry, without reset/reseed.

Implementation commits: `10619ce Allow eight-character passwords across account
writers`; `6101891 Enforce unique account mobiles and shop names`. The upload
commit and combined local pull request draft are reported in the handoff.

## October 13, 2026 — H01 handover reports and independent review

The [handover plan](handover-plan/README.md) separates reporting, pickup recovery,
hub/manifest recovery and native integration. The first branch,
`feat/handover-issue-reporting`, implements the reporting and review gate.

Involved sellers, assigned riders, eligible hub handlers and company
administrators can report an exact known waybill with their observation of the
holder/location and private supporting evidence. A report retains the original
parcel/order/route state and original custody checkpoint. Reported possession
never replaces recorded custody. A different eligible company administrator or
platform admin can confirm a concern or dismiss it with a reason; confirmation
keeps the case open as Needs recovery.

One open report per parcel, request-token replay checks and version/current-source
checks prevent duplicate reports and stale decisions. Losing account, facility or
company authority prevents new work. Original reporters retain scoped read access
while eligible. Mixed-shop legacy orders cannot be reported as wholly owned
parcels. Original facts and events resist model and direct SQL edits/deletion.
Missing or altered evidence prevents a new review. Private downloads recheck
scope and the saved hash; actual type/extension and bounded readable-image checks
reuse the existing 5 MB JPG/PNG/WEBP/PDF policy.

All affected portals link to the workspace using their existing shell. The rider
keeps four bottom tabs and gains a sidebar/drawer link. The pages separate
reported facts, original records, current records and review history, with field
feedback, safe retries, clear empty states and automatic review refresh.

Verification:

- Final focused run: **74 tests / 2,071 assertions**, including all supported
  formats, forged/empty/oversized evidence, ownership and authority loss,
  self-review, competing reports and decisions, retries, stale parcel facts,
  private downloads, retained history, rollback and scoped pagination.
- Broader Docker/SQLite run: **737 tests / 42,287 assertions** across logistics,
  rider/native operations, finance, seller order workspaces and account closure.
  This run includes the original 72 handover cases; the two added image-format
  cases passed in the final focused run. It is not a full-repository suite claim.
- All **222 frontend checks**, the TypeScript/Vite production build, changed PHP
  style checks, documentation links and whitespace checks passed.
- The additive local PostgreSQL migration succeeded. Before/after audits retained
  **9 accounts, 1 shop, 17 products, 17 visible products, 3 orders and 3 parcels**;
  the two new reporting tables have no demo reports. No reset, reseed or
  PostgreSQL test was run.

**Scoped assessment: missed-handover reporting and triage 2/10 -> 8/10 locally.**
Staff now have an accountable cross-role reporting and review path instead of
forcing an unsupported ordinary scan. This rating does not raise complete
recovery readiness: H01 adds no operational hold, corrective receipt, custody
transfer, notification delivery promise or native reporting endpoint. Confirmed
concerns stay open for H02/H03. PostgreSQL simultaneous-request behaviour,
rendered browser/device interactions and Azure deployment remain unverified.
Apply ordinary migrations when deploying; original scans, transport routing,
stock, cash and buyer-only completion retain their existing rules.

Local implementation commits: `f96adb3 Plan handover reporting and controlled
recovery`; `c102421 Add private handover reports and independent review`;
`6bd05a2 Connect handover issue reporting to each staff portal`. The combined PR
is a local draft; publication and later planned batches remain separate work.

## October 13, 2026 — H02 reviewed holds and seller/rider handover recovery

The `feat/pickup-handover-recovery` branch implements
[H02](handover-plan/02-pickup-recovery.md) under its
[writer and receipt contract](handover-plan/02-writer-and-receipt-contract.md).
An independently confirmed H01 report now holds ordinary parcel movement;
an unreviewed report does not. Seller fulfillment/cancellation, rider claims
and outcomes, hub scans, manifests, counter collection and competing recovery
writers enforce the same hold. Observation, scoped reading and existing cash
responsibilities remain available. Counter reminder and seven-day expiry
processing retain their existing calendar rules.

Recovery covers a missing seller readiness record, a missed pickup and a missed
destination-hub departure. An eligible independent reviewer authorizes a known
holder and the original receiving hub. Missing seller acceptance, packing and
readiness require the owning seller's explicit commercial confirmation. The
holder acknowledges actual possession with the matching waybill; an eligible
handler then records actual receipt at the designated hub. The hold clears
only with that receipt. Unknown possession and unsupported sources stay held.

All corrective events use the current server time and retain their original
source and authorization. Existing checkpoints are preserved; no old readiness,
assignment or scan is fabricated. Ordinary routing resumes through the required
Mother Hub after actual receipt. Stock, purchased prices, cash responsibilities
and buyer-only completion retain their existing rules. Inactive or suspended
holders get only their own valid recovery acknowledgement and receipt status;
they do not regain ordinary portal or native access.

Authorizations expire after 24 hours and bind the current parcel, purchased
items, route, original evidence and account/network authority. Changed sources,
missing or altered evidence, lost authority, wrong waybills and stale versions
prevent new corrective work. Withdrawal and expiry keep the hold. Fresh
authorization can continue a valid acknowledged handover without replaying its
past movement. Exact retries preserve committed results; competing intents and
receipt failures leave no partial custody correction. Reporting events and new
authorization records remain immutable, including after SQLite schema changes.

Seller, rider, hub and admin pages use their existing shells and show only
permitted recovery actions, required confirmations and field feedback. The
restricted holder page shows only owned grants. Buyer/public tracking uses a
general hold explanation without private evidence or authorization details.
Existing native task flags and movement commands also enforce the hold; this
branch adds no native reporting/recovery endpoint and changes no Flutter code.

Verification:

- Focused recovery run: **65 tests / 5,149 assertions**, covering supported
  handovers, both receipt parties, omitted fulfillment, rejected inputs,
  authority/source/evidence changes, restricted access, expiry/withdrawal,
  retries, competing receipts, rollback and held writers through web/native
  services. The route resumes with original commercial and custody evidence.
- Full Docker backend run: **3,744 tests / 91,971 assertions**, all passing,
  with no skips. Every test used isolated SQLite `:memory:`; no PostgreSQL
  development or production database was used for tests.
- All **229 frontend checks**, the TypeScript/Vite production build, changed
  PHP style checks, documentation links and whitespace checks passed.
- The additive local PostgreSQL migration succeeded. Before/after counts remain
  **9 accounts, 1 shop, 17 products, 17 visible products, 3 orders and 3 parcels**.
  No reset or reseed was performed. No demo handover reports or grants were
  created in that database.

**Scoped assessment: missed seller/rider handover recovery 2/10 -> 8.5/10
locally.** Previously, a reviewed report could identify the problem without
preventing conflicting work or recording a controlled actual receipt. Staff now
have an enforced hold, independent authorization and accountable two-party
recovery. This does not raise overall logistics readiness: H03 hub/manifest
repair, H04 native integration, simultaneous PostgreSQL requests, rendered
browser/device interactions and Azure deployment remain outside this evidence.
Apply ordinary migrations when deploying; no database reset is required.

Local implementation commits: `b14a2bf Define the H02 hold and actual receipt
contract`; `19ddd9b Enforce reviewed handover holds across shared parcel
workflows`; `1655891 Recover seller and rider handovers through actual named-hub
receipts`; `9929d14 Guide each portal through authorized handover recovery`.
The combined PR title/body remains a local draft; publication and later planned
batches remain separate work.

## October 14, 2026 — H03 actual hub and corrective manifest recovery

The `feat/hub-handover-recovery` branch implements
[H03](handover-plan/03-hub-recovery.md) under its
[writer and receipt contract](handover-plan/03-writer-and-receipt-contract.md).
It starts from the locally completed H02 branch; publication remains a separate
user action. Reviewed missed origin intake, correct-facility final-mile handover,
manifest loading/departure/receipt and failed-delivery return now have an actual
receipt path. Unknown custody and missing original transport evidence stay held.

Independent company or platform authorization names the known holder and fixes
the original source, facility and route. The holder acknowledges current
possession; another eligible assigned handler records actual matching-waybill
receipt. Restricted holders retain only their own recovery access. Authorization
does not grant ordinary hub permissions. Acknowledged possession blocks new
rider work and account closure until an actual later custody receipt; expiry or
withdrawal cannot manufacture release from that responsibility.

Immutable corrective manifest receipts preserve the original list, original
member when present, vehicle/driver, source snapshot, before-recovery counts and
missing or recorded lifecycle timestamps. Unlisted receipts remain separate
from the original expected count. A held unlisted parcel also holds its selected
manifest's batch changes. Other unheld expected parcels may still be received.
Partial arrival cannot imply complete receipt. Reviewed closure requires every
original included obligation to have actual receipt evidence and every remaining
discrepancy to have its supporting resolution; it records closure now.

Existing wrong-hub recovery continues only under the exact linked observation
and case, original guards and real Mother Hub route. Actual corrective receipts
can also continue frozen reverse transport. Neither path invents loading,
departure or past receipt scans. Changed evidence, accounts, facilities,
commercial facts, route or recorded personnel invalidate new corrective work.
Exact committed retries return their original result; changed submissions,
stale versions and failed writes cannot leave partial receipt evidence.

Portal guidance uses the existing role shells and separates original records,
actual corrective receipts, unlisted arrivals and outstanding obligations.
Stock, purchased prices, COD responsibility and buyer-only completion remain
separate. Public tracking exposes no private report or authorization evidence.
H03 changes no Flutter files and adds no native reporting/recovery endpoint;
[H04](handover-plan/04-native-contract.md) remains a separate integration batch.

Verification:

- Focused recovery run: **54 tests / 4,766 assertions**, all passing. It covers
  missed load/departure/receipt, restricted and failed-return routes, linked
  original recovery, partial counts, held writers, authority/source changes,
  expiry/withdrawal, exact retries, competing submissions, rollback, immutable
  history, scoped forms and cash/buyer separation.
- Full Docker backend run: **3,798 tests / 96,737 assertions**, all passing,
  with no errors or skips. Every test used isolated SQLite `:memory:`; no
  development or production PostgreSQL database was used for tests.
- All **236 frontend checks**, the TypeScript/Vite production build and changed
  PHP style, documentation links and whitespace checks passed. Rendered
  browser/device testing was not performed.
- The additive local PostgreSQL migration succeeded. Before/after audits retained
  **9 accounts, 1 shop, 17 products, 17 visible products, 3 orders and 3 parcels**.
  The new corrective-receipt table has no demo records. No database reset,
  reseed or PostgreSQL test was run.

**Scoped assessment: missed hub/manifest handover recovery 2/10 -> 8.5/10
locally.** Staff can now recover a known physical handover through independent
authorization and actual receipt while preserving transport history and truthful
counts. This assessment does not raise overall logistics or native integration
readiness. Simultaneous PostgreSQL requests, rendered device interactions and
Azure deployment remain unverified. Deploy with ordinary migrations; no fresh
database is required.

Local implementation commits: `7d1790b Define the H03 corrective receipt and
manifest evidence contract`; `8432c9a Recover actual hub and manifest handovers
without rewriting transport history`; `1efd179 Guide supervised hub recovery
and show actual manifest receipt counts`. The combined PR title/body remains
a local draft; user publication and H04 are separate actions.

## October 14, 2026 — H04 native handover contract and integration handoff

The `feat/handover-api-contract` branch starts from published `main` at `5de04eb`
and implements [H04](handover-plan/04-native-contract.md) under its
[writer and API boundary](handover-plan/04-writer-and-api-contract.md).
Five bearer methods expose involved report lists/detail, private evidence,
missed-scan reporting and the named holder's actual possession acknowledgement.
They reuse the H01-H03 services. Seller fulfillment, independent company/platform
review and authorization, and actual eligible hub receipt retain their web roles.
Inactive/suspended holders keep restricted web recovery; ordinary native access
is not restored.

New eligible sessions receive a separate handover ability. Current credentials,
token expiry/revocation, account eligibility and resource authority remain
enforced. Account/Home advertise the available contract and current capability
flags; older read tokens cannot write without the new permission. Native JSON
uses explicit fields and string IDs, bounded integer issue counters, opaque
source tokens and UTC timestamps. It omits private snapshots, storage paths,
unrelated buyer contacts and identity documents. Downloads reauthorize and verify
the saved document hash.

Every write uses the existing actor-scoped command journal and domain request
token. Original committed replay and owned reconciliation survive a lost
response; changed requests conflict. Seven-day expired keys remain readable
without allowing another write. Failed outer commands roll back their domain
records and remove unused uploaded bytes, including late token revocation.
Version/source/evidence changes, expired or withdrawn grants, unrelated actors
and unsupported receiver fields cannot create partial possession evidence.
Ordinary held movement remains blocked. Duty-off retains owned responsibility;
only actual authorized hub receipt releases the hold.

The [API guide](api/RIDER_HANDOVER_API.md),
[executable OpenAPI](api/rider-operations.openapi.json) and
[exercised fictional examples](api/examples/rider-handover/README.md) provide the
wire shapes, field/file limits, private download behavior, retry instructions,
deployment prerequisites and a copy-paste Flutter handoff. Contract verification
also corrected the older native task schema to include its already implemented
handover hold and denial fields. Multipart validation stays JSON without an
Accept header, and numeric unexpected fields keep the typed error map.

Verification:

- Focused run: **95 tests / 3,989 assertions**, all passing, including the three
  existing executable-contract cases. Real requests exercise omitted seller
  readiness, native holder acknowledgement, actual hub receipt, required Mother
  Hub transport, native delivery and buyer-only completion. Manifest correction,
  missed intake and failed-attempt return preserve their original evidence.
- Rejection checks include current account/token loss, another role or holder,
  bounded pagination, minimum/maximum text, all supported document formats,
  forged/empty evidence and the exact **5,242,880-byte** limit. Stale/expired/
  withdrawn sources, same/changed retries, replay expiry and injected write
  failures retain holds, original stock and cash authority without partial writes.
- **85 schemas** compiled; all **48 actual/sanitized response samples** passed.
  OpenAPI 3.1 structure, coverage of all **30 operational methods**, changed PHP
  style, documentation links and whitespace checks passed.
- Full Docker backend run: **3,890 tests / 100,418 assertions**, all passing,
  with no errors or skips. Every test used isolated SQLite `:memory:`; no
  PostgreSQL development or production database was used for tests.
- H04 adds no migration, reset, reseed, dependency, React page or Flutter change.
  The runtime follows the locked Laravel **13.32.0** patterns; the repository
  instruction's Laravel 12 label is older than the executable lock. This branch
  does not upgrade the framework.

**Scoped assessment: native handover backend 2/10 -> 8.5/10 locally.** Previously
native movement showed/enforced holds while reporting and recovery required the
web. Approved involved riders now have a verified API for observations, private
history/evidence and their authorized acknowledgement, with retained retries.
This does not certify Flutter implementation, rendered device interactions,
simultaneous PostgreSQL requests or Azure deployment. Deploy the merged backend
with the existing H01-H03 migrations, then sign in again for the new permission.
No fresh database is required.

Local implementation commits: `fadf229 Define the H04 rider handover API and
retry boundary`; `092c715 Connect native rider handover reports and authorized
acknowledgements`; `d321011 Document rider handover integration and exercised
response examples`. The PR title/body remains a local draft; user publication,
Flutter integration and deployed acceptance are separate actions.

## October 14, 2026 — Rider company onboarding plan

Planning inspection used merged `main` at `77fb96e`. The current courier signup
contains no company picker and prohibits managed company/hub inputs. Three
required private uploads are government ID, driver's licence and vehicle OR/CR,
with real JPG/JPEG, PNG, WEBP or PDF content and a 5 MB limit per file. The
[registration service](../app/Services/AccountRegistrationService.php) and
[document rule](../app/Rules/RegistrationDocument.php) enforce those requirements.

The [rider Assignment section](../resources/js/Pages/Courier/Profile.tsx) displays
managed company/hub data. The [company Network form](../resources/js/Pages/Hub/Network.tsx)
places an approved account by email and asks for its current hub. The
[shared placement service](../app/Services/Logistics/LogisticsPlacementService.php)
enforces owning-company, approved-rider, hub, vehicle, stale-placement and active
parcel/manifest guards. It does not require a rider-originated request or rider
acceptance for first company association. Company choice, offers and consent
are a proposed extension of that authority, not existing application features.

The [rider company plan](rider-company-plan/README.md) separates three deliveries:
RC01 discovery/intake/requests, RC02 company offers and atomic rider-accepted
placement with a scoped roster, and RC03 native integration/acceptance handoff.
RC01 intentionally stops before consent enforcement; RC02 must cover the old
manual form, direct services and both portals before mutual onboarding is complete.
The plan preserves fixed roles, Platform Admin KYC, private documents, original
assignments, deliberate duty and existing custody/cash responsibilities. Legacy
records receive no invented consent. Employment contracts, payroll, recruitment
chat and company-transfer/offboarding writers remain outside these batches.

This delivery adds planning documents and routing only. It does not implement
RC01–RC03, add application routes/tables, change the Flutter app or deploy code.
Runtime ratings and the last recorded test results remain unchanged. Future
estimates are planning ranges; selected implementation dates require capacity
and prerequisite review within the existing November 20 delivery target.

## October 14, 2026 — RC01 company intake and rider requests

Selected [RC01](rider-company-plan/01-company-discovery-and-requests.md) from
merged `main` at `89c85bf`, on `feat/rider-company-requests`. Approved, unassigned
riders can now open Profile → Assignment, search eligible companies accepting
requests, inspect their recorded hub localities and submit one request. Search
is literal, filters are retained and company/history pages contain 15 records.
A saved province filter remains readable when the last matching company pauses.

Company owners explicitly open or pause intake from their existing Network
workspace. Intake starts closed and retains each decision and revision. An
open preference without an eligible facility says **Facility needed**; it does
not advertise acceptance. Handlers and Platform Admin cannot act as the owner.
Owners read only their own pending requests, including the original shared
profile and a separate indication of current rider eligibility.

The rider confirms sharing name, sign-in email, contact number, locality,
vehicle type and approval state, with an optional 5–500-character normalized
note. Private identity/licence/vehicle files, birth date and plate/licence
numbers are excluded. The server checks raw limits and unsafe text before
normalization, rejects managed inputs and rechecks current authority under
ordered locks. A partial unique index enforces one pending request per rider
across companies. Original submission data and decision/event history are
immutable; withdrawals require a matching retained receipt.

Owned history survives withdrawal and later profile edits. Identical command
retries return the original receipt; changed intent or stale revisions conflict.
Audit failures and eligibility changes during saving roll back the command.
Approved riders awaiting email verification retain Profile, Privacy and security
and owned history access; request creation, replay and withdrawal still require
a currently verified email. A supplied `0` is preserved for validation rather
than silently becoming an empty note or search.

The UI reuses rider panels, dialogs, 8px controls, blush surfaces and the existing
Profile navigation. Company controls retain Network's own style. Invalid text
receives immediate feedback; uncertain saves preserve their intent for checking
history or retrying. Empty states and current blockers have explicit copy.

| Verification | Evidence |
|---|---|
| Final request acceptance | **80 tests / 751 assertions**, all passing in SQLite `:memory:`, including both portal contexts, reload/fresh sign-in, owned reads, sharing, field/page boundaries, stale intake, competing company inputs, exact/changed retries, immutable history and injected rollback failures. |
| Final request and shared access regression | **317 tests / 4,423 assertions**, zero failures, errors or skips. Includes the final request tests plus courier portal access, shared/guest account access, password updates and private verification documents. |
| Broader initial implementation regression | **1,960 tests / 61,011 assertions**, zero failures, errors or skips across Logistics, Courier, Auth, identity correction, logistics seeds and courier/subdomain acceptance. This run preceded the final numeric-input/profile-access corrections; those corrections passed the final 317-test run above. |
| Frontend | **41 courier/company helper and static-rendering tests**, plus **19 hub/account regression tests**, passed. The final `npm run build` passed. Source review retained responsive grouping, labelled controls, focus states and each portal's existing patterns. |
| Source checks | Scoped Pint passed for the 13 changed PHP files; final service/test changes passed again. Git whitespace and branch-content checks passed. |

**Scoped engineering assessment: company request/intake implementation
2/10 → 8/10.** Previously riders could read managed assignment details but could
not request a chosen company. Owned intake, safe sharing, retained requests and
withdrawal now have local acceptance evidence. This does not raise the overall
rider/logistics rating or complete mutually accepted company membership.

Rollout requires the additive `2026_10_14_200000_create_rider_company_requests`
migration. Existing company intake stays closed until its owner enables it;
existing assignments and seed accounts receive no invented requests or consent.
No database reset is required. Rollback refuses to discard recorded decisions
or request history. Development/Azure installation, simultaneous PostgreSQL
execution and browser/device rendering remain unverified in this delivery.

RC01 stops at pending/withdrawn requests. The existing manual placement form and
service remain in force; [RC02](rider-company-plan/02-offers-consent-and-placement.md)
must add offers, rider acceptance and enforce consent across those placement
paths before the complete joining workflow is ready. Native joining endpoints
and Flutter acceptance remain [RC03](rider-company-plan/03-native-contract-and-acceptance.md).

Local implementation and review commits:

- `b89647d` — `feat(logistics): retain owned rider company requests`
- `b5d1c5c` — `feat(portals): add rider requests to Assignment and Network`
- `f760be8` — `fix(logistics): preserve literal zero in rider request inputs`
- `65d9db2` — `fix(courier): keep profile access before email verification`
- `a1bd49f` — `fix(logistics): show when rider intake needs a facility`
- `18bfc05` — `test(logistics): verify request persistence and record RC01`
- `dc83c47` — `style(courier): align the sharing control with rider design`

The final evidence commit is reported in the branch handoff. The combined PR
title/body is a local draft in `.codex/rider-company-requests/PR.md`; it has not
been saved on GitHub. User publication, merge and deployment are separate steps.

## October 15, 2026 — Buyer registration, personal access and notifications

Scope: `fix/buyer-access-and-notifications`, based on fresh `main` at
`1327bc1`. This delivery follows the requested buyer policy change: new buyers
must supply a real birth date proving age 18 or older, including first Google
signup. Earlier optional-date examples no longer define new buyer registration.

| Before | Current behavior |
|---|---|
| Buyer signup allowed a missing birth date or a minor. | Browser, preflight and final password/Google submission require an adult date on the Philippine calendar. The server derives age. Invalid submissions create no account or policy record. |
| Unreviewed buyers were sent to a separate application page; personal links could redirect or fail. | Sign-in opens the marketplace with a dismissible buyer application dialog. The same dialog provides private application corrections, review feedback and a focused, current status check with a visible result. |
| Profile, order history and the Bag shared the checkout approval gate. | Buyers can read their own profile, history and Bag before approval, save ordinary contact/photo edits, and change Bag items while active or pending. Empty history says there are no orders yet. Checkout still needs an active approved account. |
| A single combined name appeared beneath the profile photo. | Settings show recorded first, middle and last names separately, with the middle control narrower. Missing legacy components remain visibly unrecorded; the existing identity correction/application process supplies them. |
| The bell navigated away and update links did not acknowledge the opened notice. | A bounded recent-updates panel highlights unread notices, acknowledges only displayed owned IDs and keeps newer/undisplayed updates unread. Opening an update saves read state through Inertia; approval opens account/application context with feedback. |
| The full notification list looked separate from buyer pages. | Buyer notifications reuse the marketplace header, navigation and surfaces. Ordinary status/read updates preserve the application experience without a full browser refresh. |

Restrictions remain independent. Suspended/inactive buyers can read personal
pages and their Bag but cannot change Bag items or place new orders. Explicit
history denials, current account state and ownership are checked again on the
server. Reading an unapproved buyer's own history does not grant receipt
confirmation or private pickup codes. Existing approved-buyer receipt handling
and the canonical delivery flow remain unchanged. No role conversion, approval
bypass, fabricated birthday or historical order ownership change is introduced.

Acceptance evidence:

| Check | Result |
|---|---|
| Combined PHP selection | **1,730 tests / 21,357 assertions**, zero failures, errors or skips: Auth, Buyer, Notifications, account/profile updates, admin KYC, identity corrections and account restrictions, using isolated SQLite `:memory:`. |
| Final application/history assertion | **58 tests / 2,012 assertions** passed after strengthening the owned private-document link assertion. |
| Frontend | **78 helper/static-rendering checks** passed for buyer access, profile, vouchers, registration validation and account settings. They cover required dates, separate name fields, empty pending-buyer history, notification shell/highlighting and Inertia acknowledgement. |
| Production build | `npm run build` passed after the final mobile popover positioning and empty-history copy changes. TypeScript and Vite completed successfully. |
| Formatting and review | Scoped Pint passed for 41 changed PHP files; the final application test passed its follow-up format check. Git whitespace checks passed. |

**Scoped engineering assessment: the requested buyer registration, personal
access and notification experience improves from 4/10 to 8.5/10.** Required
adult registration, reachable owned pages, current status feedback and owned
notification reads now have automated evidence. This assessment does not raise
overall commerce, delivery or production readiness.

Browser/device interaction, real mail delivery and Azure rollout were not
tested. Legacy reviewed accounts retain their recorded identity and correction
path; missing name parts or birth dates are not guessed. No schema migration,
database reset or reseeding is required by this branch. The dependency lock
uses Laravel `13.32.0`; the repository instruction's Laravel 12 label is existing
documentation drift, and no framework upgrade is part of this delivery.

The implementation commits and combined local PR draft are recorded in the
branch handoff. Publication, merge and deployment remain separate user actions.

## October 15, 2026 — RC02 accepted company offers and protected placement

Scope: `feat/rider-company-consent-and-placement`, from current `main` at
`d145375`. This completes the web joining batch after RC01; it does not start
[RC03](rider-company-plan/03-native-contract-and-acceptance.md).

| Before | Current behavior |
|---|---|
| An administrator could associate an unassigned rider through the email placement form without the rider's agreement. | The shared placement writer requires the retained acceptance of an exact current offer for a first company association. Root aliases, subdomains and direct service calls share this boundary. |
| Requests stopped at pending or withdrawn. | Company offers, replacements, rider acceptance/decline, reasoned rejection/cancellation and server expiry retain the original application, offer versions and decision history. |
| An operator entered a rider email and guessed the current facility. | Network shows the company's paginated current rider roster with saved facility, vehicle, barangay, duty, eligibility and honest existing/accepted-offer provenance. Same-company edits start from those recorded values. |
| Rider Assignment could display managed details but offered no consent journey. | Assignment displays exact working arrangements, version, issue/expiry dates, explicit acknowledgement and accept/decline controls, with retained history, field feedback and Inertia refresh/retry paths. |

Offers last seven calendar days and reserve neither a rider nor a vehicle.
Acceptance rechecks current owner/rider approval, intake, reviewed identity,
placement, eligible facility, covered barangay and available fleet sources under
consistent locks. Retained changes to assignment-related fields prevent a
change-and-restore cycle from reviving old terms. Ordinary contact/photo edits
do not stale an offer. Source hashes are independent of JSON object key order;
the PostgreSQL watcher compares the existing JSON coverage column through
`to_jsonb` rather than assuming it supports ordinary JSON equality.

Consent, fleet/profile placement, immutable placement history, the request
decision and required notice intents commit together. Identical actor-scoped
retries return the original offer/time/outcome. Changed intent, stale versions,
false acknowledgement and competing vehicle acceptance conflict without a
partial membership. Notice delivery runs after commit and retries independently.
Owned notices link to the rider's Assignment or the company's Network.

Reads show effective expiry without writing events. The scheduled
`rider-companies:expire-offers` command records actual reconciliation time with
a system actor; the next application also reconciles its rider's expired offer.
Acceptance rejects at the expiry boundary even without a running scheduler.

Existing assignments receive no invented historical request or consent.
Legitimate same-company moves retain the previous parcel/manifest guards and
original cash responsibility. New placements leave duty off. KYC, fixed account
roles, actual scans, buyer-only completion, COD and the 90/10 product split keep
their separate authority. Transfers, departure and employment/payroll management
remain outside this workflow.

| Verification | Evidence |
|---|---|
| Final focused acceptance | **219 tests / 2,256 assertions**, zero failures, errors or skips in SQLite `:memory:`. Covers both portal contexts, exact saved assignment reads, field boundaries, privacy, permissions, expiry, source cycles, replay, competing intent, database history guards and injected rollback failures. |
| Canonical journey | Newly accepted pickup and delivery riders deliberately activate duty, use actual waybills and retained hub/manifest scans, deliver to the buyer and keep completion separate from COD reconciliation and seller settlement. A later same-company move preserves the original cash holder and destination responsibility. Included in the focused run. |
| Shared regression | Initial **2,194 tests / 80,188 assertions** across logistics, courier, finance, notifications, admin, cross-role journeys, seeds and portal isolation: 2,192 passed; two older buyer-policy test scenarios needed correction. The final **68 tests / 458 assertions** pass after giving the signup smoke an adult date and keeping shared-profile closure feedback on a seller with its actual owned shop/order. Deletion blockers, authentication and private-reference checks remain asserted. No buyer runtime policy was changed. |
| Frontend | **80 helper/static-rendering checks**, all passing across courier/company, hub, account and financial presentation. The final TypeScript/Vite production build passes. Static review preserves each portal's own layout, labelled controls, responsive grouping, readable errors and local rider 8px surfaces. |
| Formatting and content | Scoped Pint passes for all 21 changed PHP files, including the two compatibility tests and final follow-up checks. Git whitespace checks pass. The local task guide and review artifacts remain ignored. |

The broad run began before the final conflict-response and saved-value checks;
those final changes pass the 219-case focused selection. The recorded broad run
is not represented as a fresh zero-failure full-suite run. Both corrected test
scenarios pass in the subsequent 68-case selection.

**Scoped engineering assessment: mutually accepted first company placement
2/10 → 8.5/10.** The request, exact offer, rider consent and protected placement
now form one verified local web journey. Overall rider, logistics and project
ratings are unchanged. Browser/device interaction, PostgreSQL migration and
simultaneous concurrency execution, and Azure rollout remain unverified.

Rollout requires the additive
`2026_10_15_050000_add_rider_company_offers_and_consents` migration after RC01.
No database reset or reseeding is required. The migration retains existing
requests, assignments and immutable history; schema rollback deliberately
requires a reviewed forward repair rather than discarding that provenance.

Local commits:

- `6fd3377` — `feat(logistics): retain assignment offers and rider consent`
- `57eb9b7` — `feat(logistics): require rider acceptance before first placement`
- `d2a01e2` — `feat(portals): add assignment offers and saved rider roster`

The final documentation/evidence commit is reported in the branch handoff.
The combined PR title/body remains a local draft at `.codex/rc02/PR.md`; it has
not been saved on GitHub. User publication, merge and deployment are separate.
The Flutter project and native joining contract are unchanged; RC03 is the
next separately authorized delivery.

## October 15, 2026 — RC03 native company joining and backend acceptance

Scope: `feat/rider-company-api-contract`, from reviewed current `main` at
`cf8df4a`, which includes the merged RC02 web workflow. This completes the
selected [native contract batch](rider-company-plan/03-native-contract-and-acceptance.md).
The Flutter repository, user publication and live rollout remain separate.

| Before | Current behavior |
|---|---|
| Native riders could read their existing operational placement but could not request a first company association. | Ten bearer methods expose eligible companies, owned applications, exact offer acceptance/decline/withdrawal, saved assignment and separate enrollment reconciliation without requiring a prior company/hub. |
| Company joining had no native discovery or dedicated permissions. | Account/Home discovery waits for the complete account/enrollment/command baseline. New eligible sign-ins receive explicit company read/write abilities; old, narrow and wildcard tokens gain no authority automatically. |
| A lost native enrollment response had no owned native recovery path. | The existing actor-scoped journal retains the original committed result/time. Changed intent conflicts, expired retry windows remain readable and company reconciliation cannot expose operational commands. |
| Native assignment lacked a distinction between original membership evidence and today's readiness. | Saved company/hub/vehicle, off-duty state and current operational eligibility remain separate. Original accepted-offer evidence and existing seeded membership are distinguished without inventing historical consent. |

The native adapter reuses RC01/RC02 services and the protected placement writer;
company administration remains in web Network. JSON acknowledgement must be
true and match the current request version, offer and terms hash. Source changes,
expiry, competing vehicle acceptance and current authority retain their shared
server-side checks. Native reads are bounded, reject unexpected fields and omit
private evidence. Owned company notices expose native destinations only with
explicit company read authority.

Consent, placement, immutable history, decision, notice intents and native command
result commit together. Final token/credential/account checks can roll back all
effects. Unique domain races return a conflict when no original command exists,
rather than a misleading missing-resource result. Enrollment retries the entire
transaction at most three times for detected deadlocks; it adds no uploads.
SQLite fault injection verifies rollback and retry orchestration, not actual
simultaneous PostgreSQL contention. The existing ordered locks and uniqueness
constraints remain in force.

| Verification | Evidence |
|---|---|
| Final shared backend regression | **676 tests / 12,083 assertions**, zero failures, errors or skips in isolated SQLite `:memory:`. Includes native authentication/settings, courier operations, RC01/RC02, resource eligibility, notices, seeded data and both accepted-rider journey variants. |
| Native boundaries and failures | Approved unassigned discovery, bounded directory/history, all terminal outcomes, strict counters/booleans/text, wrong role/owner/company, old/narrow/wildcard/revoked/expired credentials, changed sources/terms, original replay, seven-day retention, uniqueness races, competing decisions and failures at every linked enrollment writer. Top-level deadlock fault injection verifies a complete retry and the bounded failure path without partial records or duplicate notices. |
| Real account and parcel sequence | Native application, company web offer, exact native acceptance and deliberate duty precede real pickup, origin Bayan, Mother and destination Bayan custody. Another company's rider cannot claim the parcel. Buyer completion, original cash responsibility and seller settlement stay distinct. Seeded riders retain their original placement and native queue/duty access with no invented acceptance. |
| Executable wire contract | **108 schemas** compile; **25 published examples and 25 actual captures** validate with counter/consent rejection checks, covering all **10 company methods** and the **40-method** operational contract. The OpenAPI 3.1 document passes schema validation; **48 existing operations/handover response checks** also pass. |
| Final notice examples | The final test-only capture adds both owned company-request and saved-assignment notice targets; its subsequent focused run passes **1 test / 160 assertions**. This is additional evidence after the 676-case regression, not a second full-suite claim. |
| Frontend and formatting | **63 existing frontend checks** and the TypeScript/Vite production build pass. Scoped Pint passes for **14 PHP files**; Git whitespace checks pass. No web page, global style or Flutter source was changed. |

The first broad run found an incomplete-deployment sign-in compatibility failure.
Company discovery now waits for the account baseline too; the final regression
above includes that passing check. Earlier test-fixture problems were corrected
and retested rather than counted as successful checks.

**Scoped engineering assessment: native company onboarding backend/contract
2/10 → 8.5/10.** The backend now exposes the verified web joining rules with
bounded private reads, explicit authority, retained retries and a tested handoff.
This does not raise overall project or rendered mobile readiness. Actual
PostgreSQL migration/contention execution, Azure rollout, live scheduler/notice
delivery and Flutter/device acceptance remain unverified in this delivery.

The accepted [API and Flutter handoff](api/RIDER_COMPANY_API.md),
[executable schema](api/rider-operations.openapi.json) and
[sanitized examples](api/examples/rider-company/README.md) define deployment
prerequisites, old-token behavior, input limits, states and response recovery.
RC03 adds no migration: deploy the ordinary backend after RC01/RC02 additive
migrations, refresh routes/caches and sign in again. No fresh database, reseed,
role conversion or synthetic legacy consent is required.

Local implementation and acceptance commits:

- `6c6c43e` — `feat(rider-api): add company joining with scoped retries`
- `9d0607d` — `test(rider-api): verify accepted riders and seeded assignments`

The final contract/documentation commit is reported in the branch handoff.
The combined PR title/body remains a local draft at `.codex/rc03/PR.md`, not a
saved GitHub description. Stop at this backend handoff; user publication,
deployment and the separate Flutter implementation require their own evidence.

## October 15, 2026 — Seller Dashboard entry and navigation

Scope: `fix/seller-dashboard-navigation`, from current `main` at `a978e82`.

| Before | Current behavior |
|---|---|
| An approved seller could return to an old Shop URL after sign-in. | Seller sign-in opens Dashboard on root and seller-subdomain portals, clearing the retained destination. Other roles retain their existing sign-in behavior. |
| The sole shop had a separate page with an extra Open dashboard step. | Existing Shop URLs forward to Settings, which contains the actual approval, category, feedback, review history and existing correction/resubmission actions. |
| Settings displayed unconditional preferred-mall, active-store and auto-dispatch claims. | The shop panel displays its recorded review and activity state. Legacy resubmission remains available; restricted shops cannot open new work. |
| Financial overview, Seller proceeds and Finances appeared as separate menu entries above and below the work pages. | Dashboard comes first. One Finances entry groups Overview, Payouts and Sales report, with the current child visible and marked. The shop summary opens Settings. |

Overview follows recorded proceeds and payment evidence. Payouts retains the
order-level release/payment history. Sales report retains completed sales and
estimated shares, using its existing completion-date filters. These views keep
their original sources, date bases, ownership, promotional accounting and
separate shipping rules; this batch changes navigation and explanatory copy.

The Settings panel reuses the existing shop review presentation and submission
endpoint. Old URL redirects preserve flash feedback, and blocked-work messages
use the existing visible error channel. Missing shops still show an explicit
unavailable screen without creating a shop; conflicting ownership still rejects.
Pending account holding, private evidence authorization, reviewed corrections
and one-shop/category restrictions remain enforced.

Verification:

- Final focused authentication/shop/finance selection: **93 tests / 2,710
  assertions**, zero failures, errors or skips, using SQLite `:memory:`.
- Wider seller, shared portal, shop review, seeded seller and settlement
  selection: **379 tests / 5,763 assertions**, zero failures, errors or skips.
  This run preceded the final redirect-message refinement; the final focused
  selection verifies its rendered feedback. The selections overlap.
- **48 frontend checks**, the TypeScript/Vite production build, scoped Pint for
  eight PHP files and Git whitespace checks pass. The frontend checks cover
  grouped finance navigation and actual shop review feedback in Settings.
- The first message-retention assertion inspected session data after it had
  expired for the next request. The corrected acceptance checks the rendered
  Inertia feedback and exposed the previously invisible informational channel;
  the final implementation and test above pass.

**Scoped engineering assessment: seller navigation and landing experience
5/10 → 8.5/10.** One-shop administration and the three financial destinations are
clearer, with existing approval and money rules retained. Overall seller,
financial and project ratings are unchanged. Browser/device interaction and
Azure rollout remain unverified; no migration or database reset is required.

Implementation commits:

- `10c59a5` — `fix(seller): open Dashboard and keep shop reviews in Settings`
- `abdee6b` — `fix(seller): group finance pages under clear navigation`

The combined PR title/body remains a local draft at
`.codex/seller-navigation/PR.md`. It has not been saved on GitHub. The final
documentation commit is reported in the branch handoff; publication and
deployment remain user actions.

### October 15, 2026: buyer homepage advertisements and shortcut cleanup

Implemented on `fix/buyer-home-advertisements`, based on merged seller
navigation. The buyer homepage no longer shows the eight colorful service
shortcuts. New Arrivals and the sales/rating filters remain in the existing
product toolbar; the product feed, category navigation and purchasing rules
are preserved. Unused shortcut and sample-voucher response data are removed.

The advertisement area uses all five supplied Bagoo banner images as local
assets in the requested grid: a larger three-slide carousel on the left and two
smaller advertisements stacked on the right on desktop. Smaller screens place
the two supporting images in a two-column row beneath the carousel. The section
stays within the buyer content width, avoiding the oversized viewport-wide
presentation shown in the user feedback. There are no card borders, visible
caption bars or text footers. Previous/next arrows stay over the left and right
edges of the main image, with small slide indicators near the bottom. Each image
keeps its full aspect ratio; the carousel title remains available to screen
readers without a visible text panel. There is no automatic rotation. Empty
advertisement data renders no section; a single advertisement needs no carousel
controls.

The five original images totalled 12,379,230 bytes. Their optimized WebP assets
total **767,890 bytes**, a **93.8% reduction**, at 1880 by 880 pixels each. The
artwork retains its existing promotional text; it does not create campaigns,
activate discounts or change checkout totals. Clicking the artwork opens the
product feed. Vouchers remain available through the existing buyer account
pages; the advertisement has no separate text footer.

Verification:

- The initial **98 focused buyer backend tests / 2,656 assertions** pass with
  zero failures, errors or skips, using isolated SQLite `:memory:`. The selection
  covers buyer discovery/saved products and buyer access alignment. The later
  framing/grid refinements change only frontend presentation and this record.
- **46 existing frontend checks** pass again after restoring the grid across
  commerce, buyer access and buyer vouchers. The final TypeScript/Vite production
  build and Git whitespace checks pass; the unchanged PHP controller passed
  scoped formatting in the initial delivery.
- Server-rendered markup was inspected for guests and signed-in buyers and for
  empty, single, three-image and five-image advertisement data. The old shortcut
  labels are absent, the product feed remains available, three slide selectors
  and two supporting images render, and the arrows stay inside the main image.
  All five local image files and their dimensions were checked; a converted
  asset was also visually inspected for retained artwork.

**Scoped engineering assessment: buyer homepage advertisement clarity
4/10 → 8/10.** This covers the simpler hierarchy, supplied artwork, working
destinations and smaller image payload. Overall buyer/project readiness and
campaign accuracy are not raised by this visual change. Interactive browser,
device appearance and Azure rollout remain unverified; no migration or database
reset is required.

The final **requested-layout fit assessment is 5/10 → 8/10** for replacing the
oversized banner shown in the user screenshot with the compact grid while
retaining image-overlay arrows. This is a scoped screenshot/code/markup
assessment; final browser and device appearance still need user verification.

Implementation commit: `894498f` —
`feat(buyer): refresh homepage ads and remove shortcut tiles`.
The combined PR title/body remains a local draft at
`.codex/buyer-home-ads/PR.md`; it has not been saved on GitHub. Publication and
deployment remain user actions.

### October 15, 2026: advertisement management research and proposed deliveries

Prepared [the advertisement management plan](advertisement-plan/README.md) on
`docs/advertisement-management-plan`, based on `55f22a3`, which includes the
accepted buyer advertisement grid. This is a documentation proposal, not a new
advertisement writer, public campaign page or paid-ad capability.

Static integration audit:

| Inspected source | Finding and planned boundary |
|---|---|
| [BuyerHomeController](../app/Http/Controllers/Buyer/BuyerHomeController.php) | Supplies five static local artwork references with id/title/image. It does not choose content from saved approval, schedule or placement records. |
| [BuyerAdvertisements](../resources/js/Components/BuyerAdvertisements.tsx) | Uses three carousel slides and two supporting images; every artwork link currently targets `#product-feed`. The plan keeps the layout and replaces generic links with typed, validated destinations. |
| [Seller settings](../app/Http/Controllers/Seller/SellerDashboardController.php) and [storefront branding](../app/Http/Controllers/MarketplaceController.php) | Existing owner-scoped logo/banner/description edits remain ordinary branding. A shop banner is not permission to occupy the marketplace homepage. |
| [VoucherCampaignService](../app/Services/Vouchers/VoucherCampaignService.php) and [buyer collection](../app/Http/Controllers/Buyer/BuyerProfileController.php) | Reuse published offer revisions, funding/eligibility and owned saving/selection rather than creating discounts from an image or duplicating campaign accounting. |
| [Web routes](../routes/web.php) and inspected models/controllers/migrations | Product, shop, category/search and owned voucher destinations exist. No dedicated advertisement authoring/scheduling resource or public editorial collection/offer-terms destination was identified in this audit; those are proposed work. |

The [official research notes](advertisement-plan/REFERENCES.md) distinguish
merchant storefront decoration, seller-managed paid shop ads and controlled
homepage/display inventory. They do not claim access to internal marketplace
staffing or a universal approval process. Bagoo's proposal assigns marketplace
editorial ownership to existing Platform Admin, preserves seller branding and
adds later review of exact seller-submitted revisions.

The four proposed batches are AD01 platform management, AD02 buyer destinations
and scheduled display, AD03 seller submissions, and AD04 non-billing measurement
and combined release checks. AD01 + AD02 are the recommended first branch, with
separate gates; later batches are not automatic authorization. Proposed dated
windows remain planning assumptions within the November 20 delivery boundary.

The plan includes realistic upload/content limits, position conflicts, read-time
expiry without scheduler dependence, unavailable targets/assets, truthful offer
claims, stale/duplicate writes and an additive rollout/disable path. Existing
11.11/discount/free-shipping artwork needs supported offer terms before future
managed publication; the plan neither changes today's images nor activates a
discount. No application code, schema, seed data or operational behavior changed.
No runtime readiness score is raised by documentation coverage.

Documentation validation checked **431 local file links across ten documents**
with no missing targets. Batch references/dependencies and whitespace were
reviewed. The four linked official research sources were consulted; their market,
date and evidence limits are recorded in the references. Plan commit:
`801e737` — `docs(advertisements): plan ownership, destinations and delivery`.

UI/backend implementation tests, database concurrency, browser/device acceptance
and Azure deployment remain work for selected future batches. No runtime tests
or production build were run for this documentation-only proposal. The routing
commit is reported in the planning branch handoff. The PR title/body is a local
draft at `.codex/advertisement-plan/PR.md`; it has not been saved on GitHub.

### October 15, 2026: managed marketplace advertisements and matching destinations

Implemented the selected **AD01 + AD02** batch on
`feat/advertisement-management`, based on `7ae1436`. The preceding planning
entry describes the proposal at that time; this entry records implementation.
Seller submissions/review (AD03), statistics (AD04) and paid advertising remain
separate scope.

| Before | Current behavior |
|---|---|
| Five fixed local images pointed at the generic product feed. | Eligible managed publications retain their exact internal product, shop, category, collection or offer destination. |
| No advertisement authoring or publication history. | Current Platform Admin can upload private artwork, retain draft revisions, preview desktop/mobile arrangements, publish/schedule, pause, resume, end and archive with retained decisions. |
| Artwork did not have a managed publication period or capacity reservation. | Three main and two side positions share a serialized calendar. Overlapping reservations reject, adjacent periods can meet, pauses retain reservations and ending releases them. |
| A sale image had no linked terms validation. | Offer artwork requires an actual published voucher revision, compatible products and dates, and manual claim review. Changed or exhausted offers are suppressed on fresh reads. |
| Missing supporting content could enlarge the remaining main image. | The accepted grid keeps image-overlay controls and no caption bars; single, partial, side-only, broken-image and empty cases stay bounded or disappear. |

The additive schema retains advertisement identities, original/optimized assets,
immutable content and action evidence, exact publication windows and effective
end decisions. Every writer reloads current authority, checks the submitted
version and records an idempotent request. Stale editors keep entered values but
block publication until reload and review. New drafts do not silently replace
live content; failed uploads or database writes retain the working publication
and clean only proven unreferenced new files.

Uploads decode real JPEG/PNG/WebP content, enforce the documented 5 MiB and
dimension/shape limits, retain private originals and deliver metadata-stripped
WebP renditions through guarded routes. The PHP image now includes WebP support;
an outdated runtime gets a clear upload error. Local work directories and private
task guidance are excluded from the Docker build context. The supplied artwork
importer creates incomplete, unpublished candidates; no sale, discount or
homepage placement is automatically activated.

Fresh homepage, click and collection/offer reads enforce the saved half-open
period, current eligibility and offer revision. They do not rely on a scheduler
or shared presentation cache. An old publication link cannot silently resolve to
a replacement; unavailable pages give a useful browsing choice and withhold the
old offer/product details. Previously public optimized images retain historical
access, while private originals and canceled, never-started artwork stay private.
Already-open pages need a fresh request to reflect later changes.

Guest and pending-buyer browsing remains available. Ads never claim/apply a
voucher, mutate the Shopping Bag, reserve stock or bypass checkout approval.
Linked offers open readable terms, including the real cap and minimum, before
the existing owned voucher flow. Promotion product cards do not add the legacy
unconditional delivery badge. Existing seller branding and commerce policies
retain their original authority.

Verification:

- **38 advertisement backend cases / 497 assertions**, included in a passing
  **339-case / 4,344-assertion** buyer, voucher, shop-review and portal-access
  regression using SQLite `:memory:`. No failures, errors or skips.
- Checks cover private drafts and previews, forged/stale/replayed commands,
  publication rollback, failed storage cleanup, immutable history, start/end
  boundaries, reservation conflicts, replacement links, pause/resume/archive,
  current restrictions, offer changes/exhaustion and a missing installation.
  Real formats and the exact 5 MiB source boundary pass; corrupt, disguised,
  unsupported, oversized and incorrectly shaped images reject.
- **279 frontend/deployment checks** pass. After the final stale-action guard,
  the **13 advertisement frontend checks** pass again, alongside the final
  TypeScript/Vite production build. These include actual component rendering
  and an interaction harness for carousel navigation and failed images.
- The PHP Docker image rebuild, scoped Pint and Git whitespace checks pass.
  Desktop/mobile preview widths use local container queries; markup/static
  checks do not establish rendered browser/device appearance.

**Scoped engineering assessment: advertisement management and destination flow
2/10 before → 8/10 locally verified.** This measures the managed admin-to-buyer
feature, not whole buyer/admin/project readiness. PostgreSQL publication races,
live migration and Azure/browser/device acceptance remain unverified. Marketing
claims inside artwork still require a responsible manual review; there is no
automated image-text verification or new buyer-segmentation promise.

Use [the management guide](ADVERTISEMENT_MANAGEMENT.md) for additive deployment,
private files, draft import and the display disable switch. Normal browsing
continues without installed or published advertisements. No development or
production database was reset or migrated during verification; the rebuilt
image was exercised in disposable application containers with SQLite memory.
Deployment and publishing verified artwork remain user release actions.

Implementation commits:

- `aafd6a2` — `feat(advertisements): retain private artwork and publication decisions`
- `01022d5` — `feat(advertisements): connect admin publishing to matching buyer pages`

The combined PR title/body remains a local draft at
`.codex/advertisement-management/PR.md`; it has not been saved on GitHub. The
documentation commit is reported in the branch handoff. No commits were pushed.

### October 15, 2026: owned seller promotions and exact platform review

Implemented the selected **AD03** on `feat/seller-promotion-review`, based on
`3e70485`, which contains the accepted AD01 + AD02 work. Statistics (AD04) and
paid advertising remain separate scope.

| Before | Current behavior |
|---|---|
| Sellers had ordinary shop branding but no marketplace promotion request. | An eligible seller saves private artwork for its single shop, owned product or published shop-funded offer, then submits the saved revision. |
| No seller submission, review feedback or withdrawal history. | Immutable submissions and decisions retain approval, changes requested, rejection, replacement and withdrawal. Review state and actual display state appear separately. |
| No exact seller approval to bind an admin placement. | Platform Admin reviews the submitted artwork, destination, dates, shop name/category and current products/offer terms. Approval alone does not publish or reserve a position. |
| Changing reviewed shop scope had no promotion approval contract. | A changed reviewed name or category invalidates reuse of the old submission, including after the shop becomes eligible again. Ordinary logo, banner, description and contact edits preserve approval and a valid live promotion. |
| No seller controls to stop approved promotional content. | The owner may withdraw one request or stop the promotion, ending remaining placements while retaining evidence. Admin archive also resolves open submissions. |

Admin placement and replacement stay within the reviewed requested period and
existing slot capacity. A replacement links to its original publication without
rewriting the original dates. Failed replacement restores the previous booking;
stale seller edits and admin decisions reject without erasing entered values.
Seller and admin writers share the calendar transaction, source-version check
and idempotent action evidence. New drafts supersede pending requests while an
earlier approved live revision retains only its original valid conditions.

Root and seller-domain routes recheck current role, original owner, shop and
target eligibility. Foreign drafts, previews, images, products, vouchers and
forged publication fields reject server-side. Private image sources stay off
the response. The existing durable notification pipeline records committed
submission/decision delivery intents, retries failed delivery and resolves only
authorized portal links. Current restriction, withdrawal, changed scope and
unavailable offer checks also apply to fresh public advertisement reads.

Verification:

- **73 advertisement backend cases / 927 assertions**, including 35 new seller
  review/portal cases, pass in SQLite `:memory:`. The complete selected seller,
  notification, buyer discovery/voucher/checkout approval, shop review,
  moderation, closure and shared portal-access regression passes **645 cases /
  7,842 assertions**, with no failures, errors or skips.
- Checks cover the seller-to-review-to-placement-to-buyer path, stale/replayed
  writes, foreign revisions/offers, text and upload rejection, immutable review
  history, shop category changes, retained approval after branding edits,
  failed placement rollback, notification outage/retry and missing installation.
- **289 frontend/deployment checks**, including **23 advertisement checks**,
  pass. The TypeScript/Vite production build, scoped Pint and Git whitespace
  checks pass. Interaction harnesses verify stale guards and saved-version
  rendering; they do not establish browser/device appearance.

**Scoped engineering assessment: seller promotion requests and platform review
0/10 before (absent) → 8/10 locally verified.** This does not raise whole seller,
admin or project readiness. PostgreSQL races, deployed migration behavior,
browser/device appearance and Azure acceptance remain unverified. Marketing
claims still require responsible manual review; no automatic image-text check,
traffic promise, billing or statistics is implied.

The migration is additive and retains review evidence. No development or
production database was reset or migrated; application checks used disposable
containers and SQLite memory. Use [the management guide](ADVERTISEMENT_MANAGEMENT.md)
for the seller/admin flow and existing deploy, backup and disable procedures.

Implementation commits:

- `471b8ab` — `feat(advertisements): require owned seller submissions and exact review`
- `574d597` — `feat(advertisements): add seller requests and admin review screens`

The combined PR title/body remains a local draft at
`.codex/seller-promotion-review/PR.md`; it has not been saved on GitHub. The
documentation commit is reported in the branch handoff. No commits were pushed.

### October 16, 2026: recorded advertisement performance and combined acceptance

Implemented the selected **AD04** on `feat/advertisement-reporting`, based on
`c262473`, which contains the accepted seller-review delivery. AD01–AD04 feature
implementation is now present in this checkout: platform authoring, buyer
display/destinations, seller review and modest reporting. No further coding
batch is named in the advertisement plan. Publishing, deployed acceptance and
the runtime limits below remain distinct from local implementation completion.

| Before AD04 | Current behavior |
|---|---|
| No recorded advertisement performance. | Platform Admin and original sellers can inspect recorded views, clicks and click-through rate by exact revision, placement and inclusive Manila date window. |
| A response or hidden slide had no measurement contract. | A selected image needs to load and remain half visible for one uninterrupted second in a visible tab. Failed images, private previews and hidden slides do not earn views. |
| No bounded click or replay handling for measurement. | Deliberate image activation is separate from arrows/dots, and page/revision/type deduplication prevents repeat counts. Navigation does not wait for tracking. |
| No event retention or honest unavailable state. | Seven-day anonymous raw-event maintenance leaves atomic daily totals and publication/review evidence intact. Missing or failed reporting is distinct from zero, and collection can be disabled independently. |

The new event route accepts only bounded token/type JSON with web CSRF protection
and a hashed-source rate limit. Encrypted tokens last 30 minutes; event writers
recheck age and current publication state under the shared calendar boundary.
The unique page/revision/type hash and daily publication/date key protect replay
and aggregation. Both the raw record and counter increment commit together, so
failed storage cannot consume the duplicate key without retaining its count.
Safe failure references log exception type/code without request payloads or SQL.

Raw rows contain no account identifier, email, legal evidence, raw network
address, browser fingerprint or event token. Each mounted homepage attempts a
revision/type once, and a rotated session cookie is preferred over an old page
CSRF token. Server ownership checks apply to report choices, totals and every
advertisement/revision/placement filter. Results are paged, preserve original
publication periods and separately explain an early stop or replacement.
Zero views produce an unavailable rate rather than division by zero. Counts
are not unique people, certified traffic, attributed orders or revenue; failed
delivery and unsupported observation are not reconstructed.

Verification:

- The final selected SQLite `:memory:` regression passes **669 cases / 8,449
  assertions**, with no failures, errors or skips. It covers advertisement,
  seller, notification, buyer discovery/voucher/checkout approval, shared access,
  shop review, moderation and account-closure behavior.
- Included are **97 advertisement cases / 1,534 assertions**: the retained
  AD01–AD03 gates, **22 measurement/reporting cases**, and **two combined release
  journeys**. The journeys exercise a platform collection, shop feature and
  actual published voucher, future start, expiry, pause, removed target, seller
  changes requested, resubmission, exact approval/publication and withdrawal.
  Events are generated only against isolated test fixtures; no production
  traffic, orders or voucher redemptions are fabricated.
- Checks cover malformed/tampered/future/expired tokens, exact 2,048-character
  and 4,096-byte limits, CSRF, rate rejection, current restrictions, replay,
  mid-write rollback, retained statistics, scheduled pruning, safe error
  references, foreign IDs, 90-day/year boundaries, pagination and replacement
  history. Retained totals also block destructive migration rollback.
- **305 frontend/deployment checks** pass, including **39 advertisement checks**
  with load/intersection/continuous-time/visibility/error/unmount cases, replay
  prevention, failed transport, carousel versus image activation, rotated CSRF,
  saved-revision reports, zero/unavailable states and owned filter choices.
  The final TypeScript/Vite production build, scoped Pint and Git whitespace
  checks pass.

**Scoped engineering assessments:** recorded advertisement measurement/reporting
**0/10 before (absent) → 8/10 locally verified**; the combined advertisement
feature **8/10 before AD04 → 8.5/10 locally verified**. This is not a rating of
the entire buyer, seller, admin or project. The remaining uncertainty is actual
browser/device appearance, PostgreSQL contention, deployed migrations and
scheduler operation, Azure acceptance and owner review of real marketing claims.
The PostgreSQL design was reviewed for lock ordering, unique conflict handling,
atomic counters and retention beyond token expiry; SQLite does not prove its
runtime contention behavior. No browser automation, production deployment or
development/production database migration/reset was performed.

Use [the management guide](ADVERTISEMENT_MANAGEMENT.md) for deployment, independent
display/measurement switches, raw-event maintenance and the release demonstration.
Daily maintenance requires an actually running Laravel scheduler; registered
jobs alone do not prove that it runs. Paid advertising, auctions, ad balances,
sales attribution and automated marketing-text verification remain outside the
four-batch plan.

Implementation commits:

- `76356e6` — `feat(advertisements): retain anonymous activity and scoped performance`
- `670e537` — `feat(advertisements): measure visible artwork without blocking shopping`

The combined PR title/body remains a local draft at
`.codex/advertisement-reporting/PR.md`; it has not been saved on GitHub. The
documentation commit is reported in the branch handoff. No commits were pushed.

### October 16, 2026: consistent notification previews and portal pages

Implemented on `fix/portal-notifications`, based on merged `main` at `beda044`.
The buyer notification interaction now applies to every signed-in web role,
while each portal retains its own layout and appearance.

| Before | Current behavior |
|---|---|
| Only buyers could open a compact recent-updates preview. Other bells navigated immediately to the full list. | Buyer, seller, courier, company administrator, hub handler and Platform Admin bells open the same bounded owned preview with a separate “View all notifications” action. |
| Non-buyer history used an isolated header and dropped the operational navigation. | Buyers keep the marketplace shell; riders keep their sidebar, bottom navigation, account controls and actual duty/assignment; sellers, hub staff and admins keep their existing dashboard shell. |
| Limited workers used the same isolated history presentation. | Applicants and restricted workers retain the account gateway and their owned updates without a duty switch, operational navigation or new operational permission. Buyers retain their existing marketplace application behavior. |
| Failed opening could dismiss the buyer preview, and preview reads left an already-open history list stale. | Failed actions retain visible feedback and retry controls. Successful preview reads refresh only the current notification list and summary when that page is open. A depleted later unread page returns to an existing page. |

The shared preview uses the installed accessible popover component for floating
placement, focus and dismissal. Its width and height are bounded to the viewport;
the update rows scroll independently of the close and history controls. Buyer
corners remain larger and rider controls retain explicit 8px corners. Empty and
unavailable full-page panels center their explanatory content, and notice dates
stack beneath titles on narrow screens.

Preview loading remains read-only. Only returned owned rows rendered in the
opened panel are submitted to the existing atomic acknowledgement service, with
a six-ID request limit. Unseen updates, unsupported notice types and other
accounts remain unread. Closing, reopening or changing accounts cancels obsolete
work; repeated taps cannot start duplicate navigation. Current authorization and
safe destination resolution still run on the server. Session expiry, unavailable
storage and network failures retain recoverable feedback. Notification sources,
custody, account approvals and financial authority are unchanged.

Verification:

- **242 backend cases / 4,019 assertions** pass with zero failures, errors or
  skips in isolated SQLite `:memory:`. This includes **89 notification cases**,
  shared ordinary/privileged portal access and buyer message-read regressions.
- The **42 new portal cases / 1,507 assertions** cover each role/persona on root
  and portal hosts, bounded private previews, only-displayed acknowledgements,
  original retry times, late unseen updates, atomic foreign-ID rejection,
  malformed IDs, 15 held-worker states, actual rider duty and company/hub scope,
  unavailable storage, closed/unsupported accounts and shrinking unread pages.
- The final combined JavaScript run passes **326 checks**: **317 frontend** and
  **nine deployment-helper checks**, including **21 new notification checks**.
  Rendered component markup covers every portal with populated, empty and
  unavailable lists. Controlled state/effect checks cover preview activation,
  rendered-only reads, cancellation/reopening, account changes, focused list
  refresh, restored counts, duplicate taps and session/server/network recovery.
  These checks do not launch a browser or establish actual device appearance.
- TypeScript/Vite production build, scoped PHP formatting and Git whitespace
  checks pass. An earlier deployment-helper run encountered a host Node shared
  library startup error; its six-case focused retest and the final complete
  JavaScript rerun pass without a deployment-script change.

**Scoped engineering assessment: web notification consistency 5/10 → 8.5/10
locally verified.** The improvement is consistent entry, retained portal context
and recoverable interactions across roles. This does not increase whole-project,
security, mobile or deployed-readiness scores. Browser/device appearance and
Azure acceptance remain unverified. No native API or Flutter implementation,
dependency, schema migration, database reset or production deployment is included.
Use the existing [deployment and verification workflow](WEB_AUTH_DEPLOYMENT.md)
after user publication; this delivery adds no migration or reseeding requirement.

Implementation commits:

- `0e458ea` — `fix(notifications): support owned previews across web roles`
- `260bad3` — `fix(notifications): keep previews and history inside each portal`

The combined PR title/body remains a local draft at
`.codex/portal-notifications/PR.md`; it has not been saved on GitHub. The
documentation commit is reported in the branch handoff. No commits were pushed.

### October 16, 2026: connected core-flow acceptance and commerce recovery

Implemented on `fix/core-flow-acceptance`, based on merged `main` at `4b9f610`.
The review follows one purchase through commercial state, recorded custody,
counted COD and evidenced seller payment, with separate retry, return and
counter journeys. It fixes confirmed defects rather than changing the
documented commercial or financial policy.

| Before | Current behavior and evidence |
|---|---|
| The existing delivery journey ended at buyer confirmation; financial gates were exercised separately. | Four connected HTTP cases now begin with the Shopping Bag and a real checkout token, pass through seller fulfillment, both riders, Bayan/Mother manifests, buyer confirmation, counted cash handovers, reconciliation and seller payment. Buyer-first and cash-first orderings run with and without funded offers. |
| A saved retry approval accepted the same token with a changed failed-attempt reference. | Replay must name the original retained attempt. Stored fingerprints and approvals are unchanged. Two failing reproductions now reject changed references, while an exact original replay remains valid after a second failure. |
| Exception operations and settlement lacked a joined acceptance gate. | Five connected cases cover retry through payment, original replay after another failure, refused/third-failure returns through the Mother Hub to seller receipt, and secure counter collection with reconciliation before buyer confirmation. Returns never manufacture collection, completion or seller payment. |
| Self-pickup checkout hid required address fields and replaced part of the buyer address when selecting a hub. | Both delivery methods expose the required recipient address, barangay and postal code. Hub selection stays separate, preserving the recipient snapshot, coordinates and landmark; the server still checks actual hub coverage. |
| Checkout and receipt confirmation could queue duplicate requests; tracking copy announced success before the clipboard finished. | Synchronous pending guards retain safe retry behavior. Receipt actions recheck the current order and permission. Copy feedback waits for success and cannot close a later order's QR dialog. |
| Checkout advertised unsupported SMS, fixed counter hours, escrow, GPS guarantees and an incomplete transport chain. | Instructions describe the existing private claim-code flow, actual order tracking, separate collection/payment and the required Mother Hub. Delivery choices use native radio controls; the installed dialog component supplies confirmation semantics. |

The normal reference purchase uses PHP 320.07 of merchandise and PHP 50.00
shipping. The product split remains PHP 32.01 platform commission and PHP
288.06 gross seller share. Funded offers separately record PHP 20.00 shop
expense and PHP 10.00 platform expense, without including shipping in the
commission basis. A failed payment writer rolls back its evidence, payment and
funding expenses before the same legitimate request retries successfully.

Verification:

- The completed isolated SQLite `:memory:` run reports **4,318 passed,
  two failed, 108,898 assertions**. The two failures were old expectations that
  inactive/suspended buyers could not read their own profile. The policy,
  controller and access-service sources were unchanged from `4b9f610`; the
  documented exception and existing account tests require that read access.
  Correcting only those expectations preserves denied generic writes and all
  restricted nonbuyer reads. The focused profile/access/settings retest passes
  **123 cases / 2,524 assertions**, including both corrected cases and the
  unchanged rejection cases. All 4,320 original case identities are covered by
  the completed run plus the current profile retest; production source is
  unchanged between them. This is not a new single all-green full-suite run.
- The complete run includes **nine new connected cases / 1,711 assertions**:
  four normal journeys and five exception/replay cases, all passing. Their
  transactions and custody records come from actual Laravel HTTP requests,
  with initial account/network eligibility as fixtures. They are not
  browser-driven tests.
- All **334 frontend/deployment checks** pass, including eight new actual-page
  callback/state checks. They cover empty-address self-pickup, retained address
  and pins, field limits and associated errors, review agreement reset,
  duplicate submission, receipt validation/retry and stale handlers, and
  clipboard rejection and delayed selection changes. Existing server rendering
  and flow checks remain included.
- The production TypeScript/Vite build, scoped PHP formatting and Git whitespace
  checks pass. No schema or dependency change is included.
- The first diagnostic run used an older local PHP container without WebP
  support required by the existing advertisement code. That incomplete run was
  stopped after identifying the environment fault. The completed
  regression uses a disposable container built from the existing Dockerfile, with WebP
  enabled and dedicated storage/configuration paths. Development and production
  databases were not used for tests.

These are scoped engineering assessments, not coverage percentages:

| Area | Before | After | Limit |
|---|---:|---:|---|
| Connected purchase/exception verification | 6/10 | 8/10 | Joined custody, cash and payment evidence is established locally; runtime/browser deployment is separate. |
| Buyer/seller checkout and action feedback | 6.5/10 | 8/10 | The self-pickup blocker, misleading copy and pending/error gaps are corrected; rendered device behavior is unverified. |
| Retry approval replay consistency | 7/10 | 8.5/10 | Changed references reject and original historical retries remain valid; PostgreSQL contention was not exercised. |

The component harness intentionally stubs effects and does not mount a browser
DOM. It does not prove keyboard traversal, focus restoration, native browser
validity enforcement or actual phone appearance. Existing buyer layouts render
domain `flash.error`; the new receipt callback check specifically exercises
validation feedback. The new reverse-route cases use one shared Mother Hub;
broader existing routing tests remain part of the complete regression.

No Azure deployment, browser automation, phone/camera/GPS acceptance or
PostgreSQL concurrency test was performed in this branch. Prior native
duty/claim deployment evidence does not certify these web changes. Whole-role,
security and whole-project readiness ratings are unchanged. Buyer-requested
post-delivery returns/refunds, wallets and ordinary mobile-write activation
remain separate scopes.

The executable dependency currently requires Laravel `^13.17`; the `AGENTS.md`
stack summary still names Laravel 12. This review uses the installed/locked
dependencies and does not change either the dependency constraint or lifecycle
policy.

## October 16, 2026: responsive navigation and search planning

The user reported marketplace pagination showing a second page but no apparent
screen change on selection, and requested a system-wide plan for faster
navigation and product search. The screenshot is evidence of the report, not a
reproduction or a verified root cause. No seed reset or runtime repair was
performed during this planning review.

Source inspection found:

- The active buyer home and search controllers use `BuyerDiscoveryService`,
  with 18 and 24 products per page respectively, deterministic ID tie-breaking
  and last-page recovery. `Buyer/Home` and `Buyer/Search` render paginator URLs
  with `preserveScroll`; actual request, response IDs and viewport behavior
  need to be distinguished in the first implementation batch.
- The existing buyer discovery test covers filtered search pagination. That
  source coverage does not prove the reported home/paging UI path or Azure
  deployment works; no application test suite was run for this document change.
- The shared product card has no enabled page prefetching. The installed
  Inertia React 2.3.27/core implementation supports it. Header search currently
  submits a route and initializes its input from the URL; responsive typing,
  late-response handling and history synchronization need explicit acceptance.
- Product detail responses include owned Bag quantities/offers, and shared
  Inertia props include account/hub data and feedback. The middleware retains
  private/no-store headers. A cache must isolate this context separately from
  HTTP caching.
- `ChatController::getMessages` changes unread state during a read. Therefore
  indiscriminate GET/link prefetching is unsafe; activity, session feedback and
  operational secrets require explicit exclusions or a verified read redesign.

The [performance plan](performance-plan/README.md) proposes five bounded
deliveries: PF01 working product pagination, responsive search and the initial
shared policy; PF02 buyer/seller navigation; PF03 courier web/logistics; PF04
admin; PF05 performance measurement and combined acceptance. Each plan keeps
portal style, current authorization, mutation freshness, meaningful failure
feedback and independent acceptance gates. Bounded idle preparation targets one
likely next page rather than an entire catalogue. Current operational queues,
private evidence, secret codes, acknowledgements and financial decisions are not
treated as cacheable authority.

**Status: planning only.** Documentation/source-reference checks are recorded in
the branch handoff. The reported pagination behavior remains unresolved until
PF01 is selected and verified. No runtime speed, whole-system readiness or
before/after feature rating is raised by this documentation delivery. Browser,
Azure, Flutter, PostgreSQL concurrency and production load checks were not run.

## October 16, 2026: verified architecture, stack and presentation documentation

Implemented on `docs/verified-architecture-stack`, from merged `main` at
`6d2ff6a`. This is a documentation review; application and deployment behaviour
are unchanged. [The review record](DOCUMENTATION_REVIEW.md) explains the scope,
corrections, source evidence and verification limits.

The reference stack now identifies Laravel 13.32.0, React 18.3.1, TypeScript
5.9.3, Inertia's version-2 adapters, Tailwind 3.4.19 and Vite 6.4.3 from the lock
files. A read-only local runtime probe confirms PHP 8.4.24 and the installed
Laravel/Inertia/Sanctum versions; twelve inspected frontend package installations
match their locks. Compose defines PHP-FPM, Nginx and PostgreSQL 16. Allowed
ranges, resolved packages, container tags and live server state are distinguished.
The earlier dated notes about the Laravel-12 instruction mismatch remain
historical; the current instruction and reference files now name Laravel 13.

[TECH_STACK.md](TECH_STACK.md) explains each technology and optional supporting
integration. [ARCHITECTURE.md](ARCHITECTURE.md) maps the shared Laravel services,
React/Inertia web pages, bearer-token JSON API, transactions, storage and actual
background/deployment boundaries. [TECH_STACK_PRESENTATION.md](TECH_STACK_PRESENTATION.md)
provides a plain-language opening, six-part outline, practical rationale,
request example and questions about REST-style interfaces, authentication,
container hosting, persistence and verification.

Root setup/context, duplicate agent references, test guidance, physical-logistics
routing and deployment references now point to those sources. Old credentials,
one-click demo claims, separate courier-role labels, role conversion, simulator
routes and automatic delivery-time payout/fixed-rider-fee claims are removed
from current overviews. The native account guide now matches the required-sex
validator and options controller; registration postal code remains optional and
four digits when supplied. Historical/source material and intended branch plans
retain their separate authority.

Verification:

- All Markdown documents in the tracked inventory plus new guides are scanned
  for stack/architecture drift. Repository-wide local-link/heading and changed
  source-reference checks pass; changed Markdown code fences are balanced.
- Twenty locked PHP/frontend package versions are checked, with the local
  runtime and twelve frontend installations above. Composer manifest/lock
  validation passes. An initial Git ownership warning was avoided for the final
  check using process-scoped safe-directory settings, with no global Git change.
- Laravel's isolated route listing contains **733 application route registrations**;
  all **seven endpoint examples** in the architecture match method/path pairs.
  No operational HTTP requests are made, and retired simulator paths are absent.
- A guarded isolated SQLite `:memory:` migration inspection produces **98 tables**.
  [SCHEMA.md](SCHEMA.md) inventories every table exactly once and verifies selected
  columns in ten core tables. Actual current variants, identity fields, custody
  sources, COD and settlement records replace the old partial schema claims.
- All **nine demo account addresses** and the documented seeded password match
  the current seeders. Helper command references match `bagoo.sh`; a fresh-clone
  setup was not executed. Git whitespace and documentation-only change checks pass.

**Scoped documentation assessment: 4/10 before -> 8.5/10 after.** Current stack,
request boundaries, source-backed data context and presentation explanations are
clearer and traceable. This is a documentation assessment, not a higher runtime,
security, role or project-readiness rating.

No application source, dependency, route, migration, seed record or server
configuration is changed. Functional suites and the asset build were not rerun
for unchanged runtime code. No development/production database read/reset,
PostgreSQL test, Azure connection/deployment, real provider/mail call, visible
browser, screenshot or external Flutter-repository inspection was performed.
Mermaid structure was checked without browser rendering. Live environment,
background processes, HTTPS/firewall/backups, mail delivery and native device
behaviour retain their separate acceptance requirements.

## October 16, 2026: PF01 buyer pagination and responsive search

Implemented the selected [PF01 plan](performance-plan/01-buyer-pagination-and-search.md)
on `fix/buyer-pagination-and-search-performance`, from merged `main` at
`7878629`. This updates buyer catalogue continuity and navigation preparation;
PF02-PF05 and the other portals' data-prefetch allowlists remain separate work.

### Result and source evidence

| Concern | Before | Implemented result |
|---|---|---|
| Product pagination | Home/search used server-origin paginator URLs, preserved the old viewport and represented unavailable links with `#`. | `BuyerDiscoveryService` emits origin-relative paths. Shared `CataloguePagination` displays a range/current page, actual disabled controls, current-origin links, last-page URL correction and focus/scroll to the changed grid. |
| Filter/history consistency | Some controls were initialized from incoming values without synchronizing later changes; the header cleared by returning to the homepage. | Search and price/tab controls follow returned values and history. Query changes reset paging; clearing search preserves other filters, including minimum zero. The homepage rating option now uses a supported integer threshold. |
| Typing and submission | Header search waited for submission and had no bounded preparation path. | `CatalogueSearch`/`useCatalogueSearch` debounce 300ms, prepare a settled query off the search page, and update live Search with coherent partial props. Enter submits immediately; one-character explicit searches, 100-character limits, composition, paste, cancellation and late-response guards remain explicit. |
| Product/page intent | Shared cards and paginator links did not prepare their destinations. | `CatalogueLink` uses 150ms pointer/focus intent and non-blocking touch intent. Visible paging controls can prepare one next page after 1s of inactivity, when connection hints permit it. Page-code preparation remains cancellable before navigation. |
| Cost and cache identity | No shared catalogue policy governed the proposed speculative reads. | `CataloguePrefetch` wraps the existing Inertia cache: one speculative page-data request, a 10s lifetime, 20 retained entries, exact canonical query/page identity, account/context/version boundaries and normal-request fallback. Existing Vite/static-asset requests are a separate mechanism. |
| Feedback/recovery | An unsuccessful page click could leave the previous screen without a useful explanation. | Actual catalogue navigation failures have a visible dismissible retry for the requested destination. Search errors distinguish invalid input from transport trouble. Unsupported/background reads cannot consume pending session feedback. |

The reported screenshot's original runtime cause was not reproduced in a
browser. Server tests establish that the active home/search handlers select
different product IDs on later pages. This delivery fixes the observed URL,
control/state and viewport weaknesses, and verifies both rendered page variants;
it does not invent a missing-seed diagnosis or certify the Azure screenshot.

### Boundaries and freshness

- `HandleInertiaRequests` permits this marked speculative path only for reviewed
  buyer home/search/product GET handlers and a guest/buyer context. Pending
  feedback, unsupported actions, worker accounts and disabled configuration
  require an ordinary visit instead. Session flash is preserved; private/no-store
  headers remain. An identifying client header is not an authorization token.
- New preparations stop for hidden/offline/Save-Data/very slow clients and yield
  to foreground work. Missing connection hints disable idle warming. Failure
  cooldown, cache/descriptor caps and one-shot idle scheduling prevent loops.
- Mutations, account/context changes, returning to a page/tab and supported
  cross-tab signals discard prepared state. Generation checks, cancellation
  and identical request descriptors prevent late/foreign cached reuse.
- Product detail responses contain owned Bag quantities/offers; their entire
  response is scoped accordingly. Checkout, stock, prices, eligibility and
  voucher decisions still use fresh server validation. Preparation reserves
  nothing and cannot perform a custody, message-read or financial action.
- Partial Search responses retain the coherent products/related-products/filter
  set. Lazy category props skip their count aggregation when not requested;
  an isolated check verifies a smaller response than the corresponding full
  search. Controlled transport checks verify matching in-flight/cache reuse,
  not an independently measured browser-network speed result.
- The installed Inertia 2 update callback is notification-only. Superseded
  search responses stop through a known handled cancellation before page
  application; HTTP and cached response paths handle that cancellation without
  a failure notice. A false callback result is not treated as cancellation.
- `config/catalogue.php` and `.env.example` define
  `CATALOGUE_PREFETCH_ENABLED`. After normal deployment/config refresh and an
  updated ordinary response, `false` disables speculation while ordinary
  browsing works. See the [acceptance/configuration guide](performance-plan/ACCEPTANCE.md).

### Verification and assessment

- Selected buyer, notification, advertisement and session/access backend checks:
  **945 passed, 15,102 assertions**, inside Docker with SQLite `:memory:` and
  a separate task-local storage directory. No development/production PostgreSQL
  database was used or reset.
- Full frontend suite: **362 passed**, including actual home/search page
  rendering, paging controls, query/history synchronization, typing/composition,
  quick submits, cancellation, cache expiry/caps, account/hub changes,
  slow/hidden/offline clients, touch/focus and navigation retry feedback.
- TypeScript and the production Vite build pass through the existing Docker app.
  The initial host build could not remove root-owned generated assets. A host
  backend run hit its 128MB cap, root-owned test fixtures and missing GD; the
  isolated Docker run with 512MB and the required extensions passed. These
  failures were environment limits rather than waived assertions.
- Whitespace, linked documentation, source references and the final handoff are
  checked separately. The implementation uses the installed stack without a
  new dependency, migration, external service or seed repair.

**Scoped buyer discovery/navigation assessment: 6/10 before -> 8/10 after.**
Paging and input state are clearer, likely reads start earlier, background work
is bounded and failure/retry behavior is explicit. The rating concerns local
correctness and request handling; it does not imply a measured latency reduction
on Azure or a higher overall system/security rating.

Local code commits:

- `6a87ccd` — `fix(buyer): make catalogue pagination visible and reliable`.
- `dadc596` — `perf(buyer): prepare catalogue pages within a bounded cache`.
- `41c4cd6` — `perf(buyer): keep product search responsive while typing`.
- `b964790` — `fix(buyer): recover interrupted catalogue navigation`.
- `bcf60c6` — `fix(buyer): cancel queued superseded search updates`.

The documentation commit's generated hash is reported in the branch handoff.
The PR title/body remains a local draft until explicitly published. No push,
merge, Azure deployment, browser/screenshot check, external Flutter change or
PostgreSQL concurrency check was performed. Device visuals and production
latency remain the manual/measurement work described in the acceptance guide;
these local checks do not claim universal instant navigation.

## October 17, 2026: reliable waybill scans and logistics next steps

Implemented on `fix/logistics-waybill-scanning`, based on the refreshed `main`
at `d55e4dc`. This addresses the reported need to visit Overview or refresh before
scanning, confusing QR/barcode recognition and missing next-step controls. The
original Azure browser failure was not captured in a browser trace; the changes
address verified source-level failure modes and exercise direct entry through
the actual components and isolated server routes.

### Before and after

| Area | Before | Implemented behavior |
|---|---|---|
| Scanner entry and requests | Requests read the original document token, used named-route URLs, and ignored ordinary validation messages. Repeated clicks and late requests could leave stale parcel actions. | Requests use the current session cookie and current portal path. Direct entry focuses the barcode field. Validation/session failures have useful feedback; an obsolete lookup is cancelled and an unsuccessful new lookup removes the previous confirmation. |
| Printed code identity | Barcode and QR sat beside each other and encoded different payloads: a parcel code versus a tracking-page URL. Missing tracking used an invented prefix. | Packing and print layouts share separated code zones with quiet margins; both codes carry the actual tracking identifier or existing order alias. The standalone operational QR matches. The separate View tracking link remains available. |
| Camera recognition | The station had separate camera code and chose the first detected value, including a QR URL rejected by barcode validation. | The shared camera canonicalizes supported old tracking URLs and raw codes. Matching codes deduplicate; different parcel codes require a closer view. Permission/start/stop, unmount and facility changes release streams and discard old callbacks. Unsupported browsers retain manual/handheld entry. |
| Next action | Already-sorted or rider-assigned parcels could receive a generic rejection before guidance. Most scanner guidance links were hidden. | Owned facility records expose sorting, rider assignment, manifest, counter or recovery navigation as appropriate. Waiting states explain the responsible actor and blocker. Check for updates, View parcel records where scoped, and Scan next parcel provide clear continuation or recovery. |
| Incoming transport | A scanner result could identify the incoming transfer while its ordinary hub-custody guidance offered no action. | A dispatched, readable manifest with actual included unreceived membership opens that specific manifest at its destination. Its receiver still records the parcel receipt, and company management still closes the manifest. |
| Queues after handover | The scanner refreshed after confirmation, but an already-open rider work page could retain old props. | Confirmation still refreshes the station. Relevant station and rider web queues also use non-overlapping 15-second refreshes while visible/online, pausing and cancelling an in-flight refresh during a submitted action. |

Sources: [scanner](../resources/js/Pages/Hub/ScanStation.tsx),
[waybill input/request handling](../resources/js/utils/waybillScan.ts),
[camera](../resources/js/Components/WaybillCamera.tsx),
[printed codes](../resources/js/Components/WaybillCodes.tsx),
[seller waybills](../resources/js/Pages/Seller/Orders.tsx),
[workstation controller](../app/Http/Controllers/Logistics/LogisticsHubWorkstationController.php),
[shared guidance](../app/Services/Logistics/HubParcelWorkstationService.php) and
[queue refresh hook](../resources/js/hooks/useOperationalRefresh.ts).

### Authority and compatibility

- Looking up a code never records receipt, custody, cash or completion. The
  existing confirmation writers, expected state/reference checks and original
  checkpoints retain authority. Wrong facilities, company management without
  handler placement, missing pickup confirmation and held parcels remain blocked.
- Barcode input remains bounded to 255 characters. Old tracking-page URLs are
  an explicit web scanner option limited to known platform origins and the
  exact tracking path. Foreign hosts, extra URL payloads, joined codes and hidden
  controls are rejected. Other service/native API inputs retain raw-code rules.
- Incoming manifest guidance checks retained custody, company, destination,
  dispatched status and actual membership. It cannot substitute a loose scan
  for manifest receipt or omit the required Mother Hub route.
- No dependency, schema, seed, commission or role policy changes are introduced.
  The Flutter repository and native command contracts are unchanged. These
  bounded operational refreshes do not complete the later portal-wide performance
  batches.

### Verification and assessment

- Backend selection: **928 tests, 46,433 assertions passed** using Docker PHP
  and isolated SQLite `:memory:` with separate task-local storage/configuration.
  This includes the logistics directory, courier operations/portal/native parcel
  boundaries, seller fulfilment/workspaces, web session transitions and waybill
  input. No development or Azure PostgreSQL database was used for tests.
- Full frontend suite: **377 passed**. Controlled actual-component checks cover
  initial scanner focus, current cookie credentials, first camera startup, equal
  and ambiguous codes, late permission cleanup, duplicate confirmation clicks,
  cancelled old-facility lookups, expired-session recovery, field feedback,
  next-step links and polling pause/cancellation.
- TypeScript/Vite production build and PHP formatting checks pass. The generated
  manifest resolves the station, seller orders and rider work pages to existing
  built assets. Markdown links, source references and Git whitespace are checked
  separately for the final handoff.

**Scoped local assessment: logistics scanning and next-step guidance 5/10 before
to 8/10 after; printed code clarity 6/10 to 8/10.** The backend custody boundaries
were already substantial; this change makes entry, recognition, progression and
feedback coherent. This is a local correctness/usability assessment, not a
production latency, optical recognition or whole-system readiness rating.

Publish, pull and deploy the reviewed branch before expecting these changes on
Azure. See [deployment and device acceptance](WEB_AUTH_DEPLOYMENT.md#waybill-scanner-update).
Actual camera distance/focus, printed output and the deployed signed-in browser
flow remain device/release checks. No push, merge, deployment or visible-browser
test was performed during this implementation.

Local code commits:

- `a8fe160` — `fix(waybills): align printed codes and camera recognition`.
- `a1d182a` — `fix(logistics): connect waybill scans to authorized next steps`.

The documentation commit hash and separate local PR description are included in
the branch handoff.

## October 18, 2026: PF02 buyer and seller navigation

Implemented the selected [PF02 plan](performance-plan/02-buyer-and-seller-navigation.md)
on `perf/buyer-seller-navigation`, based on merged `main` at `9dbf2ce`. This
extends likely-page preparation to buyer and seller navigation and reduces
unrequested private-page work while preserving each portal's design.

### Audited destinations and freshness

| Destination/source | Review result | Implemented preparation and activation |
|---|---|---|
| Buyer public home/search/product handlers | PF01 already reviews guest/buyer page data, including context-dependent Bag/offer props. | Existing bounded data preparation stays limited to those handlers. Header/footer discovery and department links reuse it, retaining current search, filters and a reset page. |
| `BuyerProfileController::index`, `OrderHistoryController::index` | Responses include personal records, account correction/email settings, mutable order flags and offers. | Profile and purchase-history links prepare their actual Profile or restricted-history code only. Opening or switching sections requests current authorization, identity and the selected section before showing its records. |
| `SavedProductController::index`, `SavedProductService::paginate` | Eligibility and buyer-owned entries, including genuinely unavailable products, must be current. | Prepare Saved products code. Actual list opens/paging use fresh owned responses; normal save/remove mutations invalidate prepared context through the existing Inertia events. |
| Seller `Products`, `Orders`, `Dashboard` handlers | Owned products/moderation, operational flags, current metrics and generated return references require current reads. `HasSellerShop`/`ShopEligibilityService` also validate the sole current shop and discard an obsolete shop-picker session. | Sidebar/dashboard intent prepares only the reviewed component code. Root and seller-host requests remain fresh and retain current ownership/approval rules. Product filter requests return a coherent list/filter/shop/category/auth set. |
| `SellerDashboardController::previewStorefront` | Preview is an owned, currently authorized route that renders `Marketplace/ShopDetail`, not a separate Seller preview component. | Prepare the real component without requesting shop/product data. The actual preview still runs its current shop guard. |
| Bag, checkout, messages, notices, evidence, codes and finance | Mutable authority, secrets or display/acknowledgement side effects exclude speculative page data. | Remain outside the private preparation allowlist. Unreviewed destinations retain their existing request/error handling. |

Owned page data is not cached speculatively in this delivery. The shared policy
permits code/reference preparation when a fresh authority check is required;
preparing a private response and then fetching it again would spend a request
without avoiding the required read. Code preparation uses the existing resolved
page modules and coordinator, not a new endpoint, external cache or service.

### Before and after

| Area | Before | Implemented result |
|---|---|---|
| Buyer/seller navigation intent | Owned navigation started page-code loading only after activation. | Reviewed hover/focus waits 150ms; touch stays immediate. One code candidate shares the catalogue preparation budget. A private activation starts its fresh request without waiting for the speculative import. |
| Profile sections | Switching tabs changed client history and reused already-loaded records. Order partial responses still eagerly built unrelated address, account and voucher data. | Section switches wait for a fresh coherent response. Lazy prop closures skip unrequested work; order counts, account settings and offers share request-local results when requested together. Pending guards and visible feedback preserve recovery. |
| Seller moderation feedback | Product lists queried the latest moderation decision separately for each row. | An eager, per-product-limited query loads the latest decision for the page. An isolated six-product check uses one moderation lookup and keeps the private relation out of serialized products. |
| Owned list paging | Seller inventory and saved-product URLs included a server origin; a shortened last page could retain an obsolete page URL. | Inventory, saved products and buyer/seller order pagination use current-origin paths. A small shared hook reconciles a server-clamped page with the URL while keeping other selections. |
| Speculation boundaries | Only catalogue-marked prefetches entered the explicit server gate. | Any detected prefetch must be a permitted marked catalogue read; unmarked/private/side-effecting requests reject before controller work and retain flash feedback. |
| Failures and rollback | Private links used ordinary navigation without the catalogue retry target; profile tabs could not show a fresh-read failure. | Reviewed private navigation has a retry destination, and sections/filters retain errors and inputs. The existing configuration switch also stops PF02 preparation; ordinary fresh navigation continues. |

Preparation metadata retains the existing short lifetime, entry cap, account/
role/hub/asset context, failure cooldown and mutation/tab/session invalidation.
No private response store, localStorage record cache or background private page
GET is introduced. Started module imports cannot be aborted; their preparation
slot stays occupied until settlement, and invalidated generations are discarded.
Dependent static chunks remain distinct from the page-data request budget.

### Verification and assessment

- Affected buyer, seller, web-session, notification and advertisement backend
  regression: **963 tests / 14,539 assertions**, passing with
  SQLite `:memory:` and task-local storage/config inside Docker. No development
  or production PostgreSQL data was read, reset or changed by tests. The updated
  **18-case focused selection / 202 assertions** also passed, including retained
  feedback after repeated unmarked/private speculation.
- Full frontend suite: **391 tests passed**, including actual link intent,
  duplicate/modified/foreign/download clicks, shared code/data budgets, stale
  imports, disabled/hidden/offline/slow clients, fresh private activation,
  failure targets, profile filters and last-page/history reconciliation.
- TypeScript and the Vite production build passed. Changed PHP style, whitespace,
  local documentation links and generated asset references were checked.
- New backend cases verify smaller order-only responses with no unrelated
  address/email/voucher queries, fresh owned addresses/stock/saved entries,
  current account restrictions, wrong-shop denial, root/seller-host behavior,
  rejected private speculation and latest moderation feedback in one query.

**Scoped buyer/seller navigation assessment: 6/10 before -> 8/10 after.**
Reviewed code can start earlier, unnecessary partial-read work is avoided and
mutable/private content is fresh on activation. This is a local correctness and
request-handling assessment, not a measured Azure latency reduction or an
increase in overall buyer, seller, security or project readiness. Profile tab
switches now make a deliberate section read instead of promising instant access
to an old snapshot. First visits still load their normal full page; code
preparation does not remove database, image or render time.

No schema, seed, dependency, Flutter or native API change is included. Use the
normal pull/deploy/verify workflow without a database reset. Browser/device
visuals, actual production timing and PostgreSQL simultaneous requests were not
run; [the acceptance guide](performance-plan/ACCEPTANCE.md#buyer-and-seller-navigation)
retains those release checks. PF03-PF05 remain separate work.

Local implementation commits:

- `1657241` — `perf(buyer): prepare navigation and load current profile sections`.
- `d1b1733` — `perf(seller): prepare workspaces and streamline product list reads`.

The documentation commit's hash is reported in the handoff. The combined PR
title/body remains a local draft; no agent push, merge or deployment was made.

## October 18, 2026: PF03 rider and logistics navigation

Implemented the selected [PF03 plan](performance-plan/03-rider-and-logistics-navigation.md)
on `perf/rider-logistics-navigation`, based on merged `main` at `14b589f`.
This improves likely-page preparation and operational refresh handling while
preserving the rider and logistics portals' own layout and authority.

### Audited destinations and freshness

| Surface | Source decision and implemented behavior |
|---|---|
| Rider Dashboard, Trips and Profile | `CourierDeliveryController::index`, `earnings` and `profile` return current work/history or personal and placement data. Sidebar, bottom navigation, account and dashboard-history links prepare only their actual component code; activation always makes a fresh authorized request. |
| Rider Messages, notifications and COD | Keep reads and acknowledgement tied to intentional display/selection and retain the existing cash actions. They remain outside the preparation map. No native client or Flutter project is modified. |
| Hub Overview, Network, Fleet and Parcels | `LogisticsHubWorkstationController` resolves current authorized company/facility scope and can update the selected facility session. Prepare reviewed code from the sidebar and dashboard; page data stays fresh. Company administrators and assigned handlers retain separate access. |
| Scan Station and Counter | Current custody, handler authority, claim state and counters require current reads. Prepare code only; hover/focus does not inspect a waybill, fetch a secret, reserve a rider or record custody/collection. |
| Manifest list/detail | `LogisticsManifestController::page` reads current authority and creates a session creation token for an owning company administrator. Prepare the component without requesting list/detail data or action tokens. Actual opening and commands still run current server guards. |
| Finance, private evidence, governance and recovery actions | Remain outside the preparation map and use their existing authorized requests. No decision form, proof or financial snapshot is cached by this delivery. |

### Before and after

| Area | Before | Implemented result |
|---|---|---|
| Likely navigation | Rider and hub links started loading their page code after activation. | Reviewed pointer/focus intent shares the existing 150ms delay and one-candidate budget. Touch remains immediate, and an activation starts its fresh request without waiting for a speculative import. |
| Context observation | Preparation observed ordinary navigation; the installed adapter does not emit that event for replacements/reloads. Rider placement, duty and returned work phases were absent from its context key. | Successful replacements/reloads update the current page, and intent observes the rendered page including errors. Metadata keys include current placement, duty, vehicle, barangay and pickup/final-mile IDs/stages, plus the existing identity/hub/asset scope. Late invalidated imports cannot restore metadata. |
| Queue refreshes | Local submission guards paused the existing poll, but layout actions or hub changes could overlap it. An already returned obsolete response had no context-generation check. | Intentional requests cancel the old poll and pause new polls while pending. A generation check rejects obsolete responses before page update. Hidden/offline changes and unmount also release requests. The existing visible/online 15-second rest policy and independent message scrolling remain. |
| Facility switching | A redirect back to a filtered old hub URL could undo the new session selection. The sidebar could also show invented fallback facility details. | The server replaces the hub filter and resets the page on supported workspace returns, keeping compatible filters. Foreign/unsupported return URLs fall back to the local dashboard. Pending switches cannot repeat, and missing facility details use honest labels. |
| Parcel registry | Filter updates loaded full page props and had no local pending/error recovery. Hub changes could preserve local rider choices. | Focused fresh updates carry matching identity, facility, list, counts, filters and current rider candidates. Input and server errors survive a failure, mutations prevent duplicates, and a keyed hub boundary clears local choices. Paging uses current-origin paths and truly disabled controls. |
| Unrequested read work | Rider context-only reads built work queues/history, and hub count-only reads built parcel rows and eligible-rider lists. | Lazy controller props avoid that unrequested work and share request-local results when requested together. Normal full work reads still run the existing current business queries; no claim of lower full-page database time is made. |

The implementation retains the rider's blush canvas, local 8px corners,
1280px breakpoint, 280px sidebar, 260ms transitions, reduced-motion behavior,
chat panes and mobile clearances. Hub sidebar grouping, table hierarchy and
geometry stay in place. Retry and pending feedback use existing portal styling
and useful focus/touch controls. No global CSS/theme, font, dependency or image
system was replaced.

### Verification and assessment

- Rider, logistics, shared portal access, buyer catalogue/navigation and seller
  navigation regression: **1,218 tests / 52,755 assertions passed** in Docker
  using isolated SQLite `:memory:` and task-local storage/config. The updated
  focused selection also passed: **10 tests / 428 assertions**, including
  root/subdomain hub switching, current duty/placement, count-only reads,
  private-prefetch rejection, invalid page values and foreign return URLs.
- Full frontend suite: **397 tests passed**. Coverage includes actual link
  intent, touch/keyboard handling, shared budgets, stale context/imports,
  replacement/reload observation, fresh activation, duplicate filter clicks,
  retained field errors and obsolete queue callbacks after facility/actions.
- TypeScript/Vite production build and changed PHP style (**4 files**) passed.
  The host build reached Vite but could not replace root-owned generated assets;
  the normal Docker build completed. No ownership or dependency change was needed.
- Static review retained existing rider CSS and both portal layouts. Generated
  assets for all ten reviewed components, documentation links, whitespace,
  ignored private tooling and branch content/history were checked. No visible
  browser or device testing was performed.

**Scoped rider web navigation assessment: 6/10 before -> 8/10 after.**
**Scoped logistics navigation assessment: 5/10 before -> 8/10 after.**
These assess local navigation correctness, preparation and fresh-context
handling. They do not raise overall courier, logistics, security or project
readiness. The facility-switch defect and obsolete refresh risk are addressed;
actual server work, image loading and rendering still contribute to waiting.

No private page response cache is introduced. Code preparation uses bounded,
short-lived metadata over ordinary static module loading. Existing session,
mutation, tab and connection safeguards remain; a started import cannot be
aborted and keeps its slot until settlement. Cancelling a browser request does
not prove that already-started server work stopped.

Use the normal pull/deploy/verify workflow. No schema migration, reset, reseed,
new service, Flutter change or native API change is required. No push, merge,
Azure deployment, visible browser or screenshot check was performed. Device
visuals, real Azure timing and simultaneous PostgreSQL requests remain separate
acceptance work in [the guide](performance-plan/ACCEPTANCE.md#rider-web-and-logistics-navigation).
PF04 and PF05 remain separate selections.

Local implementation commits:

- `047ef3f` — `perf(rider): prepare web navigation and protect fresh queue reads`.
- `dfbe94d` — `perf(logistics): keep prepared workspaces in the selected facility`.

The documentation commit's generated hash is reported in the final handoff.
The separate PR title/body remains a local draft and has not been saved on GitHub.

## October 18, 2026: PF04-PF05 admin navigation and measured acceptance

The user selected the remaining [PF04](performance-plan/04-admin-navigation.md)
and [PF05](performance-plan/05-performance-and-release.md) batches together.
`perf/admin-navigation-release` starts from merged `main` at `9b1b3c8` and keeps
separate commits for admin behavior, measurement/asset control and documentation.
This completes the five batches' selected implementation and local acceptance;
it does not establish deployed/device acceptance or reopen the numbered admin
feature plan.

### Reviewed scope and fresh authority

| Surface | Audit and implemented boundary |
|---|---|
| Admin dashboard | `AdminDashboardController::index` and `AdminOverviewService::overview` retain fresh counts, queues and financial definitions. Only `Admin/Dashboard` code can prepare in advance. No summary response or current decision state is retained. |
| Users and products | Existing root and admin-host routes retain `auth`, role and approval/open-account middleware. `AdminDirectoryService` validates relevant query bounds, builds only requested rows and sends displayed fields. Directory page code can prepare; activation/focused updates still request fresh authorization and current account/compliance state. |
| Governance history | The existing `GovernanceHistoryController` and `GovernanceHistoryService` apply current actor/source scope, read-only union/filter pagination and safe projection. Only the history-list component is prepared. No history response, subject detail or evidence file is fetched on intent. |
| Decisions and excluded reads | KYC, shops, identity corrections, account/resource restrictions, closures, product moderation, advertisements, private evidence and finance remain outside the admin preparation map. Inspection links and actions retain their current source-token/evidence/conflict contracts. A successful mutation invalidates preparation; a competing review cannot become a cached success. |
| Shared middleware and feedback | Marked private speculation remains rejected before controller work. Flash/error preservation, private response headers, current account context and foreground priority retain the established policy. Admin links join the reviewed runtime and its visible retry notice; no notification/message acknowledgement or advertisement activity is added. |

### Before and after

| Area | Before | Implemented result |
|---|---|---|
| User directory | Fifteen rows were paginated on the server without visible paging controls. Full accounts and unused shops were serialized; filters had no pending/empty feedback or explicit search action. | Stable timestamp/ID ordering, numbered/current/disabled paging, a clear Search button, bounded input, coherent fresh partial updates and duplicate guards. Back/Forward follows returned filter props; an out-of-range page resolves to the last page and corrects its history URL. Validation retains input. |
| Product directory | Full product/shop/category models and rows were built for every request. | Only rendered fields are sent; unrequested rows/relations are lazy. Paging stays in the current origin and preserves fresh compliance state. Product details/review actions remain separate authorized reads. |
| Preparation budget | The policy was bounded, but a separate load-triggered Vite waterfall could warm unrelated portal chunks at concurrency three. | The blanket waterfall is removed. Initial module loading remains; only reviewed intent and the existing bounded catalogue idle/search preparation select likely destinations. One candidate can import its static dependencies, so this is not a one-file network limit. |
| Evidence | Earlier batches had correctness checks but no comparable combined measurement report. | Reproducible isolated endpoint samples, paired controlled cold/prepared/in-flight/expired samples for every selected role, adverse/unused preparation, search scheduling, actual build dependency budgets and explicit manual release steps. |

The admin retains its sidebar/header, white tables, existing local corners,
slate canvas and Plus Jakarta Sans. New controls use the existing Bagoo accent,
visible boundaries/focus, current/disabled states and narrow-screen wrapping.
There is no theme rewrite, new dependency, image gallery preload or new polling.

### Measurements and acceptance evidence

Read [the measurement report](performance-plan/MEASUREMENTS.md) for the exact
fixture, routes, method, median/p90 tables, byte budgets, reproduction commands
and interpretation limits.

- Each of eight real Laravel HTTP-kernel cases has ten measured reads per
  revision after a warm-up, using equivalent isolated SQLite fixtures. Admin
  user JSON falls from **9,926 to 4,945 bytes**, product JSON from **38,203 to
  6,843 bytes**; normal user reads use **14 to 13 queries**, and context-only
  user reads use **14 to 11**. Unchanged role endpoints retain their query and
  payload costs. Their elapsed-time variation, including a slightly slower
  rider sample, is not attributed to this change.
- Six representative destinations have ten paired samples for each of four
  preparation states. Under deliberately fixed **40ms data / 70ms code**
  transport, completed private preparation still performs one fresh data read;
  completed/in-flight catalogue preparation makes no duplicate foreground data
  request. Cold policy-on/off samples show no meaningful difference under that
  model. This establishes ordering/reuse, not browser rendering speed.
- The existing global waterfall's candidate manifest budget spans **203-217
  additional static files** and about **1.89-1.98 MB raw** after a source page
  is loaded. Reviewed admin user intent instead needs **three files / 9,261 raw
  bytes** after its dashboard. These are eligible file budgets, not measured
  wire transfers or a claim that every previous browser downloaded every file.
- Forty additional adverse samples cover failed/cancelled catalogue and admin
  preparation. Fresh fallback remains available and no private data is fetched
  speculatively. Six abandoned preparations expose their wasted-byte cost;
  expiry/cancellation does not prove that earlier server/transfer work stopped.
  Search has ten scheduling/responsiveness samples. Real cache-use rate is
  unmeasured. Existing product cards remain lazy; large original images still
  need separate transfer/decoding and visual acceptance before an image rewrite.
- Governance, admin, finance, shared access and selected cross-role navigation
  regression: **815 tests / 14,666 assertions passed**, isolated SQLite
  `:memory:` with task-local storage/config. Final focused admin and asset
  checks: **14 tests / 290 assertions passed**. The asset contract first
  demonstrated unrelated portal warming, then passed with both flag values.
- Full frontend suite: **402 tests passed**, covering the actual policy,
  runtime, link intent/touch, composition, filters/paging/history, duplicate
  clicks, retained errors, offline/hidden/slow clients, disabled preparation,
  cache expiry, cross-tab/context invalidation and late-response rejection.
  TypeScript/Vite production build and changed PHP style (**six files**) passed.
- The first broader run failed because the newly isolated storage directory
  lacked its compiled-view folder. Preparing that test-only folder resolved the
  setup error; the complete rerun passed. No development/production database,
  application file ownership or production configuration was changed.

The disable path remains `CATALOGUE_PREFETCH_ENABLED=false` followed by the
normal deployment/config refresh and an ordinary response in each loaded tab.
Direct links, forms and fresh actions keep working; ordinary initial module
preloads/browser caching remain. The removed global waterfall no longer runs
independently of that policy. No schema migration or reseed is required.

### Assessment and handoff boundary

| Scoped area | Before | After local verification | Basis and remaining limit |
|---|---|---|---|
| Admin directory/navigation usability and efficiency | 6/10 | 8/10 | Reachable stable pages, coherent fresh filters, useful pending/error/empty controls, smaller directory payloads and code preparation. Device rendering and real deployed latency remain unverified. |
| Background asset budget and performance evidence | 5/10 | 8/10 | Removed unconditional cross-portal warming, retained bounded intent and measured requests/costs with reproducible scenarios. Static-byte budgets and controlled timings do not establish real use rate, paint time or production capacity. |

Implementation commits: **`8e5d5d3`** (`perf(admin): prepare navigation and
streamline fresh directories`) and **`718552a`** (`perf(navigation): bound asset
warming and add repeatable measurements`). Documentation and the combined PR
handoff are saved separately. The PR title/body remains a local draft; no push,
GitHub publication, merge or deployment is performed by this delivery.

[Combined release checks](performance-plan/ACCEPTANCE.md#combined-release-checks)
retain the unperformed browser/device and deployed network/session checks.
Flutter and simultaneous PostgreSQL behavior are outside this local acceptance.
No overall admin security, whole-system readiness, Azure performance or thesis
readiness rating is raised by this scoped work.

## October 18, 2026: Account identity and registration review

The [account registration and review delivery](auth-plan/ACCOUNT_REGISTRATION_AND_REVIEW.md)
uses `fix/account-registration-and-review` with separate logical commits. One
normalized sign-in email may now belong to different fixed roles, while original
and verified recovery emails remain unique within each role. Accounts retain
their own passwords, approval, restrictions and records. Sign-in forms identify
the role, dedicated portal hosts constrain it, and ambiguous older requests must
choose an account type. The shared host supports each portal's path aliases.

Password broker tokens use the account ID; links carry the real email and role.
Registration codes are role-bound. Google linking, additional recovery addresses,
native courier entry, personnel placement and demo lookups select the intended
account. Original registration addresses and ownership guards remain intact.
Existing unconsumed email-only reset links and worker codes may need resending.
The identity migration deliberately requires a roll-forward repair rather than
collapsing different roles back to globally unique email ownership.

Company and shop names reject normalized duplicates on preflight and final
submission, with database protection against a persistence race. Existing
conflicting company names stop migration for controlled correction; no company
is silently renamed or removed. Logistics registration now requires a unique
canonical 12-digit TIN and multiple declared service regions, independently of
the office province. Corrections/admin review retain those values; public lists
do not expose private TINs. Existing missing TINs are not fabricated. The demo
company seeder contains an explicit synthetic TIN and declared coverage. Declared
regions do not activate unsupported facilities or transport routes.

Rejected, suspended and deactivated owners can authenticate into **Account status**
and send a **Request review** explanation. Every ordinary protected operation
still checks current eligibility. Owned correction/history and documented custody
or cash recovery keep their existing boundaries. Closed accounts remain closed.
Separate shop/company restrictions are shown without changing the account role;
legacy companies disabled through their activity flag are included.

The admin queue supports a reasoned decline, returning a rejected application to
pending evidence review, reactivation through the existing gates, and closing an
outdated request. It prevents self-review, foreign ownership, duplicate pending
requests, stale decisions and reuse of an old acceptance to lift a newer
restriction. Declined unchanged cases wait 24 hours before resubmission. Requests
and decisions are retained; reopening never approves an application or removes an
independent restriction. Admin and owner notifications link to the correct view.

Committed approval/rejection and account/shop/company activity decisions now
create required email delivery intents. Review outcomes also notify the owner.
Delivery failure leaves the decision saved and is retried by the existing
notification command; Account status reports a pending email. Signing in does
not send another decision email. New activity decisions explicitly mark feedback
shared with the owner. Historic internal restriction notes remain private, and
missing shared feedback does not prevent requesting review.

Verification:

- **2,595 broader authentication, admin, notification, buyer and rider tests /
  36,559 assertions passed**. The final review-identifier boundary checks were
  also verified in the focused appeal run below.
- **46 appeal tests / 572 assertions**, including ownership, restricted work,
  current-state review, self-review prevention, retained evidence, visible
  notifications, legacy company activity and oversized review identifiers.
- **218 focused review/notification regression tests / 2,809 assertions** passed
  before the final identifier boundary assertions. The email cases verify all
  public application roles, all five account activity roles, committed delivery,
  rollback, preferred recipients, duplicate events and retry.
- **414 frontend checks**, TypeScript/Vite production build, PHP formatting and
  patch whitespace checks passed. No browser automation was used.
- Earlier foundation checks include **296 identity-related regression tests /
  3,545 assertions**, **2 populated migration checks / 16 assertions**, and
  **25 TIN/region cases / 198 assertions**. Older global-email, logout and notice
  destination assertions were updated for the explicit new policy, retaining
  protected-operation denial and unchanged owned data.

Normal local migrations applied successfully on the existing PostgreSQL database,
including the previously pending main migrations. No fresh/reset or reseeding was
performed. All automated database tests used isolated SQLite `:memory:`; applying
a normal migration does not establish PostgreSQL concurrency behavior. Azure,
interactive browser/device behavior and real SMTP/inbox delivery remain
unverified. SMTP can deliver at least once if a process stops between sending and
recording success. Follow the [deployment checks](WEB_AUTH_DEPLOYMENT.md#account-registration-and-review-update)
after the user publishes and pulls the branch; retain the scheduler and real mail
configuration. Existing companies missing TINs need owner correction rather than
a fabricated tax identity.

| Scoped assessment | Before | After | User-visible improvement |
|---|---:|---:|---|
| Role-aware email identity | 4/10 | 8/10 | Separate roles can share a mailbox without ambiguous sign-in or cross-role recovery. |
| Duplicate company/shop blocking | 6/10 | 8/10 | Final submissions and database writes enforce normalized uniqueness. |
| Logistics registration completeness | 5/10 | 8/10 | Required private TIN and every selected region survive review and correction. |
| Account status and owner review | 3/10 | 8/10 | Clear feedback, saved appeals and admin responses retain operational restrictions. |

These are assessments of the tested scope, not a higher rating for unverified
fulfillment, transport coverage, deployed mail or unrelated system flows.

Implementation commits:

- `761dfbd` — separate shared emails by account role and block duplicate companies.
- `18fc13d` — require logistics TIN and retain multiple service regions.
- `9cdac65` — send clear status emails and retry failed delivery.
- `ff8e0da` — add owner appeals with guarded admin decisions.

The combined pull request title/body is a local draft. Local commits, publication,
merge and deployed verification remain separate events.

## Buyer delivery address book: October 18, 2026

Branch: `fix/buyer-address-book`, based on the merged account-registration update.
This delivery concerns saved delivery addresses, checkout's use of those contacts
and existing recipient snapshots. It does not change reviewed legal identity,
registration location selectors, transport coverage or the rider mobile project.

Previously, saving an address replaced its entered recipient with the account
name, dependent locations were not checked against one complete local hierarchy,
the address count was unlimited, and deleting the default silently selected a
replacement. Checkout also preferred the account name over the selected address.
The seller waybill omitted parts of the destination and could substitute current
profile contacts for missing historical recipient details.

The address service now retains the actual delivery recipient, checks complete
parent-linked locations, accepts valid Philippine mobile formats, enforces ten
saved addresses and requires an explicit replacement before deleting any default.
All mutations share the existing buyer lock and ownership/eligibility checks;
checkout saves obey the same limits within its existing transaction. Errors from
that optional save use the checkout's visible shipping fields. A full book can
still place an order with a new address without saving it.

The buyer editor follows the surrounding white panels, rounded fields and red
actions. It retains entered details after validation or location failure, cancels
obsolete lookups, bounds a read to ten seconds, offers retry and waits for usable
choices before saving. It loads only relevant choices from a local geographic
reference, with a bounded in-memory cache of public location choices. The
[reference attribution and cutoff](../resources/data/philippine-addresses/README.md)
are explicit; valid geography does not bypass network serviceability.

Acceptance evidence uses independently written project cases:

| Requirement | Verified behavior |
|---|---|
| Saved details | Entered recipient, location and label survive reload without changing the account name; adding another address preserves the first default. |
| Linked locations | Server and form use the same parent hierarchy; parent changes clear descendants and stale replies cannot restore them. |
| Required values | Missing, empty, whitespace-only, unsafe and overlong values produce field errors without changing existing saved addresses. |
| Mobile numbers | Accepted local and international Philippine forms normalize consistently; short, long and foreign values fail. |
| Capacity | The tenth address saves; direct and checkout attempts at an eleventh fail without replacing the default or consuming checkout state. |
| Default selection | Changing the default leaves exactly one and checkout uses its actual saved recipient. |
| Deletion | The current default is protected even when it is the sole address; explicitly select another before deletion. |
| Historical delivery | A real checkout followed by address edit and deletion leaves order/parcel records and buyer/seller details unchanged; the waybill prints complete saved recipient details without live-profile fallbacks. |
| Ownership | Another buyer and other roles cannot read or mutate a foreign saved address, including edited identifiers and direct requests. |

Verification:

- **864 backend tests / 61,925 assertions passed** across Buyer, Seller,
  geographic-reference integrity and selected normal fulfillment lifecycle cases.
- **39 final address acceptance tests / 327 assertions passed**, including an
  additional explicit check that editing persisted before the historical address
  was deleted. An updated legacy-fixture run also passed 11 tests / 56 assertions.
- **426 frontend checks passed**; the final 12 address-specific checks also
  passed after bounding location requests. These cover rendered controls and
  recipient snapshots, validation, changed parents, delayed replies and recovery.
- TypeScript/Vite production build passed in Docker, PHP formatting and patch
  whitespace checks passed. Host output permissions required using the existing
  Docker build command. No browser automation was used.

The additive region migration was applied to the existing local PostgreSQL
database, with no reset or reseed and no pending migrations afterward. Existing
address values are retained and a missing legacy region is not fabricated.
Automated database tests used SQLite `:memory:` only; they do not establish
PostgreSQL concurrency behavior. Azure, real browser interaction, camera/printer
output and the separate mobile application remain unverified for this delivery.
After publication, follow the [address deployment checks](WEB_AUTH_DEPLOYMENT.md#buyer-address-book-update).

| Scoped assessment | Before | After | Basis and remaining limit |
|---|---:|---:|---|
| Buyer saved-address reliability | 4/10 | 8/10 | Required fields, hierarchy, capacity, default rules, privacy and checkout rollback now have automated evidence; deployed browser review remains. |
| Historical recipient display | 6/10 | 8/10 | Orders remain unchanged and the printed recipient uses the complete snapshot; physical print review remains. |

These ratings apply to this address-book scope and do not raise readiness for
unrelated buyer modules or unsupported delivery routes.

Implementation commits:

- `a5c9ef4` — enforce address validation, limits and defaults.
- `458144e` — align address forms and preserve recipient displays.

The combined PR title and body are prepared as a local draft. No GitHub
publication or Azure deployment was performed for this delivery.

## Buyer readiness planning: October 18, 2026

The [three-delivery plan](buyer-readiness-plan/README.md) allocates B3-B14's
92 scenarios to shopping/Bag (34), vouchers/checkout/payment (30), and
orders/tracking/communication (28). B1 and the merged B2 address-book work remain
separate. The user set checking for October 19 at 07:00 Asia/Manila and selected
circular tracking milestones with current recorded location and useful existing
tracking details. The proposed overnight windows reserve a final walkthrough,
publication/deployment interval and buffer; they are planning assumptions.

Static inspection confirms existing discovery/filter/paging, saved-product,
inventory, voucher, idempotent COD checkout, order-tracking, completed-purchase
review, text chat and notification foundations. This is not a fresh acceptance
run for the remaining scenarios. Repair only actual defects and reuse those
services rather than replacing previous work.

Specific gaps and disagreements for the delivery audit:

| Area | Executable finding | Delivery disposition |
|---|---|---|
| Shop following | `Marketplace/ShopDetail.tsx` uses component state and a fixed displayed following count. | Batch 1 assesses persistent owned following and real counts; the current button is not verified persistence. |
| Discovery/product additions | `BuyerDiscoveryService` performs literal matching; current inventory selects colors and sizes rather than demonstrating an independently priced combination matrix. | Batch 1 checks typo handling, combinations and purchase caps explicitly; sale/vacation behavior needs its actual saved policy and estimate. |
| Shipping and voucher policy | `CheckoutQuoteService` uses the current per-shop shipping calculation and rejects more than one Bagoo offer. | Batch 2 records tariff and additional shipping-slot decisions before changing prices or funding. |
| Payment | `CommerceInputService` normalizes checkout to COD and `CheckoutOrderService` saves COD orders. | Batch 2 verifies supported COD and clear unsupported-method rejection; gateway/refund/expiry behavior is not implemented evidence. |
| Buyer cancellation | `routes/web.php` and `OrderLifecycleService` expose seller cancellation; no buyer cancellation writer was found. | Batch 3 assesses an owned buyer action with explicit allowed states, existing claim/custody gates and once-only stock/funding effects. |
| Buyer returns/refunds | `BuyerDisputeController` returns `available: false`. | Reuse the existing return/refund planning work. Full post-delivery requests, decisions and repayment remain unresolved scope, not a passing flow. |
| Review policy | `ReviewService` allows creation with text up to 1,000 characters and five validated images; buyer review routes expose creation, not an edit writer. Public review output contains the stored buyer name. | Batch 3 resolves text/privacy limits, edit/window rules and photo behavior while retaining purchase verification and existing history. |
| Chat and reminders | `ChatController::sendMessage` validates text and context but has no image upload field. Flash-sale reminder evidence was not found in the inspected buyer path. | Batch 3 treats attachments and actual sale subscriptions as additions; retain existing text ownership/read and lifecycle-notice checks. |
| Parcel presentation | `OrderTrackingPanel` currently uses a status badge, text summary and plain checkpoint rows. | Batch 3 restores circular recorded milestones and current-step emphasis using existing history, holder, hub, time and next-step data, scoped to the buyer style. |

The current 10% commission, explicit buyer completion, separate settlement and
road/hub custody rules remain authoritative. Alternate sample assumptions cannot
silently enable time-based completion, automatic seller fund release, maritime
shipping, or a payment/refund service. Every allocated scenario needs an actual
result or a plainly recorded unresolved feature/policy decision.

This delivery changes planning documents only. No application behavior, database,
frontend, tests, external publication, automation workflow or Azure deployment
was changed. The code findings were inspected statically; no new readiness rating
or acceptance pass is claimed. Documentation links, coverage allocation and
patch whitespace are checked for the planning handoff.


## Buyer delivery 1: discovery, following and Bag pricing — October 19, 2026

**Scoped assessment: 6.5/10 before → 8.3/10 after.** Store search now filters the
whole eligible shop catalogue with validated categories, sorting and stable
pagination. Typo suggestions use at most 250 eligible product names, respect the
current filters and never silently replace the query. Live-search partial reads
include suggestions so an earlier correction cannot remain under a new result.

Shop following is now an owned database relationship with a unique buyer/shop
pair, repeat-safe follow/unfollow, actual follower counts and a Followed shops
page in the buyer menu. Restricted followed shops retain a removable generic
entry without exposing their current details. Storefront editing remains owner
only, public preview flags cannot enable it, and storefront chat opens the
existing merchant conversation. Fixed voucher codes and free-delivery claims
were removed in favor of actual available offers and quotes.

The product UI previously added a configured size surcharge that the Bag and
order writers omitted. The shared inventory selection now resolves that price in
cents for additions, updates, Bag reads, quotes, order snapshots and Buy Again.
Product options require an explicit choice; invalid typed quantities receive
feedback rather than silently changing to another quantity. Removed and depleted
variants are unavailable for selection while other valid Bag items remain usable.
Buy Now identifies the returned owned Bag line explicitly.

Validation evidence:

- Discovery/storefront suite: **46 tests / 1,083 assertions**, passing.
- Selection, inventory, Buy Again and storefront suite: **86 tests / 984
  assertions**, passing, including surcharge snapshots and unavailable variants.
- Buyer/finance regression: **692 tests**, with 691 passing and one stale
  redirect expectation. The existing suspended-rider route already uses Account
  status; the test expectation was corrected and that case passed separately
  with **93 assertions**, retaining restricted access and cash custody checks.
- Frontend discovery/commerce suite: **75 tests**, passing. Production build,
  formatting and patch whitespace passed. No browser, Azure, reset or publication
  verification is claimed.

All 34 allocated scenarios were assessed against existing behavior and the above
checks. This is not a claim that every external scenario was manually executed.
Vacation mode, configurable cumulative purchase caps, a flash-sale/quota engine
and a full color-by-size inventory matrix remain unimplemented features. Current
colors have availability flags and sizes have shared stock/surcharges. A Buy Now
request for an already matching Bag line still includes its accumulated quantity;
the next checkout delivery must address exact requested quantities. Preserve the
agreed 10% commission, COD, buyer receipt and deferred full-refund rules.

Deployment requires the additive `shop_follows` migration; ordinary deployment
migrations suffice. This delivery did not modify the development database. The
branch PR title/body is a local draft, and only the user publishes commits.


## Buyer delivery 2: checkout consent and exact quantities — October 19, 2026

**Scoped checkout assessment: 7.3/10 before → 8.7/10 after.** New confirmation
credentials bind the displayed merchandise prices to the owned Bag. A changed
base price, size surcharge or Bag contents requires a fresh checkout review;
changing prices during page preparation cannot silently update the consent.
The selected products are locked before this final comparison. The snapshot
conservatively covers the complete Bag, so an unrelated Bag change also asks for
reload. Unused older confirmations require reload, while saved successful COD
submissions retain original-result replay, including application-key rotation.

Buy Now now carries its requested quantity through checkout, voucher previews,
choices and best-offer calculation. Only that quantity is purchased; any earlier
matching Bag quantity and other lines remain. Quantities are validated against
owned selected lines and actual stock. The order transaction updates or removes
the correct Bag lines and repeats return the original orders without another
stock, funding or Bag change. This resolves delivery 1's matching-line limitation.

Unsupported online payment requests now fail with a clear COD-only message;
missing payment choice is rejected. The interface retains its explicit COD and
confirmation flow. Quote and confirmation error feedback preserve the requested
items and Buy Now quantity when reloading checkout.

Evidence: **705 buyer/finance tests / 15,109 assertions** passed. The final focused
run passed **116 tests / 1,046 assertions**, including the page-preparation race,
partial checkout, funding, rollback and retained replay. Relevant frontend checks
passed **55 tests** in total; formatting, patch whitespace and production build
passed. Verification used isolated SQLite and static/rendering checks; no deployed
browser, PostgreSQL concurrency or live payment acceptance is claimed.

All 30 allocated areas were assessed. Agreed differences retain 10% commission,
COD only, the existing per-shop shipping calculation, one offer per shop plus
one Bagoo offer, existing cancellation usage accounting and a 500-character
checkout note. Gateway callbacks/payment expiry and full refunds remain deferred.
A shipping subsidy reduces the actual fee within its cap; it does not grant a
second simultaneous Bagoo slot or change merchandise settlement. No invented
online success, refund or reused voucher is reported as passing those alternate
assumptions. No schema migration is added in this delivery.


## Buyer delivery 3: order actions, tracking and communication — October 19, 2026

**Scoped order/tracking/review/chat assessment: 6.0/10 before → 8.6/10 after.**
The buyer parcel detail now uses connected circular milestones with an explicit
current stage, last recorded location and expandable original checkpoints. Missing
scans remain unrecorded; every Mother Hub event stays in history. Doorstep handover,
counter collection, return travel, cancellation and buyer receipt remain distinct.
The shared default tracking panel retains its existing presentation for other roles.

An active approved buyer can cancel an owned unpaid COD order before readiness,
with a required reason. The locked action checks current payment, rider claims,
custody and handover holds, restores selected stock and releases only applicable
original funding once, and records the buyer as actor. It preserves delivery notes
and sibling orders. Legacy paid orders require payment support; no refund is
fabricated. Field errors and storage failures retain a clear recovery path.

A verified completed-purchase review can receive one owned rating/text edit within
30 days. Its original content is private, photos remain attached and unchanged
successful retries are safe. Public names are masked, actual purchased options are
shown and verified averages/counts use saved data. Existing 1,000-character review
text and five 5-MB photo limits remain.

Buyer and seller chat now accept one real JPG, PNG or WEBP image up to 5 MB through
participant-only private links. New sends use owned UUID retry identity, file cleanup
on rollback, context/ownership checks, bounded new-send rate and reduced response
fields. Drafts survive failures, duplicate clicks are guarded and late reads cannot
replace a newly selected conversation. Only messages in the returned read snapshot
are acknowledged; newer arrivals remain unread. Native rider messaging remains
separate and its backend contract checks pass.

Evidence: integrated **855 tests / 20,558 assertions**; related native rider/seller
checks **47 tests / 1,472 assertions**; all **436 frontend tests** passing. Final
focused checks cover cancellation, communication, public review options and input
boundaries; builds, formatting and patch whitespace pass. Testing used isolated
SQLite, without browser automation or PostgreSQL concurrency claims.

All 28 allocated areas were assessed with the retained rules. Full refunds and
online payments remain deferred, completion remains buyer-confirmed, and flash
reminders still require a real sale engine. The dated
[delivery results](buyer-readiness-plan/04-delivery-results.md) list all retained
expectations, remaining new features and manual-review pages. No claim is made
that all 92 original browser scenarios passed unchanged.

The three additive follows/review-history/private-chat migrations were applied
locally; a read-only probe confirmed the new schema and **17 visible products**.
No data was reset or reseeded. Azure, Flutter and GitHub publication were untouched.
All three branch PR descriptions and the combined handoff remain local drafts.


## Advanced buyer and seller shopping — October 19, 2026

The five feature gaps left after the three buyer deliveries now have saved
settings and operational paths on `feat/advanced-shopping-controls`. The branch
contains those predecessor deliveries. [The advanced shopping plan](buyer-readiness-plan/05-advanced-shopping.md)
records the rules, review pages and deployment steps. These additions preserve
10% commission, COD, explicit buyer receipt, shop/category approval and the
canonical parcel custody route; full post-delivery refunds remain deferred.

| Scoped feature | Before | After | Change and evidence |
|---|---:|---:|---|
| Vacation purchasing policy | 0/10 | 8.5/10 | Previously absent. The sole shop's seller saves vacation mode; approved listings stay visible with a label, new purchases stop, and existing orders can still be prepared. |
| Cumulative buyer purchase limits | 0/10 | 8.5/10 | Previously absent. A configurable product cap counts non-cancelled order units across options and sessions. Bag changes and locked final checkout recheck it; early cancellation releases the allowance once. |
| Independent option stock | 5.5/10 | 8.6/10 | Shared size stock remains supported; sellers can explicitly configure every combination's stock and surcharge. Combined stock is derived, invalid/missing/duplicate rows fail, and cancellation restores the exact pair. Open-order options cannot be removed or change stock mode. |
| Timed prices and flash quota | 0/10 | 8.5/10 | Previously absent. Owned schedules reject overlaps, allow adjacent slots and permitted store-price edits, and retain ended schedules. Checkout separates flash and fallback quantities, applies option surcharges, preserves voucher/funding rules and stores each actual price. Cancellation restores only its original flash allocation once. |
| Owned sale reminders | 0/10 | 8.4/10 | Previously absent. Buyers opt into future flash slots and can opt out. The minute scheduler records one reminder within ten minutes before the start; failed delivery retries remain durable, cancelled/late notices are discarded, and rejoining resumes only an undelivered intent. Actual delivery state controls the interface. |

Zero is scoped to an absent feature, not a rating of the existing marketplace.
The ratings reflect isolated checks and source review; they do not establish
simultaneous PostgreSQL behavior, live email delivery or a deployed browser pass.

### Price, stock and recovery boundaries

- The final checkout holds the shared product locks while verifying buyer caps,
  physical stock, timed prices and quota. Quoted promotional units do not reserve
  stock. Another buyer's quota change requires a fresh price review. A second
  comparison binds the final quote to the buyer's displayed prices, including a
  sale ending between the initial check and quote calculation.
- Successful checkout retries retain their original result after a sale ends or
  prices change. A rollback after quota reservation restores the entire order,
  stock, quota, Bag and funding transaction. Existing order items retain their
  actual prices and selected options.
- Store/flash reductions are seller-funded through the charged merchandise price.
  Voucher minimums use that actual subtotal. Platform voucher funding and shipping
  remain separate, with no invented platform subsidy for a sale reduction.
- Future slots can be edited before they run, but recorded reminders freeze their
  times. Running flash terms stay fixed; a running store sale may change its price
  for new orders. End now stops new use. A discount never raises an item above
  its current regular base price.
- Legacy null size stock still falls back to listing stock; an explicit zero stays
  sold out. Combination rows require explicit counts rather than guessing how
  older stock should be split. Product prices reject excess decimals and values
  outside the supported money range before they reach the sale engine.
- The broader seller check found an older expectation that a guest's preview query
  enabled seller controls. The existing owner-only implementation was retained;
  the expectation now checks guests, buyers, foreign sellers and the actual owner.

### Local rollout and evidence limits

Three additive migrations installed the purchase controls, sale allocations and
reminders locally. A read-only check confirms all new schema fields, **17 products,
17 visible products and four orders**, with no reset, reseed or fabricated live
promotion. Existing products retain their original stock mode and no timed sale
until the seller configures one. Scheduler inspection confirms the new reminder
command alongside the existing notification delivery worker.

The seller's product Sales page exposes schedule creation, allowed edits, history
and End now. Buyer product pages show real prices, remaining quota, countdowns
and owned reminder controls; catalogue sorting/filtering use the current sale
base price. Bag and checkout show distinct priced quantities rather than charging
all units the lowest flash price. Existing portal layouts and typography remain.

Verification: the integrated isolated SQLite gate passed **1,147 tests / 23,822
assertions**, including buyer, seller, finance, notifications, product moderation,
stock variants, uploads and tracking. Final pricing/funding follow-ups passed
**86 tests / 756 assertions** after keeping subtotal arithmetic in integer cents.
Related rider API and seller order checks passed **47 tests / 1,472 assertions**.
The final recovery gate passed **77 tests / 742 assertions** for limits, reorder
behavior, reminders and notification delivery. All **448 frontend/helper tests**,
the production build, formatting and patch whitespace
passed. The first expanded run exposed two new null-stock compatibility failures
and one stale preview expectation; these were corrected before the passing run.

Local commits: `88b09e8` adds vacation mode and buyer caps; `47f5e07` adds independent
combination stock; `79be6bf` retains the owner-only storefront preview expectation;
`365d1f8` adds timed pricing, retained quota allocations and durable reminders.
`42fd462` exposes the seller/buyer controls and priced quantities; `ef3ed0d`
clarifies a reached cap during reordering; `4c8f054` manages the deployment
scheduler. The final evidence commit hash is reported in the handoff.
The local PR draft covers the combined branch scope. No GitHub publication,
Azure deployment, Flutter edit, browser automation or PostgreSQL test reset was
performed by this delivery.

The final deployment review found that schedule registration previously lacked a
managed Compose process. The new scheduler shares the app image, mounted code,
environment and database dependency and publishes no port. Deployment stops it
before dependency changes and starts it only after migrations and caches succeed;
verification rejects a missing worker. The application scheduler was not started
against the local development records during these checks. Configuration checks,
registered-command inspection and controlled deployment success/failure tests
support the prepared rollout; they do not establish a running Azure service.

A final reorder check also gives a reached purchase cap its own accurate message,
instead of claiming that an empty Bag contains the remaining stock. Existing
reorder ownership, stock and retry behavior remain covered by the focused gate.


## Buyer acceptance review and polishing plan — October 19, 2026

The user published the combined shopping delivery and current `main` at
`7c1bb59` includes it. A fresh local evidence review then passed **803 backend
tests / 14,007 assertions** and **447 frontend/helper checks**. Backend tests used
isolated SQLite memory; no application behavior, development/production reset,
browser walkthrough or Azure deployment was performed during this review.

The original acceptance record remains stricter than the automated forecast:
three confirmed gaps, 23 blocked and 66 Not Run, with no inferred manual Pass.
Of the unexecuted scenarios, 57 have positive equivalent automated evidence and
nine need a particular missing interaction or boundary observation. The user
explicitly excludes those 57 likely passes from the active polishing backlog;
their existing tests remain available for related regression risk.

| Confirmed active gap | Executable evidence | Planned disposition |
|---|---|---|
| Combined location filtering | `BuyerDiscoveryService` has no location selection/filter, and catalogue URL helpers do not retain one. Reviewed shop data currently establishes address/city, not a separate province/region field. | BP01 traces the actual approved pickup-location source, handles ambiguous legacy data and implements the supported combined filter without private address disclosure. |
| Fractional rating control | The buyer search control sends a 4.5 threshold; server selection uses an integer-only rule, and an existing test labels that advertised value malformed. | BP01 aligns UI/server average thresholds and tests the real control request while preserving integer individual review stars. This finding is additional to the original cases. |
| Explicit bulk notification read | `NotificationController` supports one-notice and displayed-preview acknowledgement, with no bulk-all control/action. | BP01 adds an explicit owned snapshot action with correct badge/list refresh, replay/failure behavior and no acknowledgement of foreign or later notices. |
| Positive buyer return flow | `BuyerDisputeController` still returns `available: false`; complete buyer requests, seller decisions, escalation and repayment are absent. | Retain the positive failure and three dependent blocks under existing return/refund planning ownership. The separate negative pre-delivery check remains in BP03. |

The [polishing plan](buyer-polishing-plan/README.md) and
[case ledger](buyer-polishing-plan/CASE_COVERAGE.md) allocate every active item:
two original cases plus the additional rating finding in BP01; 15 rule/boundary
cases in BP02; six interaction checks in BP03; one scheduled reminder observation
in BP04; seven payment/prepaid-cancellation prerequisites and four buyer-return
prerequisites. This accounts for **all 35 unresolved original cases plus one
additional finding** without duplicating the excluded 57 likely passes.

The blocked expectations do not authorize a different commercial system.
Preserve COD, 10% commission, separate funding/shipping, the existing seller-funded
cap, retained redemption usage, supported shipping routes, documented note/review
limits, buyer-confirmed completion and independent cash settlement. Record a
justified aligned expectation and its actual result; changing a label or passing
an alternative behavior does not pass the unchanged original requirement.

Planning branch: `docs/buyer-polishing-plan`. It changes documentation only and
does not start the proposed coding branches, payment provider, refund flow,
publication or deployment. Coverage/allocation, relative links, privacy and patch
checks verify this planning handoff; no new runtime test or implementation-readiness
increase is claimed for writing the plan. Future code changes require their own
focused checks and build, and current results remain in this roadmap.

### Buyer search control polish: October 19, 2026

The first BP01 change adds a public **Ships from** province/area selection and
accepts the advertised **4.5+** verified average-rating threshold. Area, category,
price, stock and rating combine consistently; related results and spelling
suggestions retain those constraints. Search, paging and prepared navigation
retain the area in their URLs and distinguish different areas in cache identity.
Individual purchase reviews still require integer stars.

The source is the reviewed shop pickup city, never the seller's account residence
or a buyer delivery address. The bundled Philippine hierarchy resolves only
unique city names, including case/accent and City-of aliases. Repeated or unknown
legacy city names remain visible under All provinces and areas and are excluded
from a specific area; the control explains that limit. No street address or
private review evidence is added to the filter payload. A separately reviewed
structured pickup province is still needed to classify ambiguous legacy cities.

Verification: **60 focused backend tests / 1,260 assertions** passed using SQLite
`:memory:`, including combined filters, fractional boundaries, ambiguous locations,
private-listing exclusion, suggestions and stable pagination. **25 frontend/helper
checks** and the TypeScript/Vite production build passed. No browser walkthrough,
development/production reseed, publication or Azure deployment was performed.

Scoped engineering assessment: combined area discovery **3/10 to 7/10**, with
ambiguous-city coverage explicitly limited; advertised average-rating control
**5/10 to 8/10**. These ratings do not certify unrelated buyer flows. Bulk
notification reading and the remaining polishing gates are still in progress.
The user has also selected the full COD buyer-return prerequisites for subsequent
implementation; online payment integration remains separate.

### Direct Buy again to Shopping Bag: October 19, 2026

The requested repurchase action now uses the existing Shopping Bag. From an owned
completed purchase it adds the original quantities and exact product options at
current prices, increases matching Bag lines, then opens the Bag with the affected
available owned lines selected. The buyer edits quantities and reviews shipping,
vouchers and checkout there. The duplicate product-selection page is removed;
old GET bookmarks return to the purchase and never add anything.

The shared Bag rules still enforce current stock, approved shop scope, option
availability, purchase limits and the 99-unit line limit. One failing original
item rolls back the whole addition. An identical request retains its original
result instead of adding twice; a changed reuse rejects. Original purchases,
stock, delivery history and payment records remain unchanged by rebuilding a Bag.
Selection from the redirect is intersected with the current owned available Bag,
so a deleted, foreign or unavailable line cannot be selected for checkout.

Verification: **67 focused backend tests / 617 assertions** passed with SQLite
`:memory:`. **56 frontend checks** passed, including actual button duplicate/retry
handlers and Bag checkbox/quote selection. The TypeScript/Vite production build
passed. These checks do not claim a browser walkthrough or Azure deployment.
Scoped repurchase usability assessment: **6/10 to 8/10**. Original unavailable
items still produce a clear rejection; the buyer uses their Bag or current
product page to choose a different quantity or option.

### Explicit notification bulk read: October 19, 2026

BP01 now includes **Mark all as read** on the full notification page. The server
captures the exact owned supported unread records, including other pages, in a
short-lived database snapshot. An explicit authenticated action acknowledges only
that set. Later arrivals in the same clock tick remain unread; foreign records,
unsupported types and forged counts cannot expand the selection. Existing read
timestamps survive retries. Expired lists ask the user to reload.

The list and shared badge refresh from the confirmed response. Pending controls
prevent repeated clicks; validation, expired-session, storage and network failures
retain the last confirmed result and offer recovery. Opening the bell still
acknowledges only displayed preview records. Shared pages retain each portal's
existing shell and style. The additive snapshot migration is required before use;
it neither resets records nor alters notification delivery history.

Verification: **99 notification backend tests / 2,706 assertions** passed in
SQLite `:memory:`, including ownership, same-tick arrivals, beyond-page selection,
unchanged timestamps, expired snapshots and an injected write failure with rollback
and retry. **26 notification frontend checks** and the TypeScript/Vite production
build passed. The local environment was verified before applying the additive
snapshot migration; no production database, browser walkthrough, publication or
Azure deployment is part of this result.

Scoped bulk notification usability/correctness assessment: **4/10 to 8/10**.
The control has actual server-backed acknowledgement and recovery; fresh deployed
and user browser observations retain their separate gates. This closes the
selected BP01 code work, without declaring the other buyer polishing or return
workflows complete.

### Retained buyer rules and precise boundaries: October 19, 2026

BP02 records all 15 affected references in
[retained expectations](buyer-polishing-plan/RETAINED_EXPECTATIONS.md), preserving
the original assumptions beside the user-selected Bagoo rules. It does not
relabel a policy disagreement or an unperformed visible procedure as a manual
Pass. The planning ledger remains the original baseline.

New tests exercise one cent below/at/above a funded shop-offer minimum without
using another shop or unselected merchandise; complete percentage eligibility,
rounding and cap sequences; the exact shipping threshold; and real stored product
weights under the existing fee rule. Preview, saved order amounts, funding
commitments, stock and retry usage agree. Additional checks establish full
499/500-character seller-note persistence and 501 rejection; 999/1,000-character
reviews and 1,001 rejection; four/five/six photo boundaries; and actual photo bytes
one below, exactly at and one above 5 MB. Rejected submissions retain no new order,
review or uploaded photo.

A real custody flow remains Delivered after eight days and history reads until
the owner explicitly confirms receipt. That confirmation still cannot release
seller funds without COD reconciliation. Existing selected suites cover approved
root taxonomy, voucher slot replacement/excess rejection, seller-share caps,
original cancellation allocations, owned stage counts, review content/ownership
and independent settlement evidence.

Verification: **350 selected backend tests / 4,549 assertions**, plus the separate
**receipt-clock test / 104 assertions**, passed with SQLite `:memory:`. This batch
changes tests and documentation only; BP01 owns the last application build.
Browser procedures, a deployed reminder and the newly selected full return and
repayment workflow remain separate work. No application policy, production data,
published branch or deployment is changed by this verification batch.

Scoped boundary-evidence assessment: **5/10 to 8/10**. The missing exact money and
field sequences now have direct checks and saved-value evidence; overall buyer
or project readiness is not raised for unobserved interactions or unbuilt returns.

### Selected complete buyer-return contract: October 19, 2026

The user brought full post-delivery returns and their prerequisites into the
current goal. The [return delivery plan](buyer-returns-plan/README.md) separates
requests/review, real reverse custody and verified manual COD repayment. Buyers
explicitly keep seven elapsed days from evidenced physical delivery, including
Completed orders. Receipt confirmation and seller settlement remain independent.

The plan retains original item/unit and voucher amounts, shipping treatment,
original 90/10 records, unpaid-proceeds holds/adjustments and paid-seller recovery.
Actual custody, cash backing, payment proof and buyer receipt are independent
acceptance gates. New cases cannot fabricate failed deliveries or money movement.
Separate future estimates and cross-role rejection/retry/rollback checks are
recorded before implementation. This documentation establishes scope, not a
working return feature, browser result, published branch or production repayment.
No implementation rating changes in this planning delivery.

### Buyer-return requests and review: October 19, 2026

R01 replaces unavailable buyer/seller return areas with real owned queues,
requests and retained review history. Original purchased units can be requested
through exactly seven elapsed days from evidenced delivery/counter collection,
including Completed orders. Actual forward hub receipts remain required; a
legacy delivered label is insufficient. New claims reserve original unit
positions without changing order status, original prices, delivery or money.

The form requires meaningful 10–1,000-character details and one to five actual
private JPG/PNG/WebP images, at most 5 MB each. Protected downloads verify owner
or current review authority and the stored content hash. Failed case, notice or
journal writes roll back and remove only new unused uploads. Identical retries
retain the saved case; changed/stale commands cannot replace it.

The current eligible seller has three elapsed days to approve or reject with an
exact retained reason. Buyers can escalate a rejection within its three-day appeal
period or an unanswered overdue request. Current admins record reasoned decisions.
Approval grants a separate seven-day physical drop-off period; it does not record
intake or repayment. Unused expiry/withdrawal is an explicit retained action.
Owned history remains accessible under supported restrictions; closed accounts
cannot use it. In-app notices link to the actual case without exposing photos.

Purchase history and details expose only server-authorized requests and saved
cases. The Return/Refund filter counts owned original orders with return history
while preserving their actual Delivered/Completed status. Buyer, seller and admin
screens use their existing portal shells, upload hints, readable empty states,
exact feedback, decision timeline, pending controls and recoverable errors.

An unresolved return blocks seller release and closure of its buyer/seller
accounts. A seller payment already made remains verified with its original proof,
amount and ledger; a return within the remaining window does not make it unpaid.
The complete reverse parcel route and COD repayment remain R02/R03 prerequisites,
so this result does not establish that a product or money has been returned.

Verification: **219 unique backend tests / 8,054 assertions** passed with SQLite
`:memory:`, including existing settlement/closure/notification/access regression,
precise clocks, real file bytes, late replay, failed writes and retained paid
settlement evidence. **47 frontend checks** and the TypeScript/Vite production
build passed. The PHP groups were rerun sequentially after parallel temporary-file
interference; only the passing isolated runs count as evidence. No browser,
production database, publication or deployment is included.

Scoped request/review assessment: **2/10 to 8/10**. Buyers now have backed requests
and exact seller/admin feedback; overall return/repayment readiness is not raised
until actual custody and financial acceptance pass. The additive return-record
migration is required before these routes are used; no database reset is needed.

### Buyer-return reverse custody: October 19, 2026

R02 gives an approved case its own return label and parcel, separate from the
original successful delivery. The destination Bayan Hub records actual intake,
measured weight, condition and private evidence. Return manifests traverse the
original Mother Hub route in reverse; repeated endpoint hubs retain their actual
route positions. Assigned handlers and current fleet, driver and capacity checks
govern each departure and receipt. The origin hub stages the parcel for the
original seller's evidenced collection and acceptance or condition dispute.

The scan workstation identifies a return label and opens its case without
inventing a delivery confirmation. Missing arrivals and unexpected codes retain
their observations and current custody until actual receipt and authorized
resolution. Versioned retries, failures and private-file cleanup preserve both
manifest and case history. Original order status, delivery checkpoints, stock,
COD and paid seller records remain unchanged. The immutable delivery checkpoint
sets the seven-day request clock even when receipt creation crosses a second.

A separate immutable return-route link leaves the existing manifest table and
its database guards intact. Migration checks preserve populated old manifests,
members, events and checkpoints. Existing cash/receipt responsibilities also
prevent account closure while return custody remains unresolved.

Verification: **172 backend tests / 9,804 assertions**, **32 frontend checks** and
the TypeScript/Vite production build passed. Backend tests used isolated SQLite
`:memory:` sequentially, covering complete one/two-Mother-Hub routes, repeated
endpoints, exact deadlines, authority, capacity, discrepancies, rollback and
existing manifest/failed-return/closure regression. No browser, production data,
publication or deployment is included. R03 repayment remains open.

Scoped post-delivery return-custody assessment: **0/10 to 8/10**. The system now
records actual reverse handovers instead of stopping at approval; this does not
raise financial repayment readiness. Apply the additive return migrations before
using these routes; a database reset is unnecessary.

### Buyer-return COD repayment: October 19, 2026

R03 allocates original merchandise, the original gross 10% commission and original
shop/platform product discounts over exact purchased unit positions. Prefix
allocation retains every cent regardless of case order; platform contributions
remain signed when original promotions exceed commission. Separate immutable
adjustments preserve shipping, voucher usage, commitments/expenses, original
order status and paid seller records. Current listing prices never set repayment.

Actual seller receipt and reasoned condition review precede amount approval.
Original reconciled COD, actual seller payments, remaining unpaid seller proceeds,
shipping obligations, received recovery/funding and previous outgoing repayments
determine available backing. Offers, debts and campaign budgets are not received
cash. An original seller may record a partial recovery transfer; the named current
admin independently acknowledges it. Actual platform funding also requires a
different named receiving admin and private proof on both sides.

One fixed original-buyer repayment retains its normalized unique reference,
actual private proof and transfer attestation. Only that buyer's receipt closes
it as Repaid. Non-receipt retains the original payment under review; linked review
evidence cannot authorize another payout. A fully discounted entitlement closes
explicitly with no repayment due, without a fake zero-cash payment. Received
money and remaining seller debt remain separate after buyer repayment; debt and
unacknowledged transfer responsibilities still block account closure.

Unpaid seller release derives separate return deductions and requires renewed
consent after its basis changes. A zero remainder closes with retained evidence,
not a transfer. A prior positive seller payment remains verified using its
payment-time deductions. Finance shows actual paid proceeds, remaining unpaid
amounts, return deductions, entitlements, outgoing repayments, received recovery
and actual received funding separately from historical COD collection. Private
funding evidence stays with current admins; operational users receive no money
proofs or financial notes.

Verification: **185 unique backend tests / 10,467 assertions** passed with SQLite
`:memory:`. The 180-test financial/return regression run and the final 18-test
repayment/file run are deduplicated by method using the latest passing result.
Coverage includes full/partial returns before and after seller payment, refreshed
authorization, zero closures, two unit cases, actual funding versus offers,
shipping promotions, signed contributions, partial recovery, non-receipt,
ownership, stale/replayed requests, private content/hash access, exact real 5 MB
boundaries, database retention guards and failed outbox/file rollback.
**24 frontend checks** and the TypeScript/Vite production build passed. Browser,
deployed scheduler and real banking outcomes are not established by these tests.

Scoped COD return-repayment assessment: **0/10 to 8/10**. The application now has
an evidenced manual path from original amount to actual backing and buyer receipt.
It does not initiate bank transfers or implement online checkout payments. Apply
the additive return migrations before using the feature; retain original data.

### Buyer interaction acceptance preparation: October 19, 2026

BP03 has a prepared isolated HTTP fixture and a
[method-by-method evidence record](buyer-polishing-plan/INTERACTION_EVIDENCE.md).
The selected **26 Docker backend tests / 271 assertions** passed with SQLite
`:memory:`. Separate actual HTTP cookie sessions verified same-buyer Bag
persistence, foreign isolation/removal denial, owned removal persistence and a
pre-delivery return-form rejection in a host PHP 8.5.11 in-memory fixture.

No browser, console, loaded-image sequence, disabled-control observation or
visible failed-removal retry was executed. All six selected browser procedures
remain Not Run while explicit authorization/user execution is pending; the task
and acceptance gate remain open. The temporary fixture server was stopped. This
preparation changes no application behavior or implementation-readiness rating.

### Deployed reminder acceptance preparation: October 19, 2026

BP04's selected reminder suite passed **10 tests / 93 assertions** with Docker
PHP 8.4 and SQLite `:memory:`. The
[reminder evidence](buyer-polishing-plan/REMINDER_EVIDENCE.md) separates those
clock/ownership/retry checks and inspected schedule configuration from actual
runtime delivery. The scheduler observation is prepared but Not Run: the actual
published commit, running scheduler and a future eligible sale/subscription must
be established, followed by a genuine pre-start buyer notification and later
once-only source counts. No production setup, notification replay, publication
or deployment was performed. This remaining gate and BP03 stay open; no deployed
or overall readiness rating increases from their preparation.

### Return receipt format compatibility: October 19, 2026

Final review reproduced a valid PDF 2.0 receipt being rejected by an unnecessary
`1.x` header restriction. The private receipt inspector now accepts the numeric
PDF version reported in an otherwise content-classified PDF, retaining its EOF,
actual-byte, private hash/download and canonical-extension checks. No cash,
recipient, entitlement or repayment state rule changes.

The targeted receipt suite passed **6 tests / 15 assertions**, covering PDF 1.4,
1.7 and 2.0 plus real bytes below/at/above 5 MB. Replacing earlier repeated class
results gives **187 unique R03 backend tests / 10,471 assertions** across the
passing financial regression and latest receipt reports. This is a backend-only
compatibility fix; the last frontend build remains the verified R03 build.

Scoped receipt-format assessment: **6/10 to 8/10**. Supported PDF versions no
longer fail an arbitrary version gate; the overall R03 assessment stays 8/10.
BP03 browser observations and BP04 deployed delivery remain open.

### Existing Azure reminder runtime: October 19, 2026

Read-only checks established the applicable deployed reminder source at
`7c1bb59`, the running scheduler worker and the required four source tables. The
reminder service, console schedule and notification delivery service match the
current local source. This completes BP04's runtime-identification prerequisite,
without claiming that the newer return branch is deployed.

No future flash slots or reminder subscriptions exist in that observed runtime.
Actual scheduled delivery therefore remains Not Run. A controlled demo source
and its bounded cleanup are prepared for explicit authorization. The separate
SQLite `:memory:` fixture checks passed **4 tests / 34 assertions**, including
exact setup retry, unchanged accounts/stock/existing notices, rollback after a
failed subscription and retained cleanup history. They do not replace the real
scheduled observation. No production writes, notification replay, scheduler
restart, publication or deployment were performed; readiness ratings are unchanged.

### Buyer browser acceptance and Bag recovery: October 19, 2026

After explicit authorization, all six selected BP03 procedures passed in headless
Chromium against the production frontend assets and an isolated SQLite `:memory:`
HTTP fixture. Actual observations cover literal hostile search with no script or
unintended write, loaded option images with matching prices/stock, independent
sold-out pairs, same-buyer session persistence and foreign isolation, selected and
unselected Bag removal/recovery, and pre-delivery return-control/direct denial.
The [interaction evidence](buyer-polishing-plan/INTERACTION_EVIDENCE.md) records
the methods and precise limits; original manual statuses remain unchanged.

The failed-removal procedure first reproduced silent premature deselection and
an uncaught network exception. Removal now waits for the server result, prevents
concurrent removal, retains the item and selection on failure, and provides clear
retry/reload feedback. The observed PHP 625 selected total survives an interrupted
request; retry removes the real line and the independent session sees that result.

All six final browser procedures, **38 focused frontend checks** and the Docker
TypeScript/Vite production build pass. The host build encountered root-owned
asset permissions before the successful Docker build. No screenshots, ordinary
database reset, production middleware claim or real-device result is included.
Scoped failed-removal recovery assessment: **3/10 to 8/10**. Wider role scores and
unrelated flow readiness remain unchanged.

### Retained Azure reminder delivery: October 19, 2026

One explicitly approved demo sale/subscription established a genuine scheduled
reminder on the existing deployed core. The source was saved more than ten minutes
before start and first observed with no intent/notice. The actual delivery and
owned buyer notification share one identity and timestamp at **13:36:01
Asia/Manila**, before the 13:46 start, with the correct current product target.
Retained worker logs show the scheduled run and later successful minute cycles;
later source-wide reads still find one notification and one delivery attempt.

The [reminder evidence](buyer-polishing-plan/REMINDER_EVIDENCE.md) preserves the
material procedural limit: the notification was verified retrospectively, and
planned early cleanup was missed. The slot expired unused at 14:16 and was ended
at 17:03:08; its quota usage is zero and the original product price/stock are
unchanged. No completed reminder is replayed. A separately proposed live repeat
with an independent early-cleanup watcher awaits the user's decision; BP04's
strict live procedure remains open. No newer application code was deployed and
no overall readiness score increases from this retained-delivery verification.

### Bag recovery on narrow screens: October 19, 2026

The targeted follow-up reproduced about 14 pixels of clipped retry feedback at
320 pixels after an interrupted Bag removal. The existing quantity/removal row
now wraps and bounds its error area. Actual Chromium geometry checks keep the
message and both retry controls visible at **320, 390, 768 and 1,440 pixels**.
All **38 focused frontend checks** and the Docker production build pass again.
This completes the responsive follow-up to the same Bag recovery fix; its scoped
assessment remains 8/10, with no real-device or wider-role certification.

### Live reminder acceptance completed: October 19, 2026

The user separately approved one repeat of the bounded demo source with an
independent early-cleanup watcher. The genuine scheduler delivered one owned
notification at **17:22:01 Asia/Manila** for the **17:32** sale. It was observed
live at **17:22:21** with the exact buyer/source and current product target.
Successful later worker cycles at **17:23:01** and **17:24:01** retained one
notification. The watcher ended only that slot at **17:24:33**, seven minutes and
27 seconds before start, with no promotional units used and original price/stock
unchanged. No completed source was reset or replayed.

BP03's six browser procedures and responsive recovery follow-up, and BP04's
strict live scheduler procedure are now verified. Combined with the earlier
BP01/BP02 and R01–R03 evidence, this completes the selected buyer polishing and
manual COD return implementation scope. The retained first attempt still records
its missed early cleanup. Online checkout payments remain outside the selected
scope; newer application publication/deployment, PostgreSQL concurrent execution
and real bank transfers remain separate release limits. Overall role/readiness
ratings do not increase from this acceptance record.

### Buyer order detail: five stages and one journey — October 19, 2026

**Scoped order-detail clarity assessment: 6/10 before → 8/10 after the first layout
→ 8.5/10 after the requested follow-up.** Order progress now precedes Package
items. Item prices and the payable total use Bagoo red, while Total savings stays
neutral. Package items retains its original amounts and an expandable delivery
summary containing the actual courier service, pickup rider when supplied, seller
pickup, full recipient address and courier notes. It replaces the separate dispatch
card and removes the repeated buyer next-step panel.

The progress summary has exactly five circles: Order placed, Confirmed,
Shipped out, Order received, and Rate & review. Completed circles are Bagoo red;
the current circle is yellow, with a visible current label and distinct icons.
Assignment alone does not prove shipment. Missing original checkpoints are not
painted as completed, and cancellation, failed delivery and return travel do not
manufacture receipt or a sixth successful stage. Physical handover and the buyer's
receipt confirmation remain distinct. The final circle opens the existing review
form only for eligible completed purchases, stays pending while any purchased
item is unreviewed, and becomes Review saved after all items have saved reviews.
Existing saved-review edit controls remain with their purchased item.

Owned notices live inside Journey checkpoints, which opens by default with the
latest five updates visible. When there are more than five, See more reveals the
earlier updates above them in chronological order; See less hides only that older
history. The summary retains the server's current parcel stage, latest original
checkpoint location/time and unread count. Past checkpoint and notice dots are
filled red; the latest original checkpoint stays yellow. Exact owned checkpoint links
combine a notice with its matching scan without hiding separate Mother Hub visits.
Unambiguous order milestones may share their original row; legacy or ambiguous
updates remain visible separately. Neither disclosure nor grouping acknowledges a
notice; deliberate read actions reuse the existing guarded notification behavior.
Raw source keys, private evidence and foreign/mirrored checkpoint IDs are excluded
from the added order-specific projection.

Verification from the preceding backend change: **102 tests / 3,735 assertions**
passed with isolated SQLite
`:memory:`, covering the projection, notification privacy/ownership, original
tracking, review integrity and explicit buyer receipt. The follow-up changes only
presentation, so those backend checks were not repeated. **115 frontend checks** pass
for stage grouping/colors, partial and completed reviews, self-pickup, exceptions,
notice merging, refresh/claim behavior, package composition, zero/five/more-than-five
history boundaries and receipt price/savings emphasis. The small follow-up improves
immediate visibility without losing older scans or unread actions. TypeScript and the
Vite production build pass in Docker. These are source, rendering/helper and local
HTTP checks; no new browser, real-device or Azure deployment result is claimed.
The assessment concerns this layout only, not overall system readiness.

### Bagoo virtual wallet foundation — October 19, 2026

The user selected admin-approved virtual credits alongside COD, with wallet
equivalents for payment success, failure, cancellation, browser-close recovery,
unpaid expiry and refunds. [The wallet contract](BAGOO_WALLET.md) records this
addition and its separation from cash collections and cash seller payouts.

**Foundation milestone; superseded by the final wallet evidence below.** Additive wallet tables, immutable balanced journal
entries, non-negative user balances and owned demo-credit requests are in place.
An active verified admin approves the original requested amount once; rejection
creates no funds, and a failed journal write rolls back the entire approval.
The scoped wallet implementation assessment is **0/10 before → 3/10 for the
foundation**. Checkout, portal controls, expiry and financial integration remain
required before wallet readiness can be claimed. COD behavior has not changed.

Foundation evidence: **27 isolated SQLite tests / 47 assertions** passed for
credit approval/rejection, role restrictions, retry protection, amount format and
boundaries, transaction rollback, immutable database history and rejection of a
fabricated cached balance. These are service/database checks, not a completed
payment workbook or browser acceptance run.

### Wallet checkout and reservation recovery — October 19, 2026

Wallet checkout now creates a distinct unpaid payment for each shop order with
the original amount, purchased-item fingerprint and one-hour server deadline.
Successful payment debits the buyer and credits virtual escrow atomically.
Insufficient credits, cancelled confirmation, lost-response retries and deadline
boundaries preserve one authoritative outcome. Sellers cannot advance an unpaid
wallet order. Pre-dispatch cancellation refunds its original paid credits once.
COD retains its collection flow and has no wallet payment deadline.

Expired unpaid reservations restore stock and release original promotional
funding once. A shared voucher use becomes available again only after every
allocation belongs to a cancelled unpaid wallet payment; paid and sibling orders
retain their original usage. Original voucher evidence is never edited.

The scoped wallet payment-domain assessment advances **3/10 → 6/10**.
**105 isolated SQLite checks / 935 assertions** passed for wallet and checkout
recovery plus existing confirmation and purchase workspace behavior. A separate
cross-check passed **144 tests / 8,994 assertions** across wallet HTTP, cash
finance, secure pickup and native parcel regressions. These runs do not yet
certify the new complete wallet delivery, return and virtual-settlement flows;
those and final portal verification remain part of this active feature.

### Bagoo Wallet: payment, delivery and refunds — October 19, 2026

**Selected web/backend scope implemented and locally verified. Scoped virtual
wallet assessment: 0/10 when unavailable → 8/10 after this addition.** The earlier
foundation and checkout milestones above are historical intermediate states.
This assessment does not raise unrelated role ratings or certify real money,
browser/device acceptance or an Azure release.

Buyers retain COD as the default and can request virtual demo credits in their
existing Wallet tab. Only an active verified platform admin can approve the
original requested amount, with a reason and explicit demo acknowledgement.
Requests are limited to ₱1–₱100,000, one pending request per buyer, and an approval
cannot raise the available balance above ₱1,000,000. Rejection creates no money.
An empty wallet has a genuine zero balance; an inconsistent balance is unavailable.
Owned requests, feedback, notifications and immutable journal history are retained.

Wallet checkout reserves one exact payment per shop order for one hour. The buyer
explicitly confirms it, and debit, escrow credit and saved outcome commit together.
Insufficient funds, cancelled confirmation, stale balances, changed original
amounts/ownership and lost-response retries cannot create another charge. To pay
is separate from To ship. Expiry releases stock and eligible unpaid voucher uses
once; a paid sibling retains its original payment and shared discount allocation.

Paid parcels retain the complete seller/Bayan/Mother-Hub route, recipient, proof,
counter identity/code and buyer-only completion requirements. Rider web, counter
and native backend handovers collect no cash and create no COD journal. Tracking
and waybill copy distinguish prepaid wallet orders from COD. Flutter files were
not changed; its client needs the additive native handover contract separately.

Virtual proceeds retain the original 90% seller share, 10% merchandise commission,
discounts and separate shipping/promotion cost. Admin approval requires evidenced
buyer receipt and resolved returns. Sellers can read their own credits, with no
spending or withdrawal. Post-delivery returns use the seven-day window and actual
reverse custody; original merchandise refunds work before or after virtual
settlement, including centavo rounding and original funding allocations. A failed
delivery completing its real reverse route and seller receipt can receive one
admin-reviewed full original wallet refund. Unused funding is released atomically;
a paid voucher use is retained. The original order and custody history stay intact.
Return reasons up to 1,000 characters fit the wallet journal schema.

| Verification | Observed result and boundary |
|---|---|
| Broad buyer, returns, finance, courier, journey and secure-counter regression | 1,338 passed / one new test assertion failed because it requested a nonexistent quote field. Corrected to the actual `discount` field; its complete wallet suite passed in the following run. This was not a clean full-suite result. |
| Wallet, native contract, tracking and notification checks after the corrections | 203 passed / 7,571 assertions in isolated SQLite `:memory:`. Includes original-source payments, shared voucher expiry, handovers, returns, proceeds, authorization, retries and rollback. |
| Final refund reason/schema boundary | 10 passed / 1,230 assertions, including exact 1,000-character reasons, refunds before/after settlement and failed full-refund rollback. Overlaps the previous run. |
| Complete frontend suite | 496 passed. Final refund acknowledgement wording/guard also passed its 12 wallet rendering/handler checks; these are not browser tests. |
| Required frontend and style checks | TypeScript/Vite production build, PHP formatting and Git whitespace checks pass. |

The seven payment scenarios in [BAGOO_WALLET.md](BAGOO_WALLET.md) have executed COD
or internal-wallet equivalents. Original workbook requirements for external card
or gateway authorization remain distinct; they have not been relabelled as passing
gateway tests or overwritten in the original workbook. Real cash deposits,
withdrawals, external transfers, bank automation and external payment providers
remain outside this selected feature.

Deployment uses the normal additive `./bagoo.sh deploy` and `./bagoo.sh verify`;
no database reset or reseed is required. Expiry requires the existing deployed
scheduler. This work did not deploy, migrate/reset development or production data,
open a browser or modify Flutter. PostgreSQL trigger execution and simultaneous
contention still need their permitted runtime acceptance; SQLite and source
inspection do not establish those results.

### Registration and portal consistency — October 20, 2026

The `fix/portal-consistency` branch uses the existing Philippine address catalogue
for the buyer address book and every public web registration form: buyer, seller,
courier, logistics and first Google signup. All use `/address-locations`, with
linked region, province/area, city/municipality and barangay choices. Ancestor
changes clear descendants, slow responses cannot restore old selections, and
failed reads preserve the remaining input and expose retry controls. The server
validates region-aware submissions before storing documents or creating accounts;
older clients without a region field retain their existing submission contract.
Google signup may still omit its optional address entirely.

The shared middle-initial control accepts one or two Latin letters, with one
trailing period added on blur and saved submission. Re-entering the field allows
editing the letters. Digits, extra letters, malformed punctuation and hidden
characters retain clear feedback and fail validation. First/last-name limits,
the narrower middle control, and legacy full middle names remain supported.

Every signed-in web role can mark the captured unread notification set as read
from the bell as well as the complete list. Earlier pages are included; the
operation cannot include another account or a later unseen arrival. Double taps,
stale/expired snapshots and failed writes keep the existing recovery behavior.
The menu stays on the current page and refreshes saved read state and attention
markers. Automatic acknowledgement still applies only to rendered preview rows.

Admin navigation now groups all existing destinations under Accounts & reviews,
Marketplace, Deliveries & returns, Finance and Governance. Sections have keyboard
controls, expose expansion state, and reveal the active destination. Red sidebar
dots reflect actual admin review/credit queues and restricted listings, and owned
seller unread messages, drafts/restricted/low-stock products, eligible preparation
work and relevant unread updates. Collapsed groups retain an attention marker.
There are no numeric sidebar badges or new product-approval rules. Work dots clear
when their source is handled; simply opening a queue does not resolve its work.
Unavailable notification storage leaves other work markers usable and preserves
the notification page's explicit unavailable state.

The seller chart defaults to 30 days and offers 7 and 90 days. Completed sales use
recorded buyer-confirmation dates grouped in Asia/Manila; delivered orders awaiting
confirmation use their actual delivery dates in a separate view. This preserves
buyer-only completion and the existing sales/commission/payout definitions.
Completed orders outside seven days are discoverable, zero periods are explained,
and date labels remain bounded. Chart queries do not grow with the number of days.
Keyboard arrows can inspect dates without relying on hover. The local diagnostic
found no delivered/completed orders, so its empty sales total was accurate; the
dated-activity behavior was verified with isolated fixtures, without manufacturing
history or changing stored local orders.

| Scoped engineering assessment | Before | After | Evidence and practical limit |
|---|---|---|---|
| Registration location and initial consistency | 6/10 | 8/10 | All public roles and Google share choices, formatting and saved-value checks. Legacy requests without a region retain their prior contract. |
| Notification bulk-read access | 7/10 | 8.5/10 | Bell and full-page actions cover all five roles, ownership, later arrivals, duplicate taps, expiry and retry. |
| Admin/seller navigation clarity | 5/10 | 8/10 | Every existing admin destination remains available; active sections, real work dots and message refresh have rendering/handler and HTTP checks. |
| Seller dated activity | 6/10 | 8/10 | Stored dates, Philippine midnight, period boundaries, foreign shops, delivery versus completion and bounded queries are checked. Missing historical event dates are not invented. |

Verification: **1,465 isolated SQLite `:memory:` tests / 68,253 assertions passed**
across authentication/registration, notifications, seller flows, sidebar attention,
buyer addresses/navigation, admin/rider/hub navigation, wallet HTTP and the bundled
location catalogue. **508 frontend tests passed**, including actual component
rendering and controlled handlers for navigation and notification actions.
After the broad run, the final unread-destination integration passed another
**131 isolated tests / 3,280 assertions** across sidebar attention, notifications,
admin/seller navigation, chart behavior and navigation asset budgets. Final public
host/Google checks passed **10 tests / 192 assertions**; the final registration,
session and portal rendering/handler check passed **48 frontend tests**. These
focused runs overlap the broader counts and are not additional unique totals.
TypeScript/Vite production build, focused PHP formatting and Git whitespace checks
pass. An older registration migration assertion now compares every strict
attribute value independently of SQLite column order; production migrations and
stored review evidence were not changed. The initial broader run exposed that
assertion and two notification-outage regressions; the clean rerun above follows
their corrections.

This is repository/local verification, without browser/device acceptance or an
Azure deployment. Flutter project files, database resets and seed data are outside
this branch. No new database migration is required; deployment uses the normal
`./bagoo.sh deploy` and `./bagoo.sh verify` after user publication and merge.

### Actionable sidebar cues and seller chart — October 20, 2026

The `fix/actionable-sidebar-cues` follow-up supersedes the earlier chart mode
buttons and broad sidebar markers. The seller chart has no Completed sales /
Awaiting confirmation buttons. It prefers completed sales in the chosen period,
or automatically shows clearly labelled delivered value when only deliveries
exist. Changing the period recalculates that choice from the current figures.
The period selector, recorded Philippine dates, keyboard chart controls and
buyer-only completion rules remain intact. An empty message is centered beneath
the header instead of being separated by redundant controls.

Products never receives a sidebar dot for either admin or seller. Drafts, low
stock, existing restrictions and informational notifications remain visible in
their existing destinations without adding sidebar attention. Seller dots now
represent owned unread messages or an available preparation action, using the
same `OrderLifecycleService` flags as Review & Confirm, Pack Order and Ready for
Pickup. Shop eligibility, full order ownership, recorded wallet payment, parcel
claims, packing/route evidence and recovery holds govern whether an action
exists. Waiting for a rider, transit, delivered/completed orders and unread
status notifications do not create an Orders dot. Notification history is not
acknowledged or deleted by this calculation. Order refreshes, stage changes and
pagination include the current attention state; successful fulfillment writes
also return the saved state.

Admin dots retain real pending account/shop/correction/advertisement reviews
and wallet credit requests. Each of the five section headers has a matching
icon. Expansion, active-section visibility, grouped child links and keyboard
access remain in place; collapsed sections only mark an actionable child.

| Scoped engineering assessment | Before | After | Evidence and practical limit |
|---|---|---|---|
| Seller chart clarity | 6/10 | 8.5/10 | Redundant buttons removed, current-period activity chosen automatically, and empty content centered. Actual browser/device appearance remains unverified. |
| Sidebar attention accuracy | 5/10 | 8.5/10 | Action flags, ownership, payment, route, holds, pending reviews and cleared work are checked. Does not add new operational workflows or dots to unrelated portals. |
| Admin section recognition | 7/10 | 8.5/10 | All five headers have existing-family icons; destinations, expansion and accessible active navigation pass rendering/handler checks. |

Verification: **358 isolated SQLite `:memory:` tests / 5,919 assertions passed**
across sidebar attention, seller workflows, admin navigation, notifications,
wallet HTTP and navigation asset budgets. **511 frontend tests passed**, including
chart rendering, absent mode buttons, header icons, section controls and stale
informational props without dots. PHP formatting and Git whitespace checks pass.
The TypeScript/Vite production build passed through `./bagoo.sh npm run build`;
the host build first reached Vite but could not replace Docker-owned generated
assets, so the supported Docker build was used.

No database reset, migration, seed change, Flutter change, browser automation or
Azure deployment was performed. After user publication/merge, deploy normally
with `./bagoo.sh deploy` and verify with `./bagoo.sh verify`.

### Seller input limits and repeated hub intake — October 20, 2026

The `fix/catalogue-validation-feedback` branch addresses twelve bounded
acceptance cases through the existing seller and hub actions. The product editor
shows the limits beside its controls and gives field feedback while typing;
the server independently rejects invalid writes. Rejected updates retain the
saved listing, gallery, stock or pending return instead of reporting a save.

| Selected case | Current behavior |
|---|---|
| SEL-PROF-006 | Shop description accepts up to 500 characters; 501 is rejected. |
| SEL-PROF-008 | Logo and banner accept images up to 5 MB, with readable format/size feedback and retention of previous branding after rejection. |
| SEL-PROD-003 | Product title is 5–120 characters and description is 20–3,000, on both creation and update. |
| SEL-PROD-004 | Base selling price is ₱1.00–₱1,000,000.00 with at most two decimal places; excess precision is rejected. |
| SEL-PROD-005 | Listing stock is a whole number from 0 to 99,999; zero keeps the existing sold-out behavior. |
| SEL-PROD-006 | Publishing requires 1–9 images. Retained images, URLs and new uploads share the limit; malformed or excessive galleries fail before permanent writes. Drafts may omit images. |
| SEL-VAR-003 | Each option group accepts at most 20 values and exposes group errors. Combination stock also respects the 99,999 total. |
| SEL-INV-009 | Set/add stock actions reject invalid quantities and addition overflow without replacing the previous stock. |
| SEL-DISC-006 | A fixed sale price must be at least ₱1.00 and below the regular price. |
| SEL-VOU-004 | Newly authored codes use 4–12 letters/numbers and uppercase normalization; generated codes use the same format. |
| SEL-RET-003 | Seller rejection reasons are 10–300 characters. Other return decisions retain their existing limits; saved decision retries return their original result. |
| LOG-HUB-004 | Repeated origin intake inspection/confirmation says “Already received,” keeps the original intake time and produces one origin-hub event. |

Existing voucher codes retain their identity and original published revisions,
including older long or separated codes. Fresh demo campaigns use `DEMOSHOP100`,
`DEMOBAGOO10` and `DEMOSHIP30`; reseeding recognizes the previous codes too and
does not duplicate, rename or revive existing offers. Seeded account identities
and passwords retain their established contract.

The wider checks also found that admin attention queried optional advertisement
reviews even when their installation was incomplete. It now uses the existing
seller-promotion installation check before querying that queue. Ordinary
browsing and platform advertisement management remain available in that state;
other attention indicators keep their existing meaning.

| Scoped engineering assessment | Before | After | Evidence and limit |
|---|---|---|---|
| Seller field limits and feedback | 5/10 | 8.5/10 | Accepted/rejected boundaries, persistence, real image contents, mixed galleries, legacy codes and return retries have automated checks. Browser/device appearance remains unverified. |
| Repeated intake clarity | 6.5/10 | 8.5/10 | Inspection and confirmation report the retained intake and preserve one checkpoint. This does not establish simultaneous PostgreSQL scan behavior. |

Verification: **591 isolated SQLite `:memory:` checks / 13,587 assertions** and
**512 frontend checks passed**. The TypeScript/Vite production build passed via
`./bagoo.sh npm run build`; focused PHP formatting and Git whitespace checks pass.
The updated local case review separately records exact result evidence and
preserves workbook formatting and original files. This is bounded acceptance
work, not a new overall project rating or manual browser sign-off.

All checks cited by its **88 Pass results** were rerun against the current code:
**249 selected checks / 6,665 assertions passed**, including the unchanged rider
and finance outcomes. The twelve selected cases move from Fail to Pass; the
209-case assessment becomes **88 Pass, 106 Fail and 15 Blocked**, compared with
76/118/15 before this branch. That is 10.2% of the previous failures addressed
and a workbook match rate of 42.1%, up from 36.4%. Remaining failures include
missing larger features and documented policy differences; blocked interactions
and native concurrency are not credited as passed.

There are no schema changes in this branch. No development/production database
was reset, no real seed was run and no Azure deployment or Flutter change was
performed. After user publication/merge, use the normal additive deployment and
verification commands. Larger catalogue, dispatch and payment workflows remain
separate scope.

### Seller catalogue and worklists — October 20–21, 2026

The user selected SE01 and SE02 together on
`feat/seller-catalogue-workspaces`. The
[selected plan](seller-readiness-plan/README.md) covers seven listing/worklist
cases and fifteen store-category/publication cases. SE03 and SE04 remain
separate selections. This delivery implements those outcomes; it does not
rewrite original workbook verdicts or claim unperformed live procedures.

Approved sellers can save name-only private drafts, complete them before
publishing, and duplicate a listing into a distinct draft opened for editing.
Minimal draft edits retain the existing eligible subcategory and optional
details when those fields are omitted. Copied galleries/options and the original
product remain intact. A retained
request key returns one original create/copy result, rejects changed payloads,
and cannot recreate a removed result. New gallery files are removed on rollback
without removing old files or replacing the original saved listing.

Delete now rejects unfinished-order products without changing their listing
state. Explicit archive/deactivate remains available. Bulk actions validate the
whole owned selection before writes, skip unfinished deletions, preserve other
retained references through archival, and show individual results. Rejections
appear inside the removal dialog. Seller order search and Philippine date
filters run across the owned history before pagination, retain selections and
matching counts, and treat wildcard characters literally. Review star filters
also run on the server and show verified original purchased options.

Products expose their current variant minimum/maximum before selection and the
chosen price afterwards. Publication requires the existing title, description,
price, stock and gallery rules plus an explicit eligible department and declared
weight. Brand/condition and complete positive dimensions persist through editing
and the buyer detail view. New order items retain original declared unit weight;
the waybill sums those retained values, shows unknown legacy weight honestly,
and keeps declared product weight separate from actual hub weighing. Shipping
continues to use the existing subtotal-based policy.

Store categories are independent shop-owned merchandising groups with 1–50
character normalized unique names, two levels, saved ordering and visibility.
A product may belong to up to three groups. Hidden parents also hide their
children's public destinations, without hiding eligible products elsewhere.
Deleting a category previews its children and distinct affected products,
requires the current preview, and removes only categories/memberships. Products
with no remaining membership appear under Uncategorized. Storefront group,
department, search, sort and pagination remain separate and scoped to that shop;
none of these controls grants another shop or a new approved master category.

| Scoped engineering assessment | Before | After | Evidence and practical limit |
|---|---|---|---|
| Seller listing and worklist usability | 5.5/10 | 8.5/10 | Private incomplete drafts, copy editing, safe mixed selections, full-history filters, exact option prices and actionable rejection feedback have backend and controlled frontend checks. Live browser layout remains unobserved. |
| Storefront product organization | 2/10 | 8.5/10 | Independent categories now have ownership, naming, depth, visibility, ordering, membership and safe deletion checks. The approved platform taxonomy remains the selling boundary. |
| Listing facts and declared waybill weight | 5/10 | 8.5/10 | Required/invalid/boundary values, saved details, checkout retry and original weight retention are checked. Actual printer output and the full live cross-portal procedure remain unobserved. |

Verification: **565 isolated SQLite `:memory:` checks / 8,402 assertions passed**.
An additional navigation/asset-budget selection passed **33 checks / 855
assertions**; it overlaps the main selection and is not added as a unique-test
total. **523 frontend checks** and the TypeScript/Vite production build passed.
Focused PHP formatting and Git whitespace checks pass. Migration tests preserve
existing data and retention guards through an isolated schema round trip and
reject unsafe populated nested SQLite changes. Existing successful listing
fixtures now supply the newly required declaration/category. An outdated seed
expectation now verifies the documented separate-account behavior for an email
shared across roles; it still proves that the original buyer is never converted
or changed. Application identity rules and demo seed data were not changed.

Three additive migrations were applied to the **local Docker database only**.
Before/after counts remained 9 users, one shop, 17 products, four orders and four
order items; the hash of every original product column/value stayed unchanged.
Read-only HTTP checks returned 200 for the local home, catalogue, storefront,
Uncategorized filter and a product detail. The catalogue and storefront still
show all 17 eligible products. No development/production reset or reseed, Flutter edit or Azure deployment
was performed. These public HTTP reads are not browser or
printer observations.

Implementation commits: `bde98f4` (complete order/review filters), `ceb8c57`
(catalogue grouping/details foundation), `65a851d` (private drafts and safe
listing actions), and `2ac4224` (store category management and buyer filtering).
The final handoff records the documentation commit and combined local PR draft.
After user publication/merge, Azure needs the normal additive
`./bagoo.sh deploy` and `./bagoo.sh verify`; it does not need a database reset.
The original blocked timing, printing and native-concurrency procedures remain
separate evidence work, and unchanged policy differences retain their status.


### Seller variants, stock alerts and percentage sales — October 21, 2026

The selected SE03 and SE04 batches share `feat/seller-variants-and-sales`, based
on the merged SE01/SE02 delivery. The [selected plan](seller-readiness-plan/README.md)
covers nine targeted cases plus buyer visibility of saved listing facts. Larger
voucher, bundle and cash-withdrawal proposals remain separate selections.

Independent option rows retain full selling prices, stock and unique SKUs.
Legacy surcharge pricing remains explicit and unchanged. Row validation rejects
missing/zero/invalid prices, invalid stock and repeated codes with field-specific
feedback. Blank, repeated and excessive option names have immediate input
feedback; saved option errors appear beside their entries. Valid size/SKU `0`
values are preserved rather than treated as missing. A shared, case-normalized database SKU key protects listing and option
identities, including numeric codes and case expansion of existing valid main
SKUs. Failed claims roll back edits. Copies receive fresh row SKUs. New order
items snapshot the current selected SKU and price; later listing changes and
checkout replay cannot rewrite purchased values.

Whole 1%–99% percentage sales apply to complete option prices, including legacy
adjustments. Integer-centavo rounding, current ranges, catalogue price filters,
product selections, Bag quotes and checkout use the same terms. Running store
sale edits invalidate stale quotes while existing orders retain their original
prices. Running flash slots retain their authored terms and schedule; name-only
percentage edits do not mistake a cached base preview for a changed discount.
Both seller URL forms support sale creation, editing and ending with the same
authority. The missing subdomain aliases were added and the persisted actions
were checked on both entry points. Authored quotas account for used units plus
current physical stock without creating/reserving stock. Fixed reductions and
later price edits cannot make an option free or negative. Split flash/store purchases reserve and release their
original allocations once.

Active unrestricted stock falling from above five to five or fewer records one
durable seller alert. Further low-stock edits do not repeat it; replenishment
resets the crossing. Owner edits, checkout inventory and restoration use the
same transaction and persisted identity. Rollback retains neither a stock
change nor an alert; delivery replay produces one notification. New low-stock
products and private/archived edits do not generate operational crossings. The
existing bell opens Products, without a Products sidebar dot.

Buyers receive saved brand, New/Used condition, declared weight, dimensions,
department and visible store memberships. A short summary near the title links
to the full specifications, with readable units and explicit missing values.
Hidden memberships remain private. Selected option prices/SKUs and linked image
handlers have controlled component checks. Equal-price variants use their real
option price before selection, and sale labels use the correct regular option
price rather than an unrelated base price.

| Scoped engineering assessment | Before | After | Evidence and limit |
|---|---|---|---|
| Complete variant and sale pricing | 5/10 | 8.5/10 | Saved row prices/SKUs, collision rollback, full-price percentage arithmetic, stale quotes, original orders and cancellation have automated acceptance. Native simultaneous PostgreSQL behavior remains unobserved. |
| Durable low-stock feedback | 2/10 | 8.5/10 | Manual/inventory crossings, replenishment, replay and rollback are checked. Informational stock does not add sidebar attention dots. |
| Buyer specification clarity | 6.5/10 | 8.5/10 | Saved values reach guest/buyer responses and render in the summary/specification components; linked images and selected prices are checked. Live browser layout remains unobserved. |

Verification: **1,575 broad isolated SQLite `:memory:` checks / 34,037 assertions passed**.
The final seller/pricing/navigation follow-up passed **387 checks / 4,567 assertions**,
including the two additional boundary cases. It overlaps 385 earlier cases;
there are **1,577 distinct backend checks / 34,091 assertions** across these
selections, counting overlapping checks once.
**533 frontend checks** and the TypeScript/Vite production build passed. Focused
PHP formatting and Git whitespace checks pass. The first broad run exposed an
old exact range expectation plus three positive fixture inputs that no longer
met the existing publication/voucher rules. The repaired fixtures retain their
original behavior assertions; production rules were not relaxed.

The additive schema was applied only to the local Docker database, preserving
all original product values and counts: nine users, one shop, 19 products, four
orders and four order items. Existing SKU claims also survived the normalized
key-capacity refinement. Four read-only public local HTTP destinations returned
200, including PostgreSQL-backed price filtering/sorting. The 17 public listings
have no saved condition/dimensions; the populated listing remains one of two
private drafts. Publishing a completed draft exposes its saved facts, rather
than inventing values for older products.

Implementation commits: `6e2628b` (complete variant and percentage pricing),
`3a7ad75` (durable stock crossings), `b9a3fc2` (seller controls and buyer facts),
`0df5a9b` (valid positive fixtures) and `d66709b` (subdomain sale actions, zero
values and exact option feedback). The final handoff also records the
documentation commit and combined local PR draft.

No workbook verdicts, Flutter files, Azure deployment, development/production
reset or reseed were changed. Original live timing, printing and native
concurrency procedures retain their separate evidence status; these automated
checks do not award new observed manual passes. After user publication/merge,
use `./bagoo.sh deploy` and `./bagoo.sh verify` for additive deployment.


### Selected-product seller vouchers — October 21, 2026

The user selected `feat/seller-product-vouchers` from merged main `152a3e0`,
targeting `SEL-VOU-008`. The [contract](voucher-plan/PRODUCT_SELECTION.md) extends
the existing campaign engine with all-products or 1–100 owned eligible products,
including their variants. Code-only discovery, bundles and cash withdrawals
remain separate selections.

Seller drafts retain canonical sorted product IDs. Publication rechecks current
eligibility and stores an immutable scope. Older edit payloads cannot silently
replace an existing selection with the whole shop. Both seller URL forms support
the bounded, searchable product picker. Invalid types, duplicate/foreign IDs,
limits, stale listings and unauthorized roles have focused rejection checks.
Buyer product details show applicable offers, and collections, choices and
applied discounts explain the restriction and link only public qualifying
products. An empty qualifying catalogue is unavailable in both collection and
profile offer previews. Search, paging, retained choices, errors, retries and
out-of-order responses have controlled component checks; Enter in product search
does not submit a voucher draft.

Qualifying selected merchandise alone supplies the minimum and fixed/percentage
basis, using current full variant and scheduled-sale prices. Integer-centavo
seller-share attribution also protects tiny mixed orders. Platform stacking and
mixed-shop allocations preserve the existing slots and original 10% commission.
Redemptions retain the selected IDs; retries, cancellations, unpaid wallet
expiry and original partial/full returns keep their evidence and once-only
funding behavior. Six actual isolated COD/wallet procedures cover qualifying,
unrelated and full merchandise returns through delivery, reverse hub custody,
review, repayment and final receipt. Later listing/campaign changes preserve
those purchased amounts.

| Scoped engineering assessment | Before | After | Evidence and limit |
|---|---|---|---|
| Selected-product voucher flow | 2/10 | 8.5/10 | The existing whole-shop funding foundation now has owned product authoring, immutable scope, qualifying-only prices, buyer guidance and original cancellation/refund checks. Live browser layout and simultaneous native PostgreSQL behavior remain unobserved. This does not raise unrelated role or system readiness. |

Verification: **1,530 broad isolated SQLite `:memory:` checks / 36,081 assertions
passed**. The final affected-flow run passed **138 checks / 1,816 assertions**,
including three new boundary cases and revised preview/variant assertions; it
overlaps 135 broad cases. There are **1,533 distinct backend cases / 36,107 latest
assertions**, counting overlaps once. **542 frontend checks** passed. The final
36 voucher checks and nine selector checks passed after the last control and
test-cleanup refinements. The production TypeScript/Vite build, two asset-budget
checks, focused PHP formatting and Git whitespace checks passed.

The selected additive migration was applied only to the local Docker database.
Original column hashes and record counts were preserved: nine users, one shop,
19 products, four orders and four order items; existing local voucher records
were empty. No reset or reseed was used. All 31 read-only local public page
requests, including 17 published product details, and 34 product images returned
200, with no new Laravel error records. All 277 manifest files exist and the
compiled entry asset requests returned 200. Authenticated authoring, buyer data
and money responses were exercised with isolated fixtures rather than writes
to the development database.

Implementation commits: `d6d6dd6` (scope, authoring, quotes and original returns)
and `e6a439b` (seller selection and buyer presentation). The branch handoff also
records its documentation commit and local PR draft. Detailed evidence remains
local under `.codex/product-vouchers/`.

No workbook verdicts, Flutter code, Azure deployment or user publication changed.
After user publication/merge, use the existing additive deployment and verification
workflow; a fresh database is unnecessary. Pause selected-product campaigns
before reverting to backend code that does not understand their scope, and retain
original schema and monetary history. Automated/source checks do not award new
observed manual workbook passes.

### Cross-role readiness gap planning — October 21, 2026

Reviewed merged main `f8b2176` to prepare the
[cross-role readiness gap plan](READINESS_GAP_PLAN.md). The review traces routes,
controllers, shared services, models, seed fixtures and existing checks across
buyer, seller, courier web/native backend, logistics company/handler and platform
admin. Its six proposed deliveries separate actual read-model defects, useful
operational additions, policy decisions and unperformed acceptance work. Planning
does not select or implement the later application branches.

The first proposed fixes address concrete inconsistencies: the buyer homepage
still selects older order statuses and presents an unsupported arrival fallback;
seller reports can describe wallet proceeds as pending COD settlement and stop
their visible history at 25 rows. Deployment interruption recovery and readiness
checks also need strengthening. Existing financial date filters, recorded cash
and virtual journals, parcel guidance, returns and company joining remain the
foundations to extend, not missing modules to rebuild.

Fresh execution of `node --test tests/Deployment/bagoo.test.mjs` returned **five
passes and one failure**. The verification-success test's Docker mock does not
return the running scheduler ID now required by `bagoo.sh verify`. This is an
observed test-harness gap; it does not establish an Azure outage. Repairing the
mock and covering missing/stale scheduler behavior are proposed implementation
work, not changes made by this documentation branch. The 25 source hashes from
the prior voucher regression record still match; its backend/frontend/build
results are retained historical evidence, not a newly rerun complete gate.
An ignored diagnostic copy passed all six checks after restoring only that
mocked scheduler response, confirming the cause without editing the tracked test.

Implementation and defense ratings do not increase from writing the plan. The
earlier approximate web/backend assessments of 85/100 maturity and 80/100 defense
readiness were engineering estimates, not measured completion or worksheet-pass
rates. A 95-point target now has separate weighted rubrics and critical gates;
it remains unachieved until the selected scope has actual evidence. Whole-device
Flutter readiness and current Azure acceptance remain separate, unverified
boundaries for this review. Workbook results, application behavior, database
records and deployed environments are unchanged.

### Private login activity and device sessions — October 21, 2026

Selected addition before the proposed readiness batches, based on main `c6de561`.
Each buyer, seller, courier, logistics and admin account now has an owner-only
Login activity page reached from its existing settings. Holding/status pages
retain a narrow entry without granting operational access. The stable contract
and deployment notes are in [ACCOUNT_LOGIN_SECURITY.md](ACCOUNT_LOGIN_SECURITY.md).

Actual successful browser and rider authentication records its original time,
approximate description and encrypted network/user-agent metadata. Later activity
does not rewrite that time. Earlier browser sessions and rider tokens have
unknown sign-in times; no city or physical-device identity is invented. Owners
receive masked networks, the current session and independent 12-entry active and
90-day history pages. Stale props from a different account are hidden.

Selected-session and all-other-session sign-out require a current password or a
purpose/account/role-bound code to the verified original email. Actual browser
and bearer access is revoked. Saved-login rotation, credential fingerprints and
an account generation prevent older cookies or stale token issuance returning.
Retained action results make a retry return its original outcome without ending
a newer session. Failed code attempts persist; unspent confirmation can retry a
failed save, while committed confirmation cannot authorize another action.

Password changes retain the current browser and end other sessions; verified
password resets end all older sessions. Ordinary logout, valid remembered sign-in,
account closure and restricted access keep their existing meaning. Authentication
records are disposable when an otherwise unreferenced account can be deleted;
business-history retention remains unchanged. Sign-out does not change rider
duty, assignments, physical custody, order status, stock or money.

The additive migration was applied locally without resetting or reseeding. The
existing nine accounts, one shop, nineteen products and four orders were retained,
and the migration's identity comparison passed. Owner metadata minimization is
defined as a daily command; isolated command checks pass, while actual scheduled
execution still depends on the scheduler service. No Azure deployment or Flutter
repository change was made.

Fresh focused verification passes **289 backend checks**, including **59 session
security cases**, and a separate **361-case** wider authentication, closure,
governance and promotion selection. The frontend selection passes **555 checks**,
including **13 interaction checks** for the new controls. Those 13 checks and
the production build also pass after the final confirmation-copy update.

The complete **5,125-case backend run returned 5,096 passes and 29 failures**
(26 assertions and three errors). Eleven failures came from older guest, flash
or long-duration business fixtures. They now use real logout, an actual saved
feedback redirect, or an explicit test-only session lifetime. Production timeouts
and business assertions remain unchanged. The final **76-case recheck passes**,
including all 59 session-security cases and every affected fixture method.

Case-by-case comparison leaves **18 pre-existing failures**, each reproduced in
an isolated source snapshot of main `c6de561` with SQLite in memory:

| Existing test expectation | Cases |
|---|---:|
| Email upgrade checks referring to a removed global index | 2 |
| Card checkout silently becoming COD | 1 |
| Old rejected-account holding redirects | 3 |
| Creating a duplicate company name as a legacy fixture | 1 |
| Former company-request denial responses | 2 |
| Earlier subdomain error text, sign-in and registration expectations | 9 |

These are not a passing complete-suite result. The original full run, isolated
main comparisons and final focused reruns establish the selected feature's gate;
no remaining failure is unexplained by that comparison. The separate deployment
helper selection retains its previously recorded **five passes and one scheduler
mock failure**. Neither baseline is evidence of an Azure outage or authorization
to change the deferred readiness scope.

Local implementation commits: `a0d5a4e` (tracking and credential boundaries),
`0d0e3f4` (confirmed revocation and security checks), `885d947` (explicit flow
fixtures), and `16a23c0` (portal interfaces and interaction checks). These commits
have descriptive bodies. The combined PR description remains a local draft;
commits have not been pushed and no GitHub PR or deployment was created.

Scoped owner-visible login/session-control assessment: **3/10 -> 8/10**. The
previous browser/token stores offered no unified owner view or remote controls;
the new page provides actionable, confirmed access management with tested
ownership, expiry, replay and recovery behavior. This does not increase the
overall role, commerce, defense or physical-device readiness ratings. Descriptions
are approximate, HTTPS remains necessary for deployed private access, and actual
browser/device, mail-delivery and fresh PostgreSQL concurrency acceptance remain
separate. No browser automation was used; all database tests use isolated SQLite
in memory.

### Account settings sections and embedded login activity — October 21, 2026

Follow-up to private login activity on `feat/account-login-sessions`. The buyer's
**My Account & Security** sidebar entry now reveals personal information, email,
identity, verification, password and login-activity shortcuts while that tab is
active. Each shortcut selects the account workspace and scrolls to its real
section, using the desktop pane or mobile document and respecting reduced motion.
Anchor entry and keyboard focus are covered by the focused interaction checks.

Buyer, seller, courier, logistics and admin settings now embed the same session
controls inside their existing navigation. The compact card groups the latest
sign-in, current/other sessions and recent history. Private lists load on entry;
the hidden courier privacy tab makes no request. Independent pagination and
refresh stay within settings. Account changes hide previous data and abort old
requests; malformed responses, expired authentication and failed refreshes show
honest recovery feedback. Confirmed sign-out retains its existing password/email,
ownership and retry protections. The direct security page remains a compatibility
and restricted-account entry using those same controls.

Verification: **568 frontend checks pass**, including **26 session/settings
interaction checks**. All 26 focused checks and the TypeScript/Vite production
build pass after the final accessibility and heading refinements. The unchanged
backend session-security selection passes **59 checks / 573 assertions** with
isolated SQLite in memory. No full backend rerun was needed for this interface
refinement; the previously recorded baseline failures remain unresolved. No
browser automation or physical-device UI check was performed.

Scoped settings-navigation/interface assessment: **6/10 -> 8/10**. Previously,
owners scrolled a long account pane and left it to manage sessions. Section
shortcuts and embedded controls keep that work in the existing portal. This is
an interface assessment, not an increase in whole-project security or defense
readiness. No database migration/reset, Azure deployment or Flutter change was
made for this follow-up. Local commits and a separate local PR draft are prepared
for the user's publication; nothing is pushed by this session.

Local implementation commits: `71e0a31` (shared session controls and private
settings loader) and `783ba59` (section navigation, portal integration and
interaction checks). The combined branch PR title/body remain a local draft.

### Buyer account highlight and identity presentation — October 21, 2026

Follow-up to the settings sections. The active submenu item now follows the
section at the viewing edge during manual or automatic scrolling. Passive scroll
listeners share one pending animation frame; viewport changes and observed
content-size changes recheck positions. The short last section is recognized at
the bottom. Observing does not move focus, scroll, change history or request data;
listeners, observers and pending work are removed when leaving the account view.

The buyer menu now follows personal information, email, password, **Identity
correction**, then Login activity. Verification, correction requests and decisions
are grouped inside Identity correction. An older verification anchor resolves to
that group, and the email section has one unique anchor. Content headings use
matching icons. Buyer correction controls have consistent spacing, boundaries,
focus treatment and touch sizes, and the initial ID-upload trigger supports a
keyboard. The recorded identity, review/evidence rules, request destinations and
file formats/size limits remain unchanged. Verification copy no longer promises
unimplemented priority dispatch or unlimited payment privileges.

Verification: **574 frontend checks pass**, including **55 focused settings,
scrolling and rendered-form checks**. **50 identity-review/account-settings PHP
checks pass with 776 assertions** using isolated SQLite in memory, and the
TypeScript/Vite production build passes. The checks cover every menu section,
mobile scrolling, resize/content changes, bottom-of-pane selection, cleanup,
unique anchors, icon headings and separate verification/correction forms. This
was static/server-rendered and simulated-interaction verification; no browser
automation or physical-device UI check was performed. The earlier complete
backend baseline remains outside this interface selection.

Scoped buyer account navigation/identity-interface assessment: **8/10 -> 8.5/10**.
The highlight reflects the visible section, and related identity work has one
clear group with readable controls. Overall security, role, lifecycle and defense
readiness ratings are unchanged. No database migration/reset, Azure deployment
or Flutter change was made. Local implementation commits are `a0b7436` (visible
section highlighting) and `7fa343d` (identity grouping, controls and headings).
The combined PR title/body remain a local draft; this session has not pushed.

### Compact buyer workspace and horizontal order filters — October 21, 2026

The buyer profile's large heading, subtitle and Continue Shopping strip are
removed from the visible layout. A screen-reader heading retains page context,
and the existing adaptive measurement gives the workspace the reclaimed height.
Purchase and order-issue filters use one horizontally scrollable row with
non-wrapping buttons, keyboard focus and contained horizontal overscroll.
Desktop wheel containment permits horizontal trackpad/shift-wheel gestures over
an overflowing filter row while still containing vertical input over stationary
panels. Counts, filter requests and tracking refresh retain their existing rules.

The **55 focused buyer/settings/session checks and TypeScript/Vite production
build pass**. Existing checks now cover horizontal gestures and the removal of
the visible strip; no browser automation was used. No backend, database, Azure or
Flutter change was made. Scoped workspace/filter-layout assessment: **7/10 ->
8/10**, based on recovered space and accessible filters without a second row.
This does not change overall role or system readiness. The refinement is saved
in one local commit with the combined PR description kept as a local draft.

### Buyer sidebar fit and panel alignment — October 21, 2026

The desktop sidebar uses its natural content height, capped at the workspace
height for an expanded menu that needs scrolling. Its card no longer stretches
below the links. Tracking refresh sits beside the horizontal order filters, so
the left sidebar and right control panel start at the same height. The refresh
control keeps an accessible label and uses its icon on narrow screens.

The **55 focused buyer/settings/session checks and production build pass**.
An existing interaction check now identifies filter/refresh actions by label
instead of their former position. No browser automation or backend/database,
Azure or Flutter change was made. Scoped sidebar/alignment assessment:
**6/10 -> 8/10**, based on fitting the visible menu and aligned panel tops.
The fix is one local commit; the combined PR description remains a local draft.

### Logistics page loading and recovery — October 21, 2026

Reviewed the current company, Bayan Hub and Mother Hub web workspaces on
`fix/logistics-page-reliability`, starting from merged main `a3f4d6a`.
Company ownership still does not grant physical scanning. Navigation and
Overview now use the current handler assignment before offering Scan Station;
company-only operators open the parcel registry instead. Other existing
company, handler, custody and cash permissions remain unchanged.

Logistics exceptions now have a Bagoo recovery response even with local debug
enabled. Direct visits use a small server-rendered page that does not depend
on the frontend build; Inertia visits receive the matching `Hub/Error` page.
JSON requests receive a safe message. Missing and foreign records retain their
404/403 status, form validation retains 422, and native APIs retain their
existing response contracts. Throttling/method headers survive. Unexpected
failures have a log reference instead of database or framework details.
Failed updates never receive an automatic retry link. A stale selected hub
can be recovered by explicitly opening a currently authorized facility;
an error response does not resolve potentially broken shared page queries.

Verification evidence:

- The 27 new isolated page/access/recovery checks pass, including both logistics
  entry points, assigned/unassigned operators, private records, expired forms,
  actual database query failure, stale selection and asset-version recovery.
- All 580 frontend checks and the TypeScript/Vite production build pass.
- The wider 1,094-case logistics, buyer-return, COD, portal-access,
  notification, browser-session and native-contract selection produced
  1,093 passes and one counter query-count failure. Session timestamp updates
  can vary with wall-clock seconds; the comparison now freezes time without
  changing its equal-query or two-checkpoint-batch requirements. Its focused
  recheck passes with 1,612 assertions. The wider selection was not repeated
  after this test-only correction; this is not a fresh green full-suite claim.
- The 140 eligibility/company-request checks pass after repairing an old
  duplicate-name fixture and asserting the current restricted-account JSON
  response. Thirty-nine registration/detail-consistency checks also pass.
- Direct local HTTP checks across one company administrator and three hub
  handlers load all 134 authorized page requests at the root and hub aliases.
  Forty deliberately forbidden/missing requests retain their expected status
  and show Bagoo recovery without a framework screen. Seventy-eight component
  renders use the actual returned local props, without browser automation.
- Eight guest/address requests return 200. All 134 static-asset checks across
  both local aliases return 200 with the correct content type, including the
  eleven hub page entries and their dependencies.
- Nineteen selected PostgreSQL business-table snapshots remain identical.
  Existing accounts, products, orders, parcel checkpoints and cash records
  were not reset or reseeded. Automated mutations use isolated SQLite memory;
  runtime checks use ordinary sign-in/out and page reads.
- Azure access recovered after initially refused requests. All four demo
  logistics accounts signed in, and all 67 authorized page requests returned
  200 without technical error traces. Four expected permission denials and
  sixteen deliberately missing-record/page requests returned 403/404. These
  still use generic error responses; a separate guest request confirmed the
  generic Not Found page, while registration returned 200. Deployed pages do
  not yet expose the new scanner permission prop or use Bagoo recovery.
  These checks verify the existing deployment, not this unpublished branch.
- A follow-up traversal of the offered logistics lists, pagination and detail
  links returned 200 for all 46 requests across those accounts. Live detail
  coverage includes one handover report and three hub activity reviews; this
  pass did not exercise populated manifest, COD or return detail pages. All
  64 deployed JavaScript/CSS assets needed by the ten existing hub entries
  and application shell returned 200 with the correct content type. No new
  scan-capability prop is present in the deployed accounts' page responses.

Scoped local navigation/page-recovery assessment: **5/10 -> 8/10**. This measures
permitted links, readable recovery and verified local page loading; it does
not raise overall logistics, lifecycle or security readiness. The normal
Azure pages checked load successfully; the new navigation and recovery still
require user publication, deployment and a deployed recheck. No Azure deployment,
VM schedule change, Flutter change, GitHub push or merge was performed. The
combined PR title/body are prepared locally; the Azure acceptance requirement
remains open.

Implementation and verification commits: `17a95bb` (portal navigation/recovery),
`2cabb3b` (current eligibility fixtures) and `d8e920c` (stable counter comparison).
