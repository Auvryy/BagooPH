# Voucher rollout and retained records

Deploy this batch together with its V02 funding prerequisite. Campaign writers,
quotes, placement and receipt readers must all understand immutable revisions
and separate promotional expenses before a new offer is published. Follow the
existing `bagoo.sh` deployment path after the user has published and merged the
branch; these instructions do not authorize publishing or deployment.

From the target repository after pulling the merged code:

```bash
./bagoo.sh deploy
./bagoo.sh verify
```

`deploy` builds assets, applies pending migrations with production confirmation
bypassed, and refreshes Laravel caches. `verify` checks the asset manifest and
pending migration status. It does not exercise checkout, prove PostgreSQL
concurrency, rerun seeders or establish live product eligibility.

The V02 funding and V03 campaign migrations were rehearsed on isolated SQLite
fixtures. Operational local additive migrations retained existing accounts,
products and orders. Old totals, settlements and uncertain campaign funding
remain retained; a missing shop cannot turn a shop offer into a platform offer.
Do not run `fresh`, drop tables, change existing terms or roll migrations back
to diagnose an offer. Immutable history may correctly refuse a rollback.

## Demo offers

`DatabaseSeeder` calls `VoucherDemoSeeder` after approved seller demo setup.
The focused seeder creates one shop product offer and Bagoo product and capped
shipping offers through the real campaign writer. Each has a dated end, finite
budget, global quota and per-buyer limit. A repeated run skips existing codes;
it does not revive a paused/ended offer, rewrite its terms, replenish its uses,
or create fictional saves, redemptions or expenses.

For an explicitly chosen demo environment that already has the verified demo
accounts and eligible shop, the focused additive command is:

```bash
./bagoo.sh artisan db:seed --class=VoucherDemoSeeder --force
```

This command does not repair old shop/category approval or hidden product
records. Inspect their actual eligibility separately. A demo reset deletes
existing accounts, products, orders and financial history; it is a separate
user decision, never a rollout prerequisite.

## Stop new operations without deleting history

For a single unsafe offer, pause a Bagoo campaign or use the reasoned admin
suspension for a shop campaign. Existing receipts and identical checkout-token
replays remain readable; a pause does not return an already consumed use or
reverse a settlement. Clearing a shop suspension leaves the offer paused until
its seller deliberately resumes it.

If the whole monetary path needs correction, use Laravel maintenance mode to
stop new publication and checkout together while retaining the database:

```bash
./bagoo.sh artisan down --retry=60
```

Deploy the correction, run `verify`, and check an owned buyer quote, the matching
order/receipt, a seller-owned campaign and the authorized admin view before
reopening:

```bash
./bagoo.sh artisan up
```

Local tests establish the bounded rules, rollback and retained-record cases.
Interactive device checks and PostgreSQL race checks are separate verification;
they have not been performed for this batch. No Azure deployment is implied.
