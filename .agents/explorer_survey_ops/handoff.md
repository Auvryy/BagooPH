# Phase 0 Technical Survey & Specification Mining Report: Simulator, Checkpoints, & Test Infrastructure

**Agent**: `explorer_survey_ops` (Specification Miner & Ops Subagent)  
**Date**: 2026-08-27  
**Working Directory**: `/home/andy/Projects/bagoo`  
**Target File**: `/home/andy/Projects/bagoo/.agents/explorer_survey_ops/handoff.md`  

---

## 1. Observation

Direct observations from codebase inspection, CLI execution, database queries, and test execution:

### 1.1 Test Harness & CLI Execution
1. **Tool Versions (`run_command` with PHP/Composer/Node/NPM)**:
   - PHP: `PHP 8.5.9 (cli) (built: Jul 28 2026 16:03:32)`
   - Composer: `Composer version 2.10.2 2026-07-01`
   - Node.js: `v26.7.0`
   - NPM: `12.0.2`
2. **Docker Containers (`docker ps`)**:
   - `bagoo_web`: `nginx:alpine`, port `0.0.0.0:8000->80/tcp` (Status: Up 11 hours)
   - `bagoo_app`: `bagoo-app`, port `9000/tcp` (Status: Up 11 hours)
   - `bagoo_db`: `postgres:16-alpine`, port `0.0.0.0:5432->5432/tcp` (Status: Up 11 hours, healthy)
3. **Test Suite Execution (`./bagoo.sh test` / `phpunit`)**:
   - Total Tests: 25 tests, 61 assertions across `tests/Unit` and `tests/Feature`.
   - Results: **2 failed, 23 passed (Duration: 3.64s)**.
   - Verbatim Failures:
     ```text
     FAILED Tests\Feature\Auth\AuthenticationTest > users can authenticate using the login screen
     Failed asserting that two strings are equal.
     -'http://localhost:8000/dashboard'
     +'http://localhost:8000/buyer'
     at tests/Feature/Auth/AuthenticationTest.php:30

     FAILED Tests\Feature\Auth\RegistrationTest > new users can register
     Failed asserting that two strings are equal.
     -'http://localhost:8000/dashboard'
     +'http://localhost:8000/buyer'
     at tests/Feature/Auth/RegistrationTest.php:29
     ```
   - Zero test files currently exist for: `Order` lifecycle transitions, `Delivery` claiming/milestones, `Simulator` controls, `Location/Checkpoint` scans, `Admin KYC` verification gate, or `Commission` ledger calculations.
4. **Frontend TypeScript & Asset Build (`npm run build`)**:
   - `tsc && vite build` executed cleanly in 8.89s with **0 errors**.
   - Built 74 asset chunks, main bundle: `public/build/assets/app-Bq7ggCpi.js` (362.08 kB).
5. **Database Seeder & Migration Execution**:
   - `./bagoo.sh migrate` -> `INFO: Nothing to migrate.`
   - `./bagoo.sh seed` -> `INFO: Seeding database.` (Successfully created users, 14 categories, products, orders, deliveries, reviews, and vouchers).
   - Tinker DB verification: `Users: 4, Orders: 3, Deliveries: 3`.

### 1.2 Order & Delivery State Transition Architecture
1. **Order Model & Enums (`app/Models/Order.php`, `app/Enums/OrderStatus.php`)**:
   - `OrderStatus` enum cases: `PENDING = 'pending'`, `PROCESSING = 'processing'`, `READY_FOR_PICKUP = 'ready_for_pickup'`, `SHIPPED = 'shipped'`, `DELIVERED = 'delivered'`, `CANCELLED = 'cancelled'`.
   - `orders` migration (`database/migrations/2026_01_01_000006_create_orders_and_order_items_tables.php` line 20): default `'pending'`.
2. **Delivery Model & Enums (`app/Models/Delivery.php`, `app/Enums/DeliveryStatus.php`)**:
   - `DeliveryStatus` enum cases: `UNASSIGNED = 'unassigned'`, `ASSIGNED = 'assigned'`, `PICKED_UP = 'picked_up'`, `IN_TRANSIT = 'in_transit'`, `OUT_FOR_DELIVERY = 'out_for_delivery'`, `DELIVERED = 'delivered'`, `FAILED = 'failed'`.
   - `deliveries` migration (`database/migrations/2026_01_01_000007_create_deliveries_table.php` line 17): default `'unassigned'`.
