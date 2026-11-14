# BagooPH Brainstorming Decision Checklist

Work through the source questions and proposed ideas one at a time. These are brainstorming items, not approved requirements. For each item, check it only after reviewing it against the current application and authoritative BagooPH specifications, then record whether it is accepted, changed, deferred, or rejected in the relevant implementation plan or decision record.

Source: [BAGOO_PH_BRAINSTORMING.md](BAGOO_PH_BRAINSTORMING.md) (27 topics). Every source question and every listed core-takeaway bullet is represented below.

## Dependency order and delivery tracker

Follow [BUILD_ORDER.md](BUILD_ORDER.md) for prerequisites, scope and exit gates. Original topic numbers below remain unchanged. All packages start unchecked: prior code or a completed task record is not proof that the brainstorming proposal was approved or fully verified.

For each package, record **decision:** pending/accepted/changed/deferred/rejected; **delivery:** not started/in progress/implemented/verified/merged; and **evidence:** branch/PR, checks, remaining limits. Mark its package checkbox only when its exit gate is met, or record an approved deferral and its impact on dependents.

- [ ] **B00 — Inventory existing behavior and settle shared decisions.** Topics: All 27; especially 7–9, 18, 21, 25–27. Requires: None. Decision: pending; delivery: not started; evidence: pending.
- [ ] **B01 — Verify hub topology and forward route planning.** Topics: 1–4. Requires: B00. Decision: current route contract accepted for Q1; delivery: Q1 merged in web PR #160 (`464831d`); evidence: [Q1 routing record](QUESTION_01_ROUTING.md). Full package remains open for any additional topology decisions.
- [x] **B02 — Confirm company roles, operator identities and rider placement.** Topics: 23, 24; identity part of 14. Requires: B01. Decision: accepted with corrections; delivery: selected account/scanner scope merged in web PR #160 and logistics-mobile PR #1 (`661f84f`); evidence: [worker accounts and scanner decision](QUESTION_23_24_WORKER_ACCOUNTS.md). Owner-only provisioning retained; emergency impersonation and device acceptance remain separate.
- [x] **B03 — Define shared shipment, custody and privacy contracts.** Topics: 5, 11–14, 17, 20, 25, 27. Requires: B01, B02; uses their accepted Q1 route and named-worker contracts. Decision: retain existing identity, route, evidence and privacy boundaries with explicit future transport compatibility; delivery: contract defined and locally verified on `feat/brainstorm-b03-custody-contracts`, merged in PR #161; user manual acceptance pending; evidence: [B03 contract](B03_SHIPMENT_CUSTODY_PRIVACY.md) and [roadmap results](../CORE_FLOW_ROADMAP.md#b03-shipment-custody-and-privacy-contract--november-11-2026). The 259 focused tests pass; later operational packages and remaining B00/B01 decisions retain their own gates. Mixed transport execution remains B12.
- [x] **B04 — Decide tariffs, liability and original payment allocations.** Topics: 7, 8, 17, 25–27. Requires: B00, B01, B03. Decision: user selected PHP 50/1 kg plus PHP 20 per extra started kg, seller-fault item-plus-shipping refunds, funded courier review and buyer shipping liability for in-transit change-of-mind refusal; delivery: decisions defined and tariff/allocation changes locally verified on the existing B03 branch, merged in PR #161; user manual acceptance pending; evidence: [B04 money contract](B04_MONEY_AND_LIABILITY.md) and the roadmap. Successor refund execution retains B11–B13 activation gates; this check completes the decision package, not those later features.
- [x] **B05 — Verify checkout quotes and freeze purchased terms.** Topics: 1, 2, 26; measurements and voucher part of 27. Requires: B01, B03, B04. Decision: preserve selected company/route, original money terms and supported policy versions; delivery: implemented and locally verified on the existing B03 branch, merged in PR #161; user manual acceptance pending; evidence: [B05 checkout contract](B05_PURCHASED_CHECKOUT_TERMS.md) and the roadmap. Combined B03–B05 gate: 409 backend tests / 13,986 assertions and 15 frontend checks passed, with TypeScript/build/formatting. Physical PostgreSQL concurrency and the successor refund workflow retain separate acceptance.
- [x] **B06 — Align seller acceptance and cancellation boundaries.** Topics: 6, 11–13. Requires: B03–B05. Decision: direct cancel before acceptance, seller review within 24 hours afterward, recipient disclosure after acceptance; delivery: implemented and automatically verified on `feat/brainstorm-b06-cancellation-feedback`, merged in web PR #162 (`7675682`); evidence: [B06 contract](B06_ACCEPTANCE_CANCELLATION.md) and roadmap: 258 backend tests / 7,086 assertions, 72 frontend checks, TypeScript and production build passed. User manual review remains pending in the [later-review guide](MANUAL_REVIEW_B01_B06.md).
- [x] **B07 — Verify individual scanning and actual custody transfer.** Topics: 14, 20; handover part of 25. Requires: B02, B03, B06. Decision: one named small-hub operator may receive/sort/dispatch, with a separate rider/driver for handovers; no automatic inspection-to-custody or fixed scan-count caps. Delivery: implemented and automatically verified on the existing B06 branch, merged in web PR #162 (`7675682`). Evidence: [B07 contract](B07_INDIVIDUAL_HANDOVERS.md), six COD/Wallet route journeys, 710 combined backend cases / 27,460 assertions and a final 13-case native-link check, 106 frontend checks, TypeScript/build/formatting. User review remains pending in the [later-review guide](MANUAL_REVIEW_B01_B06.md).
- [x] **B08 — Verify bags, seals and loose freight membership.** Topics: 16; cargo part of 27. Requires: B03, B04, B07. Decision: explicit automatic-lane packing, reusable sack/trip/seal separation, retained outer-package readings and loose manifest cargo; delivery: implemented with automated acceptance on the existing B06 branch, merged in web PR #162 (`7675682`); deployment/migration acceptance remains separate. Evidence: [B08 contract](B08_BAGS_SEALS_AND_LOOSE_CARGO.md) and the roadmap. Physical PostgreSQL concurrency, actual fit and later user review remain separate checks; packing does not imply sealing or custody movement.
- [x] **B09 — Connect sorting guidance, manifests and dispatch.** Topics: 15; local staging part of 27. Requires: B01, B02, B07, B08. Decision: accepted with corrections; delivery: implemented and automatically verified locally on `feat/brainstorm-b09-b10-dispatch-completion`, not pushed. Evidence: [B09 contract](B09_SORTING_AND_DISPATCH.md), [combined manual review](MANUAL_REVIEW_B09_B10.md) and roadmap. B09/B10 combined gate: 915 backend tests / 42,788 assertions, 87 frontend checks, TypeScript/build/formatting passed. Actual physical fit, device and PostgreSQL concurrency acceptance remain separate; ETA/throughput remain B14/B17.
- [x] **B10 — Verify delivery completion, earnings and cash reconciliation.** Topics: 8, 12, 25; baseline money from 26. Requires: B04, B05, B09. Decision: preserve original money/policy versions and require persisted proof/payment, actual origin receipt and current counted COD evidence; delivery: implemented and automatically verified locally on the B09/B10 branch, not pushed. Evidence: [B10 contract](B10_DELIVERY_COMPLETION_AND_CASH.md), four COD/Wallet doorstep/counter cases, rollback/retry and custody/cash/settlement regressions in the 915-case combined gate. User manual acceptance and external payouts remain separate; successor refunds remain B11–B13.
- [x] **B11 — Verify return requests and independent decisions.** Topics: 5, 8, 17, 18, 25. Requires: B03, B04, B10. Decision: accepted with corrections (`BRT-` return requests over 7-day request / 3-day review / 3-day appeal / 7-day drop-off windows, explicit `seller`/`courier` fault required on policy v2 while preserving policy 1 `'unspecified'`, voluntary post-delivery change-of-mind excluded); delivery: implemented and automatically verified locally on `feat/brainstorm-b11-b13-returns-refunds` (unpublished); evidence: [B11 contract](B11_RETURN_REQUESTS_AND_REVIEW.md), [roadmap](../CORE_FLOW_ROADMAP.md), and the 207-test Returns/continuity/checkout suite. User manual testing and B14+ timers remain separate.
- [x] **B12 — Verify reverse custody and directional co-loading.** Topics: 5, 17–19; cargo part of 27. Requires: B03, B08, B09, B11. Decision: accepted with corrections (actual intake and reversed hop custody, volumetric/50-unit return sacks without mixing forward and reverse items inside one bag, shared directional `LogisticsTransportTrip` vehicle grouping without minimum parcel-count holds, atomic arrival and quarantine recovery); delivery: implemented and automatically verified locally on `feat/brainstorm-b11-b13-returns-refunds` (unpublished); evidence: [B12 contract](B12_REVERSE_TRANSPORT_AND_SHARED_TRIPS.md), `BuyerReturnBagTest`, `BuyerReturnCargoTest`, `BuyerReturnCustodyTest`, `BuyerReturnRecoveryTest`, `SharedTransportTripTest`, and `WholeTransportLossTest`. Physical fleet/device checks and B14 staging clocks remain separate.
- [x] **B13 — Verify refund execution and financial adjustments.** Topics: 7, 8, 17, 25; voucher/debt part of 27. Requires: B04, B10–B12. Decision: accepted with corrections (`refund_policy_version => 2` active for live checkout with policy 1 compatibility preserved; cumulative item + paid-shipping allocation excluding voucher subsidies; independent loss review, whole-transport and unarrived-bag loss accountability; unused issuance/company-contribution/escrow-advance source restoration & quarantine physical release; sensitive Inertia history encryption & actor-bound form resets); delivery: implemented and automatically verified locally on `feat/brainstorm-b11-b13-returns-refunds` (unpublished); evidence: [B13 contract](B13_REFUND_EXECUTION_AND_FUNDING.md), `Finance`/`Security`/`Wallet` (390 tests / 25,717 assertions), `Returns`/continuity (207 tests), B11–B13 frontend suite (246 checks), TypeScript, Pint, and isolated Vite build. Live migration, PostgreSQL concurrency, and user manual testing remain separate; B14–B17 stay out of scope.
- [x] **B14 — Add ETA and state-aware monitoring.** Topics: 9, 18, 20, 21, 25; staging part of 27. Requires: B06–B13. Decision: accepted with corrections (`Asia/Manila` `16:00` cutoff + dynamic route-aware `EtaCalculationService` windows stored with `initial_min_at`/`initial_max_at`; `8` keyed `SlaClock` types paused during `STAGED_FOR_FINAL_MILE`, `HandoverHold`, `OrderCancellationRequest`, future retries, and `sla_pauses` Force Majeure without mutating immutable `pickup_claims.expires_at`; `48h` forward vs `96h` reverse asymmetric transit timers; immediate single-item return dispatch without 20–30 parcel batch holds; overdue return reminders/escalations/buyer-expiry closures without auto-approving refunds or moving money; dry-run & lock-ordered `monitoring:scan-sla`); delivery: implemented and automatically verified locally on `feat/brainstorm-b14-b15-monitoring-recovery` (unpublished); evidence: [B14 contract](B14_ETA_AND_STATE_MONITORING.md), [roadmap](../CORE_FLOW_ROADMAP.md), the **14 B14/B15 backend cases (756 assertions)** within the **175-test / 11,513-assertion** post-edit PHPUnit verification run (`EtaCalculationServiceTest`, `SlaMonitoringAndEtaTest`, `DispatchRecoveryAndInvestigationTest`, and 7 regression suites), **54 frontend checks** (`eta-monitoring-recovery`, `portal-consistency`, `checkout-route-preview`, `order-tracking`), TypeScript, Pint, and isolated Vite build. Live PostgreSQL scheduler concurrency, user manual acceptance in `/home/andy/Downloads/BAGOO_B14_B15_MANUAL_TESTING_GUIDE.md`, and `B16` sanctions / `B17` throughput remain separate.
- [x] **B15 — Add dispatch recovery and missing-parcel investigation.** Topics: 7, 9, 10, 18, 21, 25. Requires: B02, B09, B13, B14. Decision: accepted with corrections (`no_available_rider` vs `pickup_paused` vs `no_service_coverage` coverage taxonomy; 4-step `dispatch_escalations` ladder with `Pickup Delayed — Reassigning Rider` notice at 24h, deferred unapproved surge note, hub emergency sweep, and strictly-unclaimed 72h penalty-exempt auto-cancel reversing paid Wallet/vouchers cleanly; capacity-aware first-mile & pre-departure final-mile rider reassignment keeping post-custody handovers immutable; stalled-hub & barangay density overload telemetry; non-loss `MissingParcelInvestigation` (`proves_loss = false`) auto-closing to `'recovered'` on verified physical arrival scan with Admin-only `'confirmed_lost'` confirmation and explicit `insurer_funding_status = 'deferred_no_insurer_pool'`); delivery: implemented and automatically verified locally on `feat/brainstorm-b14-b15-monitoring-recovery` (unpublished); evidence: [B15 contract](B15_DISPATCH_RECOVERY_AND_INVESTIGATION.md), [roadmap](../CORE_FLOW_ROADMAP.md), and the same **175-test / 11,513-assertion** PHPUnit + **54-check** frontend + TS/Pint/Vite gate. External insurer/security-deposit pool funding, unapproved surge payments, live PostgreSQL concurrency, manual testing, and `B16–B17` stay separate.
- [x] **B16 — Apply evidence-based restrictions and review moderation.** Topics: 11–13, 21, 22, 25; debt restrictions part of 27. Requires: B02–B04, B11, B13–B15. Decision: accepted with corrections (`EnforcementSanction` scoped restrictions & `warning` alongside append-only `RestrictionDecision`; `EnforcementAppeal` with independent `reviewer_id !== issued_by_id` separation; non-negative `wallet_accounts` using `FinancialPenalty` + auto-releasing `unpaid_debt_hold`/`payout_hold`/`catalog_visibility_restricted` shadowbans; geo-fenced `₱150` SLA pickup penalties excluding `sla_pauses`, `hub_pickup_pauses`, `no_service_coverage`, `no_available_rider`, `penalty_exempt`, and last-scan-only claims; `ScaleDiscrepancyLog` tariff delta logging; non-punitive `ModerationSignal` + `Buyer/OrderDetail` anti-scam check-ins; 30-day & seller-fault-only review gating; `held_velocity_review` burst holds & gibberish/spam filtering; `Seller` review disputes preserving genuine `excluded_courier_issue` delivery complaints off product/shop averages; buyer appeals (`restored`); and decoupled 1–5 `DeliveryFeedback`); delivery: implemented and automatically verified locally on `feat/brainstorm-b14-b15-monitoring-recovery` (unpublished); evidence: [B16 contract](B16_RESTRICTIONS_AND_MODERATION.md), [roadmap](../CORE_FLOW_ROADMAP.md), the **10 B16 backend tests (791 assertions)** inside the **458-test / 10,246-assertion** 19-suite sequential PHPUnit verification run across `EnforcementAndPenaltyTest`, `ReviewModerationAndCourierFeedbackTest`, `SlaMonitoringAndEtaTest`, `DispatchRecoveryAndInvestigationTest`, and 15 regression suites, **58 frontend checks** across `moderation-restrictions`, `order-tracking`, `eta-monitoring-recovery`, `portal-consistency`, and `checkout-route-preview` (`commerce.test.mjs` separately retains its 1 pre-existing untouched `Out of stock` casing mismatch at 35/36), TypeScript, 37-file Pint check, and isolated Vite build (**3,268 modules**). Live PostgreSQL migration (`2026_11_14_050000_create_b16_restrictions_and_moderation_tables.php`), hardware scale OCR integration, user manual acceptance in `/home/andy/Downloads/BAGOO_B16_MANUAL_TESTING_GUIDE.md`, and `B17` throughput remain separate.
- [ ] **B17 — Optimize scanning throughput and complete acceptance.** Topics: 14–16, 19, 20; all topics for regression. Requires: B07–B16. Decision: pending; delivery: not started; evidence: pending.

