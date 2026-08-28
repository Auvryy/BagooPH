# BagooPH End-to-End (E2E) Test Suite Survey & Architectural Specification

**Agent:** Teamwork Explorer (`explorer_e2e_survey`)  
**Target Project:** BagooPH (Laravel 11/12 + Inertia React + PostgreSQL / SQLite)  
**Date:** 2026-08-27  
**Working Directory:** `/home/andy/Projects/bagoo/.agents/explorer_e2e_survey`  
**Output Target:** `/home/andy/Projects/bagoo/.agents/explorer_e2e_survey/survey.md` & `/home/andy/Projects/bagoo/TEST_INFRA.md`

---

## 1. Executive Summary

This comprehensive investigation surveys the testing infrastructure, relational database models, migrations, factories, seeders, authentication gates, HTTP controllers, and role routing across the BagooPH platform.

BagooPH implements an e-commerce ecosystem interconnecting **5 distinct user roles**:
1. **Buyer** (`buyer`): Variant selection, checkout with vouchers, COD/simulation payment, live 5-milestone tracking.
2. **Seller** (`seller`): Merchant Cockpit (`/seller/orders`), review & packaging approval, thermal waybill generation, dispatch release.
3. **Courier Rider** (`courier`): Courier Dispatch Board (`/courier/deliveries`), FCFS job claiming, pickup barcode scan, transit status progression, photo proof of delivery, rider earnings.
4. **Logistics Sorting Hub** (`logistics`): Dedicated Hub Workstation (`/hub`), incoming tracking barcode scan intake, barangay sorting bin classification, supervisor dispatch override.
5. **Platform Admin** (`admin`): Platform governance, Admin KYC Verification Queue with document inspection & Approve/Reject actions, Commission Treasury ledger.

The survey establishes the architectural blueprint for a **complete, opaque-box, requirement-driven E2E test suite** organized across **4 structured tiers** (`tests/Feature/E2E/Tier1` through `Tier4`), totaling **82 automated tests** with shared test helpers and assertions.

---

## 2. Test Environment & Harness Analysis

### 2.1 PHPUnit & Laravel Test Setup
- **PHPUnit Configuration (`phpunit.xml`)**:
  - `DB_CONNECTION`: `sqlite`
  - `DB_DATABASE`: `:memory:`
  - `APP_ENV`: `testing`
  - `CACHE_STORE`: `array`
  - `QUEUE_CONNECTION`: `sync`
  - `SESSION_DRIVER`: `array`
  - `MAIL_MAILER`: `array`
  - Testsuites: `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`).
- **Test Base Class (`tests/TestCase.php`)**:
  - Extends `Illuminate\Foundation\Testing\TestCase`.
  - All E2E Feature tests must use `Illuminate\Foundation\Testing\RefreshDatabase` trait to ensure database tables are migrated cleanly in the SQLite in-memory environment for each test class.

### 2.2 Test Runner Diagnostics & Observations
1. **PHP CLI Environment**:
   - PHP Version: `PHP 8.5.9 (cli)`
   - Composer: `Composer 2.10.2`
   - Node.js: `v26.7.0`, NPM: `12.0.2`
2. **Current Test Run Execution**:
   - Execution command: `php artisan test --do-not-cache-result` or `./vendor/bin/phpunit --do-not-cache-result`.
   - Current suite has 25 tests in `tests/Unit` and `tests/Feature/Auth`, `tests/Feature/ProfileTest.php`.
   - **Discovered Failure in Auth Tests**: `AuthenticationTest` and `RegistrationTest` fail asserting redirect to `/dashboard` because BagooPH correctly redirects authenticated users to their role landing page (`/buyer` for buyers).
   - **Permission Note on Cache**: `.phpunit.result.cache` was owned by root. Adding `--do-not-cache-result` ensures clean zero-warning execution across all CLI environments.

---

## 3. Codebase Inventory: Models, Enums, Factories, Seeders & Routes

### 3.1 Entity Model & Enum Matrix

