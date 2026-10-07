# One Branch at a Time

This workflow applies to the [18-task admin plan](README.md). The [repository instructions](../../AGENTS.md), [documentation map](../README.md), and [roadmap](../CORE_FLOW_ROADMAP.md) remain the sources for repository safety, document authority, and current evidence.

The selected B06+B07 batch uses one `admin/governance-restrictions` branch. Complete and verify B06's focused gate before B07 implementation, with separate task records and logical commits. Use a fresh pre-batch full-suite baseline and combined final regression/build evidence. Stop after both tasks; user review and merge still precede B08. The separately selected B08+B09 batch uses `admin/identity-and-closure-safety` after B06+B07 is merged. Verify B08 before implementing B09; use separate task records, focused gates and logical commits, followed by a combined full-suite comparison and build. Stop after B09 for user review and merge before B10. All other task boundaries and phase gates remain as documented.

The selected B10-B12 batch uses `admin/moderation-audit-and-overview` after B08/B09 is merged. Complete B10's focused moderation/commerce checks before B11 and B11's audit/privacy checks before B12. Keep individual task records and logical commits. Use a fresh pre-batch full-suite baseline, combined final comparison and production build; stop after B12 for review and merge before B13. This grouping does not waive the Phase 0 acceptance gate or authorize B14-B18.

The October 7 selection includes B14's required role-owned prerequisites in separate batches before exception oversight. Start the logistics sorting-input repair from reviewed, merged cross-role fixtures. Verify each batch before dependent implementation. Later local branches may be deliberately stacked on the preceding verified local commits; record their bases and dependency diffs for review. This selection permits prerequisite work without expanding the B14 admin branch into a catch-all or authorizing B15-B18. The user still publishes and merges each delivery; a local stack is not evidence of publication, merge or release.

## 1. Finish and publish the foundation checkpoint

Review the foundation diff against `main`, the recorded focused checks, the full-suite baseline, deployment requirements, and known gaps. Preserve the implemented role, KYC, and birth-date safeguards. Planning documents do not raise runtime readiness.

The user publishes `admin/governance-improvements` and opens its review. The review description must explain actual changes, verification, legacy compatibility, and remaining failures. It must not call the complete admin flow finished. Merge according to the project's review decision, then update local `main`.

Do not create all planned Git branches now. An unused branch list is not progress and can lose track of its base. Create the selected branch only when its prerequisites have been reviewed and merged.

## 2. Start one selected branch

1. Read that branch document, its normative contracts, and the latest roadmap evidence.
2. Inspect Git status, relevant code, migrations/models, routes/middleware, and existing tests. Distinguish user changes from your own.
3. Verify minimum dependencies and phase gates. If a required record/workflow is absent, report the exact blocker before dependent implementation.
4. Start from updated `main`. A clean working tree is required for the normal branch switch; do not automatically stash, discard, reset, or move unrelated changes.
5. Record the single branch objective and its acceptance cases. Do not activate an unbounded goal such as "finish every admin feature."
6. Keep the selected task inside the October 4-November 20, 2026 delivery window. Allow time for verification and review before the final target. Preserve honest effort estimates; a deadline change does not justify reporting less work, skipping a gate, or extending the window automatically.

Example commands for the selected B01 branch, after the foundation is merged and the worktree is clean:

```bash
git status --short --branch
git switch main
git pull --ff-only
git switch -c admin/seller-category-approval
```

These are instructions for a future task, not commands this planning task executes. Confirm the actual remote/upstream configuration before pulling. If `main` cannot fast-forward, inspect and resolve the cause; do not use a hard reset. If work must begin before the dependency is merged, document a deliberately stacked branch and review its dependency diff. The default is to wait for the merged foundation.

## 3. Implement the bounded feature

- Reproduce a meaningful behavior/security gap with a focused test when appropriate. Avoid tests that only mirror markup or implementation details.
- Reuse backend policies, validation rules, services, enums, components, and dependencies. Controllers coordinate; services/models decide.
- Add server validation and authorization before adding mutation controls. Bind decisions to current evidence and source state.
- Keep a limited migration plan: inspect existing schema, add only required persistence, preserve foreign references/history, and distinguish legacy records from newly reviewed records.
- For active work, identify the current order, assignment, parcel custodian, cash holder, and responsible recovery scope before changing eligibility. Do not use reassignment or a status flag as proof of handover.
- Do not silently repair ambiguous legacy data. Report review candidates with clear evidence requirements and the controlled correction owner.
- A schema mismatch or missing dependency does not authorize unrelated feature work. Update the branch's scope explicitly if splitting is necessary.

Each branch should have a short reviewer narrative: the concrete problem, resulting behavior, authority boundary, tests, data implications, and remaining limitations.

## 4. Verify the feature

### Backend and domain changes

Run the relevant PHP tests first. `phpunit.xml` forces the testing environment and SQLite `:memory:`. Explicit Docker overrides provide an additional visible safety boundary:

```bash
docker compose exec -T \
  -e APP_ENV=testing \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  -e DB_URL= \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e MAIL_MAILER=array \
  app php vendor/bin/phpunit tests/Feature/Admin \
  --do-not-cache-result --colors=never
```