Start with **B00**, then **B01 (question 1 together with questions 2–4)**. Keep every original question/idea checkbox below as a separate decision record. No application implementation is started by adding this plan.

## Original questions and idea decisions

## 1. Parcel Route Automation Calculation

- [x] Resolve the source question: first question for me what is the best way to calculate the parcel automation track meaning the first thing that the buyer does is to place order right? what could possiblely the automated route calculation for the hubs like from seller preparing parcel to hubs going to hubns etc?

Proposed ideas to review:

- [x] Review/decide: Pre-calculated at checkout using Seller's Barangay (Origin Hub) and Buyer's Address (Destination Hub).
- [x] Review/decide: Relay flow: Seller → Origin Local Hub → (Regional Mother Hub if needed) → Destination Local Hub → Buyer.
- [x] Review/decide: Small parcels are consolidated into sealed Mother Bags; barcode scanning at every station validates the route and prevents misrouting.

Decision: **accepted with corrections**. Use full company-scoped address coverage, preserve the confirmed route, and retain actual scan-based custody. The bag sentence uses the existing seal/manifest rules; further throughput changes remain in B08/B17. See [the implementation and verification record](QUESTION_01_ROUTING.md).

## 2. Delivery Distance Scenarios (Short vs. Medium vs. Long)

- [ ] Resolve the source question: can you give the xample of short delivery vs medium long vs long case scenarios? on how our system will calcualte this automation?