3. **Current Order Transition Handlers**:
   - `CheckoutController.php` (line 124): Creates Order with `status => 'processing'` and creates unassigned `Delivery`.
   - `SellerOrderController.php`:
     - `pack()` (line 67): `$order->update(['status' => 'processing'])`.
     - `readyForPickup()` (line 81): `$order->update(['status' => 'ready_for_pickup'])` and ensures `Delivery` record has `status => 'unassigned'`.
   - `CourierDeliveryController.php`:
     - `claim()` (line 70): `$delivery->update(['courier_id' => $request->user()->id, 'status' => 'assigned', 'assigned_at' => now()])`.
     - `updateStatus()` (line 100-118):
       - When `status === 'picked_up'`: updates `$delivery->picked_up_at = now()` and `$order->status = 'shipped'`.
       - When `status === 'in_transit'`: updates `$delivery->status = 'in_transit'`.
       - When `status === 'out_for_delivery'`: updates `$delivery->status = 'out_for_delivery'`.
       - When `status === 'delivered'`: updates `$delivery->delivered_at = now()`, attaches fallback `proof_image`, and updates `$order->status = 'delivered'`, `$order->payment_status = 'paid'`.
   - `LogisticsHubController.php` (line 93): `override()` allows admin supervisor to manually reassign courier and force set delivery status to `assigned`, `picked_up`, `in_transit`, `out_for_delivery`, or `delivered`.

### 1.3 Missing Features & Gaps Discovered
1. **Interactive "Fast-Forward" Simulator (R2)**:
   - No backend simulator endpoint exists (e.g. `POST /simulator/orders/{order}/advance` or `POST /orders/{order}/simulate-step`).
   - No interactive UI simulator button/toolbar exists on `Buyer/OrderDetail.tsx`, `Courier/Deliveries.tsx`, `Seller/Orders.tsx`, or `Admin/Logistics.tsx`.
2. **Location Scanning & Status Checkpoints (R3)**:
   - Checkpoint 1 (Seller Pack & Dispatch Release): Exists in `Seller/Orders.tsx` ("Pack Order" / "Schedule Courier Pickup"), but lacks tactile barcode/tracking label scan verification.
   - Checkpoint 2 (Rider Pickup Verification): Exists in `Courier/Deliveries.tsx` button, but lacks tactile barcode/tracking code input or scanner simulator.
   - Checkpoint 3 (Logistics Hub Receipt & Barangay Sorting): Only supervisor override exists in `Admin/Logistics.tsx`; lacks dedicated tactile hub receipt barcode scan and barangay sorting confirmation step.
   - Checkpoint 4 (Courier Drop-off & Proof Upload): Exists in `Courier/Deliveries.tsx`, but relies on hardcoded Unsplash photo string rather than interactive camera/file proof upload simulation.
3. **Multi-Role KYC Registration & Admin Approval Gate (R4)**:
   - `RegisteredUserController.php` sets newly registered users to `status = 'active'` (database default) and immediately logs them in with `Auth::login($user)`.
   - `RoleMiddleware.php` does not check `$user->status === 'active'`.
   - `users` table lacks explicit document path columns (`id_document_path`, `business_permit_path`, `or_cr_path`, `license_path`), though profile tables or general upload fields can be used.
4. **10% Platform Commission & Split Ledger (R5)**:
   - `commissions` table/model is described in `docs/SCHEMA.md` but has no migration, model, or automated trigger on order delivery.

---

## 2. Features Discovered

