# BagooPH End-to-End (E2E) Test Infrastructure Specification

## 1. Overview
This document defines the architecture, test helpers, tier decomposition, execution instructions, and validation standards for the BagooPH automated End-to-End (E2E) test suite.

The test suite exercises full multi-role data interconnectedness across all 5 user roles:
- `buyer` (Marketplace shopping, variant selection, checkout, voucher application, order history, live tracking)
- `seller` (Seller Cockpit `/seller/orders`, packaging approval, thermal waybill printing, ready for pickup dispatch, earnings ledger)
- `courier` (Dispatch board `/courier/deliveries`, FCFS job claiming, barcode pickup, transit updates, proof of delivery, rider earnings)
- `logistics` (Central Hub workstation `/hub`, incoming barcode scan, barangay sorting bin classification)
- `admin` (KYC verification queue `/admin/users`, approve/reject governance, Commission Treasury ledger)

---

## 2. Test Architecture & Directory Structure

All E2E tests are organized under `tests/Feature/E2E/` using Laravel's test framework with SQLite in-memory database (`:memory:`) and `RefreshDatabase` isolation.

```
tests/Feature/E2E/
├── Support/
│   ├── InteractsWithRoles.php
│   ├── CreatesE2EOrders.php
│   ├── SimulatesOrderLifecycle.php
│   ├── AssertsDeliveryCheckpoints.php
│   └── AssertsCommissionLedgers.php
├── Tier1/
│   ├── F1_KycRegistrationTest.php          (5 tests)
│   ├── F2_KycApprovalGateTest.php          (5 tests)
│   ├── F3_OrderCheckoutPackagingTest.php   (5 tests)
│   ├── F4_CourierDispatchTrackingTest.php  (5 tests)
│   ├── F5_LogisticsHubCheckpointTest.php   (5 tests)
│   ├── F6_CommissionLedgerTest.php         (5 tests)
│   └── F7_OrderSimulatorTest.php           (5 tests)
├── Tier2/
│   ├── B1_KycBoundaryTest.php              (5 tests)
│   ├── B2_AuthGateSecurityTest.php         (5 tests)
│   ├── B3_OrderCheckoutBoundaryTest.php    (5 tests)
│   ├── B4_CourierDispatchRaceConditionTest.php (5 tests)
│   ├── B5_LogisticsCheckpointValidationTest.php (5 tests)
│   ├── B6_CommissionLedgerIdempotencyTest.php (5 tests)
│   └── B7_SimulatorBoundaryTest.php        (5 tests)
├── Tier3/
│   └── CrossFeaturePairwiseTest.php        (7 tests)
└── Tier4/
    └── RealWorldWorkloadTest.php           (5 tests)
```

---

## 3. Shared Support Helpers & Assertions (`tests/Feature/E2E/Support/`)

### 3.1 `InteractsWithRoles.php`
- `actingAsBuyer(?User $user = null): static`
- `actingAsSeller(?User $user = null): static`
- `actingAsCourier(?User $user = null): static`
- `actingAsLogistics(?User $user = null): static`
- `actingAsAdmin(?User $user = null): static`
- `createApprovedUser(string $role, array $attributes = []): User`
- `createPendingUser(string $role, array $attributes = []): User`
- `createRejectedUser(string $role, string $feedback = 'Invalid ID', array $attributes = []): User`

### 3.2 `CreatesE2EOrders.php`
- `createE2EShop(User $seller, array $attributes = []): Shop`
- `createE2EProduct(Shop $shop, array $attributes = []): Product`
- `createE2EOrder(User $buyer, Shop $shop, array $items = [], string $status = 'pending'): Order`
- `createE2EDelivery(Order $order, string $status = 'unassigned', ?User $courier = null): Delivery`
- `createE2EVoucher(Shop $shop, array $attributes = []): Voucher`

