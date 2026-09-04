# BagooPH E2E Testing Infrastructure & Specification Matrix

## 1. Architecture & Testing Philosophy

BagooPH adopts an **Opaque-Box End-to-End Integration Testing Architecture**.
- **Zero Mocking of Core Logic**: State transitions, database writes, checkpoint logging, commission calculations, and role gates run against real Laravel services and Eloquent models with `RefreshDatabase`.
- **Subdomain-Aware HTTP Simulation**: HTTP requests faithfully simulate the 5 production subdomains via `$this->withServerVariables(['HTTP_HOST' => $subdomain])` or the dedicated `InteractsWithPortals` helper.
- **Immutable Checkpoint Auditing**: Every valid lifecycle transition synchronously verifies audit entries in `delivery_checkpoints`.
- **Double-Entry Financial Integrity**: Delivery completion asserts exact 10% platform commission, 90% merchant settlement, and ₱60 courier fees.
- **Strict Role & Portal Isolation**: Access to role-specific cockpits is verified against the 5 subdomains: Buyer (`bagooph.shop`), Seller (`seller.bagooph.shop`), Courier (`courier.bagooph.shop`), Logistics Hub (`hub.bagooph.shop`), and Admin (`admin.bagooph.shop`).

---

## 2. Directory Structure & Test Hierarchy

```
tests/
├── TestCase.php
├── Feature/
│   ├── E2E/
│   │   ├── Support/
│   │   │   ├── InteractsWithRoles.php
│   │   │   ├── InteractsWithPortals.php
│   │   │   ├── CreatesE2EOrders.php
│   │   │   ├── SimulatesOrderLifecycle.php
│   │   │   ├── AssertsDeliveryCheckpoints.php
│   │   │   └── AssertsCommissionLedgers.php
│   │   ├── Tier1/                               # 175 Tests (35 Features × 5 Tests)
│   │   │   ├── F01_SubdomainRoutingTest.php
│   │   │   ├── F02_SubdomainLoginViewsTest.php
│   │   │   ├── F03_RoleLockedLoginBarrierTest.php
│   │   │   ├── F04_SubdomainRegistrationTest.php
│   │   │   ├── F05_CrossDomainFallbackTest.php
│   │   │   ├── F06_NavigationIsolationTest.php
│   │   │   ├── F07_Stage1PlacedTest.php
│   │   │   ├── F08_Stage2ConfirmedTest.php
│   │   │   ├── F09_Stage3PreparingTest.php
│   │   │   ├── F10_Stage4ReadyForPickupTest.php
│   │   │   ├── F11_Stage5PickedUpTest.php
│   │   │   ├── F12_Stage6AtSortingCenterTest.php
│   │   │   ├── F13_Stage7SortedAreaTest.php
│   │   │   ├── F14_Stage8AssignedToRiderTest.php
│   │   │   ├── F15_Stage9OutForDeliveryTest.php
│   │   │   ├── F16_Stage10DeliveredTest.php
│   │   │   ├── F17_Stage11CompletedTest.php
│   │   │   ├── F18_Stage12DeliveryFailedTest.php
│   │   │   ├── F19_Stage13ReturnedTest.php
│   │   │   ├── F20_DeliveryCheckpointsTest.php
│   │   │   ├── F21_CourierSplitTabPickupTest.php
│   │   │   ├── F22_CourierSplitTabDeliveryTest.php
│   │   │   ├── F23_CourierFcfsClaimingTest.php
│   │   │   ├── F24_DeliveryFailureReasonTest.php
│   │   │   ├── F25_DeliveryFailureResolutionTest.php
│   │   │   ├── F26_DestinationAreaPartitioningTest.php
│   │   │   ├── F27_ParcelSortingByAreaTest.php
│   │   │   ├── F28_AreaMatchedRiderAssignmentTest.php
│   │   │   ├── F29_HubRiderKycReviewTest.php
│   │   │   ├── F30_HubRiderApprovalAreaTest.php
│   │   │   ├── F31_HubRiderRejectionTest.php
│   │   │   ├── F32_HubRiderActivationToggleTest.php
│   │   │   ├── F33_HubLayoutWorkstationTest.php
│   │   │   ├── F34_E2ESuiteExecutionTest.php
│   │   │   └── F35_AdversarialSecurityTest.php
│   │   ├── Tier2/                               # 175 Tests (35 Features × 5 Boundaries)
│   │   │   ├── B01_SubdomainBoundaryTest.php
│   │   │   ├── B02_LoginViewBoundaryTest.php
│   │   │   ├── B03_RoleMismatchBoundaryTest.php
│   │   │   ├── B04_KycValidationBoundaryTest.php
│   │   │   ├── B05_CrossDomainSecurityBoundaryTest.php
│   │   │   ├── B06_PortalIsolationBoundaryTest.php
│   │   │   ├── B07_CheckoutBoundaryTest.php
│   │   │   ├── B08_ConfirmationIdorBoundaryTest.php
│   │   │   ├── B09_PackagingGuardBoundaryTest.php
│   │   │   ├── B10_PickupStagingBoundaryTest.php
│   │   │   ├── B11_CourierScanBoundaryTest.php
│   │   │   ├── B12_HubIntakeBoundaryTest.php
│   │   │   ├── B13_AreaSortingBoundaryTest.php
│   │   │   ├── B14_RiderAreaMismatchBoundaryTest.php
│   │   │   ├── B15_OutForDeliveryBoundaryTest.php
│   │   │   ├── B16_DoorstepProofBoundaryTest.php
│   │   │   ├── B17_BuyerCompletionBoundaryTest.php
│   │   │   ├── B18_FailureReasonBoundaryTest.php
│   │   │   ├── B19_ReturnPipelineBoundaryTest.php
│   │   │   ├── B20_CheckpointIntegrityBoundaryTest.php
│   │   │   ├── B21_PickupTabSecurityBoundaryTest.php
│   │   │   ├── B22_DeliveryTabSecurityBoundaryTest.php
│   │   │   ├── B23_FcfsRaceConditionBoundaryTest.php
│   │   │   ├── B24_FailureModalValidationBoundaryTest.php
│   │   │   ├── B25_ResolutionAttemptCapBoundaryTest.php
│   │   │   ├── B26_AreaResolutionEdgeBoundaryTest.php
│   │   │   ├── B27_HubSortingBinBoundaryTest.php
│   │   │   ├── B28_AreaDispatchGuardBoundaryTest.php
│   │   │   ├── B29_HubFleetReviewSecurityBoundaryTest.php
│   │   │   ├── B30_RiderAreaApprovalBoundaryTest.php
│   │   │   ├── B31_RiderRejectionValidationBoundaryTest.php
│   │   │   ├── B32_CourierSuspensionBoundaryTest.php
│   │   │   ├── B33_HubWorkstationAccessBoundaryTest.php
│   │   │   ├── B34_TestRunnerResilienceBoundaryTest.php
│   │   │   └── B35_AdversarialStressBoundaryTest.php
│   │   ├── Tier3/                               # 35 Cross-Feature Tests
│   │   │   └── CrossFeatureCombinationsTest.php
│   │   └── Tier4/                               # 18 Real-World End-to-End Scenarios
│   │       ├── RealWorldStandardLifecycleTest.php
│   │       ├── RealWorldLogisticsRoutingTest.php
│   │       └── RealWorldExceptionsAndFleetTest.php
```

