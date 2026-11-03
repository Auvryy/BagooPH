# Shared regression contract review — November 3, 2026

This bounded follow-up repairs four shared test files against current executable
rules and the [selected logistics version-two contract](../new-docs-era/README.md).
It changes no application behavior, buyer-specific tests, payment rules or
production records. The reviewed starting revision was `f7018be` on the existing
web QA branch. Concurrent application and frontend work is reported separately.

## Findings and repairs

| Area | Observed baseline failure | Resulting acceptance |
|---|---|---|
| Seeded regional journey | The test required exactly one ready Mother Hub van, but the seed deliberately supplies a primary and a backup. Both journey datasets stopped before departure. | Select the reserved primary driver and plate within the same company, hub, vehicle type and ready scope. Verify its courier-profile vehicle; retain both fresh/upgraded-network journeys and unchanged fleet/user counts. |
| Seller listing weight snapshot | An otherwise successful version-two checkout/replay still expected the historical PHP 50 shipping fee. | Assert version two and the explicit PHP 60 quote before product edits, then retain the declared unit weight and original PHP 60 fee after replay. No tariff is changed. |
| Lifecycle notices | The test required buyer confirmation and pending COD even for a new-flow verified delivery. | Execute the lifecycle separately for historical version one and current version two. Verify the appropriate delivered/completed copy, exact completion checkpoint and actor, absence of the other version's completion checkpoint, one seller completion notice, unchanged original cash records and no invented reconciliation. |
| Portal sign-in privacy | The test expected errors that disclose the account's stored role; current authentication returns a generic error within the selected public portal. | Keep guest/422 checks and the current seller/rider/admin/hub error boundary. Verify that an unknown email, wrong password and otherwise valid credentials for another role produce the same seller-portal error. |
| Logistics registration | The older payload omitted required company TIN and service regions. | Supply valid synthetic values and assert the final submission has no validation errors before checking authentication and the existing pending-approval page. |

No mixed E2E suite or shared helper was switched to version one. The selected
notification test chooses its contract version before fixture creation; generic
tests retain the helper's version-two default. The distinction between paid COD,
held cash, acknowledged remittance and external settlement remains enforced.

## Verification

The focused run passed **82 tests / 1,041 assertions** in **22.475 seconds**,
with no failures, errors or skipped tests. It deliberately excludes the unchanged
buyer-main-domain sign-in case listed below. `phpunit.xml` forces SQLite
`:memory:` and clears `DB_URL`; `Tests\TestCase` independently sets the same
database before test setup. No development or production database was migrated.

```sh
docker compose exec -T app php vendor/bin/phpunit --colors=never \
  --filter '^(?!.*test_seller_credentials_rejected_on_buyer_domain_login).*$' \
  tests/Feature/Flows/CrossRoleOrderDeliveryFlowTest.php \
  tests/Feature/Seller/SellerListingDetailsTest.php \
  tests/Feature/Notifications/LifecycleNotificationTest.php \
  tests/Feature/SubdomainIsolationTest.php
```

The focused JUnit output and diagnostic log are retained locally under the
ignored `storage/app/qa-2026-11-03/` evidence directory. Formatting verification
with `vendor/bin/pint --test` passed all four changed PHP files. The whitespace
diff check passed. These are Laravel HTTP/service tests with synthetic accounts
and controlled files, not browser, deployed-mail, concurrent PostgreSQL or
physical-device acceptance.

## Deliberately unresolved ownership boundary

`SubdomainIsolationTest::test_seller_credentials_rejected_on_buyer_domain_login`
is unchanged and excluded from this focused run. The baseline records its
failure: its expectation of a buyer-only shared-host login conflicts with the
current role-neutral shared-host behavior in `AccountIdentityService`. Its owner
must review that contract. This exclusion is not a passing result or a disabled
test in the repository; ordinary full-suite runs still execute it.

Buyer-directory failures remain outside these repairs. The additional shared E2E
review is recorded below. Neither selection establishes an all-green full backend
suite or release readiness; the combined audit retains the remaining checks and
their ownership separately.

## E2E contract follow-up

The separately authorized follow-up covers eight existing shared E2E classes,
without editing application code, buyer-directory tests or shared support traits.
It preserves the helper's version-two default and all existing permission,
custody, proof, stock, retry and settlement rejection checks.

- Tier-one F17 and F34, tier-two B17, and the standard tier-four completion
  journey explicitly run both historical and current versions. Their version is
  selected before order creation. Each asserts the original completion type and
  actor and rejects a checkpoint from the other version. Repeated completion
  still preserves the original snapshot.
- Generic delivered/proof/tracking, complete checkpoint sequences and merchant
  journeys remain version two. They expect verified-delivery completion rather
  than manufacturing a buyer-confirmation event.
- The COD journey retains the original PHP 60 fee and exact PHP 2,060 collection
  source for PHP 2,000 merchandise. It checks the assigned collector, paid order
  and unreconciled cash before explicit cash handling; only the real settlement
  helper then establishes reconciliation.
- Multi-shop shipping remains PHP 60 per independent order, and the centavo
  voucher example retains the exact PHP 159.99 total. No pricing contract is
  changed to fit an older expectation.
- Rejected-courier entry attempts lead to the owned account-status review page,
  where rejection and lack of operational access remain visible.

The first repaired-failure selection passed **22 tests / 1,827 assertions**.
After strengthening the exact monetary assertions, the full affected-class run
passed **176 tests / 9,654 assertions** in **5 minutes 35.232 seconds**, with no
failures, errors, skipped tests or case exclusions. It includes all tests from:

```text
tests/Feature/E2E/Tier1/F12_to_F17_OrderLifecycleHubToCompletedTest.php
tests/Feature/E2E/Tier1/F18_to_F20_OrderLifecycleFailureCheckpointsTest.php
tests/Feature/E2E/Tier1/F26_to_F33_HubRoutingAndGovernanceTest.php
tests/Feature/E2E/Tier1/F34_to_F35_E2EAndAdversarialTest.php
tests/Feature/E2E/Tier2/B12_to_B17_LifecycleHubToCompletedBoundaryTest.php
tests/Feature/E2E/Tier3/CrossFeatureCombinationsTest.php
tests/Feature/E2E/Tier4/RealWorldLogisticsRoutingTest.php
tests/Feature/E2E/Tier4/RealWorldStandardLifecycleTest.php
```

The command was `docker compose exec -T app php vendor/bin/phpunit --colors=never`
followed by those eight paths. JUnit and diagnostic output are retained locally
as `e2e-contract-regression-all.xml` and `e2e-contract-regression-all.log` in the
same ignored evidence directory. The test configuration and independent base
test guard both enforce SQLite `:memory:`. Formatting passed all eight files,
and report-link, scoped-privacy and whitespace checks passed. Application
behavior, tariff settings and the shared fixture helper were not changed.
