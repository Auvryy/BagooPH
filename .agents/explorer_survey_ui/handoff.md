# Multi-Role UI & Frontend Workflow Survey Report

## Executive Summary
This Phase 0 investigation audits the frontend routes, pages, UI components, state management, and multi-role workflows across all 5 BagooPH user roles: **Buyer**, **Seller**, **Courier Rider**, **Logistics Sorting Hub**, and **Platform Admin**. 

While strong UI foundations, responsive Tailwind styling, and partial role dashboards exist, critical gaps remain in **end-to-end data synchronization**, **interactive barcode scanning checkpoints**, **KYC document submission & Admin approval gating**, **interactive Fast-Forward delivery progression controls**, and **persistent financial commission splitting**.

---

## 1. Observation

### 1.1 Architecture & Routing Matrix
- **Framework**: Laravel 11 + Inertia.js 2.0 (React 18, TypeScript, Tailwind CSS).
- **Core Routes File**: `routes/web.php` (lines 32–165).
- **Authentication Routes File**: `routes/auth.php` (lines 14–65).
- **Frontend Page Directory**: `resources/js/Pages/` containing `Buyer/`, `Seller/`, `Courier/`, `Admin/`, `Checkout/`, `Cart/`, `Marketplace/`, and `Auth/`.
- **Layout System**:
  - `BuyerLayout.tsx` (`resources/js/Layouts/BuyerLayout.tsx`)
  - `DashboardLayout.tsx` (`resources/js/Layouts/DashboardLayout.tsx` — shared by Seller and Admin)
  - `CourierLayout.tsx` (`resources/js/Layouts/CourierLayout.tsx`)
  - `GuestLayout.tsx` (`resources/js/Layouts/GuestLayout.tsx`)

---

### 1.2 Role 1: Buyer Workflow & UI Observations

1. **Variant Selection & Product Detail**:
   - `resources/js/Pages/Buyer/ProductDetail.tsx` (lines 331–388) allows selecting `VariationColor` (e.g. name, hex) and `VariationSize` (e.g. name, extra_price, stock).
   - Line 116–121: `handleAddToBag()` posts `{ product_id, quantity, color, size }` to `route('cart.store')`.
   - **Gap**: `database/migrations/2026_01_01_000005_create_carts_and_cart_items_tables.php` (lines 18–25) and `database/migrations/2026_01_01_000006_create_orders_and_order_items_tables.php` (lines 32–41) do NOT possess `color`, `size`, or `variant_details` columns. Variant selections are dropped during database persistence.

2. **Checkout & Voucher Application**:
   - `resources/js/Pages/Checkout/Index.tsx` (lines 52–61) manages form state for recipient details (`recipient_name`, `recipient_phone`, `shipping_address`, `shipping_city`, `shipping_postal_code`), payment mode (`payment_method`: `cod`, `card`, `e_wallet`, `bank_transfer`), and `voucher_code`.
   - Lines 71–103: `applyVoucher()` validates min spend and applies discount for `fixed`, `percent`, and `free_shipping` types against available vouchers passed from `CheckoutController.php` (lines 43–49).
   - `app/Http/Controllers/Buyer/CheckoutController.php` (lines 83–173) executes `DB::transaction()`: calculates subtotal, deducts stock, increments sales count, creates `Order` (`status = 'processing'`), creates `OrderItem`s, creates `Delivery` (`status = 'unassigned'`), and clears cart.

3. **Order Tracking & Live Milestones**:
   - `resources/js/Pages/Buyer/Orders.tsx` lists orders with status tabs (`all`, `to_ship`, `to_receive`, `completed`).
   - `resources/js/Pages/Buyer/OrderDetail.tsx` (lines 83–89, 136–168) displays a 5-step milestone tracking component:
     ```typescript
     const steps = [
         { key: 'placed', label: 'Order Placed', done: true, subtext: 'Payment Verified' },
         { key: 'packaging', label: 'Merchant Packaging', done: ['processing', 'ready_for_pickup', 'shipped', 'delivered'].includes(order.status), subtext: 'Prepared by Shop' },
         { key: 'pickup', label: 'Courier Picked Up', done: ['shipped', 'delivered'].includes(order.status) || ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || ''), subtext: 'Handed to Dispatch' },
         { key: 'in_transit', label: 'In Transit', done: ['shipped', 'delivered'].includes(order.status) || ['in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || ''), subtext: 'On Route to Destination' },
         { key: 'delivered', label: 'Delivered', done: order.status === 'delivered' || delivery?.status === 'delivered', subtext: 'Received at Doorstep' },
     ];
     ```
   - **Gap**: Order placement creates the order directly with `status = 'processing'` (skipping `'pending'`). No live interactive Fast-Forward simulation control is present.

