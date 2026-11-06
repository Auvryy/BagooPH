# Web and rider interaction audit — November 3, 2026

Status: in progress. Results below distinguish completed checks from running checks.

## Scope and environment

The requested audit covers the current Bagoo web/backend and Flutter rider
worktrees. Buyer mobile and buyer-specific fixes are excluded. Existing shared
backend/frontend regression suites may exercise synthetic buyer fixtures to
verify cross-role boundaries; that is not a buyer-mobile acceptance test.
Azure was not updated or deployed, and no phone was connected. External task
tracking was explicitly excluded from this session.

Baseline web revision: `9e5c73b`. Baseline rider revision: `03942c2`. Browser
automation uses a fresh headless Chromium profile against the existing local
seeded application. Native Linux checks use a private display. Neither requires
the user's desktop window to be focused. No real deliveries, cash transfers,
payments or account approvals are fabricated. Browser checks do not submit
business commands; ordinary login/session/read acknowledgements may persist.
Backend tests use SQLite `:memory:`; no application PostgreSQL database is reset.

## Evidence and limits

| Check | Result | What it proves |
|---|---|---|
| Existing frontend/deployment baseline | 652 passed, 4 failed out of 656 | Exposed four test-harness/expectation issues described below |
| Candidate full frontend/deployment run | **669 passed, 0 failed** | Current helper, markup, controlled event and deployment-command checks; not 669 browser journeys |
| Initial Chromium sweep | 209 passed, 2 failed; 185 rendered page/viewport combinations | Non-buyer navigation at 390, 768 and 1440px, auth validation/pending/session/role rejection; individual actions need their own checks |
| Candidate browser sweep | Pending | Must run after the final asset build |
| Candidate TypeScript/Vite build | Running | Earlier Docker build passed; host build had a permissions failure in generated assets |
| Full backend regression sweep | Running | Must retain its original nonzero result if fixtures failed before their repairs |
| Rider full baseline | 753 passed, 9 skipped | Repository/widget/state/API doubles; skips are not passes |
| Rider native integration and final checks | Running | Physical Android, real GPS/camera/background and deployed writes remain separate |
| Locked dependency advisory scans | Findings open | Package advisories are recorded; they do not establish an exploitable application route |

The initial browser failures were (1) an overly narrow empty-state text assertion
in the new audit runner, corrected to the actual useful “No listings match…”
copy, and (2) one hub page readiness timeout during overlapping build activity.
The final sweep must establish whether the latter reproduces. The initial sweep's
“pass” checks only HTTP, nonempty rendering, document width and captured JavaScript
exceptions. Offscreen-control and label measurements were candidate findings,
not silently included in a whole-page usability pass. Deliberately scrollable
tables can place controls outside the visible horizontal area.

## Confirmed defects and repairs

| Priority | Finding | Repair / finish evidence |
|---|---|---|
| High | Seller mobile chat was clipped behind its fixed contact pane; at 390px the reply field started at x349 with width183 | Separate mobile list/thread panes, Back, focus restoration and contained scrolling; verify actual 320/390/768/1280px render |
| High | An unsent seller reply followed the user into another recipient's conversation | Recipient-specific drafts; four controlled component-event regressions pass |
| Medium | Hidden mobile seller chat could fetch history and acknowledge unseen messages | Fetch only when the thread and document are visible; browser request/focus checks required |
| Medium | Seller settings/profile, counter/facility/applicant searches and hub-picker toggle buttons lacked programmatic names | Connect visible labels and name icon controls; browser label checks required |
| Medium | Hub parcel search allowed typing beyond its server's 120-character limit | Match the existing server boundary in the input; controlled filter/retry test passes |
| High | Creating logistics staff with another same-role account's recovery email returned a server error | Role-scoped email ownership validation; isolated repros and full company-setup tests pass |
| Medium | Temporary staff-password writers accepted spaces as the required symbol and accepted hidden controls | Reuse the shared password policy; valid credentials and cross-role identity allowances remain tested |
| Medium | Rider sign-in discarded server field errors and general auth errors were not announced accessibly | Rider auth fixes and focused tests; see rider report for final counts |

The four web baseline harness failures concerned: a scheduler stub that returned
no running container; an obsolete hub search selector/missing input limit; a
server-rendering harness that attempted a Node CSS import; and seller-chart copy
still expecting every delivery to require buyer confirmation. Repairs preserve
the selected version-two contract and historical version-one receipt behavior.

## Build and verification order

| Order | Work | Concrete finish evidence |
|---|---|---|
| 1 | Finish the confirmed interaction, feedback and privacy defects plus their regressions | Candidate build; focused negative/retry checks; final browser and Linux render evidence |
| 2 | Resolve supported dependency advisories in a separate bounded maintenance change | Reviewed compatible lockfile updates, clean advisory scans, relevant regressions and production build |
| 3 | Add the planned read-only release-readiness report and scheduler success/backlog evidence | Detect unavailable database/cache/storage, pending migrations, stopped or failing jobs and mail failures without changing business state |
| 4 | Verify the current rider-to-backend contracts, then Azure parity after the user updates Azure | Same-revision synthetic kernel scenarios; afterward separately verify each deployed capability and write gate with an authorized account |
| 5 | Finish physical Android acceptance when a phone is available | Real permission denial/recovery, camera scans, location, file-picker interruption, secure storage, TalkBack, process death, background behavior and performance |

The rider has seven independent write gates. Test fixtures do not activate
operations, parcel, prepaid-delivery, cash, messaging, notification or handover
capabilities in a real build. Current in-app notifications do not establish push
delivery; a missing push SDK or iOS application is not counted as a failed feature
when that platform/service is outside the implemented scope. Do not add automatic
offline writes or external services just to increase coverage counts.

## Coverage boundaries

The final matrix will link role-specific results. Existing cases cover auth,
approval/ownership, seller catalogue and order worklists, provisioning and hub
custody, Mother Bags/manifests, exceptions/returns, COD and settlement, profiles,
messages/notifications, maps/location, stale/repeated requests, recovery and
Philippine currency/date behavior. Their exact executed counts and failures must
be taken from completed output, not inferred from filenames.

SQLite does not prove PostgreSQL lock contention or simultaneous production
requests. Local kernel timings and browser navigation timings do not prove
thousands of concurrent users. Chromium viewport emulation does not prove Safari,
Firefox, iOS or physical-phone behavior. No 100% code-coverage or zero-defect claim
is made.

## Artifacts and reproduction

- Browser runner: `scripts/qa/web-interaction-audit.mjs` (requires an installed
  Playwright module supplied with `BAGOO_PLAYWRIGHT_MODULE`; optional local Chrome
  executable via `BAGOO_CHROME_PATH`). It refuses non-loopback targets.
- Private browser JSON and screenshots: ignored `storage/app/qa-web-2026-11-03/`.
- Backend details: [backend report](2026-11-03-backend-qa.md).
- Rider details: the rider repository's `docs/audits/2026-11-03-rider-qa.md`.

Local commits and a consolidated PR draft will be recorded after verification.
Nothing is pushed or deployed by this audit.
