# Cross-role readiness gap plan

Source baseline: merged main `f8b2176`, reviewed October 21, 2026. This is a
proposal for the remaining work, not authorization to implement every batch.
[CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md) remains the implementation and
verification record. No application behavior, workbook, mobile client or deployed
environment changes as part of this planning review.

The separately selected [login activity and session controls](ACCOUNT_LOGIN_SECURITY.md)
addition does not start or complete an RG batch. Its implementation evidence belongs
in the roadmap; the six proposed readiness batches below retain their own gates.

## 1. What a 95-point target means

Target **at least 95/100 separately for implementation maturity and defense
readiness**, within the agreed project scope. These are evidence-based
engineering assessments, not a percentage of completed spreadsheet rows, a
probability of passing a defense, or commercial/security certification.

| Assessment | Weighted evidence, totaling 100 |
|---|---|
| Implementation | Core capabilities 25; authorization and monetary/custody integrity 30; failure recovery 20; role usability 15; release reliability 10 |
| Defense | Repeatable cross-role demonstration 35; exception/recovery demonstration 20; explanation of architecture and rules 20; evidence from the demonstrated release 15; honest scope and limitations 10 |

Assess each role independently; use the lowest unresolved critical gate to
limit the overall assessment. More features or a high average cannot compensate
for incorrect money, unauthorized access, invented custody or an unusable portal.
Do not award fixed points per branch. Agree the scope before rescoring, record
the evidence for each weighted area, and leave unobserved behavior unverified.

Keep one shop per seller, the 14 master categories, fixed account roles, the
mandatory Mother-Hub journey, buyer-only completion and the original 10%/90%
product split. Keep shipping, COD cash and virtual credits separate. Retain
the selected seven-day buyer-return policy and original purchased terms.

## 2. Findings that determine priority

The review follows a screen through its route, controller, service, stored
source and existing checks. Findings below describe the baseline above; results
of later fixes belong in the roadmap.

| Finding | Evidence | Consequence |
|---|---|---|
| Buyer homepage shipment selection uses only `processing`, `ready_for_pickup` and `shipped`; its banner promises a fallback arrival and calls itself live telemetry. | [BuyerHomeController::index](../app/Http/Controllers/Buyer/BuyerHomeController.php), [Buyer/Home](../resources/js/Pages/Buyer/Home.tsx) | Canonical active stages can disappear from the banner; absent or past estimates can mislead. This does not establish a defect in the shared order-detail timeline. |
| Seller reports calculate pending settlement as 90% of completed merchandise minus COD payment events, without separating wallet orders, seller-funded discounts or return adjustments. Rows stop at 25 without server pagination. | [SellerDashboardController::reports](../app/Http/Controllers/Seller/SellerDashboardController.php), [Seller/Reports](../resources/js/Pages/Seller/Reports.tsx) | A virtual sale can be described as waiting for COD reconciliation; a report's apparent balance and visible history can disagree with original financial sources. The payment journals themselves are separate. |
| Deployment stops the scheduler before dependency/build steps. Verification checks the manifest, migrations and scheduler process/list, rather than successful portal requests or recent scheduled execution. | [bagoo.sh](../bagoo.sh), [scheduled commands](../routes/console.php) | A failed deploy can leave scheduled work stopped; a running process alone does not establish working background tasks. Recovery must respect whether migrations already ran. |
| One deployment test currently fails. Its Docker stub never returns a running scheduler ID for the newer verification check. | [deployment tests](../tests/Deployment/bagoo.test.mjs) | Fresh execution: 5 passed, 1 failed. Repair the mock and add stopped-scheduler cases; this failure alone is not evidence of a deployed outage. |
| Rider records contain licence number/registration status, but no licence-expiry field or expiry eligibility check. Signing out revokes a session/token without changing saved duty. | [CourierProfile](../app/Models/CourierProfile.php), [web logout](../app/Http/Controllers/Auth/AuthenticatedSessionController.php), [native logout](../app/Http/Controllers/Api/RiderAuthController.php) | Expiry management is a proposed enhancement. Duty needs explicit wording and a deliberate action; logout is not proof that every device or operational duty is offline. |
| Returns are paginated but their list accepts only a page; the admin overview has older review queues. Operational date filtering already exists in Financial oversight, while the COD custody list has a search with scope-wide totals. | [BuyerReturnController::index](../app/Http/Controllers/BuyerReturnController.php), [AdminOverviewService::overview](../app/Services/AdminOverviewService.php), [financial filters](../app/Services/Finance/FinancialOversightService.php), [COD list](../app/Services/Finance/CodCashViewService.php) | Improve finding actionable work and explaining filter scope. Do not claim finance date filters, return handling or parcel guidance need rebuilding. |