---

### 1.3 Role 2: Seller Workflow & UI Observations

1. **Seller Cockpit & Fulfillment Pipeline**:
   - `resources/js/Pages/Seller/Orders.tsx` (lines 178–220) provides filter tabs: `All Orders`, `To Pack`, `Ready for Pickup`, `In Transit`, `Completed`.
   - Lines 43–49, 298–345: Action buttons trigger status updates:
     - "Pack Order": `router.post(route('seller.orders.pack', orderId))`
     - "Schedule Courier Pickup": `router.post(route('seller.orders.ready', orderId))`
   - `app/Http/Controllers/Seller/SellerOrderController.php` (lines 57–105):
     - `pack()` updates `order.status = 'processing'`.
     - `readyForPickup()` updates `order.status = 'ready_for_pickup'` and ensures a `Delivery` record exists with `status = 'unassigned'`.

2. **Thermal Waybill / Shipping Label Generation**:
   - `resources/js/Pages/Seller/Orders.tsx` (lines 85–173): Printable Waybill Modal renders a BagooExpress thermal shipping label with simulated barcode (`||| | |||| | ||| ||||`), tracking number, sender/origin info, recipient destination, package contents, and `window.print()` trigger.
   - **Gap**: No barcode scanning checkpoint simulator for packaging release verification.

---

### 1.4 Role 3: Courier Rider Workflow & UI Observations

1. **Courier Dispatch Board & Telemetry**:
   - `resources/js/Pages/Courier/Deliveries.tsx` provides two tabs:
     - `availableJobs` (FCFS Broadcast): Unassigned delivery tasks.
     - `myDeliveries` (Active Route): Deliveries claimed by the rider.
   - `app/Http/Controllers/Courier/CourierDeliveryController.php` (lines 64–77): `claim()` assigns `courier_id = auth->id()` and sets `status = 'assigned'`.
   - `Deliveries.tsx` (lines 295–340) modal transitions:
     - `assigned` -> `picked_up` (calls `courier.updateStatus`, sets `order.status = 'shipped'`)
     - `picked_up` -> `in_transit`
     - `in_transit` -> `out_for_delivery`
     - `out_for_delivery` -> `delivered` (sets `order.status = 'delivered'`, `order.payment_status = 'paid'`, attaches proof photo URL).

2. **Courier Earnings**:
   - `resources/js/Pages/Courier/Earnings.tsx` and `CourierDeliveryController.php` (lines 125–165): Computes total completed trips × ₱60.00 and calculates on-hand COD cash.
   - **Gap**: Earnings are computed dynamically via queries rather than recorded in an immutable ledger transaction table. No barcode scan simulator for physical package scan at pickup or doorstep delivery.

---

### 1.5 Role 4: Logistics Sorting Hub Workflow & UI Observations

1. **Current Logistics Interface**:
   - `resources/js/Pages/Admin/Logistics.tsx` & `app/Http/Controllers/Admin/LogisticsHubController.php`.
   - Provides central sorting hub metrics (In-Transit, Unassigned, Delivered, Hub 20% share calculation), fleet driver roster, and supervisor dispatch override/reassignment modal.
2. **Missing Hub Features**:
   - No dedicated `/hub` route accessible directly by `logistics` role users (`RoleMiddleware.php` lines 17–35 combines `'courier,logistics'` without dedicated hub route).
   - No **barcode/tracking code scanning simulator** where hub operators can scan incoming packages.
   - No **barangay sorting confirmation** interface allowing sorting parcels by barangay destination clusters.

