# Logistics visual workspace review

Branch: `feat/logistics-visual-workspace`, based on merged `main` at `7da3b7e`.
Updated October 29, 2026. This is an in-progress local review. No publication or
deployment is claimed.

## Requested outcome

Every logistics destination should feel like one recognizable workspace. An
operator should recognize the current parcel holder, the next permitted action,
the person responsible and any blocker. Hub maps, searchable hub choices,
vehicle/driver cards, scan controls and progress diagrams should reduce the need
to read long identifiers. The review includes auxiliary pages, forms, details,
empty/error states, keyboard use and narrow screens.

The visual direction uses Plus Jakarta Sans, Bagoo crimson, a quiet grey canvas,
white panels, subtle shadows and visible form boundaries. It preserves company
scope, configured Mother Hub routes, actual custody scans, original payment
evidence and the distinction between retained earnings and real cash.

## Implementation and evidence matrix

| Area | Current change | Verified evidence | Still required |
| --- | --- | --- | --- |
| Shell and navigation | Task groups, active destinations, facility context, consistent headings and a focus-contained phone drawer | Nineteen desktop destinations and all 22 main/create destinations at 390px and 768px; real Tab/Shift+Tab, Escape, route selection and 1024px breakpoint checks | No outstanding root-page width or drawer-focus finding; populated detail controls retain their own checks below |
| Overview | Actual attention queues lead to the next workspace; phone actions sit below their explanation | Desktop page, normal parcel navigation and final phone/tablet review | No outstanding overview layout finding |
| Hub network | Actual-coordinate Leaflet map, saved parent connections, readable hub cards and details | Pins, map dialog, Escape and searchable choices exercised; new hub without coordinates retained an honest list fallback | Physical touch-map behavior is not claimed |
| Fleet and company | Actual vehicle/driver cards, searchable hubs/staff, parent/work-placement choices | Official vehicles used in both delivery legs; company isolation; actual hub creation, postal reset, placement search and invalid-rate rejection | Named staff provisioning, activation and remaining placement decisions |
| Transfers and Mother Bags | Visual route/progress, hub/map picker, vehicle cards, scan loading/seals and readable references | Two actual sealed bags, closed manifests, individual receipts, closed-detail navigation and phone/tablet lists | Reverse-transfer selection and populated discrepancy/recovery detail controls |
| Parcel registry and Scan Station | Current stage, custodian and next actor; eligible-rider dialog; scanner controls before statistics | Real sorting/assignment/POD; 390px rider dialog; phone/tablet views and focused guidance checks | Final camera/manual-entry failure and filter-control review; no physical camera claim |
| Counter pickup | Clear handler versus company-view authority; bounded existing secure dialog; Mother view no longer requests child-counter setup | Focused readiness, missing-evidence, authorization and Mother-view rendering cases | Fresh collection through the redesigned page and final phone dialog checks |
| Delivery and parcel recovery | Actual-custodian guidance, attempt timelines, bounded retry dialog and explicit permitted recovery actions | Actual-holder payload assertion and focused recovery suite; prior interrupted run reached the retry review | Valid retry approval/completion and populated parcel-recovery controls in the current run |
| Buyer returns and handover issues | Separate return route/intake choices, next-handler guidance, existing-transfer links and explicit original source choices | Actual seller approval and separate-barcode/weight/condition/photo intake; original delivery code rejected by the client; completed order retained; phone/tablet lists; automated prepared-transfer navigation and staging guidance | Current return manifest picker, complete reverse handovers and populated handover review controls; newest guidance awaits browser review |
| Cash and earnings | Neutral cash-stage diagram, collection-date explanation and phone earnings cards that keep the amount visible | Original COD collection and counted hub receipt; final phone/tablet layouts and saved visual review | Remaining evidence/detail navigation; no external seller transfer claimed |
| Resources, exceptions and history | Shared shell/header, useful empty states, compact advanced filters and no redundant single-page navigation | Main phone/tablet lists, resource detail and saved visual review | Populated decisions, errors and resulting history detail |
| Notifications, account and guide | Matching headings/icons, correct logistics role label and an operational guide with named actions | Main phone/tablet pages, role/notification checks and permitted guide navigation | Remaining account form/keyboard review |
| Activation and errors | Existing split authentication and safe logistics recovery screens retained | Intentional missing URL rendered the branded 404 without framework disclosure; focused access tests | Current rendered activation controls |

There is no separate logistics message route in the current navigation/router
inventory. This branch does not invent one. Shared destinations remain subject
to their existing permission flags; a facility view is not scan authority.

## Actual browser journey

A guarded server held a separate SQLite `:memory:` database. The fresh run used
the official demo seeds and synthetic proof files. Puppeteer operated the actual
buyer, seller, pickup rider, origin, Mother Hub, destination and delivery-rider
controls. It did not advance business state through direct database writes or
HTTP command shortcuts.

