# Logistics and Rider focused check — November 3, 2026

Scope: the current logistics website and Rider Mobile branches. Three agents
checked the backend, logistics interface and native client in parallel; the
coordinator checked courier web interactions and the combined production build.
Buyer source and tests were not changed. Existing work and branches were kept.

## Fix

The pickup counter preserved its component state when the company owner switched
facilities. An edited hours value for Hub A could remain in Hub B's form and be
saved against Hub B. Its old parcel release dialog and errors could remain open.
The counter now synchronizes hours with the selected facility and its saved value,
and clears the release selection and errors when the facility changes. Two
component regressions cover the retained-component switch, submitted hub/value,
saved-value refresh, and stale dialog/error cleanup.

Scanner, parcel-queue and counter guidance also use darker text at five nodes
identified by the earlier rendered accessibility scan. This change was checked
by source review and build; the rendered accessibility scan was not repeated.

Scoped counter facility-state safety: **5/10 → 8/10**. This engineering assessment
reflects the regression fix and focused tests, not overall production readiness.

## Fresh verification

| Check | Result | Evidence |
|---|---|---|
| Logistics backend | 168 passed, 9,508 assertions | `NewEraLocalFlowTest`, `NewEraMotherBagTest`, `RiderOperationsApiTest`, `RiderParcelApiTest`, `RiderHandoverApiTest`, `CodCashCustodyTest` |
| Owner permissions | 2 passed, 43 assertions | Focused owner provisioning and activation cases in `NewEraCompanySetupTest` |
| Logistics frontend | 82 passed, no skips | Scanner, workstation, registry, handover, counter and facility-switch checks |
| Courier web | 60 passed, no skips | `courier.test.mjs`, `rider-workspace-interactions.test.mjs`, `courier-navigation-lifecycle.test.mjs` |
| Rider Mobile | 60 passed | Eight focused activation, transport, contract, workflow, handover, cash, Trips and payment-instruction files |
| Native client → Laravel kernel | 1 scenario passed | COD delivery, failed-delivery hub receipt/retry, retained proof, duplicate safety and foreign-rider denial; frozen web `a9df50f` |
| Production frontend | Passed | Docker `npm run build` (`tsc && vite build`) |
| Diff whitespace | Passed | `git diff --check` |

Backend tests used isolated SQLite `:memory:`. They cover local/regional handoffs,
sealed Mother Bag departure and individual receipt, native rider actions,
original v1/v2 completion evidence, COD remittance and shift closure, and
company/handler ownership boundaries. No backend defect was found in this run.
No Rider code change was needed; its branch remains clean.

Local raw logs are retained under the ignored
`storage/app/qa-logistics-2026-11-03/` directory. These results are focused checks;
they do not claim every project test or every rendered state passed.

## Operational limits

No deployment, Azure business writes, live database reset, or physical-phone
checks were performed. Six unaccepted mobile write gates remain disabled.
Local fixture/kernel success does not enable them or establish deployed/device
acceptance. Seller Messages request cleanup was verified and committed separately
before branch publication: ten Firefox desktop-to-phone resize/navigation cycles
completed without browser exceptions. The focused seller interaction tests and
the production build also passed with this cleanup. It cancels both the history
request and the unread-count refresh when the conversation is hidden or left.
Earlier broad audit drafts remain historical work
in progress and are not a fresh all-green report.

## Local pull request draft

Title: Harden logistics provisioning and portal interactions

Body: This branch validates logistics staff identities and temporary passwords,
limits seller inbox identity data, improves narrow-screen seller conversations
and portal control labels, and fixes pickup-counter state when switching hubs.
Versioned logistics, account migration and shared regression fixtures now retain
the original authorization and financial evidence. Counter, scanner and parcel
guidance text is easier to read.

The fresh focused checks above pass, including 170 backend tests, 142 web tests,
60 Rider tests, one native client-to-kernel scenario and the production frontend
build. Earlier shared regression evidence is recorded in
[the shared regression review](2026-11-03-shared-regression-review.md).
Buyer-owned failures from the earlier broad audit remain outside this change.
Azure rollout, physical-device behavior and unaccepted native write gates require
their existing acceptance. This is a local draft; no PR was published or pushed.