| Model | Table | Enums / Statuses | Key Relationships | Current State / Notes |
|---|---|---|---|---|
| `User` | `users` | `role` (`buyer`, `seller`, `courier`, `logistics`, `admin`), `status` (`active`, `pending_approval`, `rejected`, `suspended`), `kyc_status` (`pending_approval`, `approved`, `rejected`) | `hasOne(Shop)`, `hasOne(CourierProfile)`, `hasMany(Order, 'buyer_id')`, `hasMany(Delivery, 'courier_id')`, `hasOne(Cart)` | Base model exists; M1 extends KYC columns & status checks. |
| `CourierProfile` | `courier_profiles` | `vehicle_type` (`Motorcycle`, `Bicycle`, `Van`), `license_status`, `or_cr_status`, `is_available` | `belongsTo(User)` | M1 creates migration & model. |
| `Shop` | `shops` | `status` (`active`, `inactive`) | `belongsTo(User)`, `hasMany(Product)`, `hasMany(Order)` | Exists; stores merchant info. |
| `Category` | `categories` | Active | `hasMany(Product)` | 14 departments seeded in `DatabaseSeeder`. |
| `Product` | `products` | `status` (`active`, `inactive`) | `belongsTo(Shop)`, `belongsTo(Category)`, `hasMany(ProductImage)` | Exists. |
| `Cart` & `CartItem` | `carts`, `cart_items` | Active | `belongsTo(User)`, `hasMany(CartItem)` | M1 adds `color`, `size`, `sku_snapshot`. |
| `Order` | `orders` | `OrderStatus`: `pending`, `packaging`, `ready_for_pickup`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `cancelled` | `belongsTo(User, 'buyer_id')`, `belongsTo(Shop)`, `hasMany(OrderItem)`, `hasOne(Delivery)`, `hasOne(CommissionLedger)` | Standardized to 7 canonical lifecycle stages. |
| `OrderItem` | `order_items` | Active | `belongsTo(Order)`, `belongsTo(Product)` | M1 adds `color`, `size`, `sku_snapshot`. |
| `Delivery` | `deliveries` | `DeliveryStatus`: `unassigned`, `assigned`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `failed` | `belongsTo(Order)`, `belongsTo(User, 'courier_id')`, `hasMany(DeliveryCheckpoint)` | Exists; `delivery_phone` mapping reconciled. |
| `DeliveryCheckpoint` | `delivery_checkpoints` | `checkpoint_type`: `seller_pack`, `courier_pickup`, `hub_intake`, `barangay_sort`, `doorstep_handover` | `belongsTo(Delivery)`, `belongsTo(User, 'scanned_by_id')` | M3 creates migration, model & audit trail. |
| `CommissionLedger` | `commission_ledgers` | `status`: `pending`, `settled`, `refunded` | `belongsTo(Order)`, `belongsTo(Shop, 'seller_id')`, `belongsTo(User, 'courier_id')` | M4 creates migration, model & atomic trigger. |
| `Voucher` | `vouchers` | `type`: `fixed`, `percent`, `free_shipping` | `belongsTo(Shop)` | Exists in migrations & seeders. |

### 3.2 Factories & Seeders Analysis
- Currently, only `database/factories/UserFactory.php` exists.
- **Recommended Factories for E2E Test Suite**:
  1. `UserFactory`: Expanded with states: `buyer()`, `seller()`, `courier()`, `logistics()`, `admin()`, `pendingKyc()`, `approvedKyc()`, `rejectedKyc()`.
  2. `ShopFactory`: Creates shop attached to a seller user.
  3. `CategoryFactory`: Creates product categories.
  4. `ProductFactory`: Creates products with price, stock, sku, and shop relationship.
  5. `OrderFactory`: Creates orders with subtotal, shipping fee, total amount, payment method, recipient details, and lifecycle states.
  6. `OrderItemFactory`: Creates order items linked to order and product.
  7. `DeliveryFactory`: Creates delivery records with tracking number, pickup/delivery addresses, and lifecycle statuses.
  8. `CourierProfileFactory`: Creates courier profile with vehicle and license data.
  9. `VoucherFactory`: Creates vouchers with min spend and discount values.
  10. `DeliveryCheckpointFactory`: Creates timestamped scan audit logs.
  11. `CommissionLedgerFactory`: Creates commission split records (90% seller, 10% treasury, ₱60 courier).

---

## 4. Multi-Role Route & Feature Status

### 4.1 Route Map Across All 5 Roles

