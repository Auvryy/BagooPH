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

Server responses add only scoped display data and reuse the existing parcel
guidance service. Original routes, scan authority, status transitions, money
evidence and separate return barcodes remain enforced by the existing services.

Validation so far: isolated PHP selections passed (22/574, 78/10,651 and 5/627;
overlapping cases); 77 affected frontend checks passed before final refinements
and the latest focused 30 passed. A fresh seeded regional COD order completed
through both bag/manifest legs, POD, buyer tracking and counted hub cash receipt
using actual Puppeteer controls. Nineteen main destinations opened without a
desktop layout overflow. The final TypeScript/Vite production build passed with
type checking retained and bounded CPU/memory. The [review matrix](LOGISTICS_VISUAL_WORKSPACE_REVIEW_2026-10-28.md)
states the exact evidence and limits.

Still required before a ready-for-review handoff: all-page phone/keyboard/detail
checks, populated retry/recovery/counter/return
controls and review of the last visual refinements. Browser testing is currently
stopped to reduce load on the user's PC. Azure, Flutter, real external transfers
and PostgreSQL concurrency are not claimed.
