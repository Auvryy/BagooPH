# Backend QA audit — November 3, 2026

Status: the complete backend sweep is still running. Focused results below are final; the sweep summary will be updated after its process finishes.

## Scope and environment

Reviewed the current web/backend worktree beginning at `9e5c73b`, using PHP 8.4.24 and PHPUnit 12.5.33 in the existing application container. Backend tests force SQLite `:memory:` in both `phpunit.xml` and `Tests\TestCase`. No development database reset, migration, seed, payment, deployment or load test was performed. The selected version-two logistics contract governs current orders; version-one fixtures retain their original completion and transport behavior.

The full existing regression suite includes synthetic buyer fixtures where shared identity and cross-role business rules require them. This does not constitute buyer-mobile application acceptance. Buyer-specific application code and the buyer-mobile repository were left unchanged.

## Confirmed defects and repairs

| Finding | Before | After and evidence |
|---|---|---|
| Company staff sign-in email collides with an existing recovery email in the same role | The user-table-only availability check passed, then the email registry rejected insertion with HTTP 500. | The setup transaction checks the shared role-scoped email registry and returns HTTP 422 with an `email` field error. No user or provision is created. A courier may still use the same address owned by a separate logistics identity. |
| Both temporary-password writers bypass the shared password restrictions | A space satisfied the symbol requirement; newline and invisible-control characters were accepted at creation. | Creation and replacement require actual punctuation/symbols and reject Unicode controls. Invalid replacement preserves the original password and creates no reset receipt. The existing twelve-character temporary-password minimum is retained. |
| Historical email migration fixtures rolled an old migration down after later migrations | Two test errors occurred before upgrade assertions could execute. | Fixtures build the actual pre-upgrade schema, with explicit SQLite-memory guards, then check preserved identity/indexes and ambiguous-owner rejection. The role-scoped migration tests continue to pass. |
| Two admin exception tests assumed historical behavior for new orders | Counter release expected `delivered` rather than automatic `completed`; receipt attempted to bypass a sealed Mother Bag. | Both tests now execute against versions one and two. Version two proves sealed receiving is blocked until actual bag opening. Original cash-holder, privacy, receipt and source-owned-resolution checks remain. |

The four new staff/password repro cases failed before the production repair: one HTTP 500 and three accepted invalid passwords. These are distinct from the existing fixture failures above.

## Executed checks

| Check | Result | Meaning |
|---|---|---|
| Full existing PHPUnit suite | Running; 5,372 original cases | Final counts and unresolved failures pending. |
| `NewEraCompanySetupTest` | Pass: 11 tests, 153 assertions | Valid provisioning, owner/company scope, role-specific identity ownership, safe rejection, activation, temporary credentials, hub coordinates and tariff versions. |
| Historical and role-scoped email migration suites | Pass: 4 tests, 26 assertions | Real populated upgrades preserve identity/guards; conflicting legacy owners and company names block safely. |
| `ExceptionOversightTest` | Pass: 16 tests, 1,402 assertions | Historical/current counter and transport behavior; scoped review, retained source evidence, retry, immutability and rollback. Separate fake-storage token prevents interference with the sweep. |
| Opt-in `NavigationBenchmarkTest` | Pass: 1 test, 209 assertions | Eight representative HTTP-kernel cases, one excluded warmup and ten measured reads each, with isolated fixtures. |
| Focused PHP formatting and `git diff --check` | Pass after formatting | Changed PHP files and whitespace only; this is not runtime acceptance. |
| `composer audit --locked --format=json` | Fails: one medium advisory | Runtime dependency finding described below. No dependency versions were changed during the audit. |

Raw local sweep/repro/JUnit and benchmark artifacts are kept under ignored `storage/app/qa-2026-11-03/`. They contain synthetic test data and are not committed. Other agents' existing audit files are not part of these changes.

## Backend navigation measurements

These results measure Laravel's in-process HTTP kernel with SQLite and synthetic fixtures while other local QA work was running. They exclude browser rendering, DNS, network, deployed PostgreSQL and native devices, and establish no concurrent-user capacity.

| Case | Median milliseconds | Slowest of ten milliseconds | Queries per read | Largest response bytes |
|---|---:|---:|---:|---:|
| Seller products | 109.58 | 143.779 | 40 | 25,310 |
| Rider deliveries | 145.07 | 239.414 | 46 | 3,156 |
| Logistics deliveries | 211.16 | 317.682 | 56 | 8,973 |
| Admin users | 66.58 | 81.476 | 40 | 5,480 |
| Admin users partial context | 58.78 | 87.764 | 19 | 3,018 |
| Admin products | 126.02 | 191.173 | 57 | 10,472 |

The benchmark also executes shared catalogue/profile fixtures as regression checks; those are outside this audit's buyer-mobile acceptance scope. The source limits and assertion evidence are in `tests/Performance/NavigationBenchmarkTest.php`.

## Dependency security finding

The locked runtime dependency `symfony/mime` is `v8.1.7`. Composer reports medium advisory `CVE-2026-45070` / `GHSA-vqc8-7275-q272`, involving untrusted MIME parameter names. The [official Symfony advisory](https://symfony.com/cve-2026-45070) lists `8.1.10` as the corrected release on the 8.1 branch and explains that earlier announced fixed versions did not contain the patch.

A source search found no application use of `ParameterizedHeader`, `addParameterizedHeader`, `setParameter`, or Symfony mail-message customization accepting parameter names. No exploitable Bagoo request path was established. This bounds the finding; it does not erase the dependency advisory. Resolve it in a separate controlled lockfile update and rerun mail, OTP, reset and provisioning checks before deployment.

## Limits and prioritized next work

1. Resolve the locked MIME advisory before the next deployment. Acceptance is a clean locked dependency audit plus passing affected authentication/mail tests; do not treat a dependency version change alone as proof.
2. Keep the staff-creation field feedback and strict temporary-password handling in the operational acceptance path. Verify the actual rendered logistics form alongside these HTTP tests; the browser audit is recorded separately.
3. Complete the version-two native contract/deployment acceptance when the backend rollout is ready. Passing isolated kernel tests does not prove the currently deployed account service has matching routes, schema or feature capabilities.
4. Keep real PostgreSQL contention evidence separate from SQLite retry/rollback tests and source review. `RiderCommandService` uses transactional actor/key uniqueness and result replay; pickup claims lock the order, parcel and network before rereading assignment and eligibility. These source safeguards do not prove two-process PostgreSQL behavior. The repository's disposable race harness requires its documented explicit PostgreSQL exception and was not run in this SQLite-only audit.
5. Do not invent missing backend features from unavailable phone or Azure evidence. Physical permissions, scanning, background lifecycle, notifications, battery/thermal behavior, real mail delivery and production capacity remain outside this backend check.

Scoped engineering assessment: company work-account rejection/recovery improves from 5/10 to 8/10 because known invalid credentials and conflicting identities now return usable field errors while preserving data. This rates only the changed provisioning path and its isolated HTTP evidence. It is not an overall project readiness score.

## Local commits

- `9ffda57` — `fix(logistics): validate work account identities and temporary passwords`
- `c0f1f38` — `test(accounts): verify email upgrades from their historical schema`
- `f7018be` — `test(admin): cover exception oversight across logistics versions`

Only local commits were created. Nothing was pushed or deployed.
