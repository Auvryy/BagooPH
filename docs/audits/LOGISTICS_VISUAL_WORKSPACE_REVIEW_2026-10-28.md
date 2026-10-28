# Logistics visual workspace review

Branch: `feat/logistics-visual-workspace`, based on merged `main` at `7da3b7e`.
Updated October 29, 2026. Scoped local implementation and acceptance are complete.
The branch and PR description remain local; publication and deployment are separate.

## Outcome

Logistics pages now share grouped navigation, facility context, consistent headings,
raised panels, visible form boundaries and readable empty/error states. Saved hub
maps, searchable hub choices, vehicle/driver cards, eligible-rider choices,
scan-first controls and progress diagrams make the current holder, next action and
responsible person easier to recognize. The visual direction uses Plus Jakarta
Sans, Bagoo crimson and a restrained shadcn-style workspace.

Maps display saved coordinates and configured hub connections. They do not invent
road geometry. Company isolation, original Mother Hub routes, actual scans,
recipient/proof checks and separate financial evidence remain authoritative.

## Acceptance matrix

| Area | Implemented and verified locally | Limits |
| --- | --- | --- |
| Shell, overview and navigation | Nineteen desktop destinations; all 22 main/create destinations at 390px and 768px, repeated after responsive fixes. Real Tab/Shift+Tab, Escape, opener focus, route selection and desktop breakpoint checks passed. | Physical device gestures are separate acceptance. |
| Network and hub choices | Actual pins/parent connections, searchable hub choices, map dialog/Escape and actual mouse selection of source and permitted Mother destination. An unpinned new hub has an honest list fallback. | Lines show the configured network, not a driving route. |
| Fleet and company setup | Vehicle/driver cards used in real transfers; scoped hub creation, postal-text reset, invalid-rate rejection, named operator placement and activated account reflection. | Real companies still need their own approved resources and correct coverage. |
| Transfers and Mother Bags | Searchable/map route choices, vehicle cards, bag membership/seal/master tag, departure, individual receipt and readable detail/history. Eight manifests closed and four bags received across forward, reverse and recovery journeys. | No physical label/printer claim. |
| Registry and Scan Station | Actual intake, sorting, eligible-rider assignment and next-actor guidance. Scanning sits before statistics. Unknown/wrong-hub and unavailable-camera paths retain clear feedback/manual entry. | Headless tests do not prove a phone camera or scanner. |
| Counter pickup | Linked labels, 44px fields/actions, focus-managed scrolling dialog and reachable footer at 1360×650, 390×650 and 390×420. Wrong code rejected; valid owner code, identity checks and original COD completed once and reflected in buyer tracking. | Test recipient, proof and cash were synthetic. |
| Delivery retry and supervised handover | Real failed attempt retained rider custody and no COD. Independent review, explicitly chosen source, known rider acknowledgement and another original-hub handler receipt recovered a local parcel. Past date rejected, future Philippine time verified in saved evidence, early scan blocked; actual timed release then delivery/COD/counting completed. | SQLite does not establish concurrent PostgreSQL lock behavior. |
| Physical parcel recovery | Actual wrong-hub observation after sealed-bag departure; independent authorization, physical intake, vehicle cards, both required Mother recovery legs and original discrepancy/receipt closure. The unchanged order then completed with original COD and counted receipt. Observed facility now names the actual scan hub separately from its intended destination. | No shortcut between different child hubs was introduced. |
| Buyer returns | Separate barcode/weight/condition/photo intake; original delivery barcode rejected. Barcode search/Enter does not submit a transfer, source/destination are locked, prepared/arrival links open correctly, both reverse legs and original-hub staging lead to actual seller receipt. Original purchase stays completed. | Physical return custody and its financial settlement remain separate. |
| Cash and earnings | Original collections, named offers/counted hub receipts, released delivery earnings and readable phone layouts. Four retained COD accounts match four completed purchases. | Ledger release is not a real bank or seller payout. |
| Resources, history and exceptions | Unused hub deactivation/reactivation retained two decisions. Actual history record and failed-attempt filters/detail open in the logistics shell, including phone layouts. | No unrelated account or working facility was restricted. |
| Account, notifications and guide | Shared headings/icons, logistics role label, existing operational links and notification rendering checks. Invalid contact rejected; valid contact saved with +63 normalization and original contact restored without changing sign-in identity or approval. | No role conversion or reviewed-identity bypass. |
| Activation and errors | Actual owner-created worker cannot skip temporary-password replacement, assignment acceptance or email verification. Owner setup returns a safe 403 to that operator. Login/register fit desktop/phone; intentional missing route uses the branded 404. | External email-provider delivery was not measured. |

There is no separate logistics message route in the actual navigation/router
inventory. Shared destinations retain their permission flags; viewing a hub does
not grant scan authority.

## Actual browser evidence

Puppeteer operated real buyer, seller, pickup/delivery rider and hub/company
controls against a guarded, separate SQLite `:memory:` server using official demo
accounts and synthetic proof. Business transitions did not use direct database
writes or HTTP command shortcuts. A read-only fixture projection supplemented the
visible results; initial isolated fixture setup supplied seeds/measurements.