| # | Category | Feature | Description | Inputs | Outputs | Error Behavior | Discovered Via |
|---|----------|---------|-------------|--------|---------|----------------|----------------|
| 1 | Simulator | Fast-Forward Order Transition Endpoint (Required) | Advances an order and its associated delivery through the canonical 7-stage lifecycle (`pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`). | `order_id` (Route parameter) | JSON/Redirect with updated Order & Delivery models, new status badge, timestamps, and financial ledger split | 404 if order missing, 400 if order cancelled/already delivered | `ORIGINAL_REQUEST.md` R2, `docs/COURIER_FLOW.md` |
| 2 | Simulator | Interactive Fast-Forward UI Control Widget (Required) | Floating/embedded action pill visible on Order Detail (`/buyer/orders/{id}`), Courier Dispatch (`/courier/deliveries`), Seller Orders (`/seller/orders`), and Logistics Hub (`/admin/logistics`) allowing 1-click step advance for evaluation. | Click event on "Simulate Next Stage" button | Optimistic UI update, flash notification, progress stepper synchronization | Toast error message on network failure | `ORIGINAL_REQUEST.md` R2 |
| 3 | Checkpoint | Seller Packaging & Dispatch Release | Seller confirms items are packed and marks parcel ready for courier collection, generating tracking waybill. | Order ID, pack confirmation | `Order.status = 'processing'` / `'ready_for_pickup'`, `Delivery.status = 'unassigned'`, tracking number generated | 403 IDOR check if order doesn't belong to seller | `SellerOrderController.php:57-105`, `Seller/Orders.tsx` |
| 4 | Checkpoint | Rider Pickup Verification & Barcode Scan | Courier claims unassigned parcel and performs store pickup verification by scanning/entering tracking barcode. | `delivery_id`, scanned `tracking_number` | `Delivery.status = 'picked_up'`, `Delivery.picked_up_at = now()`, `Order.status = 'shipped'` | 400 if barcode mismatch or job already claimed by another rider | `CourierDeliveryController.php:64-123`, `docs/COURIER_FLOW.md` |
| 5 | Checkpoint | Logistics Hub Receipt & Barangay Sorting Scan | Sorting hub facility receives parcel from first-mile rider, scans barcode, assigns barangay routing bin, and dispatches to doorstep rider. | `delivery_id`, `hub_location`, `barangay_sort_code` | `Delivery.status = 'in_transit'`, hub intake log | 400 if parcel not yet picked up | `LogisticsHubController.php:85-101`, `ORIGINAL_REQUEST.md` R3 |
| 6 | Checkpoint | Courier Drop-off & Proof of Delivery Verification | Courier delivers parcel to buyer doorstep, takes proof photo, enters customer handover note, and settles COD. | `delivery_id`, `proof_image`, `courier_notes`, COD amount collected | `Delivery.status = 'delivered'`, `Delivery.delivered_at = now()`, `Order.status = 'delivered'`, `Order.payment_status = 'paid'` | 422 if proof missing on COD completion | `CourierDeliveryController.php:79-123`, `Courier/Deliveries.tsx` |
| 7 | Checkpoint | Thermal Waybill & Barcode Label Generator | Printable 4x6 thermal shipping label with recipient details, merchant origin, COD payment badge, and simulated tracking barcode. | `order_id` / `orderItem` | Rendered printable modal with simulated code 128 barcode | None (client-side render) | `Seller/Orders.tsx:84-174`, `docs/SELLER_FLOW.md` |
| 8 | Ops / Testing | PHPUnit Automated Test Suite | Test runner configured with in-memory SQLite and Feature/Unit test suites. | `./bagoo.sh test` or `php artisan test` | CLI test pass/fail report with assertion counts and execution timings | Exit code 1 on test assertion failure | `phpunit.xml`, `composer.json` |
| 9 | Ops / Build | Vite + TypeScript Production Asset Compiler | Compiles React TSX pages, layouts, Tailwind CSS, and Lucide icons into minified production assets. | `npm run build` (`tsc && vite build`) | Built assets in `public/build/assets/` | Exit code 1 on TypeScript type errors or syntax issues | `package.json`, `vite.config.js` |
| 10 | Ops / Database | Database Migrations & Seeding Engine | Creates relational database schema and populates master product departments, sample products, demo role accounts, and initial orders. | `./bagoo.sh migrate`, `./bagoo.sh seed`, `./bagoo.sh fresh` | Initialized PostgreSQL database with 4 demo accounts and 14 categories | SQL exception on foreign key constraint violations | `DatabaseSeeder.php`, `database/migrations/` |
| 11 | Ops / Fleet | Central Logistics Hub Supervisor Override | Admin supervisor view for platform-wide delivery telemetry and manual driver reassignment. | `delivery_id`, `courier_id`, `status` | Updated Delivery record with assigned courier and new status | 422 validation error on invalid courier ID or status | `LogisticsHubController.php:14-102`, `Admin/Logistics.tsx` |

