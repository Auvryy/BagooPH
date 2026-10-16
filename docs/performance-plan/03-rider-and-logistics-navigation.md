# PF03: Rider Web and Logistics Navigation

## Outcome and dependency

After PF01 establishes the shared [policy](POLICY.md), improve eligible reads
in the courier web portal and logistics workspaces. They share freshness
concerns but retain separate commits, scope decisions and acceptance gates.
This batch does not change the Flutter project or its native API clients.

Read the [courier](../COURIER_FLOW.md), [rider interface](../RIDER_UI_DESIGN.md),
[hub custody](../SORTING_CENTER_LOGISTICS_FLOW.md) and
[logistics](../MASTER_LOGISTICS_SPECIFICATION.md) contracts. Use the actual
current company, hub, placement, phase and assignment context.

## Rider web work

- Audit `resources/js/Layouts/CourierLayout.tsx`,
  `resources/js/Components/CourierSidebar.tsx`,
  `resources/js/Pages/Courier` and the active delivery controllers.
- Candidate preparation includes permitted read-only completed-trip navigation
  and section code. Keep current work queues, duty, claims, assignment and
  operational trip detail fresh before showing newly requested protected data
  or permitting an action. A claim is never triggered by hover or idle.
- Keep Messages history/read acknowledgement tied to actual visible contact
  selection and document visibility. Never run it as speculative page data.
- Clear contextual caches when assignment, company, hub, phase, duty or account
  changes. Keep existing refresh feedback and avoid duplicate polling.
- Preserve the rider canvas, 8px corners, 1280px navigation breakpoint, shared
  media query, drawer/sidebar transitions, mobile clearances and independent
  message scrolling. Faster requests do not authorize a portal redesign.

## Logistics work

Inspect current handlers in `app/Http/Controllers/Logistics`, particularly
`LogisticsHubWorkstationController` and `LogisticsManifestController`, and their
actual pages. Cover both company administrators and hub handlers under their
existing authority; they are not interchangeable roles.

- Apply bounded intent preparation only to audited reference/history lists.
  Include the active company/hub and exact list filters in the request context.
- Optimize the fresh read and payload for current parcel registry, receiving,
  sorting, dispatch, counter and manifest work where caching would mislead.
- Keep scan inspection and confirmation separate. Do not issue scans, preview
  secret counter codes, reserve a rider or create/dispatch/receive a manifest
  in advance. Prefetching cannot create evidence that a parcel was received.
- Hub switching invalidates the previous context before any new requests.
  Old in-flight responses cannot repopulate the new hub's view. A local filter
  must not show one facility's counts alongside another facility's records.
- Handover, correction, counter collection, delivery recovery and COD actions
  use current server authority and retained request identities as before.

## Acceptance and stopping point

| Area | Required evidence |
|---|---|
| Rider placement | Unassigned/wrong-company/wrong-hub and changed-phase sessions reveal no newly prefetched protected work; current claim/duty behavior is unchanged |
| Hub isolation | Switching hubs or companies cancels/invalidates old contextual requests; stale responses cannot change the new registry/counts or authorize a scan |
| Operational race | A parcel claimed, scanned or moved by another actor requires a current rejection/refresh path; a prepared page cannot bypass the existing guard |
| Activity | Background work creates no message acknowledgement, notification read, scan, custody event, manifest, collection or cash mutation |
| Request budget | Navigation priority, hidden-tab/offline/Save-Data behavior and existing polling are coherent; independent panes and manual Refresh keep working |
| Interface | Rider keyboard/touch/focus, mobile navigation and visible feedback remain consistent with the existing layout; hub controls retain truthful pending/result feedback |

Use affected isolated PHP tests, frontend behavior tests and a production build.
Do not claim Flutter/device, simultaneous PostgreSQL or Azure acceptance from
these checks. Record role-specific results and limitations, then stop.
