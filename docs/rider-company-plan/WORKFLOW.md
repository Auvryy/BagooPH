# Rider Company Delivery Workflow

## Select and inspect

Read the machine-local task guide, [plan](README.md) and selected batch before
work. Inspect current code, schema, task records and worktree; reconcile later
roadmap evidence before treating an old task or score as an implementation gap.
Preserve other sessions' rider-mobile tasks and timers.

Start from reviewed, current `main` in a clean worktree. Select one RC batch by
default. An explicitly selected RC01+RC02 batch may share a delivery branch, but
RC01's acceptance must pass before RC02 begins and each concern keeps a logical
commit. A planning goal authorizes documentation, not all proposed implementation.

For selected work, reuse a matching project task or create a bounded one after
checking duplicates, dependencies and capacity. Schedule dated Asia/Manila
windows including review and verification within November 20, 2026. The estimates
in the plan are future effort, not a record of work already done.

## Implement and retain boundaries

Use shared domain services/models. Controllers coordinate; current server-side
ownership, eligibility, source state and consent determine permitted actions.
Never accept client company/hub/approval flags as authority. Changes to new-company
placement must cover direct services and both web portal contexts.

Inspect migrations and models first. Introduce additive request/offer/consent
records with consistent lock order, enforceable uniqueness and retained event
history. Do not overwrite original placement, identity, parcel or cash evidence
to make new onboarding appear complete. Existing ambiguous assignments receive
an honest review state; valid older assignments remain usable.

Use the courier Assignment section and company Network workspace with each
portal's own layout/style. Use existing controls and feedback. Preserve safe
input on rejection, wait for server-confirmed values and prevent repeat clicks.
Review typing/pasting, field limits, direct request bypass, saved values and
recovery using [the crucial form checks](../FORM_VALIDATION_REVIEW.md).

Keep authority distinct: Platform Admin identity review, company offer, rider
acceptance, managed placement, rider duty and actual parcel custody. Native
routes reuse the same business rules; the Flutter implementation is separate.

## Verify and stop

- Run focused PHP tests on SQLite `:memory:` for positive cases, permissions,
  duplicates, current source/expiry, record retention and rollback. Never use or
  reset development/production PostgreSQL for tests.
- Run the frontend build for UI changes and meaningful existing frontend checks.
  Broaden regression when shared eligibility, placement, notices or schema change.
- Validate executable native schemas and actual response examples for RC03.
- Review responsive/accessibility behavior statically. Do not open a visible
  browser, automate UI or take screenshots unless explicitly requested for that task.
- Keep deployment, simultaneous PostgreSQL and rendered Flutter/device checks
  distinct. Passing local checks is not evidence that they happened.

Record actual checks, limits, scoped implementation ratings and every new commit
in [the roadmap](../CORE_FLOW_ROADMAP.md). Documentation-only planning does not
raise runtime ratings or mark RC batches implemented.

Complete only evidenced task checklists, stop this session's measured time and
verify persisted fields after updates. Create small logical local commits with
clear subjects and descriptions. Prepare a separate combined PR title/body and
say whether it remains a local draft. Never push; user publication and merge
remain separate. Stop after the selected batch and its handoff.
