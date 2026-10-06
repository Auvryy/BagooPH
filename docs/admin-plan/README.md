# Admin Branch Implementation Plan

This folder divides admin governance into finite implementation tasks. Read [the documentation map](../README.md), [the admin contract](../ADMIN_FLOW.md), and [the validation contract](../CORE_FLOW_VALIDATION_AND_EDGE_CASES.md) before selecting a task.

These documents are execution plans. They do not declare features implemented, create Git branches, or authorize all branches to run automatically. The normative role and lifecycle documents remain authoritative. Current implementation findings, test baselines, ratings, and completion evidence belong only in [CORE_FLOW_ROADMAP.md](../CORE_FLOW_ROADMAP.md).

## Branch count and the checkpoint before starting

Plan for **18 bounded admin tasks on 14 admin-focused branches**, following the existing `admin/governance-improvements` foundation branch. B06/B07 share `admin/governance-restrictions`, B08/B09 share `admin/identity-and-closure-safety`, and B10-B12 share `admin/moderation-audit-and-overview`, giving **15 Git delivery branches including the foundation**. Each task retains its own acceptance checks. B01-B13 cover governance and the Phase 0 acceptance gate. B14-B18 cover later operations, notifications, COD, settlement, and financial oversight.

This is an initial branch allocation, not a count of every remaining branch in the whole project. Role-owned manifest, rider scan, retry, return, and self-pickup work has its own roadmap prerequisites. A later admin branch cannot substitute for those workflows. If evidence requires splitting a branch, update this index and the roadmap explicitly before adding another task.