Proposed ideas to review:

- [ ] Review/decide: Short (Same Town): Origin Hub == Destination Hub. Seller → Local Hub → Buyer (2 hops, 0 linehaul trucks, <24h).
- [ ] Review/decide: Medium (Nearby Towns / Same Region): Seller → Origin Hub → Mother Bag → Regional Mother Hub → Destination Hub → Buyer (4 hops, 1–2 days).
- [ ] Review/decide: Long (Cross-Region): Seller → Origin Hub → Regional Mother Hub → Central Sorting Hub (NCR) → Destination Regional Hub → Destination Hub → Buyer (6 hops, multi-manifest linehaul).

## 3. Hub Topology (Local Hub vs. Mother Hub vs. Central Sorting Hub)

- [ ] Resolve the source question: wai t i only have local hub and mother hub? what is the central sorting hub? why not having it creates a hole in my web?

The source provides no core-takeaway proposal for this topic.

## 4. Mother Hub vs. Sorting Hub Equivalency

- [ ] Resolve the source question: so i alreayd have mother hub and it does thesame job? as sorting hub?

Proposed ideas to review:

- [ ] Review/decide: A Mother Hub IS the sorting hub (is_mother_hub = true).
- [ ] Review/decide: Local Hub: Neighborhood outpost for rider pickups and final-mile doorstep deliveries.
- [ ] Review/decide: Mother Hub: Regional sorting warehouse that unloads container trucks, sorts loose parcels, packs Mother Bags, and dispatches trip manifests.

## 5. Reverse Logistics & Returns Routing

- [x] Resolve the source question: next question to put in the docs is. we already have the good image or flow how to implement the automated hubs passes, hwo about reverse logistics? like returning products?

The source provides no core-takeaway proposal for this topic.

Decision: **accepted with corrections in B11 and B12**. Post-delivery returns allocate dedicated `BRT-` barcodes over the reversed original hub route with hub intake, measurement, optional return sacks/shared directional transport, and evidenced seller receipt without altering the original delivery record. See [B11](B11_RETURN_REQUESTS_AND_REVIEW.md) and [B12](B12_REVERSE_TRANSPORT_AND_SHARED_TRIPS.md).

## 6. Order Cancellation Window & Restrictions

- [x] Resolve the source question: great next question, when does the buyer able to cancel the order? and when it shouldn't be?

Proposed ideas to review:

- [x] Review/decide: Allowed: During Pending/Unpaid (instant cancel, immediate refund) and Pre-Acceptance Processing (instant cancel with 0 seller approval before seller clicks "Accept"). Once seller clicks "Accept", cancellation requires seller approval within 24h.
- [x] Review/decide: Prohibited: Once status reaches Ready to Ship (waybill printed), Picked Up, In Transit, or Out for Delivery. The buyer must receive the item and file a Return/Refund instead.

Decision: **accepted with corrections in B06**. Direct cancellation is before acceptance; accepted/packed orders require seller review within 24 hours. Expiry leaves the order/payment unchanged. Printing is separate from readiness. Only an actual paid Wallet source is refunded; unpaid COD has no cash repayment. Original policy-1 purchases retain their rights. See [B06](B06_ACCEPTANCE_CANCELLATION.md).

## 7. Missing Parcel Protocol, Liability, and Terms & Conditions

- [x] Resolve the source question: next what should we should do when the buyer product is missing in the middle of the transit? or whatever progress? what should be the optimal way? and who is liable and why terms an condition will come into here?

Proposed ideas to review:

