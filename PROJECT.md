# Project: BagooPH Multi-Role Data Interconnectedness & Order Lifecycle

## Architecture
- **Framework**: Laravel 11/12 + Inertia.js 2.0 (React 18, TypeScript, Tailwind CSS, Lucide React).
- **Database**: PostgreSQL 16 (production/docker) & SQLite (testing/PHPUnit).
- **User Roles (5 Roles)**:
  1. `buyer`: Marketplace shopping, variant selection, checkout, voucher application, order history, live order tracking timeline.
  2. `seller`: Seller Cockpit (`/seller/orders`), packaging approval, thermal waybill printing, ready for pickup dispatch, earnings ledger.
  3. `courier`: Courier Dispatch Board (`/courier/deliveries`), FCFS job claiming, pickup barcode scan, transit updates, doorstep drop-off with photo proof, rider earnings ledger.
  4. `logistics`: Central Logistics Hub (`/hub`), incoming barcode scan intake, barangay sorting bin classification, fleet telemetry.
  5. `admin`: Platform governance (`/admin/dashboard`, `/admin/users`), KYC verification queue (document inspection, Approve/Reject with feedback), Commission Treasury ledger.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Multi-Role KYC Registration & Schema Extensions | Add KYC fields (`kyc_status`, `id_document`, `business_permit`, `driver_license`, `or_cr_document`, `kyc_feedback`), create `courier_profiles` table, variant fields in `cart_items`/`order_items`, fix `delivery_phone` field mapping. | M1 | ORIGINAL_REQUEST R4, DB Survey |
| 2 | Auth & Role KYC Approval Gate & Admin Verification Queue | Set new accounts to `pending_approval`, enforce access gate in `RoleMiddleware` & login flow, provide `/pending-approval` holding page, create Admin KYC Queue with document preview & Approve/Reject actions. | M1 | ORIGINAL_REQUEST R4, UI Survey |
| 3 | Unified 7-Stage Order Checkout & Packaging Lifecycle | Standardize 7 stages (`pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`), Buyer checkout with variant persistence, Seller review & "Packaging" approval, thermal waybill generation. | M2 | ORIGINAL_REQUEST R1, UI Survey |
| 4 | Courier Dispatch Board & Live Buyer Tracking | FCFS courier job claiming on "Ready for Pickup", rider delivery milestone execution with proof photo upload, real-time Buyer tracking timeline on `/buyer/orders/{id}`. | M2 | ORIGINAL_REQUEST R1, Ops Survey |
| 5 | Logistics Sorting Hub (`/hub`) & Barcode Scan Checkpoints | Create `delivery_checkpoints` audit trail table, dedicated `/hub` sorting workstation for logistics role, tactile Barcode Scanner modal for Seller release, Courier pickup, Hub barangay sorting, and doorstep handover. | M3 | ORIGINAL_REQUEST R3, UI Survey |
| 6 | 10% Platform Commission & Financial Split Ledger | Create `commission_ledgers` table, execute atomic revenue distribution upon delivery (90% Seller, 10% Platform Treasury, ₱60 Courier Rider), and wire up wallet/earnings views across all roles. | M4 | ORIGINAL_REQUEST R5, DB Survey |
| 7 | Interactive "Fast-Forward" Order Progression Simulator | Centralized `OrderSimulationController` (`/simulator/orders/{order}/advance`), embedded `<FastForwardControl />` widget across Buyer, Seller, Courier, Hub, and Admin screens with 1-click stage progression and complete lifecycle jump. | M5 | ORIGINAL_REQUEST R2, Ops Survey |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Multi-Role KYC Schema, Registration & Admin Approval Gate | Schema migrations for KYC & courier profiles, registration document uploads, `RoleMiddleware` status gating, `/pending-approval` screen, Admin KYC verification queue with Approve/Reject modal. | None | DONE |
| M2 | Unified 7-Stage Order Lifecycle, Packaging & Waybill Dispatch | Order checkout with variant persistence (status `pending`), Seller Cockpit packaging approval & thermal waybill, Courier Dispatch Board claiming, and live Buyer tracking timeline. | M1 | IN_PROGRESS |
| M3 | Logistics Sorting Hub & Tactile Barcode/Location Checkpoints | `delivery_checkpoints` table, dedicated `/hub` sorting dashboard, tactile `<BarcodeScannerModal />` for packaging release, courier pickup, hub barangay sorting, and doorstep delivery. | M2 | PLANNED |
| M4 | 10% Platform Commission & Financial Split Ledger | `commission_ledgers` schema & model, atomic 90%/10%/₱60 distribution service triggered on order `delivered` status, Seller/Courier/Admin wallet & earnings ledger reconciliation. | M2 | PLANNED |
| M5 | Interactive "Fast-Forward" Order Progression Simulator | `OrderSimulationController` advance/reset endpoints, tactile floating `<FastForwardControl />` component integrated in Buyer, Seller, Courier, Hub, and Admin views. | M2, M3, M4 | PLANNED |
| M-FINAL | E2E Test Suite Execution & Adversarial Coverage Hardening | Phase 1: 100% pass of E2E test suite (Tiers 1-4). Phase 2: Tier 5 Adversarial coverage hardening via Challenger -> Worker -> Reviewer loop. | M1, M2, M3, M4, M5 | PLANNED |