---

### 1.6 Role 5: Platform Admin & KYC Governance Observations

1. **Admin User Management**:
   - `resources/js/Pages/Admin/Users.tsx` (lines 15–212) displays all users in a table with search and role filters, plus a "Modify Role" modal to alter user role (`buyer`, `seller`, `courier`, `logistics`, `admin`) and status (`active`, `pending`, `suspended`).
2. **KYC Registration & Approval Gate Gaps**:
   - `resources/js/Pages/Auth/Register.tsx`, `SellerRegister.tsx`, `CourierRegister.tsx` do **NOT** have file upload fields for KYC documents (e.g. Government ID, Business Permit, Driver's License, OR-CR).
   - `app/Http/Controllers/Auth/RegisteredUserController.php` (lines 48–87) creates all users without setting `status = 'pending_approval'`, does not store KYC document paths, and immediately logs the user in with `Auth::login($user)`.
   - `app/Http/Middleware/RoleMiddleware.php` (lines 17–36) only checks `in_array($userRole, $roles)` and does **NOT** check `$user->status === 'pending_approval'`.
   - No dedicated **Admin KYC Verification Queue** with document image inspector and one-click `Approve` or `Reject` (with rejection reason feedback) modal.

---

### 1.7 Fast-Forward Simulation & 10% Commission Ledger Observations

1. **Interactive Fast-Forward Simulation Control**:
   - `grep_search` across entire codebase confirms **0 occurrences** of fast-forward controls.
   - No interactive button/widget exists to advance an order through `pending` -> `packaging` -> `ready_for_pickup` -> `picked_up` -> `in_transit` -> `out_for_delivery` -> `delivered`.
2. **Financial Commission Ledger**:
   - `resources/js/Pages/Seller/Reports.tsx` (lines 118–127) displays a 10% platform commission calculation in the UI, but database migrations lack a `commission_ledgers` or `wallet_transactions` table to record immutable transaction splits upon order delivery.

---

## 2. Logic Chain

```
Observation 1.2 (Variant drop on cart/order persistence)
  ↳ Observation 1.1 (ProductDetail passes color & size)
  ↳ Schema 2026_01_01_000005 & 000006 lack variant columns
  ⟹ CONCLUSION 1: Cart and Order item tables require variant metadata columns so chosen SKU/color/size persist through checkout, order items, and waybill printing.

Observation 1.6 (Registration creates active users with no KYC docs)
  ↳ Observation 1.6 (RoleMiddleware does not gate on account status)
  ↳ Observation 1.6 (Admin/Users has generic role edit but no document preview / approve / reject flow)
  ⟹ CONCLUSION 2: Must add KYC fields to User model/migration (id_document, business_permit, driver_license, or_cr, kyc_status, kyc_feedback), update registration forms with document uploads, enforce pending_approval in RoleMiddleware / portal redirector, and implement an Admin KYC Verification Queue with one-click Approve/Reject with feedback.

Observation 1.5 (Logistics hub is currently only under /admin/logistics)
  ↳ Observation 1.5 (No barcode scanner simulator or barangay cluster sorting)
  ⟹ CONCLUSION 3: Implement dedicated /hub route for Logistics Hub Operators featuring an interactive Barcode / Tracking Number Scanner Simulator and Barangay Sorting Confirmation Matrix.

Observation 1.3 & 1.4 (Orders transition across multiple manual steps)
  ↳ Observation 1.7 (Zero fast-forward simulation controls exist)
  ⟹ CONCLUSION 4: Implement a persistent Fast-Forward Order Progression Controller & Interactive UI Widget (visible on Order Detail, Seller, Courier, Hub, and Admin screens) enabling single-click stage advancement: pending ➔ packaging ➔ ready_for_pickup ➔ picked_up ➔ in_transit ➔ out_for_delivery ➔ delivered.

Observation 1.4 & 1.7 (Reports compute 10% fee on-the-fly without database ledger)
  ↳ Requirement R5 (Atomic revenue distribution: 90% Seller, 10% Platform, ₱50-₱60 Courier)
  ⟹ CONCLUSION 5: Implement CommissionLedger model/migration and service that atomically records the 90%/10%/rider split upon order delivery (both manual and fast-forward).
```

---

## 3. Comprehensive Multi-Role Feature & Component Gap Analysis

| Workflow Domain | Existing UI Implementation | Missing / Required Implementation | Priority |
| :--- | :--- | :--- | :--- |
| **Buyer Workflow** | `Checkout/Index.tsx`, `Buyer/Orders.tsx`, `Buyer/OrderDetail.tsx` | • Persist variant choices in cart & order items<br>• Initial order status as `pending` instead of jumping to `processing`<br>• Real-time synchronized tracking timeline with simulation triggers | **High** |
| **Seller Cockpit** | `Seller/Orders.tsx` with Pack, Ready for Pickup, and Printable Waybill Label Modal | • Tactile packaging verification checkpoint<br>• Barcode preview & scan simulator trigger<br>• Integration with real financial ledger | **Medium** |
| **Courier Rider** | `Courier/Deliveries.tsx` with FCFS Claim board, 4-stage update modal with photo proof | • Barcode scanning simulator for pickup & delivery<br>• COD collection confirmation checkpoint<br>• Real rider earnings wallet ledger | **High** |
| **Logistics Sorting Hub** | `Admin/Logistics.tsx` with platform stats & driver roster | • Dedicated `/hub` sorting dashboard route<br>• Barcode / Tracking scanner simulator<br>• Barangay sorting confirmation & routing hub | **High** |
| **Platform Admin & KYC Gate** | `Admin/Users.tsx` with basic role dropdown | • Dedicated KYC Applicant Verification Queue<br>• Document inspector modal (ID, Permit, License)<br>• One-click `Approve` / `Reject` with feedback reason<br>• Account status gate on login (`pending_approval`) | **Critical** |
| **Simulation Controls** | None (0% implemented) | • Interactive "Fast-Forward" simulation bar/widget on Order Detail, Courier, Seller, Hub, Admin screens<br>• API route `/simulation/orders/{order}/advance` | **Critical** |
| **Financial Split Ledger** | Computed dynamically in UI views | • `commission_ledgers` migration & model<br>• Atomic 90% Seller / 10% Platform / ₱60 Rider split on `delivered` | **High** |

---

## 4. Proposed Technical Design Blueprint

### 4.1 Database & Schema Extensions
1. **Users Table (`users`)**:
   - `kyc_status`: enum(`pending_approval`, `approved`, `rejected`, `none`) default `pending_approval`.
   - `id_document`: string nullable.
   - `business_permit`: string nullable.
   - `driver_license`: string nullable.
   - `or_cr_document`: string nullable.
   - `kyc_feedback`: text nullable.
   - `kyc_submitted_at`: timestamp nullable.
   - `kyc_reviewed_at`: timestamp nullable.

2. **Cart Items & Order Items Tables**:
   - `color`: string nullable.
   - `size`: string nullable.
   - `sku_snapshot`: string nullable.

3. **Commission & Financial Ledger Table (`commission_ledgers`)**:
   - `order_id`: foreignId constrained.
   - `delivery_id`: foreignId constrained nullable.
   - `seller_id`: foreignId constrained.
   - `courier_id`: foreignId constrained nullable.
   - `gross_amount`: decimal(12, 2).
   - `seller_amount`: decimal(12, 2) (90%).
   - `platform_commission`: decimal(12, 2) (10%).
   - `delivery_fee`: decimal(12, 2) (₱50–₱60).
   - `status`: enum(`pending`, `settled`, `refunded`).
   - `settled_at`: timestamp nullable.

4. **Logistics Sorting Metadata on `deliveries`**:
   - `destination_barangay`: string nullable.
   - `sorting_hub_scanned_at`: timestamp nullable.
   - `sorting_hub_operator_id`: foreignId constrained nullable.

---

### 4.2 Frontend Components to Implement

1. **`Components/FastForwardSimulationWidget.tsx`**:
   - A floating/collapsible tactile simulation widget embedded on Order Detail, Seller Orders, Courier Dispatch, Hub Sorting, and Admin dashboards.
   - Displays current stage, next stage button with instant visual feedback, and a "Jump to Delivered (Complete Lifecycle)" shortcut.
   - Posts to `/api/simulation/orders/{order}/fast-forward`.

2. **`Components/BarcodeScannerModal.tsx`**:
   - Interactive camera/laser barcode scan simulator allowing typing or clicking barcode to simulate physical hardware scan during:
     - Seller packaging & dispatch release
     - Rider store pickup
     - Hub arrival & barangay sorting
     - Courier doorstep handover

3. **`Pages/Admin/KycQueue.tsx` (or integrated in `Admin/Users.tsx`)**:
   - Tabbed verification queue showing `Pending Review`, `Approved`, `Rejected`.
   - Document inspection drawer/modal displaying uploaded Gov ID, Business Permit, or Driver's License.
   - One-click `Approve` (activates account) or `Reject` (opens feedback prompt with canned reasons e.g. "Blurry ID", "Expired Permit").

4. **`Pages/Logistics/HubSorting.tsx` (`/hub`)**:
   - Central Sorting Hub workstation with Barcode Scanner simulator.
   - Barangay sorting bin classification (e.g. Barangay San Antonio, Bel-Air, Poblacion, etc.).
   - Confirmation action marking parcel as sorted and ready for out-for-delivery dispatch.

5. **`Pages/Auth/PendingApproval.tsx` & KYC Form Inputs**:
   - Upload fields in `Register.tsx`, `SellerRegister.tsx`, `CourierRegister.tsx`.
   - Dedicated pending approval holding screen when unapproved user logs in.

---

## 5. Caveats
- Third-party courier API integrations (e.g. J&T, Lalamove, GrabExpress) are intentionally replaced by BagooPH's simulated internal dispatch fleet as per specification.
- Payment gateway webhooks (PayMongo, Maya Checkout) use simulated payment capture since the project operates in development/simulation integrity mode.
- No other unexamined routes or hidden frontend frameworks were detected.

---

## 6. Conclusion
The BagooPH frontend contains high-quality visual foundations and clean Inertia.js React architectures. Completing the Phase 1 multi-role implementation requires addressing the 5 identified gap areas:
1. **KYC Registration & Admin Approval Gate Enforcement**.
2. **Interactive Fast-Forward Order Progression Simulator Widget**.
3. **Tactile Barcode & Location Scanning Checkpoints**.
4. **Dedicated Logistics Hub (`/hub`) & Barangay Sorting Screen**.
5. **Persistent 10% Platform Commission & Financial Split Ledger**.

---

## 7. Verification Method

To independently verify all observations and subsequent implementations:

1. **Database Schema Verification**:
   - Inspect `database/migrations/` and verify table structures for `users`, `orders`, `order_items`, `deliveries`, and `commission_ledgers`.
2. **Role Dashboard Access & KYC Gate Test**:
   - Register a new Seller at `/seller/register` with permit upload. Verify account status defaults to `pending_approval`.
   - Attempt to access `/seller/dashboard` — verify user is gated with pending approval screen.
   - Log in as Admin at `/login` (`admin@bagoo.test`), navigate to `/admin/users`, inspect applicant documents, and click `Approve`.
   - Re-login as Seller and verify immediate dashboard access.
3. **End-to-End Order & Fast-Forward Progression Test**:
   - As Buyer (`buyer@bagoo.test`), place order at `/checkout`.
   - Verify incoming order appears in Seller Cockpit (`/seller/orders`).
   - Click "Fast-Forward" or advance through each checkpoint (`pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`).
   - Verify Buyer tracking timeline on `/buyer/orders/{id}` reflects each milestone in real-time.
   - Verify commission ledger records 90% Seller, 10% Platform Treasury, and ₱60 Courier payout upon delivery.
4. **Logistics Hub & Barcode Scanner Test**:
   - Access `/hub`, simulate scanning tracking barcode `BGO-XXXXXXXXXX`, confirm barangay sorting assignment, and verify delivery status transitions to sorted/in-transit.