- [x] Review/decide: Action: If transit between hubs exceeds 48–72h, auto-flag an investigation case; buyer receives an immediate 100% refund.
- [x] Review/decide: Liability: Last entity that scanned custody (rider or hub handler) is held liable. Platform reimburses the seller via courier security deposit/insurance.
- [ ] Review/decide: Terms & Conditions: Enforces declared value liability caps, exempts prohibited items, sets strict filing windows (7–14 days), and establishes digital scan receipts as the sole source of truth.

Decision: **accepted with corrections in B13 and B15**. Passing transit timeout thresholds (`48h` forward / `96h` reverse) or a manifest `'missing'` discrepancy automatically opens a non-loss `MissingParcelInvestigation` (`proves_loss = false`, `liability_determined = false`) rather than immediately declaring theft or issuing an unverified refund. Genuine physical scans auto-close custody-absence cases as `'recovered'`; permanent loss (`confirmed_lost`) requires Admin confirmation with evidence and connects to B13 `ShipmentFinancialPlan` / `ShipmentLossReview` (`B04` / `B13`). External courier security-deposit/insurer pool funding remains explicitly deferred (`insurer_funding_status = 'deferred_no_insurer_pool'`), and legal T&C liability caps remain outside executable monitoring/recovery rules. See [B13](B13_REFUND_EXECUTION_AND_FUNDING.md) and [B15](B15_DISPATCH_RECOVERY_AND_INVESTIGATION.md).

## 8. Shipping Fee Liability on Returns, Damages, and Failed Deliveries

- [x] Resolve the source question: we know we have shipping fee in the process when it is perfect?, however when some product breaks or returned or missing we know it is returned for whatever reason but who handles the shipping back to it? if we knwo there is shipping fee from going from seller to me and i didn't want the proudct so i have 0php wasted, how about the shipping fee? what happend and also what happened if it is gonna return?

Proposed ideas to review:

- [x] Review/decide: Seller Fault (Defective/Wrong/Fake): Buyer gets 100% refund (product + shipping); seller pays the return shipping fee deducted from store wallet.
- [x] Review/decide: Courier Fault (Lost/Damaged in Transit): Buyer gets 100% refund; seller gets full item reimbursement; courier absorbs shipping fee loss and damage cost.
- [x] Review/decide: Buyer Fault (Change of Mind / Joy Reserving): Buyer pays return shipping; original shipping is non-refundable. Repeated rejected COD orders trigger account restrictions.
- [x] Review/decide: Riders: Always get paid for completed trips regardless of parcel outcome.

Decision: **accepted with corrections for the successor policy**. Seller-fault returns include net item plus allocated buyer-paid shipping; courier fault needs funded review; buyer refusal after shipment carries actual shipping liability. Voluntary post-delivery change of mind remains excluded. Earned rider amounts require actual milestones, not unconditional pay. See [B04](B04_MONEY_AND_LIABILITY.md); successor `refund_policy_version => 2` execution is now active in B11–B13 for new checkouts while historical policy-1 orders remain operational.

## 9. SLA Timeouts, Expected Delivery Calculation, and Seller Fulfillment T&C

- [x] Resolve the source question: great but when does the system flag as something when the seller is taking too long to pack? or when one role is taking too long? and who calculates the expected time of delivery how do we have the calculation for the buyer to know when will the product will come here? and should there be antoher terms and condition here too?

Proposed ideas to review:

- [x] Review/decide: SLA Timers: Seller packing timeout is 48h (warning at 36h, auto-cancel + store penalty at 48h). Individual rider shift timeout is 2h upon ticket acceptance; overall order pickup SLA follows the 4-step 48h ladder. Hub transit allows 48h before auto-escalation.
- [x] Review/decide: Estimated Delivery Formula: Pre-calculated at checkout: Seller Prep (1–2 days) + Hub Hops (1 day per hop) + Final Mile (1 day). Displayed as a date range.
- [x] Review/decide: Fulfillment T&C: Seller accepts 48h auto-cancel and late shipment listing penalties; Force Majeure (typhoons/disasters) pauses timers; Buyer agrees to contact responsiveness.

Decision: **accepted with corrections in B14 (store penalties stay B16)**. `EtaCalculationService` computes route-aware `Asia/Manila` ETA min/max windows (applying the `16:00` PHT cutoff, same-hub bypass vs Mother Hub hops, active Force-Majeure pause buffers, and overdue revisions) without persisting rows on read-only checkout quote previews. `SlaClockService` tracks distinct state-aware clocks (`seller_acceptance_pack` warning at `24h`/`36h` and breach-escalating at `48h` without auto-canceling or penalizing sellers prior to `B16`, plus `pickup_dispatch_wait`, `hub_idle_sorting`, `final_mile_staging`, and `forward_interhub_transit` `48h`), while `SlaPauseService` pauses and shifts running clocks idempotently during Force-Majeure advisories (`sla_pauses`) without mutating immutable `pickup_claims.expires_at`. See [B14](B14_ETA_AND_STATE_MONITORING.md).

## 10. Unclaimed Rider Pickups and First-Mile Dispatch Stalls

- [x] Resolve the source question: my system is when proudct is ready for pickup when flag by the seller we know we have riders, what if no one dares the pickup, what should the product or our system behaves? delayed? and what if there is literally no one picking up?

Proposed ideas to review:

- [x] Review/decide: Status Change: Automatically flips to Pickup Delayed — Reassigning Rider after 24h; displays honest delay notices to both buyer and seller.
- [x] Review/decide: 4-Step Escalation Ladder: Open Pool broadcast (0–12h) → Direct Shift Assignment to low-load fleet rider (12–24h) → Surge bounty incentive added to trip (24–36h) → Hub manager emergency vehicle sweep (36–48h).
- [x] Review/decide: Fallback (Zero Coverage): If unpicked after 48–72h, auto-cancels with 100% buyer refund, 0 seller penalties, and flags the seller's barangay for pickup pausing until courier fleet coverage expands.

Decision: **accepted with corrections in B15**. `DispatchEscalation` tracks `open_pool` (`0–12h`), `direct_assignment` (`12–24h`), `priority_bounty_deferred` (`24–36h`, setting the `Pickup Delayed — Reassigning Rider` display label without mutating canonical 13-status invariants and recording explicit `surge_deferred_note` since unapproved monetary surge payouts remain deferred), `hub_emergency_sweep` (`36–48h`), and `unpicked_fallback` (`48–72h`, executing penalty-exempt cancellation for strictly unclaimed `ready_for_pickup` parcels at `72h` via `OrderLifecycleService` with clean Paid Wallet/voucher release). Checkout and telemetry separate `no_available_rider` from explicit `pickup_paused` (`hub_pickup_pauses`) and `no_service_coverage`. See [B15](B15_DISPATCH_RECOVERY_AND_INVESTIGATION.md).

## 11. Off-Platform Logistics Bypass and Seller Circumvention

- [x] Resolve the source question: what if an illegal seller does this: suppose some buyer buys in the shop and the seller knows the dtails and information, so he use the illegal route which is his friend logistic instead of our lgoistic company in our system? and the buyer receives the product and he thinks it is good, but no progress is happening. he does this beczsuxse it is cheaper and easy. how should our system prevent this? and why terms and condition is critical here