The final current run retains **four completed purchases**, **eight closed
manifests**, **four received Mother Bags**, **four COD accounts** and one accepted
buyer return with actual seller receipt. The purchases cover regional doorstep,
same-child failed attempt/reviewed return/timed retry, secure local counter and
regional wrong-hub recovery followed by normal delivery. Three doorstep orders
retained PHP 2,910 each; the no-doorstep-charge counter retained PHP 2,850.
The original regional buyer return did not rewrite its completed purchase.

The return flow exposed a prepared manifest hidden before loading and an incorrect
root link. Guidance now distinguishes preparation, departure, arrival, staging
and seller receipt, with the correct original transfer link and optional barcode
artwork. Counter review exposed labels without input association and small touch
targets; these now focus the intended fields and retain reachable actions.

The local handover check exposed a four-hub-only recovery guard. Version-two local
receipts now accept only their frozen eligible one-hub checkout route, keep local
destination intake status and bind the reviewed quote/path to authorization.
Legacy/regional recovery and terminal/holder/receipt guards remain covered.
Visual review then caught the observed-hub label using the intended destination;
both worklist and detail now use the facility on the immutable observation.

A host restart lost an earlier memory runtime. Its screenshots/checkpoints were
archived and its database records are not represented as current records. The
final runtime was rebuilt from isolated fixtures and the journeys above completed
through actual controls. Earlier native-date filling, hidden-dialog assertions,
phone-normalization expectations and unscoped duplicate map markers interrupted
automation; the corrected harness verified actual saved outcomes. These were not
silently counted as successful submissions. The current run has **63 passing
checkpoints** and **zero captured unexpected JavaScript/server errors**; checkpoints
are observations, not independent unit tests.

## Automated verification

- Latest frontend selection: **107 passed** across seven affected files.
- TypeScript/Vite production build passed with type checking retained, bounded to
  0.5 CPU / 1,536MB and a 1,024MB Node heap. Only PHP display/recovery changes followed.
- Local/legacy handover and version-two flow selection: **69 passed / 5,982 assertions**.
- Final parcel-recovery selection: **51 passed / 6,746 assertions**, including the
  actual observed/expected facility in list and detail.
- Return-custody selection: **16 passed / 1,642 assertions**, including root and
  hub-subdomain prepared-transfer links and buyer-link exclusion.
- Earlier foundation/page/company/actual-holder selections passed 22/574,
  78/10,651 and 5/627. Selections overlap and are not additive suite totals.
- PHP formatting and Git whitespace checks passed. No development or production
  PostgreSQL reset was used. A full unrelated application suite was not rerun.

An early artificial 512MB Node heap exhausted memory. Later adequate-heap builds
passed after two nullable-account diagnostics were corrected. This is local build
evidence, not an Azure performance measurement.

## Resource and scope limits

The final browser uses one capped headless process with one working tab and no
desktop-focus calls. It is parked with **zero open tabs**; role contexts and the
isolated server remain available for continuation. No personal browser was changed.
There is no reason to open more browser processes to resume this audit.

The scoped all-page local acceptance above has no outstanding observed failure.
Azure rollout, Flutter, physical cameras/touch/printers/parcels/cash, external
payments/email delivery, production load and PostgreSQL concurrency need their
own evidence. Local UI acceptance does not certify the whole project or thesis.

## Scoped assessment and handoff

Logistics task clarity: **6/10 before → 8/10 after**. Consistent navigation,
actual visual choices and explicit custody/next-person guidance reduce
interpretation across the verified ordinary and exception journeys. Counter
controls and return next-action clarity are each **5/10 → 8/10**, supported by
rendered labels/footer checks and complete original-route handovers. These are
scoped engineering assessments, not overall readiness percentages.

The combined [PR title and description](LOGISTICS_VISUAL_WORKSPACE_PR_DRAFT.md)
remains a local draft, not saved on GitHub. Only the user publishes commits.
Implementation commits are listed below; the final evidence-only handoff commit
is reported separately when created.

| Local commit | Subject |
| --- | --- |
| `d9f64f3` | feat(logistics): add visual hub and assignment controls |
| `fc8b650` | fix(logistics): make transfer progress accessible |
| `81a8a71` | feat(logistics): guide hub transfers with maps and scan steps |
| `f4439bd` | feat(logistics): clarify company hubs fleet and staff choices |
| `525d0c9` | feat(logistics): show clear parcel actions and rider choices |
| `0444e28` | feat(logistics): clarify recovery custody and review controls |
| `6a6167c` | feat(logistics): unify workspace navigation and task guide |
| `b4b5c37` | feat(logistics): align cash return and review pages |
| `a7dafd1` | docs: record logistics workspace evidence and remaining checks |
| `daf2620` | test(logistics): verify visual return and recovery choices |
| `583aff3` | fix(logistics): keep mobile navigation focus inside the drawer |
| `d59830d` | fix(logistics): put phone scanning and next actions first |
| `d0b1536` | fix(logistics): keep money and review pages readable on phones |
| `16efe70` | fix(logistics): guide returns to their original next handover |
| `318b338` | docs: refresh logistics workspace acceptance evidence |
| `d66b629` | fix(logistics): make counter verification easier to use |
| `29b02ed` | fix(logistics): retain local routes during supervised recovery |
| `2a460fa` | fix(logistics): show the actual observed recovery hub |
