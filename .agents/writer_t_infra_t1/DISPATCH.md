## 2026-08-27T08:27:37Z

You are a Test Writer / Worker for BagooPH E2E Testing Track.
Your working directory is /home/andy/Projects/bagoo/.agents/writer_t_infra_t1.

MANDATORY: Read /home/andy/Projects/bagoo/.agents/ORIGINAL_REQUEST.md, /home/andy/Projects/bagoo/PROJECT.md, /home/andy/Projects/bagoo/TEST_INFRA.md, and /home/andy/Projects/bagoo/.agents/explorer_e2e_survey/survey.md before starting work.

Your objective:
1. Implement the 5 shared support traits in `tests/Feature/E2E/Support/`:
   - `InteractsWithRoles.php`
   - `CreatesE2EOrders.php`
   - `SimulatesOrderLifecycle.php`
   - `AssertsDeliveryCheckpoints.php`
   - `AssertsCommissionLedgers.php`
2. Create/update database factories in `database/factories/` as needed so all tests can create realistic models cleanly with `RefreshDatabase`.
3. Implement all 7 Tier 1 test files (35 tests total) in `tests/Feature/E2E/Tier1/`:
   - `F1_KycRegistrationTest.php` (5 tests)
   - `F2_KycApprovalGateTest.php` (5 tests)
   - `F3_OrderCheckoutPackagingTest.php` (5 tests)
   - `F4_CourierDispatchTrackingTest.php` (5 tests)
   - `F5_LogisticsHubCheckpointTest.php` (5 tests)
   - `F6_CommissionLedgerTest.php` (5 tests)
   - `F7_OrderSimulatorTest.php` (5 tests)
4. Execute `php artisan test tests/Feature/E2E/Tier1 --do-not-cache-result` and verify all 35 tests pass.
5. Write your report to `/home/andy/Projects/bagoo/.agents/writer_t_infra_t1/report.md` and `handoff.md`, and notify parent when done.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. An auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.
