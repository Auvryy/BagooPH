# Brainstorming build order

This is a proposed dependency order for reviewing and implementing the 27 brainstorming topics. It does not approve every proposal or claim missing work is already complete. Review existing behavior first and implement only the accepted gaps. Current rules remain in [the version-two contract](../new-docs-era/README.md), [system flow](../SYSTEM_FLOW_AND_SPECIFICATIONS.md) and [Wallet contract](../BAGOO_WALLET.md). Implementation evidence belongs in [the roadmap](../CORE_FLOW_ROADMAP.md).

## Why this order

Question 1 is the first functional review, after a short shared-decision inventory. Questions 3 and 4 belong with it because hub topology determines its answer. Questions 25 and 27 must be split across earlier stages: identity/custody, money, cargo and clock rules affect later schemas and APIs. Decide these contracts early; implement their consumers in dependency order.

Forward operations establish transport primitives before reverse logistics reuses them. Return authorization precedes reverse pickup; refund execution follows the required evidence. Monitoring follows stable state transitions; automatic enforcement follows monitoring and recovery. Batch/offline throughput comes after correct single-event processing.

## Decisions to settle in B00 before affected implementation

- Pricing: on November 11 the user selected PHP 50 including 1 kg plus PHP 20 per started extra kilogram, retaining divisor 3,500. Published company tariffs and original purchases keep their original terms. See B04; geography tiers and additional insurance/handling fees remain unapproved.
- Routes: no mandatory new central/NCR facility or automatic direct route between different child hubs. Check actual configured connectivity; matching province names or a province exclusion list alone does not prove a connected service path.
- Timing: reconcile the 48-hour return holding proposal with the 96-hour proposal and distinguish packing, acceptance, pickup, transit, staging, review and claim deadlines. Counts such as 20–30 parcels are examples pending real capacity rules.
- Money: agree refund eligibility, amount, source, liability evidence, partial-item/voucher treatment, rider pay, shipping charges and insufficient-funds handling before enabling deductions, reimbursement or sanctions. Existing virtual credits are not an insurance fund or bank transfer.
- Security: define authorized address visibility, separate human handover actors, worker sessions and emergency delegation. A QR code should identify a protected record; it should not expose reusable customer data or bypass current server authorization.
- Returns and reviews: decide request windows, fault classification, approval authority, seller silence, co-loading compatibility, review eligibility and appeals. No automatic punishment or rejection of every off-platform claim is approved by this plan.

## Ordered work packages

Each package may become one small branch, or several serial branches if the scope is too large. Dependencies refer to verified contracts/results; an already working feature can satisfy a dependency after review without being rebuilt.

### B00. Inventory existing behavior and settle shared decisions

**Source topics:** All 27; especially 7–9, 18, 21, 25–27. **Prerequisites:** None.

Compare each proposal with current services, migrations, APIs and recorded acceptance. Mark each idea keep, change, defer or reject, with evidence. Approve the shared custody, money, privacy, cargo and timer contracts before changing their schema.

**Exit gate:** An existing implementation is identified separately from a missing feature. Unresolved financial or policy decisions have named blockers; the PDF is not treated as approval.

### B01. Verify hub topology and forward route planning

**Source topics:** 1–4. **Prerequisites:** B00.

Start question 1 here. Resolve the chosen company’s exact address coverage, local bypass, shared Mother Hub and different Mother Hub routes. Keep route planning separate from actual parcel progress.

**Exit gate:** Same local hub, different local hubs under one Mother Hub, and different Mother Hubs produce the expected ordered path. Ambiguous, inactive, foreign-company and disconnected coverage are rejected. No compulsory central/NCR hub is invented.

### B02. Confirm company roles, operator identities and rider placement

**Source topics:** 23, 24; identity part of 14. **Prerequisites:** B01.

Confirm company/hub boundaries, named workers, rider joining/activation and credential setup. Define emergency delegation and audit actors before permitting any impersonation.

**Exit gate:** Operators and riders can act only within their authorized company and hub; inactive resources cannot take new work. Different accounts do not automatically prove two different people. Existing joining/consent rules remain intact.