```
PUBLIC & MARKETPLACE:
  GET  /                              -> MarketplaceController::index
  GET  /products                      -> BuyerProductController::search
  GET  /product/{slug}                -> BuyerProductController::show
  GET  /cart                          -> CartController::index
  POST /cart                          -> CartController::store
  PATCH /cart/{cartItem}              -> CartController::update
  DELETE /cart/{cartItem}             -> CartController::destroy

AUTH & KYC GATE:
  GET  /register                      -> RegisteredUserController::create
  GET  /seller/register               -> RegisteredUserController::createSeller
  GET  /courier/register              -> RegisteredUserController::createCourier
  POST /register                      -> RegisteredUserController::store
  GET  /login                         -> AuthenticatedSessionController::create
  POST /login                         -> AuthenticatedSessionController::store
  POST /logout                        -> AuthenticatedSessionController::destroy
  GET  /pending-approval              -> PendingApprovalController / Inertia page

BUYER PORTAL (role:buyer):
  GET  /buyer                         -> BuyerHomeController::index
  GET  /buyer/search                  -> BuyerProductController::search
  GET  /buyer/checkout                -> CheckoutController::index
  POST /buyer/checkout                -> CheckoutController::store (or POST /checkout)
  GET  /buyer/orders                  -> OrderHistoryController::index
  GET  /buyer/orders/{order}          -> OrderHistoryController::show
  POST /buyer/vouchers/apply          -> VoucherController::apply

SELLER COCKPIT (role:seller):
  GET  /seller/dashboard              -> SellerDashboardController::index
  GET  /seller/orders                 -> SellerOrderController::index
  POST /seller/orders/{order}/pack    -> SellerOrderController::pack
  POST /seller/orders/{order}/ready   -> SellerOrderController::readyForPickup
  GET  /seller/products               -> SellerProductController::index
  POST /seller/products               -> SellerProductController::store
  GET  /seller/reports                -> SellerDashboardController::reports

COURIER DISPATCH (role:courier,logistics):
  GET  /courier/deliveries            -> CourierDeliveryController::index
  POST /courier/deliveries/{delivery}/claim -> CourierDeliveryController::claim
  PATCH /courier/deliveries/{delivery}/status -> CourierDeliveryController::updateStatus
  GET  /courier/earnings              -> CourierDeliveryController::earnings
  GET  /courier/profile               -> CourierDeliveryController::profile

LOGISTICS SORTING HUB (role:logistics,admin):
  GET  /hub                           -> LogisticsHubWorkstationController::index
  POST /hub/scan                      -> LogisticsHubWorkstationController::scanIntake
  POST /hub/sort                      -> LogisticsHubWorkstationController::sortBarangay
  GET  /admin/logistics               -> LogisticsHubController::index
  POST /admin/logistics/override      -> LogisticsHubController::override

ADMIN CONTROL & KYC QUEUE (role:admin):
  GET  /admin/dashboard               -> AdminDashboardController::index
  GET  /admin/users                   -> AdminDashboardController::users
  GET  /admin/kyc                     -> AdminKycController::index (or /admin/users tab)
  POST /admin/kyc/{user}/approve      -> AdminKycController::approve
  POST /admin/kyc/{user}/reject       -> AdminKycController::reject

SIMULATOR FAST-FORWARD API:
  POST /simulator/orders/{order}/advance -> OrderSimulationController::advance
  POST /simulator/orders/{order}/reset   -> OrderSimulationController::reset
```

---

## 5. Shared Test Helpers & Architecture (`tests/Feature/E2E/Support/`)

To maintain clean, DRY, and highly maintainable test code across all 82 tests, five specialized traits/helpers must be provided:

### 5.1 `InteractsWithRoles.php`
Provides authenticated actor switches and user state generation:
- `actingAsBuyer(?User $user = null): static`
- `actingAsSeller(?User $user = null): static`
- `actingAsCourier(?User $user = null): static`
- `actingAsLogistics(?User $user = null): static`
- `actingAsAdmin(?User $user = null): static`
- `createApprovedUser(string $role, array $attributes = []): User`
- `createPendingUser(string $role, array $attributes = []): User`
- `createRejectedUser(string $role, string $feedback = 'Invalid ID', array $attributes = []): User`

### 5.2 `CreatesE2EOrders.php`
Simplifies rapid creation of fully hydrated marketplace orders:
- `createE2EShop(User $seller, array $attributes = []): Shop`
- `createE2EProduct(Shop $shop, array $attributes = []): Product`
- `createE2EOrder(User $buyer, Shop $shop, array $items = [], string $status = 'pending'): Order`
- `createE2EDelivery(Order $order, string $status = 'unassigned', ?User $courier = null): Delivery`
- `createE2EVoucher(Shop $shop, array $attributes = []): Voucher`

### 5.3 `SimulatesOrderLifecycle.php`
Wraps the fast-forward simulator and step verification:
- `advanceOrderStage(Order $order): TestResponse`
- `resetOrderStage(Order $order): TestResponse`
- `assertOrderStage(Order $order, string $expectedOrderStatus, string $expectedDeliveryStatus): void`
- `fastForwardToDelivered(Order $order): Order`

### 5.4 `AssertsDeliveryCheckpoints.php`
Validates audit trail creation and scanning events:
- `assertCheckpointLogged(Delivery $delivery, string $checkpointType, ?string $location = null): void`
- `assertCheckpointSequence(Delivery $delivery, array $expectedTypes): void`
- `assertBarcodeScanned(Delivery $delivery, string $barcode): void`

### 5.5 `AssertsCommissionLedgers.php`
Validates financial distributions and mathematical exactness:
- `assertCommissionSplit(Order $order, ?float $expectedGross = null): CommissionLedger`
  * Asserts `seller_amount == gross_amount * 0.90` (90%)
  * Asserts `platform_commission == gross_amount * 0.10` (10%)
  * Asserts `delivery_fee == 60.00` credited to courier
  * Asserts status is `settled`
- `assertLedgerIdempotent(Order $order): void`

---

## 6. Comprehensive E2E Test Suite Matrix (Tiers 1 to 4: Total 82 Tests)

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

### 6.1 Tier 1: Feature Coverage (35 Tests, >=5 per feature)

#### Feature 1: Multi-Role KYC Registration & Schema Extensions (`Tier1/F1_KycRegistrationTest.php`)
1. `test_f1_01_customer_can_register_with_id_document`: Registers customer with valid name, email, password, and ID document; asserts user created with `role = 'buyer'`.
2. `test_f1_02_seller_can_register_with_business_permit_and_id`: Registers seller with shop details, ID, and business permit; asserts shop and user records created.
3. `test_f1_03_courier_can_register_with_license_and_or_cr_documents`: Registers courier with driver's license, OR/CR document, vehicle type, and plate number.
4. `test_f1_04_newly_registered_users_default_to_pending_approval_status`: Asserts `kyc_status = 'pending_approval'` and `status = 'pending_approval'` on fresh registration.
5. `test_f1_05_courier_profile_record_is_created_upon_courier_registration`: Asserts relational `courier_profiles` row is created with vehicle specifications.

