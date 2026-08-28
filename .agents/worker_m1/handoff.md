# Milestone M1 Handoff Report: Core Schema, KYC Registration & Admin Approval Gate

**Agent:** Worker M1 (`worker_m1`)  
**Target Platform:** BagooPH (Laravel 11 + Inertia.js 2.0 + React 18 + TypeScript + Tailwind CSS + PostgreSQL 16 / SQLite)  
**Milestone:** M1 (Core Schema, KYC Registration & Admin Approval Gate)  
**Date:** 2026-08-27  

---

## 1. Observation

### 1.1 Database Migrations & Schemas
- **Created Migrations:**
  1. `database/migrations/2026_08_27_000001_add_kyc_fields_to_users_table.php`:
     - Adds `kyc_status` (string, default `'pending_approval'`, indexed), `id_document_path`, `business_permit_path`, `driver_license_path`, `or_cr_path`, `kyc_feedback` (text), `kyc_submitted_at` (timestamp), `kyc_reviewed_at` (timestamp) to `users` table.
  2. `database/migrations/2026_08_27_000002_create_courier_profiles_table.php`:
     - Creates `courier_profiles` table: `id`, `user_id` (foreignId unique cascadeOnDelete), `vehicle_type`, `plate_number`, `license_number`, `or_cr_status` (default `'valid'`), `is_available` (boolean default `true`), and timestamps.
  3. `database/migrations/2026_08_27_000003_add_variant_fields_to_cart_items_and_order_items_tables.php`:
     - Adds `color`, `size`, and `sku_snapshot` (nullable strings) to both `cart_items` and `order_items` tables.

### 1.2 Models & Enums
- **Created Enums:**
  - `app/Enums/KycStatus.php` with cases `PENDING_APPROVAL`, `APPROVED`, `REJECTED`, and helper methods `label()` and `badgeClass()`.
- **Created Models:**
  - `app/Models/CourierProfile.php` with fillable properties, boolean casting for `is_available`, and `user()` BelongsTo relationship.
- **Updated Models:**
  - `app/Models/User.php`: Added KYC fields to `$fillable`, `kyc_submitted_at` & `kyc_reviewed_at` to `casts()`, `courierProfile()` HasOne relationship, and helper methods `isKycApproved()`, `isKycPending()`, and `isKycRejected()`.
  - `app/Models/CartItem.php` & `app/Models/OrderItem.php`: Added `color`, `size`, and `sku_snapshot` to `$fillable`.

