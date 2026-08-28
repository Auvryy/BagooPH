# Task: Milestone Tier 2 Boundary & Security E2E Tests

You are a Test Writer / Worker for BagooPH E2E Testing Track.
Working Directory: /home/andy/Projects/bagoo/.agents/writer_tier2

Read:
1. /home/andy/Projects/bagoo/.agents/ORIGINAL_REQUEST.md
2. /home/andy/Projects/bagoo/PROJECT.md
3. /home/andy/Projects/bagoo/TEST_INFRA.md
4. /home/andy/Projects/bagoo/.agents/explorer_e2e_survey/survey.md
5. Existing test helpers in `tests/Feature/E2E/Support/` and Tier 1 tests in `tests/Feature/E2E/Tier1/`

Your Responsibilities:
Implement all 7 Tier 2 Boundary & Corner Case test files (5 tests each = 35 tests total) in `tests/Feature/E2E/Tier2/`:
1. `B1_KycBoundaryTest.php` (5 tests: missing required documents, invalid file types/oversized payloads, incomplete courier vehicle details, duplicate email handling, malformed phone/postal codes).
2. `B2_AuthGateSecurityTest.php` (5 tests: direct URL dashboard access interception for pending users, rejected user blocked from actions, non-admin blocked from KYC approval endpoints with 403, suspended user blocked from authentication, unauthenticated API requests rejected).
3. `B3_OrderCheckoutBoundaryTest.php` (5 tests: insufficient stock checkout rejection, expired/sub-threshold voucher rejection, zero/negative quantity/tampered price rejection, Seller IDOR cross-merchant pack prevention 403, invalid state transition e.g. cancelled order packing rejection).
4. `B4_CourierDispatchRaceConditionTest.php` (5 tests: second courier claiming already claimed delivery rejected gracefully, courier updating other courier's delivery 403, jumping directly from assigned to delivered rejected, delivery completion proof requirement, inactive/off-duty courier blocked from claiming).
5. `B5_LogisticsCheckpointValidationTest.php` (5 tests: non-existent barcode scan error, hub scan on unpicked package rejection, duplicate hub scan idempotency, unauthorized buyer/seller accessing /hub 403, supervisor override invalid courier/status validation).
6. `B6_CommissionLedgerIdempotencyTest.php` (5 tests: duplicate delivered triggers produce exactly 1 ledger record, 100% discount voucher handles ₱0 commission without division-by-zero, fractional centavo rounding exactness, cancelled order produces zero commission credits, unauthorized direct POST to ledger blocked).
7. `B7_SimulatorBoundaryTest.php` (5 tests: advance on already delivered order is safe no-op, advance on cancelled order returns error, reset endpoint reverts order to pending and delivery to unassigned, invalid/non-existent order ID 404, unauthenticated simulator request blocked 401/302).

Execute `php artisan test tests/Feature/E2E/Tier2 --do-not-cache-result` and ensure all 35 tests pass 100%.
Write your report to `/home/andy/Projects/bagoo/.agents/writer_tier2/report.md` and `handoff.md`, then send a message back.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. An auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.