Replace the test path with the actual branch-specific suites. This example is not a claim that the existing Admin suite covers every future feature. Include actor/source-state, ownership, stale session/evidence, identical retry, conflicting retry, rollback, and restriction preservation cases relevant to the change.

For shared authorization, schema, lifecycle, or accounting changes, run the broader affected suites and full isolated suite. Record a JUnit artifact and compare **exact failing test identities and failure/error types** with a fresh pre-change baseline. Counts alone can hide a new regression replacing an old failure.

Use `./bagoo.sh test` only after confirming the same isolated configuration. Never run a test reset, `migrate:fresh`, or destructive seeder against the development/production database. Do not treat local PostgreSQL as a test fixture. SQLite does not prove simultaneous PostgreSQL row locking; inspect lock ordering/constraints and report that verification limit. Do not invent concurrency evidence.

### Frontend changes

Run the TypeScript/Vite production build:

```bash
docker compose exec -T app npm run build
```

Use the repository's documented Docker equivalent if this command is unavailable. Preserve the split authentication layout, existing admin components, Plus Jakarta Sans, PHP/₱ formatting, clear control borders, and unrelated rider styling. Use automated checks and static inspection. Browser automation or screenshots require the user's explicit request for that task.

### Documentation-only changes

Check local links, code-reference existence, headings/fences, branch counts, dependency order, normative consistency, and whitespace. No PHP suite or frontend rebuild is required solely for Markdown edits. Report which checks were actually run.

## 5. Handle failures honestly

| Observation | Required response |
|---|---|
| A focused acceptance case fails | Fix the scoped behavior or test setup; do not claim the feature complete. |
| A new full-suite failure appears | Investigate before completion. A pre-existing failure count does not excuse a new failing identity. |
| An old fixture uses a forbidden direct override or skips custody | Align setup/expectation with the contract; do not restore the unsafe shortcut. |
| A genuine bug belongs to this branch | Fix and verify it here. |
| A genuine bug belongs to another branch/phase | Name its owner, affected invariant, failing identity, and gate consequence in the roadmap. |
| A required cross-role gate is red | Keep that gate incomplete. Publishing a scoped change does not waive it. |
| An external/dependency check cannot run | Record the exact blocker and limitation; do not report a pass. |

Never suppress, delete, or skip a failing acceptance test to manufacture readiness. Do not label a failure "just a fixture" without examining its setup, executable behavior, and intended contract. Overall completion requires the entire applicable acceptance gate, not only the easiest passing subset.

## 6. Commit, report, and stop

Make small logical local commits: backend/domain and meaningful tests, affected UI, then documentation/evidence where separation helps review. Stage explicit files and preserve unrelated changes. Before reporting, inspect the complete diff and `git diff --check`.

The final report states the result, scoped before/after assessment, actual verification, every commit's short hash and subject, unresolved gaps, and whether required gates passed. Update implementation evidence and ratings only in `CORE_FLOW_ROADMAP.md`; keep role contracts and branch plans free of changing completion lists.

Then stop the selected task. Do not begin the next numbered branch, add another feature because it looks easy, push, merge, or delete branches automatically. A finite branch goal is complete when its own requirements are verified; the wider admin plan remains a separate backlog.

## 7. User publication and next branch

Only the user publishes commits. A typical user publication command after reviewing the local work is:

```bash
git push --set-upstream origin admin/seller-category-approval
```

Agents must never execute that command. Review and merge the selected branch before the next normal task starts from updated `main`. Do not use force-push to resolve a scope or base mistake. Keep merge/conflict resolution explicit and verify that the merged dependency is actually included.

## 8. Phase and release gates

- **Branch completion:** all scoped requirements are implemented and verified; no hidden expansion.
- **Phase 0 completion:** every shared validation, approval, resource eligibility, restriction, deletion, and live-placeholder acceptance requirement passes. B13 records this audit.
- **Later admin branch eligibility:** the required role-owned custody/exception/notification/finance prerequisite has passed its own gate. An admin page does not satisfy that prerequisite.
- **Whole admin readiness:** all required admin functions and their cross-role acceptance paths have evidence; unavailable finance/recovery cannot count as complete.
- **Whole project readiness:** the complete canonical transaction, adversarial, recovery, notification, and accounting gates pass. Known failures or missing required behavior remain blockers to that claim.

## Review checklist

- [ ] The selected ID and Git branch match the index.
- [ ] Required dependencies are merged and applicable phase gates pass.
- [ ] Scope and exclusions stayed within the selected document.
- [ ] Backend eligibility, ownership, current state, and evidence are verified.
- [ ] Retry/conflict and partial-failure behavior preserve history and work.
- [ ] Tests used SQLite `:memory:` and the actual results are recorded.
- [ ] Required frontend build or documentation checks passed.
- [ ] Legacy data, migration, deployment, and unverified conditions are stated.
- [ ] Every local commit is reported; only the user publishes.
- [ ] The roadmap reflects evidence without inflating unrelated ratings.
- [ ] The agent stopped instead of starting the next branch.