## Interface Contracts
### Auth & KYC Gate ↔ Role Portals
- `User.kyc_status`: `pending_approval`, `approved`, `rejected`
- `RoleMiddleware`: redirects unapproved users to `route('kyc.pending')`
- `AdminKYCController`:
  - `POST /admin/kyc/{user}/approve`: sets `kyc_status = 'approved'`, `status = 'active'`
  - `POST /admin/kyc/{user}/reject`: sets `kyc_status = 'rejected'`, `kyc_feedback = $request->reason`

### Order Lifecycle ↔ Courier & Logistics
- `OrderStatus`: `pending`, `packaging`, `ready_for_pickup`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `cancelled`
- `DeliveryStatus`: `unassigned`, `assigned`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `failed`
- `DeliveryCheckpoint`: `delivery_id`, `checkpoint_type`, `location_name`, `barcode_scanned`, `notes`, `scanned_by_id`, `created_at`

### Financial Split Ledger
- `CommissionLedger`:
  - `gross_amount`: Total order product total
  - `seller_amount`: `gross_amount * 0.90` (90%)
  - `platform_commission`: `gross_amount * 0.10` (10%)
  - `delivery_fee`: `₱60.00` credited to `courier_id`

### Fast-Forward Simulator API
- `POST /simulator/orders/{order}/advance`: Advances Order + Delivery to next logical status, logs checkpoint, triggers commission split when reaching `delivered`.
- `POST /simulator/orders/{order}/reset`: Resets Order to `pending` and Delivery to `unassigned`.

## Code Layout
- `app/Enums/`: `OrderStatus.php`, `DeliveryStatus.php`, `KycStatus.php`
- `app/Models/`: `User.php`, `CourierProfile.php`, `Shop.php`, `Order.php`, `OrderItem.php`, `Delivery.php`, `DeliveryCheckpoint.php`, `CommissionLedger.php`
- `app/Http/Controllers/`:
  - `Auth/RegisteredUserController.php`
  - `Admin/AdminDashboardController.php`, `Admin/AdminKycController.php`, `Admin/LogisticsHubController.php`
  - `Buyer/CheckoutController.php`, `Buyer/OrderHistoryController.php`
  - `Seller/SellerOrderController.php`, `Seller/SellerDashboardController.php`
  - `Courier/CourierDeliveryController.php`
  - `Logistics/LogisticsHubWorkstationController.php`
  - `Simulation/OrderSimulationController.php`
- `resources/js/Pages/`:
  - `Auth/`: `Register.tsx`, `SellerRegister.tsx`, `CourierRegister.tsx`, `PendingApproval.tsx`
  - `Admin/`: `Users.tsx`, `KycQueue.tsx`, `Logistics.tsx`
  - `Buyer/`: `Orders.tsx`, `OrderDetail.tsx`
  - `Seller/`: `Orders.tsx`, `Reports.tsx`
  - `Courier/`: `Deliveries.tsx`, `Earnings.tsx`
  - `Logistics/`: `HubSorting.tsx`
- `resources/js/Components/`:
  - `FastForwardControl.tsx`, `BarcodeScannerModal.tsx`, `WaybillModal.tsx`
- `tests/Feature/`:
  - `Auth/`: `KycApprovalGateTest.php`
  - `Order/`: `OrderDeliveryLifecycleTest.php`, `OrderSimulationTest.php`
  - `Logistics/`: `LocationCheckpointScanTest.php`
  - `Commission/`: `FinancialSplitLedgerTest.php`
