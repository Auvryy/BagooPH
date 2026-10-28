# Local pull request draft

Status: local acceptance complete; not published or saved on GitHub.

## Title

Guide logistics handovers with visual choices and a consistent workspace

## Description

Hub staff previously had to interpret long identifiers and separate screens to
find the current custodian, next action and responsible person. The logistics
pages now share grouped navigation, facility context, readable headings and
states, saved hub maps, searchable hub choices, vehicle/driver and eligible-rider
cards, scan-first controls and transfer progress.

Phone navigation contains keyboard focus. Counter verification has linked labels,
44px controls and a reachable footer. Buyer returns expose prepared/arrival
transfers and explain original-route staging and seller receipt. Recovery displays
the actual observation hub separately from the intended destination. Supervised
local handovers accept only their frozen one-hub checkout route and reviewed
quote, preserving independent receipt, original evidence and legacy/regional guards.

Validation:

- Actual Puppeteer controls completed four purchases covering regional delivery,
  timed local retry, counter collection and wrong-hub recovery, with original COD
  and named counted hub receipts. One buyer return reached seller receipt; eight
  manifests closed and four Mother Bags were received.
- All 22 main/create pages passed phone/tablet width/heading checks, with real
  drawer keyboard use, mouse map-pin selection, staff activation, account saving,
  resource decisions/history and populated exception/detail controls.
- 107 affected frontend checks and the TypeScript/Vite production build passed.
  Latest isolated PHP groups passed 69/5,982 and 51/6,746; return custody passed
  16/1,642. These selections overlap and are not additive full-suite totals.
- PHP formatting and Git whitespace checks passed. The final browser is parked
  with zero tabs and no captured unexpected JavaScript/server errors.

The [review matrix](LOGISTICS_VISUAL_WORKSPACE_REVIEW_2026-10-28.md) records exact
scope, prior automation interruptions and verification limits. Azure deployment,
Flutter, PostgreSQL concurrency/load, physical devices/cash and real external
transfers are separate acceptance. No new external service or migration is required
by this branch. Only the user publishes.