One new regional COD order passed checkout, seller readiness, original-waybill
pickup, origin intake, two sealed Mother Bags/two manifests, Mother sorting,
destination receipt/sorting, eligible rider assignment and recipient/POD checks.
The order completed automatically. The rider's original PHP 2,910 collection and
named counted hub receipt were verified, as was buyer tracking. Both manifests
closed and both bags were received. This proves that normal seeded journey
through the redesigned controls; it does not prove every exception or a physical
parcel/cash handover.

An earlier memory runtime was lost after the local environment restarted. Its
screenshots and checkpoints were archived before the fresh run. Its local
failed-attempt and retry-dialog observations are historical evidence, not current
saved orders or a successful retry approval. A datetime-input automation failure
did not establish a valid saved retry date.

The current return request was created once, approved by the seller and accepted
at the original destination hub using its separate barcode, measured weight,
condition, photo and handover details. Entering the original delivery code was
rejected by the client form. The original purchase remained completed. Reverse
hub transfers and seller receipt have not yet been completed in this browser run.

The final phone/tablet inventory covered all 22 root/create destinations twice,
with the second 44-check pass using the navigation and scanner refinements.
Keyboard testing found focus behind the phone drawer. The existing dialog
primitive now contains and cycles focus, restores the opener on Escape and closes
after selecting a real route. The final keyboard checks passed. Saved phone views
were reviewed for hierarchy as well as measured overflow. The fresh run captured
no JavaScript errors or unexpected server errors.

Automated return-link checks exposed that a prepared manifest was hidden until
its first loading scan. The presenter now finds the retained route reservation,
offers its readable original transfer and uses the correct root/subdomain path.
The page distinguishes preparing a leg, continuing a prepared transfer, recording
arrival, staging for seller collection and seller receipt. Barcode/QR artwork is
optional in the logistics view so it does not bury those instructions. These
latest details have automated coverage but have not yet had another browser pass.

## Verification checkpoint

- Foundation PHP checks: **22 passed / 574 assertions**.
- Page/navigation/company/recovery PHP selection: **78 passed / 10,651
  assertions**, including saved map coordinates and company isolation.
- Final focused actual-holder/recovery selection: **5 passed / 627 assertions**.
- Latest affected frontend selection: **107 passed**, across seven files,
  including the new return next-handler/prepared-transfer checks. This supersedes
  the earlier 77-, 30- and 106-case selections; they are not separate unique totals.
- Latest isolated return-custody selection: **16 passed / 1,642 assertions**,
  including actual readable manifest GETs on root and hub-subdomain layouts and
  buyer-link exclusion. No development or production database was used.
- The latest **TypeScript/Vite production build passed**, including the final
  return guidance, under a 0.5-CPU/1,536MB container cap with a 1,024MB Node heap. Type checking
  remains enabled. An earlier artificially reduced 512MB heap exhausted memory;
  two shared-page nullable-account diagnostics were subsequently fixed and the
  adequate-heap builds passed. These local results do not measure Azure builds.
- Earlier PHP formatting passed for **seven changed PHP files**; the latest
  return service/test formatting passed for **two files**. Git whitespace checks
  passed.

## Resource and acceptance limits

After the user reported PC lag, the single owned headless browser was inspected:
it had zero open tabs. It and its worker were closed, and the process/scope checks
confirmed no owned browser remained. Personal Chrome was untouched. The isolated
memory server was left idle to retain the cases. Any later browser acceptance
uses one capped background browser and one work tab, without desktop focus changes
or navigation during an asset build. The latest continuation used automated tests
and source review without reopening Puppeteer.

All main/create-page phone/tablet and drawer keyboard checks passed. The named
populated exception, reverse-return, counter, account/activation and remaining
detail controls above remain open. They are not replaced by the normal delivery.
Azure, Flutter, PostgreSQL concurrency, physical device operations and actual
external payments are outside this UI branch.

## Scoped assessment

Provisional logistics task clarity: **6/10 before -> 8/10 after**. Actual route
choices, vehicle/rider cards, current-holder guidance and consistent navigation
reduce interpretation during the verified normal journey. The rating remains
provisional because populated exception/detail controls and the final return
changes still need browser acceptance. It is not a whole-project readiness or
defense score. Return next-action clarity is provisionally **5/10 -> 7/10** based
on corrected actor/staging guidance and tested navigation, pending rendered review.

## Local handoff

The combined [PR title and description](LOGISTICS_VISUAL_WORKSPACE_PR_DRAFT.md)
is a local draft. Local commits record individual changes and their own check
limits. Only the user publishes commits. The full goal remains open until the
remaining acceptance items above are verified.

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
