# Project: BagooPH Multi-Role Data Interconnectedness & Order Lifecycle

## Architecture
- **Framework**: Laravel 11/12 + Inertia.js 2.0 (React 18, TypeScript, Tailwind CSS, Lucide React).
- **Database**: PostgreSQL 16 (production/docker) & SQLite (testing/PHPUnit).
- **User Roles & Actors (6 Actors)**:
  1. `buyer`: Buyer Registration -> Admin Approval -> Login -> Marketplace shopping, checkout, live tracking, Confirm Order Received -> Completed.
  2. `seller`: Seller Cockpit (`/seller/orders`), receive order notice, check stock, accept/confirm order, prepare order, pack product, print shipping label, mark ready for pickup, handover to rider, confirm rider pickup (Status: PICKED_UP).
  3. `courier_pickup`: Rider/Courier (Pickup), accept pickup assignment, collect parcel from seller, scan/confirm parcel, deliver parcel to Sorting Center.
  4. `courier_delivery`: Rider/Courier (Delivery), receive assignment from Sorting Center, pick up parcel from Sorting Center, out for delivery, deliver parcel to customer (DELIVERED -> Buyer Confirms Receipt -> COMPLETED, or DELIVERY_FAILED -> Reason Recorded -> Reschedule / RETURNED).
  5. `sorting_center`: Central Logistics Sorting Hub (`/hub`), 8-step intake & routing pipeline, sort by destination area (Area A, Area B, Area C), assign parcel to area rider.
  6. `admin`: Platform governance (`/admin`), registration approval authority (Buyer, Seller, Courier, Hub), 10% Platform Commission treasury.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Multi-Role Registration & Admin Approval Gate | Mandatory Admin Approval gate across Buyer, Seller, Courier, and Logistics Hub before login access. | M1 | Official Curriculum PDF |
| 2 | Canonical 13-Stage Order Lifecycle | Standardize 13 canonical stages (`PLACED` -> `CONFIRMED` -> `PREPARING` -> `READY_FOR_PICKUP` -> `PICKED_UP` -> `AT_SORTING_CENTER` -> `SORTED` -> `ASSIGNED_TO_RIDER` -> `OUT_FOR_DELIVERY` -> `DELIVERED` -> `COMPLETED`, with failure branch `DELIVERY_FAILED` -> `RETURNED`). | M2 | Official Curriculum PDF |
| 3 | Split Courier Operations (Pickup vs Delivery) | Dedicated Pickup Fleet (Seller -> Sorting Center) and Delivery Fleet (Sorting Center -> Doorstep). | M3 | Official Curriculum PDF |
| 4 | Sorting Center Area Routing & Rider Dispatch | Destination Area partitioning (Area A, Area B, Area C) and rider assignment engine. | M3 | Official Curriculum PDF |
| 5 | Buyer "Confirm Order Received" Finalization | Dedicated transaction completion trigger advancing order from `DELIVERED` to `COMPLETED`. | M4 | Official Curriculum PDF |
| 6 | 10% Flat Platform Commission Treasury | Automatic revenue distribution upon order completion (90% Seller net payout, 10% Platform Commission). | M4 | Curriculum Standards |

## Interface Contracts
### Auth & Admin Approval Gate ↔ Portals
- `User.kyc_status`: `pending_approval`, `approved`, `rejected`
- Mandatory gate: Registration -> Admin Approval -> Login (Applies to Buyer, Seller, Courier, Hub)
- `RoleMiddleware`: redirects unapproved accounts to `/pending-approval` holding screen.

### Canonical 13-Stage Order Lifecycle
- `OrderStatus`: `placed`, `confirmed`, `preparing`, `ready_for_pickup`, `picked_up`, `at_sorting_center`, `sorted`, `assigned_to_rider`, `out_for_delivery`, `delivered`, `completed`, `delivery_failed`, `returned`
- `Process Summary`: Buyer orders → Seller prepares → Rider picks up → Sorting Center sorts → Sorting Center assigns Rider → Rider delivers → Buyer confirms → Order completed.
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