### B03. Define shared shipment, custody and privacy contracts

**Source topics:** 5, 11–14, 17, 20, 25, 27. **Prerequisites:** B01, B02.

Shared decisions, existing field mappings, compatibility boundaries and later-package ownership are in [the B03 contract](B03_SHIPMENT_CUSTODY_PRIVACY.md). It retains Q1 routing and B02 identities; mixed transport execution remains B12.

Define identifiers and events for forward parcels, returns, bags, loose cargo, staging, handovers and corrections before expanding the scanner. Specify actor, recipient, route version and idempotency fields. Define address visibility and protected waybill lookup.

**Exit gate:** A forward parcel and a return can share transport without sharing identity or money state. Sensitive addresses require authorized server access; QR possession alone grants no access. Physical observations, commercial status and cash custody remain distinguishable.

### B04. Decide tariffs, liability and original payment allocations

**Source topics:** 7, 8, 17, 25–27. **Prerequisites:** B00, B01, B03.

The user-selected PHP 50/1 kg plus PHP 20 per extra started kg amendment, original-cent allocations and successor refund/liability decisions are in [B04](B04_MONEY_AND_LIABILITY.md). The refund successor retains B11–B13 activation and funding gates.

Agree pricing, dimensions, surcharges, cancellation/return liability, voucher allocation, rider earnings and refund sources. Define original-unit refundable amounts and who funds any lost-parcel compensation.

**Exit gate:** Integer-cent examples balance for COD, Wallet, multi-item discounts, partial returns and bulky/overweight cargo. No proposed insurance pool, negative-wallet restriction, unconditional rider pay or automatic fine is assumed to exist.

### B05. Verify checkout quotes and freeze purchased terms

**Source topics:** 1, 2, 26; measurements and voucher part of 27. **Prerequisites:** B01, B03, B04.

Confirmation locking, final source checks, exact earnings terms, original replay and additive rollout are in [B05](B05_PURCHASED_CHECKOUT_TERMS.md).

Recheck coverage and eligibility at confirmation; store route, address/measurement snapshots, tariff version, promotions and relevant earnings terms. Use existing services where they already satisfy the decisions.

**Exit gate:** Address or company changes invalidate the old quote. Two shops, lost responses, retries, changed tariffs and changed product dimensions cannot alter the original purchased route or double-create orders.

### B06. Align seller acceptance and cancellation boundaries

**Source topics:** 6, 11–13. **Prerequisites:** B03–B05.

Map pending, accepted, packed, pickup and in-transit states to permitted cancellation, disclosure and refund actions. Keep ready status and a printed waybill distinct if current behavior permits printing earlier.

**Exit gate:** The last permitted cancellation and first prohibited cancellation are demonstrated, including simultaneous pickup/cancel, timeout, stock restoration and once-only prepaid reversal. No cancellation fabricates a physical return or COD repayment.

### B07. Verify individual scanning and actual custody transfer

**Source topics:** 14, 20; handover part of 25. **Prerequisites:** B02, B03, B06.

Complete one online parcel handover at a time before adding batch/offline behavior. Validate actor, next hub, current holder, receipt evidence and recovery. Derive scan counts from the chosen route and actions.

**Exit gate:** Duplicate scans, competing receivers, wrong hubs, stale custody and a failed transaction do not duplicate or invent movement. The agreed independent handover rule supports legitimate small-hub operations. Fixed 4/6/8 scan totals are examples, not universal limits.

### B08. Verify bags, seals and loose freight membership

**Source topics:** 16; cargo part of 27. **Prerequisites:** B03, B04, B07.

Define automatic open-bag creation, physical reusable bag versus trip seal identity, capacity, numbered seals and atomic membership. Support non-baggable parcels as explicit manifest cargo without fictitious bags.

**Exit gate:** A parcel has at most one active container/manifest membership. Concurrent first scans cannot create competing bags; sealed membership cannot change. Weight/size limits and individual loose-cargo unloading are enforced.