---

## 3. Test Runner Commands

Tests execute inside the Docker application container with SQLite in-memory database:

```bash
# Run entire automated test suite
./bagoo.sh test

# Run individual test tiers
./bagoo.sh test --filter Tier1
./bagoo.sh test --filter Tier2
./bagoo.sh test --filter Tier3
./bagoo.sh test --filter Tier4

# Run specific feature tests
./bagoo.sh test --filter F03_RoleLockedLoginBarrierTest
./bagoo.sh test --filter B14_RiderAreaMismatchBoundaryTest

# Check frontend asset compilation
./bagoo.sh npm run build
```

---

## 4. Test Support Traits & Utilities

| Trait | Purpose | Key Helper Methods |
|---|---|---|
| `InteractsWithPortals` | Subdomain host simulation and HTTP routing | `onPortal()`, `portalGet()`, `portalPost()`, `portalPatch()`, host header mapping |
| `InteractsWithRoles` | User & session setup for all 5 roles | `actingAsBuyer()`, `actingAsSeller()`, `actingAsCourier()`, `actingAsLogistics()`, `actingAsAdmin()`, `createApprovedUser()`, `createPendingUser()` |
| `CreatesE2EOrders` | Fixture generator for orders, shops, deliveries | `createE2EShop()`, `createE2EProduct()`, `createE2EOrder()`, `createE2EDelivery()`, `createE2EVoucher()` |
| `SimulatesOrderLifecycle` | Fast-forward state machine helper | `advanceOrderStage()`, `resetOrderStage()`, `assertOrderStage()`, `fastForwardToDelivered()` |
| `AssertsDeliveryCheckpoints` | Checkpoint verification and sequencing | `assertCheckpointLogged()`, `assertCheckpointSequence()`, `assertBarcodeScanned()` |
| `AssertsCommissionLedgers` | Financial distribution assertions | `assertCommissionSplit()`, `assertLedgerIdempotent()` |