Proposed ideas to review:

- [x] Review/decide: System Prevention: Enforce mandatory in-app waybill/QR scanning for status updates; orders remain stuck in "Pending Pickup" and auto-cancel with full buyer refunds if platform courier integration is bypassed.
- [x] Review/decide: Payout Lock: Seller escrow funds are tied strictly to validated digital scan milestones, preventing seller payout for unverified off-platform deliveries.
- [x] Review/decide: Terms & Conditions Enforcement: Strictly prohibits off-platform fulfillment, imposing store wallet penalties, account suspension, loss of seller protection, and liability for fraudulent transactions.

Decision: **accepted with corrections across B10, B15, and B16**. Verified custody scans remain mandatory before delivery completion or seller settlement (`SellerSettlementService` and `WalletSettlementService`), `B15` cancels strictly unclaimed `ready_for_pickup` parcels at `72h` with buyer Wallet/voucher reversal, and `B16` adds non-punitive `ModerationSignal` off-platform chat & stalled-order buyer check-ins (`SIG-...`), audited `FinancialPenalty` records (`PEN-...`), and auto-releasing `payout_hold` + `catalog_visibility_restricted` debt holds (without negative wallets or keyword-only bans). See [B16](B16_RESTRICTIONS_AND_MODERATION.md).

## 12. Off-Platform COD Circumvention & Cash Handover

- [x] Resolve the source question: no suppose what if the buyer use cod and hands outthe product since he thinks ist is good and he recieve the right prouct?

Proposed ideas to review:

- [x] Review/decide: Unverified COD Risk: Without in-app digital scan confirmation upon delivery, the order remains flagged as unpaid/unfulfilled in the system, preventing official order completion.
- [x] Review/decide: Escrow & Dispute Loss: Cash handed over off-platform is not covered by buyer or seller protection; any subsequent disputes, lost returns, or refund claims will be rejected due to lack of verifiable system proof.
- [x] Review/decide: Policy Violations: Bypassing official COD reconciliation protocols violates platform terms, triggering store wallet penalties and account flags for fraudulent transaction patterns.

B10 verifies original in-app payment/handover evidence, exact COD collection and counted remittance before rider release. `B16` completes the governance policy decision: corroborated off-platform COD circumvention triggers audited `FinancialPenalty`, `payout_hold`, and `catalog_visibility_restricted` obligations (with buyer disputes still reviewed on verified evidence rather than blanket-denied). See [B10](B10_DELIVERY_COMPLETION_AND_CASH.md) and [B16](B16_RESTRICTIONS_AND_MODERATION.md).

## 13. Pre-Acceptance Address Harvesting & Shadow Delivery Bypass

- [x] Resolve the source question: suppose the thing is he didn't accept or packt he order so there is no track to be happened. what if as soon as he see someoen order he didt even accept just take down notes of the address and yeah do the illegal thing. how do we solve this?

Proposed ideas to review:

- [x] Review/decide: Pre-Acceptance Data Lock: Seller cannot access customer PII or generate waybill barcodes until clicking "Accept Order" within 48h.
- [x] Review/decide: Dual-Layer PII Masking: Thermal waybill stickers print masked recipient text (e.g., J*** D**, town-only); full street address and phone coordinates are encrypted inside the waybill QR code, decodable exclusively by authorized BagooPH rider app cameras.
- [x] Review/decide: Automated Anti-Scam Check-in: Platform triggers automated in-app prompts to buyers if orders stall or if off-platform communication is detected, helping buyers identify suspicious activity early.

Decision: **accepted with corrections for disclosure (`B06`) and non-punitive safety check-ins (`B16`)**. B06 withholds street, phone, pin, notes and waybill until acceptance; no 48-hour auto-cancellation is introduced. The shared-key encrypted PII QR proposal is rejected in favor of B03 authenticated lookup and private response controls. `B16` adds `ModerationSignalService` chat bypass & stalled-order signals (`anti_scam_stalled_order`, `off_platform_bypass_signal`) surfacing non-punitive **Bagoo Anti-Scam Safety Check-In** cards on `/buyer/orders/{order}` so buyers can confirm safety or report bypass attempts before any admin sanction/penalty is issued. See [B06](B06_ACCEPTANCE_CANCELLATION.md) and [B16](B16_RESTRICTIONS_AND_MODERATION.md).

## 14. High-Throughput Mobile Scanning, Hub Scan Milestones, and Account Multi-Tenancy

- [ ] Resolve the source question: we know there is hundreds per minute of parcel being processed in the logistics how can i manage this one? we know it is scanning suppose i have mobile scanner instead one of those iot things. but inside per hub or mother hub how many scans do they need? also is this possible for 1 account per hub?meaning 1 account per hub scanning idk how this works if there are many workers working like those inbound those mother bags scan those outbound etc

Proposed ideas to review:

- [ ] Review/decide: High-Throughput Mobile Scanning: Workers use mobile camera/PDAs with continuous batch-scanning mode (offline-first local queue, bulk web-socket/REST payload sync) to handle hundreds of scans per minute without server bottlenecks.
- [ ] Review/decide: Standard Hub Scan Milestones: Loose parcels are scanned once at Inbound Intake and once at Sorting/Mother Bagging. Outbound truck loading and bulk transit scans require scanning ONLY the parent Mother Bag barcode, updating all child parcels simultaneously.
- [x] Review/decide: Account Multi-Tenancy & Worker Sessions: Instead of shared single-account credentials, hubs use Hub Multi-Tenancy with individual worker operator IDs logged into active workstation sessions for granular audit trails and concurrent scanning.

Identity decision: **retained from B02 and verified in B07**. Named workers use their own activated accounts and assigned hub authority; no shared owner login or impersonation is introduced. One small-hub operator may perform multiple internal stages. Offline/batch throughput and scanner hardware measurements remain B17; they are not marked delivered here.

## 15. Real-Time Sorting Bin Guidance, Mother Bag Assignment, and Linehaul Dispatch

- [x] Resolve the source question: accept that idea. how to fastly also knw where workers toss the products to whcich bag that it is going to and which truck automatically and also what truck and where the truck will go?

Proposed ideas to review:

- [x] Review/decide: Real-Time Bin & Bag Guidance: Upon scanning a parcel barcode, the mobile scanner/screen instantly displays the target Mother Bag ID, bin number, and color-coded sorting indicator to direct worker placement without manual lookup.
- [x] Review/decide: Mother Bag to Truck Mapping: Once filled, Mother Bags are sealed with a unique barcode manifest. Scanning the bag at outbound dispatch dynamically pairs it with the scheduled Linehaul Truck ID based on destination hub routing.
- [x] Review/decide: Automated Trip Dispatch & Tracking: Assigned trucks generate an automated digital trip manifest defining destination hub routes, ETA, driver assignment, and real-time GPS tracking across hub networks.