#### Feature 2: Auth & Role KYC Approval Gate & Admin Verification Queue (`Tier1/F2_KycApprovalGateTest.php`)
1. `test_f2_01_pending_user_is_redirected_to_pending_approval_holding_page`: Logs in as pending user, attempts to access protected role portal, asserts redirect to `/pending-approval`.
2. `test_f2_02_admin_can_view_pending_kyc_verification_queue`: Logs in as admin, visits `/admin/users` or `/admin/kyc`, asserts list of pending applicants is returned with document URLs.
3. `test_f2_03_admin_can_approve_pending_user_activating_account`: Admin posts approve action; asserts `kyc_status = 'approved'`, `status = 'active'`, and `kyc_reviewed_at` timestamp populated.
4. `test_f2_04_admin_can_reject_pending_user_with_feedback_reason`: Admin posts reject action with reason; asserts `kyc_status = 'rejected'` and `kyc_feedback` stored.
5. `test_f2_05_approved_user_can_access_role_dashboard_immediately`: Logs in as approved user; asserts 200 OK on role dashboard without redirect to pending page.

#### Feature 3: Unified 7-Stage Order Checkout & Packaging Lifecycle (`Tier1/F3_OrderCheckoutPackagingTest.php`)
1. `test_f3_01_buyer_can_checkout_with_variant_persistence_in_pending_status`: Buyer submits checkout with variant color/size; asserts Order created with `status = 'pending'` and OrderItems store variant attributes.
2. `test_f3_02_buyer_can_apply_valid_voucher_during_checkout`: Buyer applies discount voucher; asserts order subtotal is discounted and voucher record linked.
3. `test_f3_03_seller_can_view_incoming_pending_order_in_cockpit`: Seller views `/seller/orders`; asserts pending order appears with buyer address and item details.
4. `test_f3_04_seller_can_approve_and_transition_order_to_packaging`: Seller posts `orders.pack`; asserts order transitions to `status = 'packaging'`.
5. `test_f3_05_seller_can_mark_order_ready_for_pickup_generating_waybill`: Seller posts `orders.ready`; asserts order transitions to `ready_for_pickup` and Delivery is created with `status = 'unassigned'` and tracking number.

#### Feature 4: Courier Dispatch Board & Live Buyer Tracking (`Tier1/F4_CourierDispatchTrackingTest.php`)
1. `test_f4_01_unassigned_ready_order_appears_on_courier_dispatch_board`: Courier visits `/courier/deliveries`; asserts unassigned ready-for-pickup delivery is visible in available jobs.
2. `test_f4_02_courier_can_claim_available_delivery_job_fcfs`: Courier posts claim; asserts `delivery.courier_id = courier->id`, `delivery.status = 'assigned'`, and `assigned_at` is set.
3. `test_f4_03_courier_can_confirm_pickup_and_transition_to_in_transit`: Courier marks picked up; asserts `delivery.status = 'picked_up'`, `order.status = 'shipped'` / `picked_up`, and `picked_up_at` set.
4. `test_f4_04_courier_can_transition_delivery_to_out_for_delivery`: Courier updates status; asserts `delivery.status = 'out_for_delivery'`.
5. `test_f4_05_courier_can_complete_delivery_with_proof_photo_updating_buyer_tracking`: Courier submits proof photo and completes delivery; asserts `delivery.status = 'delivered'`, `order.status = 'delivered'`, `order.payment_status = 'paid'`, and buyer tracking page reflects delivered milestone.

#### Feature 5: Logistics Sorting Hub & Barcode Scan Checkpoints (`Tier1/F5_LogisticsHubCheckpointTest.php`)
1. `test_f5_01_logistics_operator_can_access_dedicated_hub_workstation`: Logistics role user accesses `/hub`; asserts 200 OK with workstation stats and scanner interface.
2. `test_f5_02_seller_dispatch_scan_logs_packaging_release_checkpoint`: Pack action creates `seller_pack` checkpoint in `delivery_checkpoints` table.
3. `test_f5_03_courier_store_pickup_scan_logs_pickup_checkpoint`: Courier pickup scan creates `courier_pickup` checkpoint with barcode and store location.
4. `test_f5_04_hub_intake_scan_assigns_barangay_sorting_bin_checkpoint`: Hub operator scans tracking barcode; creates `hub_intake` / `barangay_sort` checkpoint with destination routing bin.
5. `test_f5_05_doorstep_scan_logs_final_handover_checkpoint_with_proof`: Doorstep drop-off logs `doorstep_handover` checkpoint with proof image and timestamp.

