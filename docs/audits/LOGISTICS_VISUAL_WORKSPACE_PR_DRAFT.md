# Local pull request draft

Status: not published; remaining browser acceptance is unfinished.

## Title

Make logistics workspaces consistent and guide the next handover

## Description

Hub staff previously had to read long hub/vehicle identifiers and interpret
separate screens to work out the next action. The logistics workspace now uses
task-based navigation, actual hub maps, searchable hub choices, vehicle/driver
and eligible-rider cards, clear custody/next-person guidance and progress through
packing, departure and individual receipt. Empty/review/account pages share the
same visual hierarchy and the guide links to existing operational workspaces.

Phone navigation now contains keyboard focus and keeps the next action reachable.
Scanning precedes statistics; earnings keep amounts visible without a wide phone
table; advanced history filters stay available without overwhelming the list.
Buyer returns name the next hub/handler, expose an already-prepared original
transfer, link to its correct portal path and explain staging before seller
receipt. Server responses add scoped display data; original route, scan,
authorization, status and financial checks continue to govern every command.

Validation so far: isolated PHP selections passed (22/574, 78/10,651 and 5/627;
overlapping cases), with the latest return-custody selection passing 16 tests /
1,642 assertions. The latest seven-file frontend selection passed 107 cases.
A fresh seeded regional COD order completed
through both bag/manifest legs, POD, buyer tracking and counted hub cash receipt
using actual Puppeteer controls. The buyer return was approved and physically
intaken with its separate code, weight, condition and synthetic photo while the
original purchase stayed completed. Nineteen desktop destinations and all 22
main/create destinations at phone/tablet widths opened without document overflow;
the final 44-check responsive pass and real drawer keyboard checks passed.
The latest TypeScript/Vite build passed with type checking retained and bounded
CPU/memory; both newly changed PHP files passed formatting.
The [review matrix](LOGISTICS_VISUAL_WORKSPACE_REVIEW_2026-10-28.md)
states the exact evidence and limits.

Still required before a ready-for-review handoff: populated retry/recovery/counter
and reverse-return controls, remaining resource/account/activation details and a
browser review of the newest return guidance. Browser testing is currently
stopped to reduce load on the user's PC. Azure, Flutter, real external transfers
and PostgreSQL concurrency are not claimed.