---

## 5. Comprehensive Coverage Matrix (403 Tests Total)

| Feature # | Feature Name | Tier 1 (Baseline) | Tier 2 (Boundaries) | Tier 3 (Cross-Feature) | Tier 4 (Real-World) | Total Tests |
|---|---|---|---|---|---|---|
| 1 | 5-Domain Routing Architecture | 5 | 5 | 2 | 2 | 14 |
| 2 | Dedicated Subdomain Login Views | 5 | 5 | 1 | 1 | 12 |
| 3 | Role-Locked Login Barrier | 5 | 5 | 3 | 2 | 15 |
| 4 | Dedicated Subdomain Registration | 5 | 5 | 2 | 2 | 14 |
| 5 | Cross-Domain Fallback Redirection | 5 | 5 | 1 | 1 | 12 |
| 6 | Subdomain Navigation Isolation | 5 | 5 | 2 | 1 | 13 |
| 7 | PLACED (Stage 1) | 5 | 5 | 3 | 3 | 16 |
| 8 | CONFIRMED (Stage 2) | 5 | 5 | 2 | 2 | 14 |
| 9 | PREPARING (Stage 3) | 5 | 5 | 2 | 2 | 14 |
| 10 | READY_FOR_PICKUP (Stage 4) | 5 | 5 | 3 | 2 | 15 |
| 11 | PICKED_UP (Stage 5) | 5 | 5 | 3 | 3 | 16 |
| 12 | AT_SORTING_CENTER (Stage 6) | 5 | 5 | 3 | 3 | 16 |
| 13 | SORTED (Stage 7) | 5 | 5 | 3 | 3 | 16 |
| 14 | ASSIGNED_TO_RIDER (Stage 8) | 5 | 5 | 3 | 3 | 16 |
| 15 | OUT_FOR_DELIVERY (Stage 9) | 5 | 5 | 3 | 3 | 16 |
| 16 | DELIVERED (Stage 10) | 5 | 5 | 3 | 3 | 16 |
| 17 | COMPLETED (Stage 11) | 5 | 5 | 3 | 3 | 16 |
| 18 | DELIVERY_FAILED (Stage 12) | 5 | 5 | 3 | 2 | 15 |
| 19 | RETURNED (Stage 13) | 5 | 5 | 3 | 2 | 15 |
| 20 | Delivery Checkpoints Pipeline | 5 | 5 | 4 | 3 | 17 |
| 21 | Split Tab: Items for Pickup | 5 | 5 | 2 | 2 | 14 |
| 22 | Split Tab: Items for Delivery | 5 | 5 | 2 | 2 | 14 |
| 23 | FCFS Pickup Claiming | 5 | 5 | 2 | 2 | 14 |
| 24 | Delivery Failure Modal & Reason | 5 | 5 | 2 | 2 | 14 |
| 25 | Delivery Failure Resolution Options | 5 | 5 | 2 | 2 | 14 |
| 26 | Destination Area Partitioning | 5 | 5 | 2 | 2 | 14 |
| 27 | Parcel Sorting by Area | 5 | 5 | 2 | 2 | 14 |
| 28 | Area-Matched Rider Assignment | 5 | 5 | 3 | 2 | 15 |
| 29 | Hub Rider Fleet Review & KYC | 5 | 5 | 2 | 2 | 14 |
| 30 | Hub Rider Approval & Area Designation | 5 | 5 | 2 | 2 | 14 |
| 31 | Hub Rider Disapproval / Rejection | 5 | 5 | 2 | 1 | 13 |
| 32 | Hub Rider Activation / Deactivation | 5 | 5 | 2 | 2 | 14 |
| 33 | Dedicated Hub Layout & Pages | 5 | 5 | 1 | 2 | 13 |
| 34 | E2E Testing Suite (Tiers 1-4) | 5 | 5 | 1 | 1 | 12 |
| 35 | Adversarial Coverage Hardening | 5 | 5 | 1 | 1 | 12 |
| **TOTALS** | **35 Features** | **175** | **175** | **35** | **18** | **403 Tests** |

---

## 6. Acceptance Standards & Invariants

- **Zero Test Failures**: 100% of all 403 tests must pass under `./bagoo.sh test` with exit code 0.
- **Strict Role Isolation**: Subdomain login barrier must return HTTP 422 for role mismatches; unauthorized paths return HTTP 403 or redirect.
- **State Machine Integrity**: Out-of-order state transitions rejected with HTTP 422.
- **Audit Immutability**: Synchronous write to `delivery_checkpoints` for every valid status transition.
- **Financial Precision**: 90% merchant, 10% platform, ₱60 courier fee with zero centavo leakage.
