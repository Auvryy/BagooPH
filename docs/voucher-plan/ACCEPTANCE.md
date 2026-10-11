# Voucher Acceptance and Handoff

Each batch needs its own relevant checks before its successor starts. This
final gate ties the same campaign and checkout together across roles; it
does not replace focused tests or authorize deployment.

## Required scenarios

| Scenario | Observable result |
|---|---|
| Buyer discovers/saves an offer | Real terms and owned save; saving consumes no quota or budget |
| Single-shop choice | Picker, preview, created order and receipt agree exactly |
| Multi-shop choice | Own-shop discounts stay isolated; platform allocations sum exactly once |
| Shipping cap | Only real eligible fees decrease; no discount reaches a zero-fee parcel's merchandise |
| Threshold and percentage | Minimum qualification and capped calculation follow the stated bases |
| Manual override | No voucher and manual choice survive refresh/requote until actually invalid |
| Seller campaign | Approved owner manages one shop; other owners/roles cannot read private data or write |
| Platform campaign | Authorized Platform Admin manages its recorded budget; other admin scopes cannot publish |
| Exhausted quota/budget | Final checkout rejects without stock/order/redemption/funding side effects |
| Same-token retry | Original order and allocations return after expiry/pause, without another use |
| Altered input retry | Changed selected campaigns or checkout content are rejected |
| Interrupted transaction | Stock, orders, redemptions, expenses and result links all roll back |
| Partial cancellation | No sibling total changes, no returned use, only verified unspent funding is released once |
| Delivered/returned parcel | No automatic completion, refund or funding reversal |
| Settlement | Buyer-confirmed, reconciled COD gates stay intact; original gross split and issuer expenses reconcile |
| Legacy migration | Past totals/settlements survive; missing shop never creates a platform issuer |

Compare proposed best-combination output with exhaustive bounded fixtures,
including ties, caps and differing shipping fees. Include zero/one-cent
remainders, expired timestamps, deleted/archived issuer records, paused shops,
wrong selection ownership, forged prices and a buyer exceeding a per-user cap.

Use isolated SQLite :memory: for PHP tests and existing frontend tooling for
component behavior. Run TypeScript/Vite builds for frontend batches. Do not
wipe PostgreSQL, claim SQLite proves its concurrency semantics, open a visible
browser or take routine screenshots. Any database-specific concurrency setup
or interactive UI check needs its applicable separate authorization.

## Safe rollout

Use a migration rehearsal on isolated fixtures, not a fresh production
database. Retain the approved demo accounts and password-cast convention.
Inspect whether a focused demonstration seeder is needed; seed real bounded
campaigns only after their issuer, funding and eligibility rules exist.
Do not backfill fictional saves or buyer-level use history.

Publication enables new campaign revisions only when both monetary and
checkout paths are available. A paused campaign remains readable for old
orders and retries. Rehearse the existing deployment path and migrations;
describe how to stop new publication/redemption if validation fails without
rolling back or deleting already recorded order/financial history.

## Evidence required before completion

Record actual checks, known failures, migration boundaries, scoped before/after
assessments and every local commit in the roadmap. Distinguish implementation,
verification, local commit, user publication and live deployment. Suggested
quality targets are not achieved scores.

The completion report states which batch passed and which feature remains
deferred. The user alone pushes or merges. Do not mark a campaign project
complete merely because its UI renders or its scheduled deadline arrived.