### 3.3 `SimulatesOrderLifecycle.php`
- `advanceOrderStage(Order $order): TestResponse`
- `resetOrderStage(Order $order): TestResponse`
- `assertOrderStage(Order $order, string $expectedOrderStatus, string $expectedDeliveryStatus): void`
- `fastForwardToDelivered(Order $order): Order`

### 3.4 `AssertsDeliveryCheckpoints.php`
- `assertCheckpointLogged(Delivery $delivery, string $checkpointType, ?string $location = null): void`
- `assertCheckpointSequence(Delivery $delivery, array $expectedTypes): void`
- `assertBarcodeScanned(Delivery $delivery, string $barcode): void`

### 3.5 `AssertsCommissionLedgers.php`
- `assertCommissionSplit(Order $order, ?float $expectedGross = null): CommissionLedger`
- `assertLedgerIdempotent(Order $order): void`

---

## 4. Test Tiers & Feature Inventory Matrix (Total 82 Tests)

| Tier | Directory | Test Files | Test Count | Description |
|---|---|---|---|---|
| **Tier 1** | `Tier1/` | `F1_KycRegistrationTest.php`<br>`F2_KycApprovalGateTest.php`<br>`F3_OrderCheckoutPackagingTest.php`<br>`F4_CourierDispatchTrackingTest.php`<br>`F5_LogisticsHubCheckpointTest.php`<br>`F6_CommissionLedgerTest.php`<br>`F7_OrderSimulatorTest.php` | **35 tests** (5 per feature) | Core feature coverage across all 7 features in the inventory. |
| **Tier 2** | `Tier2/` | `B1_KycBoundaryTest.php`<br>`B2_AuthGateSecurityTest.php`<br>`B3_OrderCheckoutBoundaryTest.php`<br>`B4_CourierDispatchRaceConditionTest.php`<br>`B5_LogisticsCheckpointValidationTest.php`<br>`B6_CommissionLedgerIdempotencyTest.php`<br>`B7_SimulatorBoundaryTest.php` | **35 tests** (5 per feature) | Boundary values, negative validation, security gates, race conditions, IDOR checks, idempotency. |
| **Tier 3** | `Tier3/` | `CrossFeaturePairwiseTest.php` | **7 tests** | Cross-feature handoffs and pairwise pipeline integration. |
| **Tier 4** | `Tier4/` | `RealWorldWorkloadTest.php` | **5 tests** | Real-world multi-role end-to-end user workflows and exception scenarios. |
| **Total** | | | **82 tests** | Comprehensive requirement-driven opaque-box E2E suite. |

---

## 5. Test Execution Instructions

### 5.1 Running the Entire Test Suite
```bash
php artisan test --do-not-cache-result
# or:
./vendor/bin/phpunit --do-not-cache-result
```

### 5.2 Running by Tier
```bash
# Run Tier 1 Feature Coverage
php artisan test tests/Feature/E2E/Tier1 --do-not-cache-result

# Run Tier 2 Boundary & Security
php artisan test tests/Feature/E2E/Tier2 --do-not-cache-result

# Run Tier 3 Cross-Feature
php artisan test tests/Feature/E2E/Tier3 --do-not-cache-result

# Run Tier 4 Real-World Workloads
php artisan test tests/Feature/E2E/Tier4 --do-not-cache-result
```

### 5.3 Running Specific Feature Tests
```bash
php artisan test --filter F1_KycRegistrationTest --do-not-cache-result
php artisan test --filter F3_OrderCheckoutPackagingTest --do-not-cache-result
php artisan test --filter RealWorldWorkloadTest --do-not-cache-result
```

---

## 6. Acceptance Standards
- **Zero Failures**: 100% of all 82 tests must pass.
- **Database Isolation**: Tests must use `RefreshDatabase` and SQLite in-memory without cross-test leakage.
- **Strict Financial Verification**: Commission ledger assertions must check exact 90% / 10% / ₱60 distribution.
- **Strict Role Isolation**: Gating and IDOR boundary tests must assert 403 Forbidden or redirect where appropriate.