#### Feature 6: 10% Platform Commission & Financial Split Ledger (`Tier1/F6_CommissionLedgerTest.php`)
1. `test_f6_01_order_delivery_completion_triggers_atomic_commission_ledger_creation`: Marking order delivered creates `commission_ledgers` record within database transaction.
2. `test_f6_02_commission_ledger_credits_90_percent_to_seller`: Asserts `commission_ledger.seller_amount == order.subtotal * 0.90`.
3. `test_f6_03_commission_ledger_credits_10_percent_to_platform_treasury`: Asserts `commission_ledger.platform_commission == order.subtotal * 0.10`.
4. `test_f6_04_commission_ledger_credits_standard_delivery_fee_to_courier`: Asserts `commission_ledger.delivery_fee == 60.00` attributed to `courier_id`.
5. `test_f6_05_seller_and_courier_earnings_views_reflect_settled_ledger_records`: Seller reports (`/seller/reports`) and Courier earnings (`/courier/earnings`) match the ledger sums.

#### Feature 7: Interactive "Fast-Forward" Order Progression Simulator (`Tier1/F7_OrderSimulatorTest.php`)
1. `test_f7_01_simulator_advance_endpoint_progresses_order_from_pending_to_packaging`: Posts `/simulator/orders/{order}/advance`; asserts order advances to `packaging`.
2. `test_f7_02_simulator_advance_endpoint_progresses_order_to_ready_for_pickup`: Posts advance again; asserts order advances to `ready_for_pickup` and unassigned delivery generated.
3. `test_f7_03_simulator_advance_endpoint_auto_assigns_courier_and_progresses_to_picked_up`: Posts advance; auto-assigns demo courier and advances to `picked_up`.
4. `test_f7_04_simulator_advance_endpoint_progresses_through_in_transit_to_out_for_delivery`: Posts advance; steps through `in_transit` and `out_for_delivery`.
5. `test_f7_05_simulator_advance_endpoint_delivers_order_and_executes_commission_split`: Posts advance to `delivered`; asserts status is `delivered` and commission ledger created.

---

### 6.2 Tier 2: Boundary & Corner Cases (35 Tests, >=5 per feature)

#### Feature 1 Boundary: KYC Validation & File Handling (`Tier2/B1_KycBoundaryTest.php`)
1. `test_b1_01_registration_fails_when_required_kyc_documents_are_missing`: Submitting seller/courier registration with empty document fields returns 422 validation errors.
2. `test_b1_02_registration_rejects_disallowed_file_types_and_oversized_payloads`: Uploading `.exe` / `.sh` files or files exceeding max upload size returns validation error.
3. `test_b1_03_courier_registration_fails_with_incomplete_vehicle_or_plate_details`: Missing plate number or vehicle type fails validation.
4. `test_b1_04_duplicate_email_registration_fails_cleanly_without_orphaned_kyc_records`: Attempting to register existing email fails without creating orphaned documents.
5. `test_b1_05_malformed_phone_number_and_postal_codes_are_rejected_at_validation`: Tests edge validation on phone and postal code inputs.

#### Feature 2 Boundary: Auth Gate Security & Bypass Prevention (`Tier2/B2_AuthGateSecurityTest.php`)
1. `test_b2_01_unapproved_user_attempting_direct_dashboard_url_is_blocked_and_redirected`: Unapproved user issuing GET to `/seller/dashboard`, `/courier/deliveries`, or `/admin/dashboard` is intercepted and redirected.
2. `test_b2_02_rejected_user_cannot_access_transactional_actions_and_sees_rejection_reason`: Rejected user is blocked from placing orders, claiming deliveries, or packing orders.
3. `test_b2_03_non_admin_user_cannot_access_admin_kyc_approval_endpoints_403`: Buyer/Seller/Courier attempting POST to `/admin/kyc/{user}/approve` receives 403 Forbidden.
4. `test_b2_04_suspended_or_inactive_user_cannot_authenticate_or_advance_orders`: Suspended user credentials cannot authenticate or execute state transitions.
5. `test_b2_05_csrf_and_unauthenticated_requests_to_kyc_endpoints_are_rejected`: Unauthenticated API requests receive 401/302 redirect.

#### Feature 3 Boundary: Order Checkout Edge Cases & IDOR (`Tier2/B3_OrderCheckoutBoundaryTest.php`)
1. `test_b3_01_checkout_fails_when_product_stock_is_insufficient`: Attempting to order quantity > available stock returns 422 error and does not mutate inventory.
2. `test_b3_02_checkout_fails_when_voucher_min_spend_is_not_met_or_voucher_expired`: Applying expired voucher or voucher under min spend threshold fails validation.
3. `test_b3_03_checkout_rejects_zero_or_negative_quantity_and_tampered_unit_prices`: Tampered payloads with negative quantities or spoofed prices are rejected.
4. `test_b3_04_seller_cannot_pack_or_ready_another_merchants_order_idor_check`: Seller A attempting to pack Seller B's order receives 403 Forbidden.
5. `test_b3_05_order_cannot_be_transitioned_to_packaging_from_invalid_states_e_g_cancelled`: Attempting to pack a cancelled order returns 400/422 Bad Request.

