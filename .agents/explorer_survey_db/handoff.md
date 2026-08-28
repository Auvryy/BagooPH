# Phase 0 Technical Survey Report: Database, API, & Business Logic

**Investigator:** Teamwork Explorer Subagent (`explorer_survey_db`)  
**Target Platform:** BagooPH (Laravel 11/12 + Inertia.js React + PostgreSQL)  
**Date:** 2026-08-27  

---

## 1. Observation

A complete investigation across the database schemas, Eloquent models, HTTP controllers, middleware, routing, seeders, and documentation was conducted. Below are the verified direct observations:

### 1.1 User Models, Auth, KYC Documents, & Status Gating
- **User Migration & Schema (`database/migrations/0001_01_01_000000_create_users_table.php`):**
  - Lines 14–29 define the `users` table:
    ```php
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('role')->default('buyer')->index();
        $table->string('phone')->nullable();
        $table->string('avatar')->nullable();
        $table->string('address')->nullable();
        $table->string('city')->nullable();
        $table->string('postal_code')->nullable();
        $table->string('status')->default('active');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    ```
  - **Missing fields:** `first_name`, `last_name`, `middle_initial`, `sex`, `birthday`, `age`, `province`, `municipality`, `barangay`, `id_document_path`, `rejection_reason`.
  - **Default status violation:** Line 24 defaults `status` to `'active'`, whereas `ORIGINAL_REQUEST.md` (R4) requires newly registered users to default to `pending_approval`.

- **Shop / Merchant Table (`database/migrations/2026_01_01_000001_create_shops_table.php`):**
  - Lines 11–25 define `shops` with `user_id`, `name`, `slug`, `description`, `logo`, `banner`, `phone`, `address`, `city`, `rating`, `status`.
  - **Missing fields:** `category_id` (registered line of business) and `business_permit_path` (uploaded business permit / DTI permit).

- **Courier Profile Table:**
  - There is NO migration or table for `courier_profiles`.
  - In `app/Http/Controllers/Courier/CourierDeliveryController.php` lines 212–221, vehicle and license info is completely hardcoded mock data:
    ```php
    'fleetData' => [
        'vehicle_type' => 'Motorcycle (Express Dispatch)',
        'plate_number' => 'NCS-8892',
        'license_number' => 'N02-18-092831',
        'license_status' => 'Verified (Class A/A1/B)',
        'or_cr_status' => 'Valid & Registered',
        'zone' => 'Metro Manila & Rizal Corridor',
        'completed_deliveries' => $completedCount,
        'rating' => 4.95,
    ]
    ```

- **Registration Processing (`app/Http/Controllers/Auth/RegisteredUserController.php`):**
  - Lines 50–56 validate only `name`, `email`, `role`, `shop_name`, `password`.
  - Lines 60–65 create the `User` record with default status (`active`). No file uploads are handled for `id_document_path`, `business_permit_path`, `license_path`, or `or_cr_path`.
  - Line 79 immediately calls `Auth::login($user)` and lines 81–86 redirect straight to the user dashboard without any Admin KYC approval check.

- **Role Gating & Status Access Middleware (`app/Http/Middleware/RoleMiddleware.php`):**
  - Lines 17–36 check authentication and role matching:
    ```php
    $userRole = $request->user()->role;
    if ($userRole === 'admin') {
        return $next($request);
    }
    if (! in_array($userRole, $roles, true)) {
        abort(403, 'Unauthorized access for your account role (' . $userRole . ').');
    }
    return $next($request);
    ```
  - **No status verification:** The middleware never checks `$request->user()->status`. A user with `pending_approval`, `suspended`, or `rejected` status can access all endpoints.
  - `app/Http/Requests/Auth/LoginRequest.php` line 45 authenticates credentials with `Auth::attempt(...)` without verifying whether the user is approved or rejected.