The [B13 follow-up ownership plan](13-phase0-acceptance.md#follow-up-ownership-for-an-incomplete-decision) adds two supplemental Phase 0 deliveries: `fix/phase0-commerce-inputs-and-replay` and `test/phase0-cross-role-fixtures`. The numbered allocation stays at 18 tasks on 14 core admin branches; including these two follow-ups and the foundation gives 17 planned delivery branches. Role-owned later prerequisites are still additional work. Each follow-up requires separate selection and user review; B13's audit prompt does not start it or clear an incomplete gate.

First review and publish the foundation branch, including this plan. After that branch is merged, create B01 from updated `main`. Use [WORKFLOW.md](WORKFLOW.md) for the exact start, verification, review, and stopping process. The user publishes commits; agents never run `git push`.

## Delivery window

The project window starts **October 4, 2026** and ends **November 20, 2026**, following [the main delivery target](../README.md#project-delivery-target). Every planned task has the same October 4 start date and stretches to its own deadline, at or before November 20. This is the planning window for each task. Actual implementation still waits for its prerequisites and the previous branch's review; the common start date does not mean all work begins at once.

| Planning checkpoint | Intended work and review window |
|---|---|
| B01-B13 governance and Phase 0 acceptance | October 4-30 |
| Remaining role-owned manifests, recovery, self-pickup, and notification gates | November 2-6, after the applicable preceding gate passes |
| B14-B18 exception, notification, COD, settlement, and financial oversight | November 9-17 |
| Final regression checks, fixes, and demonstration preparation | November 18-20 |

These are calendar targets, not reduced effort estimates or a readiness claim. Reassess capacity against actual remaining work; identify additional help or a scope decision when needed. Preserve serial branch review and required phase gates. A missing prerequisite keeps dependent work blocked, even near the deadline. Do not automatically move a task beyond November 20 or suppress checks to meet the date.

## Ordered branch list

Work serially by default. The dependency column records the minimum prerequisites; the recommended execution order is the numbered order. Merge a finished branch before creating the next branch from `main`.

The authorized B06+B07 batch is one bounded exception to the task-to-branch mapping. Verify B06's account-decision and continuity checks before implementing B07 on the same branch. Keep separate task records, focused acceptance checks, and logical commits; then run the combined regression checks and frontend build. B08 waits for review and merge of the complete batch. The separately selected B08+B09 batch shares `admin/identity-and-closure-safety`, starting from merged B06+B07. Verify B08 before implementing B09; preserve separate task records, acceptance cases and logical commits, then run the combined regression and build. B10 waits for user review and merge of this batch. No phase gate is waived.

The selected B10-B12 batch uses `admin/moderation-audit-and-overview` from merged B08/B09. Verify B10's moderation and commerce gate before B11, then B11's context/privacy gate before B12. Preserve separate task records and logical commits, followed by combined regression and build checks. Stop after B12; user review and merge precede the separate B13 acceptance gate.

| ID | Git branch | Focus and plan | Minimum dependency | Delivery phase |
|---|---|---|---|---|
| B01 | `admin/seller-category-approval` | [14 master categories, registration, correction, and original-shop KYC](01-seller-category-approval.md) | Foundation merged | 0 |
| B02 | `admin/application-validation` | [Shared canonical application fields](02-application-validation.md) | B01 | 0 |
| B03 | `admin/shop-approval-eligibility` | [Separate shop review and eligible shop context](03-shop-approval-eligibility.md) | B01, B02 | 0 |
| B04 | `admin/logistics-resource-eligibility` | [Company, facility, handler, rider, and fleet scope](04-logistics-resource-eligibility.md) | B02 | 0 |
| B05 | `admin/buyer-access-alignment` | [Buyer holding, approval, and existing-order exceptions](05-buyer-access-alignment.md) | B02 | 0 |
| B06 | `admin/governance-restrictions` | [Reasoned account suspension/reactivation and last-admin protection](06-account-restrictions.md) | B03, B04, B05 | 0 |
| B07 | `admin/governance-restrictions` | [Independent shop/company/facility restrictions](07-resource-restrictions.md) | B03, B04, B06 verified within the batch | 0 |
| B08 | `admin/identity-and-closure-safety` | [Controlled identity corrections and legacy review](08-reviewed-identity-corrections.md) | B01, B02, B06, B07 | 0 |
| B09 | `admin/identity-and-closure-safety` | [Active-work and evidence safeguards for closure](09-account-closure-safety.md) | B06, B07, B08 verified within the batch | 0 |
| B10 | `admin/moderation-audit-and-overview` | [Reasoned product compliance decisions](10-product-moderation.md) | B02, B03, B06, B07 | 0 |
| B11 | `admin/moderation-audit-and-overview` | [Account context and searchable immutable decision history](11-governance-audit-viewer.md) | B03, B06-B09, B10 verified within the batch | 0 |
| B12 | `admin/moderation-audit-and-overview` | [Real operational counts, truthful availability, PHP display](12-truthful-overview.md) | B04, B11 verified within the batch | 0 |
| B13 | `test/admin-phase0-acceptance` | [Cross-role acceptance and existing-failure triage](13-phase0-acceptance.md) | B01-B12 | 0 gate |
| B14 | `admin/exception-oversight` | [Restricted-work and delivery-exception oversight](14-exception-oversight.md) | B13; role-owned Phases 2 and 3 gates | 3 |
| B15 | `admin/governance-notifications` | [Persistent in-app governance notices](15-governance-notifications.md) | B13, B14; Phase 4 notification foundation | 4 |
| B16 | `admin/cod-reconciliation` | [Recorded COD custody and platform reconciliation](16-cod-reconciliation.md) | B13-B15; Phases 2-4 gates | 5 |
| B17 | `admin/seller-settlement` | [Buyer-completed, reconciled seller proceeds](17-seller-settlement.md) | B16 | 5 |
| B18 | `admin/financial-oversight` | [Read-only finance evidence and recorded totals](18-financial-oversight.md) | B16, B17 | 5 |

## Why these functions belong in the admin account

An admin needs to know who is allowed to act, why a restriction exists, which work it affects, what evidence was reviewed, and who made each decision. Those needs justify category/shop review, scope enforcement, reasoned restrictions, safe closure, controlled corrections, product moderation, audit search, and honest overview data.

Later operational and financial views need real custody, notification, cash, and settlement records first. The plan places those views after their source workflows instead of filling them with sample records. [CAPABILITIES.md](CAPABILITIES.md) maps the functions to every account role and distinguishes core work from optional additions.

## Common rules for every branch

- Keep buyer, seller, courier, logistics, and admin roles fixed at account creation. No role conversion, impersonation, or public admin registration.
- Keep business rules in backend services/models. A hidden control is insufficient authorization.
- Check active account, required approval, resource ownership, parent eligibility, and permitted source state on every relevant read and mutation, including root and subdomain URLs.
- Approval, activity restrictions, shop/company review, placement, and courier duty remain separate. An approval or reactivation cannot silently clear another restriction.
- Decisions retain actor, subject, reason where required, server time, evidence/version, and before/after values. Lock affected records consistently; apply the decision and audit together; preserve the original result on an identical retry.
- Preserve orders, immutable snapshots, parcel custody, proofs, cash, settlement, and previous decisions. Admin oversight never replaces the actor-owned custody scan or buyer receipt confirmation.
- Preserve the 14 master categories, verified seed accounts, product references, 90%/10% product split, and separate shipping/handling accounting.
- Use existing Bagoo components, Plus Jakarta Sans, and PHP/₱. Keep rider styling outside admin scope.
- Tests use isolated SQLite `:memory:`. No test resets or destructive commands against PostgreSQL development/production data.
- Current legacy data needs explicit review. Do not invent birth dates, approval history, categories, proof, cash, notifications, or settled amounts.
- New routes, tables, fields, and services must be justified from inspected code and the branch contract. File lists below are inspection targets, not a claim that new schema already exists.

## How a branch ends

A branch ends when its own acceptance cases and required checks pass, its scoped changes are committed, the roadmap records evidence and remaining gaps, and the agent reports the result. The agent then stops. Starting the next branch requires a separate selected task.

If a prerequisite is missing, report the specific missing gate and stop that branch's dependent work. Do not implement a fake success path or expand into another phase to keep the branch moving.

Only the user publishes or merges. Publish-ready, merged, Phase 0-complete, and whole-project-complete are different claims. Each needs its own evidence under [WORKFLOW.md](WORKFLOW.md).

## Copyable bounded task prompt

```text
Work only on B01 in docs/admin-plan/01-seller-category-approval.md.
Follow AGENTS.md, docs/README.md, and docs/admin-plan/WORKFLOW.md.
Verify its dependencies and inspect the named code before editing.
Complete its backend rules, affected UI, meaningful acceptance tests,
and required verification. Preserve unrelated changes and existing data.
Make small logical local commits and record scoped evidence/ratings in
docs/CORE_FLOW_ROADMAP.md. Report every commit and any remaining gap.
Stop after this branch's verification and report; do not start B02.
Do not push, merge, or launch a broad automatic admin implementation goal.
```

Replace the ID and document path together when selecting another branch. A request to read or edit this plan is a documentation task, not a request to implement all 18 tasks.
