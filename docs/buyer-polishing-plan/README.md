# Buyer polishing plan

This plan turns the October 19 buyer acceptance review into bounded follow-up
work. The planning branch is `docs/buyer-polishing-plan`. Creating this branch
does not start application changes or create the proposed implementation branches.

Read the [documentation authority](../README.md),
[buyer contract](../BUYER_FLOWCHART.md),
[validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md), and
[form review](../FORM_VALIDATION_REVIEW.md) with the selected batch. The
[roadmap](../CORE_FLOW_ROADMAP.md) owns changing implementation evidence.

## Selected work

The active ledger contains **35 unresolved original cases and one additional
rating-control finding**. It covers three confirmed gaps, 23 blocked cases and
nine unconfirmed checks. The 57 likely passes are excluded from the active
backlog at the user's request. Their existing checks remain a regression
baseline; exclusion does not turn them into completed manual results.

| Allocation | Original cases | Additional findings | Treatment |
|---|---:|---:|---|
| BP01: search and notification controls | 2 | 1 | Repair the location filter, rating-control mismatch and missing bulk-read action. |
| BP02: retained rules and precise boundaries | 15 | 0 | Resolve 13 rule conflicts and execute two missing voucher boundary sequences. |
| BP03: remaining interaction checks | 6 | 0 | Check search safety, option changes, Bag persistence/removal and unavailable return controls. |
| BP04: deployed reminder observation | 1 | 0 | Establish actual scheduled notification delivery and recovery. |
| PAY: payment prerequisite | 7 | 0 | Keep the absent online-payment and prepaid-cancellation lifecycle explicit. |
| RET: buyer-return prerequisite | 4 | 0 | Keep the missing positive return flow and its dependent checks explicit. |
| **Total active review coverage** | **35** | **1** | Every item has an owner batch and a closure condition. |

The [case ledger](CASE_COVERAGE.md) is the complete active allocation. The
[retained expectations](RETAINED_EXPECTATIONS.md) record BP02's explicit rule
decisions and local boundary evidence. The
[payment and return gates](05-payment-and-return-prerequisites.md) retain all
11 prerequisite cases. Those cases are included in this plan even though their
larger features remain outside the four polishing deliveries.

On October 19, the user selected implementation of all four polishing batches
and explicitly included the full buyer-return prerequisites. The RET allocation
now includes eligibility/evidence, seller/admin decisions, reverse custody and a
verified COD repayment path, with separate implementation and acceptance work.
The [return delivery plan](../buyer-returns-plan/README.md) records the selected
seven-day window, including completed orders, and the R01–R03 prerequisites.
Checkout remains COD-only; online payments in PAY were not selected. The later
repurchase refinement also uses the existing Bag instead of a duplicate preview
page. Current outcomes and limits remain in the roadmap.

## Proposed deliveries

| Order | Proposed branch, not created by this plan | Plan | Entry and exit |
|---|---|---|---|
| BP01 | `fix/buyer-search-and-notification-polish` | [Controls](01-search-and-notifications.md) | Start from accepted current main. Close all three control findings with matching UI/server checks. |
| BP02 | `test/buyer-rules-and-boundaries` | [Rules and boundaries](02-rules-and-boundaries.md) | Keep existing product rules. Record aligned expectations and actual boundary outcomes. |
| BP03 | `test/buyer-interaction-acceptance` | [Interactions](03-interaction-checks.md) | Use BP01 fixes and BP02 expectations. Execute the six targeted checks and repair only confirmed selected-scope defects. |
| BP04 | `test/buyer-reminder-release-check` | [Reminder observation](04-reminder-and-release.md) | Requires published/deployed applicable code and an eligible future sale. Observe a genuine scheduled reminder. |

Keep BP01's search and notification changes in separate logical commits. A
later test batch needs an application change only when its selected checks
expose a real defect. Each branch receives its own handoff; none is automatically
pushed, merged or deployed. The user publishes commits.

## What this review teaches

- A large passing suite is evidence for its assertions, not a completed result
  for every visible interaction. The old suite rejected a value advertised by
  the interface; the corrected test must exercise the actual control request.
- A whole-number review score and an average-rating filter are different inputs.
  Fixing their mismatch must not loosen review creation or unrelated validation.
- A server-owned Bag establishes persistence logic; a second-session check
  establishes the stated multi-session experience. Option stock checks alone do
  not establish the visible image/disabled-control sequence.
- A registered scheduled command does not prove that a deployed worker runs or
  that a reminder arrived before its sale. Retained subscription/delivery records
  and an observed notification are needed.
- A policy disagreement needs a recorded expected-result decision. Removing a
  safety gate to imitate an alternate rule can introduce a financial defect.
- An unavailable return button can satisfy one negative check while the full
  return feature remains missing. Return-to-sender is a separate custody flow.

## Acceptance rules

Preserve COD, 10% merchandise commission, the seller's 90% share, separate
shipping/funding accounting, one approved-root shop, immutable role/sign-in
identity, explicit buyer receipt and the canonical hub route. Follow the user's
decision to retain Bagoo's rules; a blocked expectation cannot silently authorize
online payments, automatic completion or extra voucher slots. Full buyer returns
are now explicitly selected as separate work, including their financial and
custody prerequisites.

For each active case, record the starting fixture/state, complete observed
result, side effects, verification method and remaining limit. Keep original
expectations alongside any justified aligned expectation. A rule decision, code
review or equivalent automated check alone does not create a manual Pass.

Use isolated SQLite `:memory:` for application tests. Never reset the development
or production database to prepare these checks. Reuse current test concerns and
record known bounded fixture changes. Browser interaction steps are user-led
unless browser testing is explicitly requested for that selected task.

Use focused existing tests after related changes; expand only for shared-risk
areas. The 57 excluded likely passes do not each receive another manual work
item. New failures may reopen the affected case with evidence. For UI work, use
the local frontend design skill, preserve the buyer portal and run the production
build. Keep seller/other-role styles intact when shared components are affected.

## Proposed working windows

These are availability assumptions for future selection, not jobs, measured
activity, new deadlines for existing tasks or a promise before today's checking.
Each window includes verification and room for recovery. Replan if the reviewed
pickup-location data needs a schema change or a required person is unavailable.

| Date, Asia/Manila | Window | Proposed purpose |
|---|---|---|
| October 20 | 09:00 to 11:30; 12:30 to 15:30 | BP01 location/rating and notification controls, with isolated checks and build. |
| October 21 | 09:00 to 12:00 | BP02 expectation decisions and exact money/text boundaries. |
| October 22 | 09:00 to 11:30; 13:00 to 15:00 | BP03 targeted interactions and any selected, bounded repairs. |
| October 23 | 09:00 to 11:00 | BP04 user publication/deployment review and observed reminder. |

Online payments remain deferred. The selected buyer-return work has its own
explicit scope and proposed allocation in the return delivery plan. Reuse its
existing planning ownership rather than duplicating it. All proposed work remains
within the project's November 20 delivery target.

## Completion boundary

This documentation task is complete when all 35 active cases and the extra
finding have a verified allocation, dependency, acceptance condition and readable
handoff. Implementation readiness remains unchanged. The polishing series is
complete only after its 24 original cases and additional control finding have
their actual agreed outcomes recorded. The seven PAY and four RET cases remain
visible until separately resolved; they cannot disappear into a Done plan label.