The last voucher delivery recorded 1,533 distinct backend checks, 542 frontend
checks and a passing production build. Its 25 audited source hashes still match
this baseline. Those checks are useful regression evidence; they do not cancel
the newly observed deployment-test failure or establish current Azure/device
acceptance. See the [recorded delivery](CORE_FLOW_ROADMAP.md#selected-product-seller-vouchers--october-21-2026).

## 3. Exact role changes

Identifiers below are proposed work items, not existing endpoints or completed
features. Extend the named services instead of creating parallel business rules.

### Buyer

- **B1 — Truthful homepage order update; P0.** Replace the banner's legacy-only
  selection with explicit normalized active stages and reuse
  `OrderTrackingService::present`. Use original purchased item names, the
  recorded checkpoint time and a human-readable stage. Remove the telemetry
  claim and invented 24-hour promise. A missing estimate says it is unavailable;
  a past estimate says an update is needed, without predicting a new date.
  Show delivered-but-unconfirmed purchases with the existing receipt action;
  completed/cancelled orders must not masquerade as active shipments.
  **Accept:** every canonical stage, legacy aliases, missing/conflicting parcel,
  overdue/missing estimate, changed product name, guest and foreign buyer; the
  banner and owned purchase detail agree without advancing any status.
- **B2 — Find the return that needs attention; P1.** Extend the existing scoped
  returns list with order/reference search, status and action-needed filters.
  Return each case's next responsible actor and relevant existing deadline from
  its review, custody and money services. Keep decision, parcel receipt and
  repayment distinct; retain the existing detail page and seven-day eligibility.
  **Accept:** correct owned counts across pages, an actionable expired-response
  case, partial return after completion, no repayment claim before evidence,
  retained filters/back navigation and no unrelated buyer data.

### Seller

- **S1 — Make the sales report agree with money records; P0.** Keep completed
  gross sales as the original commercial measure. Derive separate COD pending,
  eligible, authorized and paid amounts from `SellerSettlementService`; derive
  virtual pending/credited amounts from `WalletSettlementService`. Use original
  voucher funding and return adjustments, integer centavos and explicit date
  bases. Never add cash and virtual credits into a withdrawable balance. Missing
  evidence is unavailable rather than zero. Replace the 25-row cutoff with a
  validated, scoped paginator and a visible total.
  **Accept:** discounted and undiscounted COD/wallet purchases, partial/full
  returns before and after seller payment, more than 25 rows, Philippine-date
  boundaries and a foreign shop. Report entries reconcile to their existing
  evidence pages and preserve all original journal records.
- **S2 — Complete the demonstration catalogue; P1, shared with release work.**
  Give new synthetic demo listings complete condition/dimensions and consistent
  variant/SKU examples through the existing `SellerDemoSeeder`. Existing fixture
  updates must be narrowly identified and preserve owner edits, prices, stock,
  approvals, IDs and purchased snapshots. Never infer missing real product facts;
  report them for owner correction. Keep inactive/private listings private.
  **Accept:** fresh isolated seeding and repeat seeding, visible marketplace
  details, one-shop/category eligibility and unchanged purchased history.

### Rider web and native backend

- **R1 — Reviewed credential readiness; P1, policy extension.** Propose a
  reviewed licence-expiry date and a clear current/expiring/expired/not-recorded
  summary. Capture it at registration and through the existing identity
  correction process; retain the date and evidence used in each decision.
  Display current owner document links using `VerificationDocumentService`,
  including missing-file feedback; existing correction-history links remain.
  Recommend validity through the stated Philippine calendar date. Confirm that
  policy before implementation, review unknown legacy dates before enabling a
  new-work restriction, and never invent expiry dates. Expiry blocks new work
  once that policy is enabled; existing parcel/cash obligations use the existing
  authorized continuation or recovery rules.
  **Accept:** yesterday/today/tomorrow, unknown legacy date, rejected/pending
  correction, reviewed renewal, file integrity/private access, and expiry during
  active custody. Company staff receive only authorized readiness information,
  not new access to private identity files.
- **R2 — Make sign-out and duty deliberate; P1.** Show the stored duty state
  and offer an explicit “Go off duty and sign out” action using
  `CourierOperationsService::setAvailability`. Keep ordinary session sign-out
  distinct, especially when another device remains signed in. A failed duty
  change must not be reported as saved. Do not invent online presence or abandon
  assigned parcels, cash, messages or recovery access.
  **Accept:** two sessions, repeated request, interrupted response, off-duty
  pickup rejection, and continued authorized handling of an existing assignment.
  Native adoption needs a documented contract/handoff and separate client work.

### Logistics company administrator and hub handler

- **L1 — Work that this actor can actually perform; P1.** Extend the existing
  `HubParcelWorkstationService::guidance` with an attention filter and bounded
  date/age filtering. Derive counts from actual custody and permitted next
  actions. Distinguish “You need to act” from “Waiting for seller/rider/another
  hub.” Preserve first-visit scanner identification, explicit confirmation,
  manifest routing and counter-code checks. Show attention dots only for work
  requiring that actor's action; ordinary in-transit parcels create no dot.
  **Accept:** company versus handler permissions, direct scanner visit, hub
  switch, duplicate/wrong-hub scan, stale action, manifest arrival, failed-parcel
  inbound and counter pickup. Counts, filters and detail guidance agree.
- **L2 — Reusable movement/COD reports; P1.** Add a modest date-filtered movement
  report from original checkpoints and cash report/export from the existing
  journal services. Reuse financial date rules; label collection dates and
  movement dates separately. Make filtered totals and all-filtered-row CSV
  exports reproducible, including empty ranges. Keep each original order's cash
  responsibility; a report must not create an aggregate remittance command.
  **Accept:** Philippine midnight bounds, partial receipt/shortage, duplicate
  checkpoints, safe CSV text, stable ordering and no foreign company/facility,
  claim code, private proof path or unnecessary customer information in exports.

### Platform admin

- **A1 — One accurate overview of pending decisions; P1.** Extend the existing
  overview with pending account-review requests, identity corrections, escalated
  returns and demo-credit requests. Reuse each source's actual permission and
  action predicate, retain decision-page links, and show age/responsible role.
  Use bounded search/status filters on the underlying queues. Counts must drop
  after a recorded decision and never treat restricted-but-settled history as
  new work. Source failure must not render an “all clear” zero.
  **Accept:** pending versus resolved cases, independent suspension after
  approval, competing/stale decisions, missing source, unauthorized admin and
  no cross-company exposure in company-owned views. No generic override may
  replace a seller, rider, handler or buyer action.
- **A2 — Explain and inspect financial/notification evidence; P1.** Reuse L2's
  read-only report mechanism for authorized platform records. Link COD,
  settlement, virtual proceeds and buyer-return money to their own original
  sources; keep cash and virtual-credit columns separate. Expose sanitized
  notification backlog/last-attempt information from the existing durable
  delivery records and the release diagnostic below. Retry uses the existing
  idempotent worker; do not add a “mark sent” button or a second sender.
  **Accept:** consistent screen/export totals, unsupported metrics labelled
  unavailable, retry without duplicate notice, actual email receipt for required
  account-status notices, and no credentials or private evidence in diagnostics.

## 4. Shared release functions and proof

**F1 — Release readiness and interruption recovery; P0.** Extend `./bagoo.sh
verify` with a proposed operator-only `bagoo:readiness` command. Check required
schema, all manifest-referenced assets, application/database boot, private-file
configuration and the configured origin/portal routes. Add sanitized last-success
heartbeats for scheduled tasks and due notification/email backlog information;
a process ID or `schedule:list` is insufficient. Keep the diagnostic read-only
with respect to business records and return a failing exit status for blockers.
Test both a stopped worker and a running worker with stale/failed execution.

Guard deployments against overlapping runs and preserve a known-good asset
release. Track whether failure occurred before or after schema changes. Restore
the previous scheduler only when its code/schema remain compatible; otherwise
fail visibly with a precise recovery instruction. Do not automatically reverse
migrations or remove financial history. Record backup identity and restoration
instructions before a release; a verified backup/restore exercise is separate
from claiming a backup file exists. Repair the deployment mock and include the
Deployment tests in one reproducible verification entry point alongside backend
tests, frontend checks and build.

**F2 — Demonstrate the same saved transaction across roles; P0 acceptance.**
Use a designated synthetic scenario and one order/parcel reference across
buyer, seller, pickup rider, origin Bayan, required Mother Hub, destination Bayan,
delivery rider/counter and admin. Record expected actor, state, money and next
action at each step. Add scenarios for COD, wallet prepayment, secure self-pickup,
early cancellation, unpaid-wallet expiry, approved retry, third-failure RTS,
missed-handover recovery and a partial buyer return after seller payment. Test
mixed-shop/variant/voucher interactions and original amounts after later edits.
Existing tests can supply most prerequisites; extend them only for actual gaps.

For every scenario, require valid/invalid/boundary/permission checks, stale and
duplicate submissions, rollback and notification reflection. Confirm expected
authorization responses as well as successful pages; a correct 403/404 is not a
broken page. Check both portal URL forms, first visit without overview warm-up,
back/refresh, interrupted responses, account changes and empty/small datasets.
Keep each portal's existing style, accessible keyboard/focus behavior and
responsive controls. Prefetched pages must not reuse another actor's private
context or grant a stale operational action.

Tests remain isolated SQLite `:memory:`. Review database constraints, shared lock
ordering and transaction boundaries for stock, claims and money. SQLite and
sequential replay do not prove simultaneous PostgreSQL behavior. Any additional
disposable PostgreSQL test needs a separate explicit repository-rule exception;
never reuse earlier narrow approval or test against development/production.
Restoration exercises likewise require an approved isolated environment.

**F3 — Verify the demonstrated release and mobile boundary; P0 evidence.**
Record the same deployed commit, additive migration results, working assets,
portal responses, private-file behavior and actual scheduled/email delivery.
Preserve existing records; `fresh` is not a deployment step. Demonstrate recovery
from one controlled failure and compare the saved state before/after. Manual
browser/device evidence remains pending until actually observed; browser
automation requires an explicit request for that task.

Flutter's earlier duty/claim result covers that scenario only. Its owning session
must confirm current backend capabilities, paid-wallet parcels collecting no COD,
camera/proof upload, permission denial, weak-network saved-command reconciliation,
restart/reassignment/account changes and current native builds. Share a sanitized
handoff with version and original command references; do not repeat an already
committed claim. This repository does not edit the separate Flutter project.

## 5. Proposed delivery order

Each row is a separate reviewable branch; use logical commits within it. Start
from the reviewed main after its predecessors are merged. User publication and
deployment remain separate actions. No implementation branch is started here.

| Batch | Scope | Depends on | Completion gate |
|---|---|---|---|
| RG01 — Truthful buyer/seller views | B1, S1 | Current merged main | Tracking banner and paginated reports agree with original order/money sources. |
| RG02 — Reliable release checks | F1, deployment-test repair | RG01 | Controlled build/scheduler failures produce correct outcomes; complete verification command is green. |
| RG03 — Logistics attention and reports | L1, L2 | RG02 | Own-action queues, counts, scan guidance and sanitized exports agree across company/handler scope. |
| RG04 — Rider readiness | R1, R2 | RG03 and an explicit expiry/duty policy decision | Reviewed credential handling, deliberate duty/sign-out, private access and active-work recovery pass. |
| RG05 — Review worklists | B2, A1, A2 | RG03; RG04 if credential work is selected | Current decisions, deadlines, notifications and financial evidence remain consistent across involved roles. |
| RG06 — Release acceptance | S2, F2, F3 | Selected earlier batches merged | Same-release role demonstrations, recovery, runtime checks and the separate mobile handoff have retained evidence. |

RG04 adds a policy capability; do not quietly add it to the original baseline or
claim it already exists. If it is not selected, record the agreed scope before
the final assessment. Plan RG01/RG02 first; size and schedule later rows after
those results. Use the existing October 4–November 20 delivery window and reserve
the final week for fixes and demonstration; these are not promises of completed
work or invented historical hours.

## 6. What must not be added merely to chase a score

Timed nearest-rider offers, arbitrary three-job capacity, fixed rider pay, a
five-percent platform fee, automatic buyer completion, direct hub bypasses,
unbacked refunds, external payment gateways, bank withdrawals, bundles and
terminal lost/damaged compensation are not prerequisites for the agreed core.
Some could be useful later, but each requires its own product and financial
decision. Existing lost/missing/damaged evidence and recovery must remain usable;
never fabricate a terminal handover or refund to imitate a different workflow.

Keep the existing four acceptance workbooks as separate external checks. For a
disagreement, record the agreed rule, its reason and equivalent evidence. For an
unperformed test, retain the verification gap. Neither a planned fix nor a
policy explanation is an observed pass. Reassess 95-point readiness only after
the selected work and all critical acceptance gates above are actually complete.
