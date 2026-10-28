# Logistics visual workspace review

Branch: `feat/logistics-visual-workspace`, based on merged `main` at `7da3b7e`.
This is an in-progress local review. No publication or deployment is claimed.

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
| Shell and navigation | Task groups, active destinations, facility context, consistent content headings and local surface styles | Nineteen main destinations returned HTTP 200 with one content heading and no desktop document overflow | Final phone/tablet inventory, drawer and keyboard traversal |
| Overview | Actual attention queues lead to the next workspace | Desktop page and normal parcel navigation | Final narrow-screen review |
| Hub network | Actual-coordinate Leaflet map, saved parent connections, readable hub cards and details | Map pins, hub drawer and searchable choices exercised; scoped payload checks | Final map-dialog, missing-pin and keyboard review |
| Fleet and company | Actual vehicle/driver cards, searchable hubs/staff, parent/work-placement choices | Official seeded vehicles used in both delivery legs; company payload excludes another company | Updated hub/staff/rate form error and persistence checks |
| Transfers and Mother Bags | Visual route/progress, hub/map picker, vehicle cards, scan loading/seals and readable retained references | Two actual sealed bags, departed/received manifests and individual parcel receipts completed | Reverse-transfer selection, discrepancy/recovery detail and phone checks |
| Parcel registry and Scan Station | Separate parcel stage, commercial state, actual custodian and next actor; deliberate eligible-rider dialog | Real sorting/assignment/POD journey; rider dialog fitted a 390px viewport; focused rendering checks | Final camera/manual-entry and filter/error review on narrow screens |
| Counter pickup | Clear handler versus company-view authority; bounded existing secure dialog; Mother view no longer requests child-counter setup | Focused readiness, missing-evidence, authorization and Mother-view rendering cases | Fresh collection through the redesigned page and final phone dialog checks |
| Delivery and parcel recovery | Actual-custodian guidance, attempt timelines, bounded retry dialog and explicit permitted recovery actions | Actual-holder payload assertion and focused recovery suite; prior interrupted run reached the retry review | Valid retry approval/completion and populated parcel-recovery controls in the current run |
| Buyer returns and handover issues | Separate return route/intake choices and readable original source choices; shared logistics framing | Lists opened; one new buyer return request exists in isolated memory | Seller approval, separate-barcode intake, return manifest picker and completed reverse handovers |
| Cash and earnings | Consistent headings, neutral cash-stage diagram, clearer original funding labels | Original COD collection and named counted hub receipt after the new delivery | Remaining evidence/detail navigation and phone layouts; no external seller transfer claimed |
| Resources, exceptions and history | Shared shell/header, useful filtered empty states and no redundant single-page navigation | Main lists opened; saved screenshots reviewed; resource/exception empty-state polish inspected in source | Populated detail, decision/error and responsive checks after the final polish |
| Notifications, account and guide | Matching headings/icons, corrected logistics role label and an actual workflow guide with named actions | Main pages opened; role-label and notification checks; guide checks distinguish company viewers from floor handlers; saved screenshots reviewed | Final keyboard/form review and updated guide/empty-state screenshots |
| Activation and errors | Existing split authentication and safe logistics recovery screens retained | Source and existing focused access tests | Current rendered activation and intentional missing-page recovery check |

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

The current return request was created once. The seller automation then searched
for an obsolete submit caption after choosing Approve; the actual caption is
"Approve return." The resume script was corrected, but a saved approval/intake
has not yet been verified. That interruption is not recorded as an application
failure or as a successful return.

## Verification checkpoint

- Foundation PHP checks: **22 passed / 574 assertions**.
- Page/navigation/company/recovery PHP selection: **78 passed / 10,651
  assertions**, including saved map coordinates and company isolation.
- Final focused actual-holder/recovery selection: **5 passed / 627 assertions**.
- Earlier affected frontend selection: **77 passed**. The latest narrower
  selection passed **30 cases**, including the logistics account label,
  Mother-counter explanation, accessible progress text and permitted guide
  navigation. These selections overlap and must not be added into a unique-case
  total.
- The final **TypeScript/Vite production build passed** after the guide permission
  fix, under a 0.5-CPU/1,536MB container cap with a 1,024MB Node heap. Type checking
  remains enabled. An earlier artificially reduced 512MB heap exhausted memory;
  two shared-page nullable-account diagnostics were subsequently fixed and the
  adequate-heap builds passed. These local results do not measure Azure builds.
- Final PHP formatting passed for all **seven changed PHP files**. Git whitespace
  checks passed.

## Resource and acceptance limits

After the user reported PC lag, the single owned test browser, its one work tab
and worker were closed after saving the checkpoint. No browser is kept running
for this review. The isolated memory server was left idle to retain the current
case state. Browser testing must resume with one bounded browser and only the
tabs needed for the current check, without changing desktop focus or rebuilding
assets during navigation.

The all-page mobile/detail/keyboard review, current exception/return controls
and final rendered polish remain open. Those requirements are not replaced by
the successful normal delivery. Azure, Flutter, PostgreSQL concurrency, physical
device operations and actual external payments are outside this UI branch.

## Scoped assessment

Provisional logistics task clarity: **6/10 before -> 8/10 after**. Actual route
choices, vehicle/rider cards, current-holder guidance and consistent navigation
reduce interpretation during the verified normal journey. The rating remains
provisional because the remaining populated exception, keyboard and responsive
checks are not complete. It is not a whole-project readiness or defense score.

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
