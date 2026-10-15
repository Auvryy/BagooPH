# Advertisement Delivery Workflow

Planning does not start implementation. Inspect the current branch, roadmap and
existing project tasks before selecting a batch. Preserve unrelated work and
the rider-mobile session. Follow repository task-management and Git rules;
details of local tooling do not belong in this plan.

## Branches and stopping points

| Proposed branch | Batches | Stop when |
|---|---|---|
| `feat/advertisement-management` | AD01 + AD02 | Admin-created, scheduled, claims-supported content reaches its matching buyer page, and each gate passes. |
| `feat/seller-promotion-review` | AD03 | An owned seller proposal can be reviewed/published without changing ordinary branding or identity rules. |
| `feat/advertisement-reporting` | AD04 | Actual bounded events feed authorized, honest summaries and combined release evidence. |

Use small logical commits for storage/permissions, admin authoring, buyer
destinations/display, seller review and measurement as selected. Each has a
plain-language body and actual verification. Prepare one final PR title/body
for each delivery branch. Do not begin a later branch automatically, publish
commits, add paid advertising or interpret a planned target date as completion.

## Proposed work windows

These are planning assumptions for one available web developer/agent, not booked
work, historical activity or saved task deadlines. Start from a clean checkout
containing the accepted banner grid, and review earlier batches before their
dependents start.
Shift the windows if other project commitments take priority; keep the overall
November 20, 2026 delivery boundary and time for the final demonstration.

| Date, Asia/Manila | Implementation/review window | Proposed purpose |
|---|---|---|
| October 16, 2026 | 09:00-11:00 and 13:00-15:00; checks/review 15:00-16:00 | AD01 platform drafts, assets, schedules and authority. |
| October 19, 2026 | 09:00-11:00 and 13:00-15:00; checks/review 15:00-16:00 | AD02 buyer reader, matching pages, expiry and combined first-branch handoff. |
| October 20, 2026 | 09:00-11:00 and 13:00-15:00; checks/review 15:00-16:00 | AD03 seller requests and review, only after accepted prerequisite work. |
| October 21, 2026 | 09:00-11:00 and 13:00-14:00; checks/review 14:00-15:00 | AD04 modest reporting and combined verification, if selected. |

The 19-hour allocation includes review/uncertainty room; it is an initial
estimate, not a guaranteed duration. A useful first release is AD01 + AD02.
If capacity is tight, defer seller submissions and metrics instead of weakening
authorization, claim accuracy, scheduling or checkout boundaries.

## Verification and handoff

Use each batch's focused checks before combining gates. Test database changes
only with SQLite memory unless the user separately authorizes a broader setup;
never wipe development/production PostgreSQL. Run the frontend build when UI
changes. Document old failures, browser/device gaps and production-engine limits.

Use additive releases, accurate demo content and a disable path. Do not make
up traffic, prices, voucher claims, completed reviews or task time. Record actual
implementation and readiness only in the roadmap. Task Done, local commits,
GitHub publication and deployment are separate events.