### B09. Connect sorting guidance, manifests and dispatch

**Source topics:** 15; local staging part of 27. **Prerequisites:** B01, B02, B07, B08.

Derive bin, next bag and vehicle choices from the stored next hop and cargo constraints. Separate local final-mile shelves from linehaul staging. Keep route topology separate from optional road/GPS display.

**Exit gate:** Local orders skip Mother Bag transport; different hubs follow the stored corridor. Departure, partial arrival, seal exceptions and recovery preserve actual custody. Animation, a generated manifest or a GPS dot cannot mark a parcel received.

### B10. Verify delivery completion, earnings and cash reconciliation

**Source topics:** 8, 12, 25; baseline money from 26. **Prerequisites:** B04, B05, B09.

Use the approved proof/payment event to complete the versioned order and release eligible ledger amounts. Keep COD collection/remittance distinct from Wallet delivery and from actual bank/cash payout.

**Exit gate:** COD, prepaid Wallet and hub collection each conserve the original amounts; repeated proof or remittance does not pay twice. Legacy orders retain their contract, and held cash/differences block duty closure where required.

### B11. Verify return requests and independent decisions

**Source topics:** 5, 8, 17, 18, 25. **Prerequisites:** B03, B04, B10.

Implement only missing return authorization, item quantities, fault reasons, evidence review and decision records. Separate post-delivery returns from rejection/failed-delivery RTS. Define timeout policy now; schedule automatic execution after B14.

**Exit gate:** A refund request alone cannot create a physical pickup or refund. Wrong owner, excess quantity, duplicate claims, partial returns, disputes and already-adjusted units are handled without changing unrelated items.

### B12. Verify reverse custody and directional co-loading

**Source topics:** 5, 17–19; cargo part of 27. **Prerequisites:** B03, B08, B09, B11.

Reuse the approved transport primitives for the reversed stored path. Receive returns immediately, then stage within agreed capacity/deadline limits. Co-load only compatible cargo with the same next destination and company.

**Exit gate:** Both approved buyer returns and failed-delivery RTS reach their proper destination with separate identities. Mixed forward/return loads, partial arrivals, bulky freight and wrong-hub recovery retain individual custody and destination branching.

### B13. Verify refund execution and financial adjustments

**Source topics:** 7, 8, 17, 25; voucher/debt part of 27. **Prerequisites:** B04, B10–B12.

Apply approved refunds and recovery charges only on their required evidence. Preserve original discounts, shipping liability and earned amounts; record adjustment entries. Define insufficient-funds handling before enabling any debt collection or restriction.

**Exit gate:** Seller fault, buyer fault, courier fault and lost-parcel outcomes balance for COD and Wallet. Partial/voucher returns cannot exceed original paid units or refund twice. An admin decision does not falsely claim a real cash/bank refund occurred.

### B14. Add ETA and state-aware monitoring

**Source topics:** 9, 18, 20, 21, 25; staging part of 27. **Prerequisites:** B06–B13.

Define each clock’s start, stop, pause and resume events after the workflows stabilize. Calculate ETA from route/service windows and show an honest range. Start monitoring with observable warnings and dry runs.

**Exit gate:** Forward transit, approved reverse consolidation, waiting-for-pickup and final-mile staging have distinct clocks. Timezones, outages, force-majeure pauses, duplicate scheduler jobs and changed states cannot trigger false loss/refund/approval actions.

### B15. Add dispatch recovery and missing-parcel investigation

**Source topics:** 7, 9, 10, 18, 21, 25. **Prerequisites:** B02, B09, B13, B14.

Add the approved pickup escalation ladder, capacity-aware reassignment, stalled-hub alerts, case handling and admin review. Enable automatic cancellation/approval/refund only after its exact prerequisite and financial path is verified.

**Exit gate:** No available rider and no service coverage are distinct outcomes. A scan timeout opens a case rather than proving theft or liability. Actual recovery, insurer funding and compensation approvals are traceable; unsupported surge payments remain deferred.

### B16. Apply evidence-based restrictions and review moderation