#### Feature 4 Boundary: Courier Dispatch Race Conditions & State Invariants (`Tier2/B4_CourierDispatchRaceConditionTest.php`)
1. `test_b4_01_second_courier_claiming_already_claimed_delivery_is_rejected_gracefully`: Courier B attempting to claim a delivery already claimed by Courier A receives error message.
2. `test_b4_02_courier_cannot_update_delivery_status_of_an_unassigned_or_other_couriers_order`: Courier B cannot update status of Courier A's assigned delivery (403/422).
3. `test_b4_03_courier_cannot_jump_from_assigned_directly_to_delivered_skipping_pickup`: Direct status jump from `assigned` to `delivered` is rejected by state machine.
4. `test_b4_04_delivery_completion_without_proof_image_fails_validation_or_uses_verified_fallback`: Verifies proof image requirement on final doorstep delivery.
5. `test_b4_05_inactive_or_off_duty_courier_cannot_claim_new_delivery_jobs`: Courier with `is_available = false` or `status != 'active'` cannot claim jobs.

#### Feature 5 Boundary: Logistics Checkpoint Validation (`Tier2/B5_LogisticsCheckpointValidationTest.php`)
1. `test_b5_01_hub_scan_fails_when_tracking_barcode_does_not_exist`: Scanning an unknown barcode string returns 404/422 with error message.
2. `test_b5_02_hub_scan_rejects_packages_not_yet_picked_up_by_courier`: Attempting hub scan on `unassigned` or `packaging` order returns invalid state error.
3. `test_b5_03_duplicate_hub_scans_do_not_corrupt_delivery_state_or_create_duplicate_checkpoints`: Scanning same parcel multiple times at same hub is handled idempotently.
4. `test_b5_04_non_logistics_and_non_admin_users_cannot_access_hub_intake_workstation`: Buyers and Sellers are forbidden from accessing `/hub` (403).
5. `test_b5_05_supervisor_override_with_invalid_courier_id_or_status_returns_validation_error`: Invalid courier ID or bogus status in admin override is rejected.

#### Feature 6 Boundary: Financial Ledger Idempotency & Precision (`Tier2/B6_CommissionLedgerIdempotencyTest.php`)
1. `test_b6_01_duplicate_delivered_triggers_do_not_create_duplicate_commission_ledger_entries`: Triggering delivery completion twice produces exactly one ledger record.
2. `test_b6_02_order_with_100_percent_discount_calculates_commission_without_division_by_zero`: Free order with 100% voucher calculates ₱0 commission and handles delivery fee cleanly.
3. `test_b6_03_fractional_centavo_rounding_maintains_exact_double_entry_balance`: Order of ₱199.99 splits cleanly (90% = ₱179.99, 10% = ₱20.00) without losing centavos.
4. `test_b6_04_cancelled_order_never_generates_positive_commission_ledger_records`: Cancelled order creates no credit ledger records.
5. `test_b6_05_unauthorized_user_cannot_tamper_with_or_directly_post_to_commission_ledger`: Direct POST to ledger tables by non-system actors is rejected.

#### Feature 7 Boundary: Simulator State Constraints (`Tier2/B7_SimulatorBoundaryTest.php`)
1. `test_b7_01_simulator_advance_on_already_delivered_order_returns_safe_noop`: Advancing an already `delivered` order returns message and does not duplicate actions.
2. `test_b7_02_simulator_advance_on_cancelled_order_returns_error`: Advancing a cancelled order is rejected with 400 error.
3. `test_b7_03_simulator_reset_endpoint_reverts_order_to_pending_and_delivery_to_unassigned`: Simulator reset endpoint resets order to `pending` and delivery to `unassigned`.
4. `test_b7_04_simulator_endpoints_reject_invalid_or_non_existent_order_ids`: Invalid order ID returns 404.
5. `test_b7_05_unauthenticated_request_to_simulator_endpoint_is_blocked`: Unauthenticated simulator request is rejected with 401/302.

---

### 6.3 Tier 3: Cross-Feature Interactions & Pairwise Integration (7 Tests, `Tier3/CrossFeaturePairwiseTest.php`)

1. `test_t3_01_kyc_approval_to_seller_fulfillment_pipeline`:
   - Integrates **F2 (KYC Approval)** + **F3 (Order Checkout & Packaging)**.
   - Seller registers -> Admin approves KYC -> Seller accesses cockpit -> Seller receives buyer order -> Seller approves packaging.
2. `test_t3_02_seller_packaging_release_to_courier_dispatch_broadcast`:
   - Integrates **F3 (Order Packaging)** + **F4 (Courier Dispatch Board)**.
   - Seller marks order ready for pickup -> Delivery broadcasts to Courier Dispatch Board with `status = 'unassigned'` -> Courier claims job.
3. `test_t3_03_courier_pickup_scan_to_logistics_hub_barangay_sorting`:
   - Integrates **F4 (Courier Dispatch)** + **F5 (Logistics Hub Checkpoints)**.
   - Courier picks up parcel at store (scans barcode) -> Logistics hub receives parcel, scans barcode intake, and logs barangay sorting bin.