Decision: **accepted with corrections in B09**. Scans show the purchased next facility/bin and a retained or capacity-checked proposed bag. The staging panel suggests an eligible source-hub vehicle with its named driver and configured kg capacity. Preparing a manifest retains that authorized corridor; operators still choose the actual vehicle, scan cargo, verify seals and record departure/individual arrival. No inspection-generated membership, automatic truck pairing, assumed weight, generated departure or real-time GPS is introduced. ETA remains B14 and measured scanning throughput B17. See [B09](B09_SORTING_AND_DISPATCH.md). These checkboxes record the corrected decisions, not delivery of the original automation/GPS proposal.

## 16. Automatic Mother Bag Generation, Physical Barcode Seals, and High-Speed Bulk Relay

- [x] Resolve the source question: how bag is automatically created? like thos mother bags? also we knwo we ahave parcel scaning and these motherbags, does this means there is a printed bag code they need to scan? how fast do they need this tho? how will this really works tho? ofc we now we just bag and slid it and then go per hub right? discuss this tome

Proposed ideas to review:

- [x] Review/decide: Implicit Generation: Bags are automatically instantiated in the system the moment the first parcel for a destination hub is scanned at an active sorting bin.
- [x] Review/decide: Physical Barcode Seals: Uses heavy-duty reusable sacks with permanently stitched barcodes or disposable numbered tamper-evident zip-tie seals paired to the bin station.
- [x] Review/decide: High-Speed Bulk Relay: Individual parcels are scanned into the bag once (0.2–0.5s per item); subsequent linehaul truck loading and receiving requires only scanning the parent Mother Bag barcode, moving dozens of parcels in seconds.

Decision: first scan means an explicit authorized packing action, not inspection or a fabricated sorting-bin scan. Optional permanent sack identity, a new trip reference and a fresh numbered seal remain separate. Bag loading is atomic; arriving parcels retain individual receipts. The unmeasured 0.2–0.5 second target and bag-only receipt premise are not accepted evidence. Measured throughput stays B17; sorting-bin guidance stays B09. See the [B08 contract](B08_BAGS_SEALS_AND_LOOSE_CARGO.md).

## 17. Reverse Logistics Architecture, Return Waybills, Cost Liability Splits, and Transit SLAs

- [ ] Resolve the source question: next is about parcel or reverse logistics we know how easy it is for the normal way how about reversing at what cost and time shoudl a product setn back tho? how this would work? will it just do the same thing scanning or there is a new logic behind this?

Proposed ideas to review:

- [x] Review/decide: Same Scanning, New Identity: Reverse logistics uses identical physical camera scanning motions, but mints a dedicated Return Tracking Barcode (e.g., RET-XXXXX) and flips origin and destination hubs dynamically. Mother Bags use polymorphic relations to co-load both forward and return items by destination direction.
- [x] Review/decide: Cost Allocation: Defective/seller faults deduct return shipping from the store wallet; courier-damaged items are reimbursed via the courier bond pool; buyer change-of-mind deducts shipping from the buyer's refund.
- [ ] Review/decide: Consolidated Transit SLA: Reverse linehaul runs 1–2 days slower than forward rush delivery to allow economical consolidation at sorting hubs.

Decision: **accepted with corrections in B11–B13 (transit SLAs remain B14)**. Returns mint dedicated `BRT-` barcodes over the reversed hub sequence and co-load on shared directional `LogisticsTransportTrip` vehicles via separate forward `MotherBag`s and dedicated reverse return sacks rather than mixing both directions inside one sack. Policy v2 requires seller or independent admin courier fault (`buyer_paid_shipping_cents` allocated per unit; voluntary post-delivery change-of-mind remains excluded while in-transit buyer refusal carries shipping liability). See [B11](B11_RETURN_REQUESTS_AND_REVIEW.md), [B12](B12_REVERSE_TRANSPORT_AND_SHARED_TRIPS.md), and [B13](B13_REFUND_EXECUTION_AND_FUNDING.md).

## 18. Return Batching Rules, Auto-Timeout Approvals, and Admin Dispute Resolution

- [x] Resolve the source question: my question is how about when to relaly abring back a product? does it wait 5 more returning products before it is returned or what? when will a product trigger this return as soon it is flag by user or someone what if someone didn't click the flagging thing what should the system throw how would the admin response to this?

Proposed ideas to review:

- [x] Review/decide: Immediate First-Mile Pickup: Riders are dispatched individually to pick up approved returns from buyer doorsteps without waiting for batches.
- [x] Review/decide: Hybrid Hub Threshold: Hub-to-hub linehaul dispatches when the bag reaches capacity (20–30 items) OR hits the 48h maximum holding timeout.
- [x] Review/decide: Auto-Approval Timers: If a seller ignores a return request for 48h, the system auto-approves it; if a seller fails to acknowledge delivery within 48h, the buyer's refund is auto-released.
- [x] Review/decide: Admin Evidence Governance: Admins adjudicate contested claims using immutable digital logs, unboxing footage, and GPS scan timestamps.

Decision: **accepted with corrections in B11–B14**. Single approved returns dispatch immediately on reverse manifests/shared directional trips (`>= 1` item; `reverse_consolidation_transit` carries a `96h` SLA vs `48h` forward in `B14`, rejecting 20–30 item batch holding). `StateMonitoringService` sends pre-expiry reminders and escalates overdue `72h` seller return responses to Admin review (`escalated`) while auto-closing expired `72h` buyer appeals (`denied`) and `7d` drop-off windows (`expired`, releasing unit claims) **without** auto-approving refunds or moving money (`B11`/`B13`). See [B11](B11_RETURN_REQUESTS_AND_REVIEW.md), [B12](B12_REVERSE_TRANSPORT_AND_SHARED_TRIPS.md), and [B14](B14_ETA_AND_STATE_MONITORING.md).

## 19. Co-Loading Forward and Reverse Logistics in Shared Directional Mother Bags

- [x] Resolve the source question: meaning if the product goes to me and i want a return and i click return someone might pickup my prlduct and then just pay if there is shipping needed or not if it is other rason then when it goes to logistic it will not instantly go to scanning instead it will wait for a mother bag to be almost full or full FOR THE SAME ROUTE of the other products that will going itno? meaning if there is a seller near me that he passed someone order and i have a return the two products might have on the same mother bags then repeat the process?

Proposed ideas to review:

- [x] Review/decide: Immediate Inbound Scan: Returns are scanned instantly upon arrival at the local hub to release rider custody and notify the buyer, but staged until linehaul departure.
- [x] Review/decide: Directional Co-Loading: Mother Bags and linehaul trucks group parcels purely by destination direction, allowing forward orders and return parcels to share the same physical sack and vehicle.
- [x] Review/decide: Destination Branching: The shared bag is opened at the destination hub where barcodes branch forward orders to buyer delivery shelves and return parcels to seller return shelves.

Decision: **accepted with corrections in B12**. Destination hubs intake returns without minimum-count holds; `LogisticsTransportTrip` co-loads sealed forward `MotherBag`s and dedicated reverse return sacks (`direction = buyer_return`) on the same directional vehicle leg rather than mixing forward and return parcels in the same physical sack, and destination hubs open and branch each arrived manifest separately. See [B12](B12_REVERSE_TRANSPORT_AND_SHARED_TRIPS.md).

