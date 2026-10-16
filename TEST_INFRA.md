# BagooPH Verification Guide

This guide describes the existing test structure and what each check can prove.
Current results, ratings and unresolved findings belong in
[CORE_FLOW_ROADMAP.md](docs/CORE_FLOW_ROADMAP.md). Technology versions are in
[TECH_STACK.md](docs/TECH_STACK.md); application boundaries are in
[ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Ordinary backend checks

[phpunit.xml](phpunit.xml) defines Unit and Feature suites and forces testing
settings, including SQLite `:memory:` and an empty `DB_URL`. HTTP tests execute
Laravel routes, middleware, services and model writes with controlled fixtures.
They are application integration tests, not a browser session.

Use a configuration-cache path separate from a cached application deployment.
The following command runs in a disposable app container and requires an
available built image and installed development dependencies:

```bash
docker compose run --rm --no-deps -T \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= \
  -e APP_CONFIG_CACHE=/tmp/bagoo-testing-config.php \
  app php artisan test
```

To select a relevant case, append an existing PHPUnit filter, for example
`--filter=PurchaseToSettlementAcceptanceTest`. `./bagoo.sh test` is also an
existing helper that supplies database overrides; its command does not by
itself isolate an already cached Laravel configuration. Never point tests at
the development or production PostgreSQL database, and never use `fresh` as a
test setup against application data.

A production `composer install --no-dev` intentionally omits PHPUnit and other
development tools. Run tests in a prepared development/test environment rather
than adding those tools to the live server for a demonstration.

## Existing test organisation

| Location | Purpose |
|---|---|
| `tests/Unit` | Focused rules, value handling and helper behaviour |
| `tests/Feature/Auth`, other role/domain directories | Authentication, forms, approval, ownership and service/HTTP contracts |
| `tests/Feature/E2E` | Existing tiered cross-role HTTP and boundary cases |
| `tests/Feature/E2E/Support` | Shared account/network fixtures and actual role-owned action helpers |
| `tests/Feature/Flows/PurchaseToSettlementAcceptanceTest.php` | Joined checkout, custody, buyer/cash ordering, offers and seller payment with rollback/retry |
| `tests/Feature/Flows/ExceptionJourneyAcceptanceTest.php` | Joined retry, return-to-seller and secure-counter financial gates |
| `tests/Frontend` | JavaScript/TypeScript helper, markup and component-harness checks |
| `tests/Deployment` | Deployment-command behaviour with controlled command stubs |

Tier names and filenames are organisation, not a permanent count or proof of
coverage. A passing selection must be reported with its real scope. Shared
fixtures may fake files, time, mail or external failures; the domain transition
being checked must still run through its real authority and service boundary.

## Frontend, build and formatting

With dependencies installed and a compatible local Node runtime:

```bash
node --test tests/Frontend/*.test.mjs tests/Deployment/*.test.mjs
```

The Node tests include controlled hooks, callbacks, timers and rendered markup.
They do not establish actual DOM focus, browser validity enforcement, camera
access or phone appearance. Browser UI testing follows the explicit permission
rule in [AGENTS.md](AGENTS.md).

The existing production build command is:

```bash
./bagoo.sh npm run build
```

It runs `tsc && vite build`. A successful build proves compilation and generated
assets, not successful sign-in, mail, scheduling, custody or payment on Azure.
PHP formatting can be checked with `vendor/bin/pint --test` against the affected
PHP paths in the prepared development container. `git diff --check` checks
whitespace errors in changes.

## Important acceptance properties

- Valid input succeeds; malformed, boundary, forbidden and stale input receives
  the documented response without unintended writes.
- Accounts, approvals, company/hub placement, ownership and source state are
  checked server-side on the actual root and configured portal entry points.
- Checkout rollback restores its writes; successful retries return the same
  original result without consuming stock or discounts twice.
- Custody scans, manifests, retries, return receipts and counter collection retain
  original evidence and reject unsupported shortcuts.
- Buyer confirmation is separate from delivery. COD collection, handovers,
  reconciliation and evidenced seller payment retain their own authority.
- The product split is 90% seller and 10% platform; shipping and promotion
  funding remain separate. There is no universal fixed rider fee or automatic
  settlement at delivery.
- A failed side effect cannot erase an already committed source. Durable
  notification intent has its own retry path.

Use [the core validation contract](docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md)
and [form review](docs/FORM_VALIDATION_REVIEW.md) for the affected acceptance
cases. Do not change an expected response merely to make a test green; prove
whether the executable behaviour or the expectation conflicts with authority.

## PostgreSQL, deployment and device limits

SQLite cannot establish PostgreSQL row-lock contention, all driver-specific
constraints or simultaneous-request behaviour. The repository has a
[disposable PostgreSQL race verifier](scripts/verify-rider-races/README.md)
for its bounded native scenarios. It requires the explicit SQLite-only rule
exception stated there. An old approved run does not authorize a new run or
using the development/production database.

Deployment verification, real mailbox delivery, an operating scheduler,
HTTPS/session behaviour and actual device use require their own evidence.
`./bagoo.sh verify` checks assets and pending migrations; it is not an end-to-end
release test. Keep blocked/failing runs and subsequent focused retests distinct
in the roadmap rather than claiming a fresh all-green full run.