4. `test_t3_04_courier_doorstep_delivery_to_commission_distribution`:
   - Integrates **F4 (Courier Delivery)** + **F6 (Commission Ledger)**.
   - Courier marks delivery complete with photo proof -> System atomically writes 90% seller credit, 10% platform treasury, and ₱60 courier fee.
5. `test_t3_05_fast_forward_progression_syncs_buyer_timeline_and_checkpoint_trail`:
   - Integrates **F7 (Simulator)** + **F3/F4/F5 (Buyer Timeline & Checkpoints)**.
   - Fast-Forward steps order through all stages -> Buyer tracking endpoint verifies each milestone matches simulated step and checkpoints are logged.
6. `test_t3_06_voucher_discounted_checkout_propagates_to_split_ledger`:
   - Integrates **F3 (Voucher Checkout)** + **F6 (Commission Split)**.
   - Buyer uses voucher -> Net subtotal correctly flows through to commission calculations upon delivery.
7. `test_t3_07_logistics_hub_reassignment_updates_courier_dispatch_and_audit_trail`:
   - Integrates **F5 (Logistics Hub Override)** + **F4 (Courier Board)** + **Audit Trail**.
   - Admin supervisor reassigns delivery to different courier -> New courier sees job in active deliveries; previous courier loses write access; checkpoint audit logs reassignment.

---

### 6.4 Tier 4: Real-World Workload Scenarios (5 Tests, `Tier4/RealWorldWorkloadTest.php`)

1. `test_t4_01_complete_metro_manila_multi_role_e2e_order_lifecycle`:
   - Full 5-role end-to-end choreography:
     * Step 1: Buyer registers -> Admin approves KYC in verification queue.
     * Step 2: Buyer adds 2 variant items to bag, applies voucher, and places order via COD.
     * Step 3: Seller reviews order in Cockpit, approves & packs, prints thermal waybill, schedules pickup.
     * Step 4: Courier checks Dispatch Board, claims FCFS unassigned delivery job.
     * Step 5: Courier visits merchant store, verifies barcode scan, transitions to `picked_up`.
     * Step 6: Central Logistics Hub scans intake, classifies parcel into Barangay San Antonio sorting bin, transitions to `in_transit`.
     * Step 7: Courier transitions to `out_for_delivery`, reaches doorstep, captures proof photo, settles COD payment, transitions to `delivered`.
     * Step 8: Commission ledger executes atomic split (90% Seller, 10% Treasury, ₱60 Courier).
     * Step 9: Buyer verifies live tracking timeline with all 5 milestones marked completed.
2. `test_t4_02_multi_seller_cart_independent_fulfillment_and_settlement`:
   - Buyer orders from 2 different merchant shops simultaneously.
   - System manages separate fulfillment pipelines: Shop A packs first, Courier 1 picks up; Shop B packs later, Courier 2 picks up.
   - Both orders complete independently with isolated commission ledger records.
3. `test_t4_03_courier_delivery_failure_exception_and_hub_rerouting`:
   - Courier picks up parcel -> Attempts delivery -> Recipient unreachable -> Marks delivery attempt failed.
   - Hub receives returned parcel, logs exception checkpoint, and reassigns to new morning courier route.
   - Second courier successfully delivers with proof photo -> Financial split settles cleanly.
4. `test_t4_04_rapid_fast_forward_simulator_stress_and_state_sync`:
   - 5 distinct orders simultaneously fast-forwarded through all 7 stages.
   - Asserts zero deadlocks, zero race condition anomalies, clean checkpoint sequences, and exact ledger balances across all 5 orders.
5. `test_t4_05_kyc_rejection_feedback_resubmission_and_first_sale_workflow`:
   - Seller registers with invalid/blurry document -> Admin rejects with feedback.
   - Seller logs in, sees rejection notice and reason, uploads valid documents.
   - Admin inspects and approves -> Seller lists product -> Buyer purchases -> Merchant fulfills first order.

---

## 7. Draft Content for `TEST_INFRA.md`

Below is the complete specification content ready to be published to `/home/andy/Projects/bagoo/TEST_INFRA.md`:

