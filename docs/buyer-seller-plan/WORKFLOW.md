# Buyer and Seller Delivery Workflow

## Start and scope

Read the local Task Management guide and the selected plan. Inspect current
code, schema, tests, task records and worktree before changing anything. Reuse
matching tasks and preserve other sessions' work and timers. A plan or pending
task is not proof that an existing feature is missing.

Start from reviewed, updated `main` in a clean worktree. Use one branch per
selected batch by default; an explicitly selected adjacent batch may share a
branch with separate acceptance checks and logical commits. Never push. Stop
after the selected scope's verification and handoff.

## Implement

- Business rules belong in shared services/models; controllers coordinate.
- Bind ownership, eligibility, source state and quantities to server records.
- Use additive schema changes, retain history and preserve ambiguous legacy
  records without manufacturing purchase or custody evidence.
- Buyer features use buyer layouts and interaction patterns; seller features
  use seller layouts and interaction patterns. Reuse local components, spacing,
  typography, surfaces, buttons, dialogs and feedback. Keep styling changes
  local to the feature and its portal.
- Wait for saved server values before displaying mutation success. Preserve
  input after rejection and prevent duplicate clicks while pending.
- Preserve root-path and role-subdomain routes and existing restricted-account
  exceptions. New ordinary features cannot grant restricted portal access.

## Verify and hand off

Run focused behavioral tests for authorization, source state, duplicates,
rollback and legacy compatibility. PHP tests use isolated SQLite `:memory:`
under `phpunit.xml`; never reset development or production PostgreSQL. SQLite
does not establish simultaneous PostgreSQL locking behavior.

For frontend changes run `npm run build` and relevant existing Node-based
frontend checks, adding meaningful behavioral checks where needed. Use
`./bagoo.sh` or Docker when host dependencies or permissions require it. Review
responsive layouts and accessibility statically; browser/device checks require
the user's explicit request and must not be invented.

Broaden regression checks when shared authorization, lifecycle or schema risk
warrants it. Record actual outcomes, remaining limits, scoped before/after
ratings and every local commit in `docs/CORE_FLOW_ROADMAP.md`. Update task
acceptance only from evidence, stop this session's measured timer and verify
saved task fields. Report commits, checks and blockers concisely. Publication,
merge, migration, deployment and rendered-device acceptance are separate facts.