## 20. Minimum and Maximum Scan Thresholds per Hub & End-to-End Lifecycle

- [x] Resolve the source question: in total what is the minimum + maxium scan of parcel per hub from start to finish? can we count?

Proposed ideas to review:

- [x] Review/decide: Per-Hub Touchpoints: Minimum 1 scan during bulk linehaul transit (scanning parent Mother Bag barcode only); maximum 2 scans when parcel is loose (Inbound Intake + Outbound Sort/Bagging).
- [x] Review/decide: End-to-End Parcel Lifecycle: Short intra-town trips require 4 scans total; medium inter-town trips require 6 scans; long cross-region trips require 7–8 scans maximum from first-mile pickup to final-mile proof of delivery.
- [x] Review/decide: Bulk Relay Efficiency: Direct loose parcel touches are eliminated at transit sorting hubs by moving dozens of parcels under a single consolidated Mother Bag barcode.

Decision: **changed in B07**. Derive the required actions from the frozen route and actual bag, manifest, delivery and exception events. Fixed four/six/eight totals and universal per-hub maxima are rejected. A bag departure may reuse its loaded membership; arrival still needs actual seal verification and individual member receipt. Canonical checkpoint counters distinguish submitted, inherited and derived evidence rather than pretending to measure physical touches. See [B07](B07_INDIVIDUAL_HANDOVERS.md).

## 21. Stagnant Parcel Auto-Flagging, SLA Timers, and Three-Way Account Penalties

- [x] Resolve the source question: next is preventing party participation such as not scanning, not progressing any sorting ones. how can we prevent this and how can we impement autoflagging by timer and how admin or palgform would step us to this? and then at the list of ideas a buyer can have warning / ban and seller have warning /ban and also the logistics company?

Proposed ideas to review:

- [x] Review/decide: Inactivity Prevention: Unloading requires driver custody clearance to prevent un-scanned drops; 2-hour idle timers flag stagnant parcels sitting unprocessed at hub sorting tables.
- [x] Review/decide: Automated Escalation Ladder: Automated push alerts to hub managers at 4–12h inactivity; automated backup fleet rerouting at 24h; admin dispute escalation and SLA fines at 48h.
- [x] Review/decide: Three-Way Penalty Matrix: Buyers face COD restrictions (30–90 days) and bans for joy reserving; sellers face search shadowbans and account termination for fulfillment failure; couriers face financial bond deductions (₱150/parcel) and route territory revocation for repeated SLA breaches.

Monitoring & recovery decision: **accepted with corrections in B14, B15, and B16**. `hub_idle_sorting` warns at `2h`, alerts hub/company operators by `12h`, raises `24h` stalled-hub alerts (`B15` capacity-aware rider reassignment within the same corridor and explicit pickup pauses), and opens non-loss `MissingParcelInvestigation` cases at `48h`. `B16` completes the three-way matrix with evidence-backed, appealable `EnforcementSanction` scopes (`warning`, `cod_restricted`, `review_restricted`, `catalog_visibility_restricted` shadowban, `payout_hold`, `pickup_zone_restricted`), geo-fenced `₱150` courier pickup penalties (never auto-charged without independent admin review), and non-negative `unpaid_debt_hold` obligations alongside existing `RestrictionDecision` portal suspensions. See [B14](B14_ETA_AND_STATE_MONITORING.md), [B15](B15_DISPATCH_RECOVERY_AND_INVESTIGATION.md), and [B16](B16_RESTRICTIONS_AND_MODERATION.md).

## 22. Verified Review Gating, Anti-Troll Filtering, and Moderation Governance

- [x] Resolve the source question: next outside the process how about rating how can we have real rating from buyers and not buyers trolling or anytihng how admin/plaform will step up and the seller?

Proposed ideas to review:

- [x] Review/decide: Fault-Based Purchase Gating: Reviews unlock upon scanned "Delivered"/"Completed" status (1 purchase = 1 review within 30 days). For returned/refunded orders, review privileges are preserved if the return was verified as Seller Fault (defective, fake, wrong item) to protect buyer warnings, but locked for Change of Mind.
- [x] Review/decide: Automated Malicious Filtering: Keywords redirect courier/shipping complaints away from seller product scores; spam text/nonsense uploads are blocked; velocity traps prevent coordinated review-bombing.
- [x] Review/decide: Seller Defense & Admin Moderation: Sellers have public reply rights and an in-app review dispute button; admins adjudicate appeals using chat history and order logs, penalizing bad-faith buyers with review privilege revocation.

Decision: **accepted with corrections in B16**. `ReviewService` enforces verified purchase delivery/completion within 30 days of `completed_at`, preserves product review eligibility on refunded `seller`-fault returns, blocks courier/non-seller-fault return and `buyer_refusal` items from skewing product ratings, rejects gibberish/link spam, holds rapid velocity bursts (`held_velocity_review`) non-punitively for human review, separates courier/driver ratings into decoupled `DeliveryFeedback` (`1–5` + tags), and lets sellers file `ReviewDispute` (`courier_delivery_complaint`, etc.) where `upheld_excluded_courier` preserves genuine delivery complaints visibly (`excluded_courier_issue`) off `products.rating`/`shops.rating`, while buyers can appeal removals (`restored`) to an independent admin (`reviewer_id !== adjudicated_by_id`). Bad-faith abuse can lead to evidence-backed `review_restricted` sanctions after review. See [B16](B16_RESTRICTIONS_AND_MODERATION.md).

## 23. Rider Fleet Onboarding, Logistics Company Hierarchy, and KYC Verification

- [x] Resolve the source question: next is rider eligeabllility . we knwo we can create account for rider and the rider have fresh account for example. do we have a dropdown of chocies for them to submit application for logistics company or what? they are the rider so is there even registering from logistic or seller aside from platform?

Proposed ideas to review:

- [x] Review/decide: Multi-Tenant Fleet Association: Riders register on the central platform but select their Logistics Company and Base Operating Hub via dropdowns; riders never register under individual sellers.
- [x] Review/decide: Mandatory KYC Verification: Riders upload professional driver's licenses, motorcycle OR/CR, and NBI/police clearances before activation.
- [x] Review/decide: Decentralized Activation: Local hub managers verify credentials and vehicle details before granting active shift status, unlocking live pickup pools and scanner functionality.

Decision: **retain existing rider registration, company joining/consent and eligibility rules**; company-created riders receive email setup invitations. The listed NBI requirement and automatic shift activation are not added. See [B02](QUESTION_23_24_WORKER_ACCOUNTS.md). These checkmarks record decisions.

## 24. Multi-Tier Logistics Account Delegation, Password Security, and Dual Impersonation Auditing

- [x] Resolve the source question: is it good because currently my creation of account in logistic is like this a company will create an account and approved by the admin and the rest is the logistic account of that or the main account can put mother hubs, local hubs and and mimic other hubs so he can control other hubs too if there something went wrong on the other such such as creating or managing as if they are the thing. and he can create password for them etc. and base on the previous idea, the normal hubs account such as motherhub and local theycan also create accounts for scanner workers? so they wont just open the account for the hub? is this idea right or you want to clarify or fix/