```markdown
# BagooPH End-to-End (E2E) Test Infrastructure Specification

## 1. Overview
This document defines the architecture, test helpers, tier decomposition, execution instructions, and validation standards for the BagooPH automated End-to-End (E2E) test suite.

The test suite exercises full multi-role data interconnectedness across all 5 user roles:
- `buyer` (Marketplace shopping, variant selection, checkout, live tracking)
- `seller` (Seller Cockpit, packaging approval, waybill printing, earnings ledger)
- `courier` (Dispatch board, FCFS job claiming, barcode pickup, transit updates, proof of delivery, rider earnings)
- `logistics` (Central Hub workstation `/hub`, incoming barcode scan, barangay sorting)
- `admin` (KYC verification queue, approve/reject governance, Commission Treasury ledger)

## 2. Test Suite Organization

Directory: `tests/Feature/E2E/`

### 2.1 Support Traits & Helpers (`tests/Feature/E2E/Support/`)
- `InteractsWithRoles.php`: Role authentication helpers (`actingAsBuyer`, `actingAsSeller`, `actingAsCourier`, `actingAsLogistics`, `actingAsAdmin`, `createApprovedUser`, `createPendingUser`, `createRejectedUser`).
- `CreatesE2EOrders.php`: Fixture helpers (`createE2EShop`, `createE2EProduct`, `createE2EOrder`, `createE2EDelivery`, `createE2EVoucher`).
- `SimulatesOrderLifecycle.php`: Simulator helpers (`advanceOrderStage`, `resetOrderStage`, `assertOrderStage`, `fastForwardToDelivered`).
- `AssertsDeliveryCheckpoints.php`: Audit trail assertions (`assertCheckpointLogged`, `assertCheckpointSequence`, `assertBarcodeScanned`).
- `AssertsCommissionLedgers.php`: Financial ledger assertions (`assertCommissionSplit`, `assertLedgerIdempotent`).

### 2.2 Test Tiers & File Matrix

| Tier | Directory | Test Files | Test Count | Description |
|---|---|---|---|---|
| **Tier 1** | `Tier1/` | `F1_KycRegistrationTest.php`<br>`F2_KycApprovalGateTest.php`<br>`F3_OrderCheckoutPackagingTest.php`<br>`F4_CourierDispatchTrackingTest.php`<br>`F5_LogisticsHubCheckpointTest.php`<br>`F6_CommissionLedgerTest.php`<br>`F7_OrderSimulatorTest.php` | **35 tests** (5 per feature) | Core feature coverage across all 7 features in the inventory. |
| **Tier 2** | `Tier2/` | `B1_KycBoundaryTest.php`<br>`B2_AuthGateSecurityTest.php`<br>`B3_OrderCheckoutBoundaryTest.php`<br>`B4_CourierDispatchRaceConditionTest.php`<br>`B5_LogisticsCheckpointValidationTest.php`<br>`B6_CommissionLedgerIdempotencyTest.php`<br>`B7_SimulatorBoundaryTest.php` | **35 tests** (5 per feature) | Boundary values, negative validation, security gates, race conditions, IDOR checks, idempotency. |
| **Tier 3** | `Tier3/` | `CrossFeaturePairwiseTest.php` | **7 tests** | Cross-feature handoffs and pairwise pipeline integration. |
| **Tier 4** | `Tier4/` | `RealWorldWorkloadTest.php` | **5 tests** | Real-world multi-role end-to-end user workflows and exception scenarios. |
| **Total** | | | **82 tests** | Comprehensive requirement-driven opaque-box E2E suite. |

## 3. Test Execution Instructions

### 3.1 Running the Entire Test Suite
```bash
php artisan test --do-not-cache-result
# or:
./vendor/bin/phpunit --do-not-cache-result
```

### 3.2 Running by Tier
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

### 3.3 Running Specific Feature Tests
```bash
php artisan test --filter F1_KycRegistrationTest --do-not-cache-result
php artisan test --filter F3_OrderCheckoutPackagingTest --do-not-cache-result
php artisan test --filter RealWorldWorkloadTest --do-not-cache-result
```

## 4. Acceptance Standards
- **Zero Failures**: 100% of all 82 tests must pass.
- **Database Isolation**: Tests must use `RefreshDatabase` and SQLite in-memory without cross-test leakage.
- **Strict Financial Verification**: Commission ledger assertions must check exact 90% / 10% / ₱60 distribution.
- **Strict Role Isolation**: Gating and IDOR boundary tests must assert 403 Forbidden or redirect where appropriate.
```

---

## 8. Recommendations & Next Steps

1. **Test Infrastructure Milestone (`T-INFRA`)**:
   - Create `tests/Feature/E2E/Support/` with the 5 shared trait files (`InteractsWithRoles.php`, `CreatesE2EOrders.php`, `SimulatesOrderLifecycle.php`, `AssertsDeliveryCheckpoints.php`, `AssertsCommissionLedgers.php`).
   - Create dedicated factories in `database/factories/` (`OrderFactory`, `DeliveryFactory`, `ShopFactory`, `ProductFactory`, `CourierProfileFactory`, `VoucherFactory`, `DeliveryCheckpointFactory`, `CommissionLedgerFactory`).
2. **Tier 1 & Tier 2 Milestone Execution (`T1` & `T2`)**:
   - Implement the 7 Tier 1 test files (35 tests).
   - Implement the 7 Tier 2 test files (35 tests).
3. **Tier 3 & Tier 4 Milestone Execution (`T3` & `T4`)**:
   - Implement `CrossFeaturePairwiseTest.php` (7 tests).
   - Implement `RealWorldWorkloadTest.php` (5 tests).
4. **Execution & Reporting**:
   - Run `php artisan test tests/Feature/E2E --do-not-cache-result` and verify all 82 tests pass.
   - Publish `TEST_READY.md`.