### 1.3 Auth, Middleware & Controllers
- **`app/Http/Controllers/Auth/RegisteredUserController.php`**:
  - Implemented `create()` (Buyer), `createSeller()` (Seller), and `createCourier()` (Courier).
  - Implemented multi-role `store()` validation and document uploads (Gov ID, Business Permit, Driver's License, OR/CR) saved to the `public` storage disk under `kyc_documents`.
  - Creates `User` with `status = 'pending_approval'`, `kyc_status = 'pending_approval'`, `kyc_submitted_at = now()`.
  - Creates associated `Shop` (status `'pending'`) for sellers and `CourierProfile` (status `'Pending Verification'`, `is_available = false`) for couriers.
  - Automatically logs in the user and redirects to `route('kyc.pending')`.
  - Implemented `pendingApproval()` rendering `Auth/PendingApproval` with user KYC attributes, shop, and courierProfile.
  - Implemented `resubmitKyc()` allowing rejected applicants to re-upload documents, resetting `kyc_status` and `status` to `'pending_approval'` and clearing `kyc_feedback`.
- **`app/Http/Middleware/RoleMiddleware.php`**:
  - Unauthenticated users redirected to `login`.
  - Admin users (`isAdmin()`) bypass KYC gate.
  - Suspended users are logged out and redirected to `login` with error message.
  - Pending (`pending_approval`) and rejected (`rejected`) users are redirected to `route('kyc.pending')`.
  - Enforces role authorization via `in_array($user->role, $roles, true)`.
- **`app/Http/Controllers/Auth/AuthenticatedSessionController.php`**:
  - On login, checks for suspended status (logs out with error) and redirects unapproved non-admin users to `route('kyc.pending')`.
- **`routes/web.php` Universal `/dashboard` Route**:
  - Gated unapproved and rejected non-admin users to `route('kyc.pending')`.
- **`app/Http/Controllers/Admin/AdminKycController.php` & Admin Routes**:
  - `index()`: Paginated queue of KYC applicants with stats counters and filters (status, role, search query).
  - `approve()`: Sets `kyc_status = 'approved'`, `status = 'active'`, `kyc_reviewed_at = now()`, activates shop/courier.
  - `reject()`: Validates reason, sets `kyc_status = 'rejected'`, records `kyc_feedback`, deactivates shop/courier.
- **`app/Http/Middleware/HandleInertiaRequests.php`**:
  - Shared auth user props now include `status`, `kyc_status`, `kyc_feedback`, `kyc_submitted_at`, `kyc_reviewed_at`, `id_document_path`, `business_permit_path`, `driver_license_path`, `or_cr_path`, `shop`, and `courier_profile`.

### 1.4 Field Mismatch Bugfix & Cart/Order Variants
- **`app/Http/Controllers/Buyer/CheckoutController.php`**:
  - Line 165 corrected from `'recipient_phone'` to `'delivery_phone'` in `Delivery::create(...)`.
  - Lines 137–144 updated to copy `color`, `size`, and `sku_snapshot` from cart items to `OrderItem::create(...)`.
- **`app/Http/Controllers/Seller/SellerOrderController.php`**:
  - Line 100 corrected from `'recipient_phone'` to `'delivery_phone'` in `Delivery::create(...)`.
- **`app/Http/Controllers/Buyer/CartController.php`**:
  - Updated `store()` to match existing items by `product_id`, `color`, and `size`, and dynamically generate `sku_snapshot`.

### 1.5 Database Seeders
- **`database/seeders/DatabaseSeeder.php`**:
  - Seeded all 5 core roles (`admin`, `seller`, `buyer`, `courier`, `logistics`) with `kyc_status = 'approved'` and `status = 'active'`.
  - Seeded `CourierProfile` for Dave Courier (`courier@bagoo.test`).
  - Seeded demo test accounts: `pending.seller@bagoo.test` (with shop and documents), `pending.courier@bagoo.test` (with courier profile and documents), and `rejected.seller@bagoo.test` (with feedback and pending shop).

### 1.6 Frontend UI Components
- **`resources/js/types/index.d.ts`**:
  - Added `KycStatus` type and `CourierProfile` interface; extended `User`, `CartItem`, and `OrderItem` interfaces.
- **`resources/js/Pages/Auth/Register.tsx`**:
  - Added full contact phone, delivery address, optional Gov ID file upload dropzone with preview, and `forceFormData: true` form submission.
- **`resources/js/Pages/Auth/SellerRegister.tsx`**:
  - Added store name, owner name, business email, contact phone, pickup address, and dropzones for Gov ID and DTI/Business Permit with `forceFormData: true`.
- **`resources/js/Pages/Auth/CourierRegister.tsx`**:
  - Added vehicle type selection, plate number, LTO license number, and dropzones for Gov ID, Driver's License, and Vehicle OR/CR with `forceFormData: true`.
- **`resources/js/Pages/Auth/PendingApproval.tsx`**:
  - Implemented dual-state UI:
    * In Review: Live radar pulse, turnaround indicator, compliance intake checklist, refresh status, and logout button.
    * Rejected: Crimson alert card with compliance officer feedback, interactive document re-upload form submitting to `route('kyc.resubmit')`, and logout button.
- **`resources/js/Pages/Admin/KycQueue.tsx`**:
  - Created complete Admin KYC Verification Queue with 4 summary metric cards, filter pills (Pending, Approved, Rejected, All), role dropdown, search input, applicant data table with document preview buttons, high-resolution document inspection modal (Gov ID, Business Permit, Driver's License, OR/CR tabs), 1-click Approve, and Reject with feedback modal + preset quick-fills.
- **`resources/js/Layouts/DashboardLayout.tsx`**:
  - Added `KYC Verification Queue` link to Admin navigation menu.

---

## 2. Logic Chain

1. **Step 1 (Schema & Model Grounding):**
   - Observations 1.1 and 1.2 established that the database and models lacked fields for KYC verification, courier fleet specifications, and variant snapshot persistence. Adding migrations `2026_08_27_000001`, `000002`, and `000003`, along with the `KycStatus` enum and `CourierProfile` model, created the persistence foundation.
2. **Step 2 (Auth & Gate Enforcement):**
   - Observation 1.3 demonstrated that unapproved accounts could previously access role dashboards. Updating `RegisteredUserController`, `RoleMiddleware`, and `AuthenticatedSessionController` guarantees that any newly registered user or rejected user is held at `/pending-approval`.
3. **Step 3 (Admin KYC Verification Queue):**
   - Admins need visibility into submitted documents and one-click actions. `AdminKycController` and `Admin/KycQueue.tsx` provide complete applicant inspection, approval (activating user, shop, and courier fleet profile), and rejection with custom feedback.
4. **Step 4 (Data Consistency & Bug Fixes):**
   - Observation 1.4 identified that `deliveries.delivery_phone` was being passed as `'recipient_phone'`, leading to empty phone numbers in courier delivery views. Correcting this in `CheckoutController` and `SellerOrderController` and persisting `color`/`size`/`sku_snapshot` ensures end-to-end data integrity.
5. **Step 5 (Seeders & Automated Verification):**
   - Observation 1.5 and 1.6 established that seeders and tests must reflect approved base roles while providing realistic pending/rejected test cases. Creating `KycRegistrationTest`, `RoleMiddlewareGateTest`, `AdminKycApprovalTest`, and `DeliveryPhoneConsistencyTest` verified the entire system.

---

## 3. Caveats

- **Storage Symlink:** KYC document previews rely on `public/storage` pointing to `storage/app/public` (`php artisan storage:link`), which was executed and verified.
- **Database Compatibility:** All schema migrations and Eloquent queries are verified portable across SQLite (memory test) and PostgreSQL 16 (production docker container).
- No further caveats.

---

## 4. Conclusion

Milestone M1 has been implemented genuinely and comprehensively according to `PROJECT.md` and `SCOPE.md`.
All database schemas, models, controllers, middleware, frontend registration pages, gate screens, and admin verification queues are fully functional, integrated, and covered by automated tests.

---

## 5. Verification Method

### 5.1 Automated Test Execution
Run the full test suite inside the application container:
```bash
./bagoo.sh test
```
**Observed Result:**
```
Tests:    81 passed (294 assertions)
Duration: 8.76s
```

### 5.2 M1 Specific Feature Test Suites
Run individual milestone feature tests:
```bash
./bagoo.sh artisan test --filter="KycRegistrationTest|RoleMiddlewareGateTest|AdminKycApprovalTest|DeliveryPhoneConsistencyTest"
```
**Observed Result:**
```
PASS  Tests\Feature\Admin\AdminKycApprovalTest (6 tests)
PASS  Tests\Feature\Auth\KycRegistrationTest (4 tests)
PASS  Tests\Feature\Auth\RoleMiddlewareGateTest (8 tests)
PASS  Tests\Feature\DeliveryPhoneConsistencyTest (2 tests)
PASS  Tests\Feature\E2E\Tier1\F1_KycRegistrationTest (5 tests)
Total: 25 passed (117 assertions)
```

### 5.3 Frontend TypeScript & Asset Build
Run the Vite asset build inside the application container:
```bash
./bagoo.sh npm run build
```
**Observed Result:**
```
✓ built in 8.90s (Exit code 0, 0 TypeScript errors)
```