---

## 3. Edge Cases

| # | Feature | Input | Observed Behavior |
|---|---------|-------|-------------------|
| 1 | Order Checkout | Placing order with COD payment | Creates `Order` with `payment_status = 'pending'`, `status = 'processing'`, and `Delivery` with `status = 'unassigned'`. `CourierDeliveryController` calculates rider COD cash on hand based on completed COD deliveries. |
| 2 | Courier Job Claim | Simultaneous claim of same unassigned delivery by two couriers (FCFS) | `CourierDeliveryController::claim` checks `$delivery->courier_id !== null` and returns redirect with error message `"This delivery has already been claimed by another rider."` |
| 3 | Fast-Forward Progression | Fast-forwarding an order that is already `delivered` | Should return a no-op or message indicating the order has already completed its lifecycle; prevents duplicate commission splits. |
| 4 | Fast-Forward Progression | Fast-forwarding an unassigned `ready_for_pickup` order to `picked_up` | Automatically assigns the default demo courier (`courier@bagoo.test`) to the delivery before advancing to `picked_up`. |
| 5 | Drop-off Proof Photo | Completing delivery without uploading a custom photo | `CourierDeliveryController::updateStatus` supplies fallback verified drop-off photo (`https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d...`) to ensure delivery completes gracefully. |
| 6 | Laravel Breeze Auth Test | Authenticating buyer account in `AuthenticationTest` | Breeze default test asserts redirect to `route('dashboard')` (`/dashboard`), but user is redirected to `/buyer` (`route('buyer.index')`), causing test assertion failure unless updated. |
| 7 | Logistics Status Filter | Filtering deliveries by `status = 'all'` or specific status slug | `LogisticsHubController::index` dynamically builds Eloquent query and paginates 15 items per page with query string preservation. |

---

## 4. Logic Chain

1. **Analysis of Requirements vs Current Implementation**:
   - `ORIGINAL_REQUEST.md` explicitly calls for R2: Interactive "Fast-Forward" Order Delivery Simulator and R3: Parcel Location Scanning & Status Approval Checkpoints.
   - We inspected `SellerOrderController.php`, `CourierDeliveryController.php`, `LogisticsHubController.php`, `CheckoutController.php`, and `OrderHistoryController.php`.
   - The state transition flow exists across separate controllers (`pack`, `readyForPickup`, `claim`, `updateStatus`, `override`), but there is no single coordinated endpoint or UI widget for fast-forwarding an order through its complete lifecycle in a single click.

2. **State Transition Mapping**:
   - Canonical 7-Stage Sequence:
     1. `pending`: Order placed by buyer, awaiting merchant acknowledgment.
     2. `packaging`: Seller packs items, attaches thermal waybill.
     3. `ready_for_pickup`: Seller schedules dispatch; delivery broadcasted to Courier Dispatch Board (`unassigned`).
     4. `picked_up`: Courier claims job and scans parcel at store (`Delivery.status = 'picked_up'`, `Order.status = 'shipped'`).
     5. `in_transit`: Parcel processed through Logistics Sorting Hub (`Delivery.status = 'in_transit'`).
     6. `out_for_delivery`: Courier dispatches for final doorstep drop-off (`Delivery.status = 'out_for_delivery'`).
     7. `delivered`: Courier drops off parcel, captures proof, settles COD (`Delivery.status = 'delivered'`, `Order.status = 'delivered'`, `Order.payment_status = 'paid'`).
   - Triggering step 7 also initiates the R5 financial split (90% seller, 10% platform, ₱60 courier).

3. **Checkpoints & Location Scanning Architecture**:
   - The UI components (`Seller/Orders.tsx`, `Courier/Deliveries.tsx`, `Buyer/OrderDetail.tsx`, `Admin/Logistics.tsx`) already render rich card interfaces and status pills.
   - Adding tactile barcode scanning inputs (with "Scan / Auto-Fill" buttons for simulation) and step confirmation modals will satisfy R3 without introducing external hardware dependencies.

4. **Test Infrastructure & Operational Commands**:
   - The project uses Docker Compose (`bagoo_web`, `bagoo_app`, `bagoo_db`) and `./bagoo.sh`.
   - PHPUnit test runner is functional (`phpunit.xml` uses in-memory SQLite for fast testing).
   - Breeze auth tests can be aligned with BagooPH role routes (`/buyer`), and comprehensive Feature tests should be created for the Simulator, Checkpoint Scans, and KYC approval gate.