- **Admin User Management (`app/Http/Controllers/Admin/AdminDashboardController.php` & `resources/js/Pages/Admin/Users.tsx`):**
  - `AdminDashboardController::users` only loads paginated users with `User::with('shop')->latest()`.
  - `AdminDashboardController::updateUserRole` (lines 74–84) allows changing `role` and `status` between `active`, `pending`, `suspended`.
  - The UI does not show submitted KYC document previews (Government ID, Business Permit, Driver's License, OR/CR) and has no one-click `Approve` / `Reject (with reason)` workflow.

---

### 1.2 Unified Order Lifecycle & Status Progression
- **Order Status Enum (`app/Enums/OrderStatus.php`):**
  - Lines 7–12 define:
    ```php
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    ```
  - **Discrepancy:** `ORIGINAL_REQUEST.md` (R1 & R2) mandates the standard sequence:
    `pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`.
  - Currently, `processing` is used instead of `packaging`, and `shipped` collapses `picked_up`, `in_transit`, and `out_for_delivery` at the `Order` level.

- **Delivery Status Enum (`app/Enums/DeliveryStatus.php`):**
  - Lines 7–13 define:
    ```php
    case UNASSIGNED = 'unassigned';
    case ASSIGNED = 'assigned';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    ```

- **Bug in Delivery Creation Field Mapping:**
  - In `app/Http/Controllers/Buyer/CheckoutController.php` line 165:
    `'recipient_phone' => $validated['recipient_phone']`
  - In `app/Http/Controllers/Seller/SellerOrderController.php` line 100:
    `'recipient_phone' => $order->recipient_phone ...`
  - In `database/migrations/2026_01_01_000007_create_deliveries_table.php` line 26:
    `$table->string('delivery_phone');`
  - In `app/Models/Delivery.php` lines 14–31:
    `$fillable` contains `'delivery_phone'`, NOT `'recipient_phone'`.
  - **Impact:** Passing `'recipient_phone'` causes `delivery_phone` to be ignored or null, failing strict database constraint checks or leaving recipient contact empty on deliveries.

- **Product Variations / SKU Variants:**
  - `products` table (`database/migrations/2026_01_01_000003_create_products_table.php`) has a single `sku`, `price`, and `stock` column.
  - There is no `product_variations` table or structured JSON column on `Product` / `OrderItem` for multi-option selection (color, size) as outlined in `docs/SCHEMA.md`.

---

### 1.3 Barcode / Location Scanning Checkpoints & Logistics Hub
- **Delivery Checkpoints & Location Scanning Logs:**
  - There is NO `delivery_checkpoints`, `location_logs`, or `scan_logs` table in `database/migrations/`.
  - In `app/Http/Controllers/Courier/CourierDeliveryController.php` lines 79–123 (`updateStatus`), statuses are updated in-place on the `deliveries` row without persisting an audit trail of timestamped location logs, hub receipt scans, or rider GPS checkpoints.
  - In `resources/js/Pages/Buyer/OrderDetail.tsx` lines 84–89, milestone tracking is rendered from static boolean checks against `order.status` and `delivery.status` rather than dynamic checkpoint history.

---

### 1.4 Interactive "Fast-Forward" Order Delivery Simulator
- **Investigation Result:**
  - Search for `simulate`, `fast-forward`, `fast_forward` across `app/` and `routes/` returned zero matching controllers, routes, or methods.
  - No UI component or API endpoint currently exists to fast-forward an order through `pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`.

---

### 1.5 Financial Ledger Models, 10% Commission, & Split Ledgers
- **Database Tables:**
  - Search for `commission`, `ledger`, `treasury`, `balance` in `database/migrations/` returned zero results.
  - There is NO `commissions` table, NO `financial_ledgers` table, and NO `seller_balances` / `courier_balances` table.

- **Ad-Hoc / In-Memory Mock Calculations in Controllers:**
  1. `app/Http/Controllers/Seller/SellerDashboardController.php` lines 123–124:
     ```php
     $platformCommission = $grossSales * 0.10; // 10% platform fee
     $netPayout = $grossSales - $platformCommission;
     ```
  2. `app/Http/Controllers/Courier/CourierDeliveryController.php` lines 48 & 136:
     ```php
     $totalEarned = $completedDeliveries->count() * 60;
     ```
  3. `app/Http/Controllers/Admin/LogisticsHubController.php` lines 61–63:
     ```php
     $totalShippingRevenue = $totalDeliveries * 60;
     $courierPayouts = $deliveredCount * 48; // 80% split
     $hubMaintenanceFee = $deliveredCount * 12; // 20% split
     ```
  - None of these transactions are stored in an immutable double-entry or credit/debit ledger database table.
  - Completing a delivery in `CourierDeliveryController::updateStatus` (lines 107–118) only updates `deliveries.status = 'delivered'` and `orders.status = 'delivered'`, without generating commission splits or crediting seller/courier/treasury ledger records.

---

### 1.6 Disputes Mocking
- `app/Http/Controllers/Buyer/BuyerDisputeController.php` lines 19–39: `$disputes` is hardcoded as an in-memory PHP array.
- `app/Http/Controllers/Seller/SellerDisputeController.php` lines 20–50: `$disputes` is hardcoded as an in-memory PHP array.
- `BuyerDisputeController::store` and `SellerDisputeController::respond` do not write to any database table.

---

## 2. Logic Chain

1. **Premise 1 (R4 Compliance):** `ORIGINAL_REQUEST.md` R4 requires that all newly registered users default to `pending_approval`, must submit identity/permit documents, cannot access role dashboards until approved by Admin, and Admin can view submitted documents to Approve or Reject with feedback.
   - *Observation:* `users.status` defaults to `'active'`, registration does not accept document uploads, `RegisteredUserController` logs in users immediately, `RoleMiddleware` and `LoginRequest` do not check status, and Admin UI lacks document inspection and one-click approve/reject.
   - *Inference:* New users currently bypass KYC entirely, creating an open access vulnerability and failing R4.

2. **Premise 2 (R1 & R2 Compliance):** `ORIGINAL_REQUEST.md` R1 and R2 require a 7-stage unified lifecycle (`pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`) with a Fast-Forward simulation control.
   - *Observation:* `OrderStatus` only contains `pending, processing, ready_for_pickup, shipped, delivered, cancelled`. There is no Fast-Forward route, controller, or UI control. `CheckoutController` and `SellerOrderController` have a field mismatch (`recipient_phone` instead of `delivery_phone`).
   - *Inference:* Order progression is disjointed across models and cannot be tested or fast-forwarded across roles as required by R1 and R2.

3. **Premise 3 (R3 Compliance):** `ORIGINAL_REQUEST.md` R3 requires tactile status update checkpoints representing physical package scans and handovers (packaging release, rider pickup barcode scan, hub sorting scan, doorstep drop-off).
   - *Observation:* No checkpoint or location scan logs table exists; courier and seller controllers mutate status in place without recording scan history or hub checkpoints.
   - *Inference:* Tracking history is purely simulated with static frontend steps rather than database-backed scanning events.

4. **Premise 4 (R5 Compliance):** `ORIGINAL_REQUEST.md` R5 requires atomic revenue distribution upon order completion (90% to Seller, 10% to Platform Commission Treasury, ₱50–₱60 Delivery Fee to Courier Rider ledger).
   - *Observation:* No commission or ledger migration/model exists. Seller reports, courier earnings, and logistics revenue are calculated on-the-fly using multiplication in controllers. Delivery completion does not trigger any financial record creation.
   - *Inference:* Platform commission and earnings balances are ephemeral and non-reconcilable.

---

## 3. Caveats

- **External Integrations:** In accordance with `GEMINI.md` Rule 72 ("No Paid / External APIs"), payment processing, SMS notifications, and mapping telemetry are intended to use local database simulation rather than external APIs (e.g. Stripe, Twilio, Google Maps).
- **Frontend Inertia Components:** The frontend React components already have rich visual layouts for Waybills, Order Details, Courier Deliveries, and Admin dashboards, but many forms and action handlers currently submit to endpoints that lack complete database backing or require additional props.
- **Database Engine:** The codebase supports PostgreSQL 16 (in Docker) and SQLite (for local development/testing). All schema migrations must remain fully compatible with both PostgreSQL and SQLite.

---

## 4. Conclusion & Actionable Implementation Blueprint

To fully satisfy all requirements in `ORIGINAL_REQUEST.md` (R1 through R5), the following database schemas, backend logic, and API endpoints must be implemented:

### Summary of Existing vs Required Assets

| Component / Requirement | Current State | Required Implementation / Fix |
|---|---|---|
| **KYC Fields & Status** (`users`, `shops`, `courier_profiles`) | Basic fields only, `status` defaults to `active`, no `courier_profiles` table | Add migration for KYC columns (`first_name`, `last_name`, `sex`, `birthday`, `age`, `province`, `municipality`, `barangay`, `id_document_path`, `rejection_reason`), `shops.category_id`, `shops.business_permit_path`, create `courier_profiles` table (`vehicle_type`, `plate_number`, `or_cr_path`, `license_path`, `is_available`). Set default `status = 'pending_approval'`. |
| **Auth & KYC Gating** (`RegisteredUserController`, `RoleMiddleware`, `LoginRequest`) | Auto-login on register, no status check in middleware or login | Support document uploads on register, redirect to `/pending-approval` notice, enforce status check in `RoleMiddleware` and `LoginRequest` (block unapproved users with appropriate messaging). |
| **Admin KYC Queue** (`AdminDashboardController`, `Admin/Users.tsx`) | Simple role/status dropdown | Add KYC verification tab/modal in Admin displaying uploaded Government ID, Business Permit, License, and OR/CR with `Approve` and `Reject (with reason)` actions. |
| **Order Status Lifecyle** (`OrderStatus`, `Order`, `Delivery`) | Uses `processing` & `shipped`, field bug on `delivery_phone` | Align `OrderStatus` and `DeliveryStatus` to standard 7-stage sequence: `pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`. Fix `recipient_phone` ➔ `delivery_phone` in checkout/seller controllers. |
| **Fast-Forward Simulator** (R2) | Completely missing | Create `OrderSimulationController` with route `POST /orders/{order}/fast-forward` (or `/api/simulate/order/{order}/advance`) that advances order & delivery to the next stage atomically, updates scanning checkpoints, and executes financial split upon reaching `delivered`. Embed interactive simulation widget on Order Detail, Seller Orders, and Courier Deliveries. |
| **Scanning Checkpoints** (R3) | Missing database table | Create `delivery_checkpoints` migration & model (`delivery_id`, `status`, `checkpoint_type`, `location_name`, `city`, `barcode_scanned`, `notes`, `scanned_by_id`, `created_at`). Record checkpoint events upon pack, pickup, hub scan, and doorstep delivery. |
| **Financial Split Ledgers** (R5) | Missing database tables, hardcoded in views | Create `commissions` and `financial_ledgers` / `wallet_ledgers` migrations and models (`account_type` [seller, courier, platform_treasury], `user_id`, `shop_id`, `order_id`, `type` [order_earning, platform_commission, delivery_fee, cod_collection], `amount`, `balance_after`, `description`). Trigger atomic distribution inside a database transaction when order is marked `delivered`. |
| **Disputes Engine** | Hardcoded array in controllers | Create `disputes` migration & model (`order_id`, `buyer_id`, `shop_id`, `reason`, `description`, `claim_type`, `proof_image`, `status`, `seller_response`, `admin_notes`) to make dispute submission and resolution fully database-backed. |

---

## 5. Verification Method

To independently verify these findings:

1. **Verify Database Schemas & Missing Columns:**
   - Inspect `database/migrations/0001_01_01_000000_create_users_table.php` (line 24 confirms `default('active')`, lacks KYC document paths).
   - Inspect `database/migrations/2026_01_01_000001_create_shops_table.php` (lacks `category_id` and `business_permit_path`).
   - Check directory `database/migrations/` (confirms absence of `courier_profiles`, `commissions`, `financial_ledgers`, `delivery_checkpoints`, and `disputes`).

2. **Verify Field Mismatch Bug:**
   - Inspect `app/Http/Controllers/Buyer/CheckoutController.php` line 165 and `app/Http/Controllers/Seller/SellerOrderController.php` line 100 (both pass `'recipient_phone'` instead of `'delivery_phone'`).
   - Inspect `app/Models/Delivery.php` line 23 (confirms `$fillable` expects `'delivery_phone'`).

3. **Verify Auth & Role Middleware Gap:**
   - Inspect `app/Http/Middleware/RoleMiddleware.php` lines 17–36 (confirms zero status checks).
   - Inspect `app/Http/Controllers/Auth/RegisteredUserController.php` lines 60–86 (confirms instant login and redirect without KYC verification).

4. **Verify Ad-Hoc Financial Calculations:**
   - Inspect `app/Http/Controllers/Seller/SellerDashboardController.php` lines 123–124 (`$platformCommission = $grossSales * 0.10;`).
   - Inspect `app/Http/Controllers/Courier/CourierDeliveryController.php` lines 48 & 136 (`$totalEarned = $completedDeliveries->count() * 60;`).
   - Inspect `app/Http/Controllers/Admin/LogisticsHubController.php` lines 61–63 (`$totalShippingRevenue = $totalDeliveries * 60;`).

5. **Verify Simulator Absence:**
   - Run grep for `fast-forward` or check `routes/web.php` lines 1–165 (confirms no simulator routes).