Proposed ideas to review:

- [x] Review/decide: Delegated Role Hierarchy: Logistics Master Admin manages Hub Managers; Hub Managers create separate operator accounts for floor sorters and scanner staff, eliminating shared credentials.
- [x] Review/decide: Secure Credential Delegation: Managers invite workers via staff ID and phone number with self-service password creation via OTP/invite links, ensuring individual accountability for lost packages.
- [x] Review/decide: Audited Impersonation Control: Master admins can control subordinate hubs during emergencies under a persistent visual banner, logging dual audit trails (operator_id + impersonated_by) for all dispatched manifests and route changes.

Decision: **accept named email invitations and personal passwords; retain owner-only provisioning; defer impersonation**. Existing managerial visibility does not grant scanner authority. See [B02](QUESTION_23_24_WORKER_ACCOUNTS.md) for implementation, corrections and remaining acceptance limits.

## 25. Operational Transit Integrity, Net-Refund Calculations, Geo-Fenced SLAs, and Two-Human Custody

- [x] Resolve the source question: great what is the fix for those and how we wil fix it so there will be no conflict and the idea is perfect?

Proposed ideas to review:

- [x] Review/decide: Asymmetric Transit Timers: Forward deliveries enforce a strict 48h inter-hub timeout before auto-flagging missing parcels; reverse logistics parcels in consolidation staging receive an extended 96h window to prevent premature loss claims.
- [x] Review/decide: Doorstep Cash Prohibition & Net-Refunds: Doorstep cash collection for returns is prohibited. For change-of-mind returns, the return shipping fee is deducted automatically from the product refund, with returns blocked if shipping exceeds item value.
- [x] Review/decide: Geo-Fenced SLA Penalties: Courier late-pickup fines (₱150) apply strictly within contracted, active coverage zones; orders in unserviceable or low-fleet territories auto-cancel without courier or seller penalties.
- [x] Review/decide: Two-Human Custody Rule: Sorter workstation scanners reject check-in scans if the current parcel custody matches the logged-in operator, preventing delivery riders from validating their own vehicle handovers.

Handover decision: **accepted with corrections in B07**; return settlement decision: **accepted with corrections in B11/B13**; asymmetric forward (`48h`) and reverse (`96h`) transit timers: **accepted in B14**, paired with `B15` coverage fencing (`no_available_rider` / `pickup_paused` / `no_service_coverage`) and `72h` penalty-exempt unpicked fallback. Direct courier geo-fenced SLA pickup fines (`₱150` default) are **accepted with corrections in B16** via `FinancialPenaltyService::proposeSlaPickupPenalty()`, rejecting paused (`sla_pauses` / `hub_pickup_pauses`), unserviceable (`no_service_coverage`), rider-shortage (`no_available_rider`), `penalty_exempt`, and last-scan-only claims, and requiring independent admin approval. See [B14](B14_ETA_AND_STATE_MONITORING.md), [B15](B15_DISPATCH_RECOVERY_AND_INVESTIGATION.md), and [B16](B16_RESTRICTIONS_AND_MODERATION.md).

## 26. Baseline Shipping Fee Matrix, Volumetric Weight Tiers, and Cargo Surcharges

- [x] Resolve the source question: great wait before i add that is how about the shippping prices? what is the best baseline shipping fee? suppose 50php? then how can it vary depending on types or weights etc what are those beofre we accept that idea!

Proposed ideas to review:

- [x] Review/decide: Baseline Tiering: ₱50 baseline for packages up to 1.0 kg within the same local hub zone; regional transit scales to ₱70–₱80, and cross-region linehaul scales to ₱100–₱120.
- [x] Review/decide: Dimensional & Overage Pricing: Heavy parcels incur +₱15–₱20 per kilogram beyond the 1.0 kg threshold; bulky items are charged using volumetric dimensional weight (L × W × H / 5,000) whenever it exceeds physical scale weight.
- [x] Review/decide: Special Cargo Surcharges: Applies optional/conditional fees for high-care fragile handling (+₱20–₱30), irregular non-baggable freight (+₱50), declared value transit insurance (1% above ₱1,000), and cash-on-delivery remittance handling (1.5%–2%).

Decision: **user-selected amendment, November 11**: PHP 50 including 1 kg plus PHP 20 per extra started kg, divisor 3,500. Keep explicitly published company tariffs and original orders. Geography tiers and extra fragile/insurance/COD charges are deferred. See [B04](B04_MONEY_AND_LIABILITY.md) and [B05](B05_PURCHASED_CHECKOUT_TERMS.md). These checks record reviewed decisions, not completion of later cargo or refund packages.

## 27. Non-Baggable Freight Loading, Local Shift Staging, Scale Auditing, and Proportional Voucher Splits

- [x] Resolve the source question: great apply that idea since i love that fix about the guitar or weird products or ghost bags etc etc!

Proposed ideas to review:

- [x] Review/decide: Dual Manifest Loading: Digital trip manifests support both bulk Mother Bag barcodes and individual loose non-baggable barcodes (e.g., guitars, bulky tools), requiring individual offloading scans before manifest clearance to prevent lost freight or crushed cargo.
- [x] Review/decide: Intra-Hub Final Mile Staging: Intra-town orders bypass Mother Bags directly into local delivery staging shelves (STAGED_FOR_FINAL_MILE), pausing idle table timers to align with afternoon delivery shifts without raising false alarms.
- [x] Review/decide: Scale Verification & Negative Wallet Freezes: Discrepancies between declared and scale weight trigger automated camera scale logs and deduct overage fees from seller wallets; balances dropping below ₱0 trigger an immediate catalog visibility shadowban until debts are settled.
- [x] Review/decide: Proportional Voucher Split: Multi-item cart discounts are divided proportionally across all items, preventing voucher exploitation during partial returns by refunding only the net discounted item price.

B09 retains the local final-mile shelf bypass and excludes it from outbound bag corridors. B13 verifies proportional unit voucher/shipping splits in multi-item and partial returns (`BuyerReturnAmounts`, `BuyerReturnRefundPlanTest`). B14 verifies that `sorted_to_barangay_bin` (`STAGED_FOR_FINAL_MILE`) and `ready_for_hub_pickup` pause `hub_idle_sorting` (`staged_for_final_mile` / `counter_holding`) while starting `final_mile_staging` or `counter_pickup_holding`. `B16` completes the scale-discrepancy and debt-shadowban governance item with corrections: `FinancialPenaltyService::recordScaleDiscrepancy()` logs declared vs measured hub scale readings (`scale_discrepancy_logs`) and queues audited tariff-delta `FinancialPenalty` charges without mutating immutable order quotes, while keeping `wallet_accounts` strictly `>= 0` via tracked `unpaid_debt_hold` obligations paired with auto-releasing `catalog_visibility_restricted` shadowbans and `payout_hold`s once settled (`AccountEnforcementController::settlePenalty()`). Hardware camera OCR / physical scale drivers remain external integration work. See [B16](B16_RESTRICTIONS_AND_MODERATION.md).