---

## 5. Caveats

1. **In-Memory SQLite for Testing**: `phpunit.xml` uses SQLite in-memory (`:memory:`), while the development container uses PostgreSQL 16. Any PostgreSQL-specific SQL syntax (e.g. `ilike` in `LogisticsHubController.php`) should either use standard Eloquent `where('col', 'like', ...)` or be tested with PostgreSQL if database-specific features are used.
2. **Third-Party API Prohibition**: In accordance with `GEMINI.md` Rule 4.1, no external paid APIs (e.g., actual barcode scanner hardware SDKs or third-party logistics APIs) should be integrated; all location scanning and GPS proofs must use clean local database simulation logic.
3. **Admin Obfuscation Guardrail**: Per `GEMINI.md` Rule 2.4, admin controls and simulator widgets should be styled with architectural precision and kept clean and contextual.

---

## 6. Conclusion

- **Simulator Implementation Path**: Implement a centralized `OrderSimulationController` with endpoint `POST /simulator/orders/{order}/advance` (and `POST /simulator/orders/{order}/reset`), accompanied by a reusable React component `<FastForwardControl />` embedded in `Buyer/OrderDetail.tsx`, `Courier/Deliveries.tsx`, `Seller/Orders.tsx`, and `Admin/Logistics.tsx`.
- **Location Checkpoints Implementation Path**: Enhance `Courier/Deliveries.tsx` with a tactile Barcode Scanner Simulation Modal (allowing 1-click scan or manual code entry), add Hub Sorting Intake scan in `Admin/Logistics.tsx`, and ensure photo proof upload simulation in `Courier/Deliveries.tsx`.
- **Test Infrastructure Plan**:
  1. Fix the 2 failing Breeze redirect assertions in `AuthenticationTest.php` and `RegistrationTest.php`.
  2. Implement `OrderDeliveryLifecycleTest.php` to verify end-to-end state transitions (`pending` -> `delivered`).
  3. Implement `OrderSimulatorTest.php` to verify fast-forward progression and commission splitting.
  4. Implement `LocationCheckpointScanTest.php` to verify barcode verification and status validation.

---

## 7. Verification Method & Clear Operational Commands

### 7.1 Independent Verification Commands

```bash
# 1. Start Docker Containers
./bagoo.sh start
# or: docker compose up -d

# 2. Check Container Health
docker ps

# 3. Re-run Migrations & Seed Clean Master Data
./bagoo.sh fresh
# or: docker compose exec app php artisan migrate:fresh --seed

# 4. Compile Frontend Assets
npm run build
# or: ./bagoo.sh npm run build

# 5. Run Automated Test Suite
./bagoo.sh test
# or: docker compose exec app php artisan test

# 6. Verify Database State via Tinker
./bagoo.sh artisan tinker --execute="echo 'Users: ' . App\Models\User::count() . ', Orders: ' . App\Models\Order::count() . ', Deliveries: ' . App\Models\Delivery::count() . PHP_EOL;"
```

### 7.2 Key Files to Inspect
- `routes/web.php`: Route registrations for buyer, seller, courier, admin, and simulator.
- `app/Enums/OrderStatus.php` & `app/Enums/DeliveryStatus.php`: Canonical status definitions.
- `app/Http/Controllers/Seller/SellerOrderController.php`: Pack and Ready for Pickup endpoints.
- `app/Http/Controllers/Courier/CourierDeliveryController.php`: Claim, Update Status, and Earnings endpoints.
- `app/Http/Controllers/Admin/LogisticsHubController.php`: Hub metrics and supervisor override.
- `resources/js/Pages/Buyer/OrderDetail.tsx`: Buyer milestone tracking timeline.
- `resources/js/Pages/Courier/Deliveries.tsx`: Rider dispatch board and milestone execution.
- `resources/js/Pages/Seller/Orders.tsx`: Merchant packaging and thermal waybill modal.
- `resources/js/Pages/Admin/Logistics.tsx`: Logistics sorting hub and fleet telemetry.
- `tests/Feature/`: Automated test suite.