**Source topics:** 11–13, 21, 22, 25; debt restrictions part of 27. **Prerequisites:** B02–B04, B11, B13–B15.

Implement the approved warnings, appeals, account restrictions and verified-review eligibility. Separate courier service feedback from product reviews without hiding genuine complaints. Financial sanctions require explicit authority and an auditable charge.

**Exit gate:** Buyer, seller and company restrictions are scoped, reasoned and reviewable. Keyword detection alone does not establish abuse; last scan alone does not establish fault. Refunded seller-fault orders and review appeals follow the approved policy.

### B17. Optimize scanning throughput and complete acceptance

**Source topics:** 14–16, 19, 20; all topics for regression. **Prerequisites:** B07–B16.

Measure the working online flow first. Add only the needed batch API, queued/offline replay and shared manifests, preserving per-item outcomes and server revalidation. Update role screens and presentation evidence throughout, then rehearse the combined release.

**Exit gate:** Measure actual hardware/network performance instead of promising 0.2–0.5 seconds or hundreds of scans/minute. Reconnect, conflicting workers, changed permissions and partial batch failures cannot duplicate custody. Full COD, Wallet, local/regional, bulky, return, loss and recovery scenarios have recorded evidence.

## How to minimize conflicts between branches

1. Use the [checklist](BAGOO_PH_BRAINSTORMING_CHECKLIST.md) as the progress tracker. Record each original idea as accepted, changed, deferred or rejected, and separately record implementation and verification evidence. Checking a decision does not mean its feature shipped.
2. Give each branch one bounded outcome and explicitly list the services, migrations, routes/API fields and UI pages it owns. Serialize work on shared checkout, routing, custody, bag/manifest and ledger services. Other sessions should not edit those files concurrently.
3. Merge and review the prerequisite branch, update main, then create the next branch. Avoid long stacks and copying shared migrations between branches. Keep commits separated by contract/schema, behavior, role UI and verification where those are independently reviewable.
4. Use additive migrations, stable identifiers and explicit API/contract versions. Preserve old orders, quotes, sealed manifests, cash journals and approved refunds. Coordinate native API changes with the owning app session before requiring new fields.
5. Each package includes its affected UI: clear controls, error recovery, saved safe drafts, field/image/file limits, keyboard/mobile behavior and honest empty/pending states. Do not postpone all UX until B17 or expose internal workflow explanations in customer screens.
6. Verify each changed boundary: valid flow, invalid input, foreign company/owner, stale state, retry, competing updates and rollback. Include meaningful money/custody concurrency checks when affected; do not run broad repeated suites for unchanged documentation.
7. A later change to a shared decision must name its affected earlier packages and reopen their acceptance checks. Never silently rewrite old commercial records or leave their readers on incompatible assumptions.

## Coverage of every source topic

| Source question | Packages that review or implement it |
|---|---|
| 1 | B01, B05 |
| 2 | B01, B05 |
| 3 | B01 |
| 4 | B01 |
| 5 | B03, B11, B12 |
| 6 | B06 |
| 7 | B04, B13, B15 |
| 8 | B04, B10, B11, B13 |
| 9 | B14, B15 |
| 10 | B15 |
| 11 | B03, B06, B16 |
| 12 | B03, B06, B10, B16 |
| 13 | B03, B06, B16 |
| 14 | B02, B03, B07, B17 |
| 15 | B09, B17 |
| 16 | B08, B17 |
| 17 | B03, B04, B11, B12, B13 |
| 18 | B11, B12, B14, B15 |
| 19 | B12, B17 |
| 20 | B03, B07, B14, B17 |
| 21 | B14, B15, B16 |
| 22 | B16 |
| 23 | B02 |
| 24 | B02 |
| 25 | B03, B04, B07, B10–B16 |
| 26 | B04, B05, B10 |
| 27 | B03–B05, B08, B09, B11–B14, B16 |

B00 inventories all topics; B17 performs combined acceptance. The table maps primary ownership and cross-cutting dependencies, so topics 25 and 27 are not left until the end.
